<?php
// editar.php — Edición de podcasts, temporadas y episodios
// Compatible con PHP 7.4+

include 'bd.php';   // Debe definir: $conn, $podcast, $temporadas, $episodios
require_once 'sesion.php';
require_once 'helpers.php';   // h(), consultar(), ejecutar(), validaciones y constantes


if ($conn->connect_error) {
    http_response_code(500);
    die('Connection Error');
}

// Si se solicita crear una temporada mediante AJAX desde la edición
if (isset($_POST['action']) && $_POST['action'] === 'crear_temporada_ajax') {
    header('Content-Type: application/json');
    if (!csrf_validar()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Sesión expirada. Recarga la página.']);
        exit;
    }
    $podcast_id = (int) $_POST['podcast_id'];

    // 1. 🛡️ VALIDACIÓN CRÍTICA: Comprobar que el podcast realmente existe en la BD
    $podcast_check = consultar($conn, "SELECT id FROM podcasts WHERE id = ?", 'i', [$podcast_id]);

    if (empty($podcast_check)) {
        echo json_encode([
            'success' => false,
            'message' => 'El podcast especificado no existe o fue eliminado.'
        ]);
        exit;
    }

    // 2. Si el podcast existe, procedemos a calcular el siguiente número de temporada
    $res = consultar($conn, "SELECT MAX(number) as max_num FROM seasons WHERE podcast_id = ?", 'i', [$podcast_id]);
    $siguiente_numero = ($res[0]['max_num'] ?? 0) + 1;
    $titulo_nuevo = "Temporada " . $siguiente_numero;

    // 3. Insertamos la nueva temporada
    $ok = ejecutar(
        $conn,
        "INSERT INTO seasons (podcast_id, number, title) VALUES (?, ?, ?)",
        'iis',
        [$podcast_id, $siguiente_numero, $titulo_nuevo]
    );

    if ($ok) {
        $nuevo_id = $conn->insert_id;
        echo json_encode([
            'success' => true,
            'id' => $nuevo_id,
            'number' => $siguiente_numero,
            'title' => $titulo_nuevo
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo crear la temporada en la base de datos.'
        ]);
    }
    exit;
}

// ------------------------------------------------------------------
// 1. VALIDAR PARÁMETROS DE LA URL
// ------------------------------------------------------------------
$tablas = [
    $podcast    => 'podcasts',
    $temporadas => 'seasons',
    $episodios  => 'episodes',
];

$type = $_GET['type'] ?? $podcast;
if (!is_string($type) || !isset($tablas[$type])) {
    http_response_code(400);
    die('Tipo no válido');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    die('ID no válido');
}

// ------------------------------------------------------------------
// 2. CARGAR EL REGISTRO ACTUAL
// ------------------------------------------------------------------
$tabla = $tablas[$type];   // Viene de la lista blanca, es seguro interpolarlo
$registro = consultar($conn, "SELECT * FROM `$tabla` WHERE id = ?", 'i', [$id])[0] ?? null;

if (!$registro) {
    http_response_code(404);
    die('Registro no encontrado');
}

// Datos auxiliares para los selects
$listaTemporadas = [];
$nombrePodcast = '';

if ($type === $episodios) {
    $listaTemporadas = consultar(
        $conn,
        "SELECT seasons.id, seasons.podcast_id, podcasts.title, seasons.number
         FROM seasons INNER JOIN podcasts ON podcasts.id = seasons.podcast_id
         ORDER BY podcasts.title, seasons.number"
    );
} elseif ($type === $temporadas) {
    $p = consultar($conn, "SELECT title FROM podcasts WHERE id = ?", 'i', [(int) $registro['podcast_id']]);
    $nombrePodcast = $p[0]['title'] ?? '(sin podcast)';
}

// Valores iniciales del formulario
$form = $registro;
if (($form['state'] ?? '') === 'Pausado') {
    $form['state'] = 'Inactivo';   // 'Pausado' es solo la etiqueta; en BD se guarda 'Inactivo'
}
$form['link'] = $registro['description'] ?? '';
$form['publish_date'] = fecha_para_input($registro['publish_date'] ?? null);

// ------------------------------------------------------------------
// 3. PROCESAR EL FORMULARIO (POST)
// ------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errores = [];
$errorGeneral = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Si hay errores, el formulario se vuelve a mostrar con lo que escribió el usuario
    foreach (['title', 'image', 'link', 'state', 'schedule_custom', 'number', 'season_id', 'duration', 'publish_date'] as $c) {
        if (isset($_POST[$c]) && is_string($_POST[$c])) {
            $form[$c] = trim($_POST[$c]);
        }
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errorGeneral = 'El formulario expiró o no es válido. Recarga la página e inténtalo de nuevo.';
    } else {
        $ok = false;

        if ($type === $podcast) {
            $v = [
                'title' => post_texto('title'),
                'image' => post_texto('image'),
                'link'  => post_texto('link'),
                'state' => post_texto('state'),
                'schedule_custom' => post_texto('schedule_custom')
            ];
            if ($v['title'] === '' || mb_strlen($v['title']) > TITULO_MAX) {
                $errores['title'] = 'El título es obligatorio (máx. ' . TITULO_MAX . ' caracteres).';
            }
            if (!imagen_valida($v['image'])) {
                $errores['image'] = 'Ingresa un link http(s) válido o déjalo vacío para usar la imagen del canal.';
            }
            if (!url_valida($v['link'])) {
                $errores['link'] = 'Ingresa un link válido que empiece con http:// o https://';
            }
            if (!in_array($v['state'], ESTADOS_PODCAST, true)) {
                $errores['state'] = 'Estado no válido.';
            }
            $horario = normalizar_schedule_custom($v['schedule_custom']);
            if ($horario === null) {
                $errores['schedule_custom'] = 'Formato inválido. Usa Día:HH:MM separados por coma. Ej: Martes:12:00,Jueves:18:30';
            } else {
                $v['schedule_custom'] = $horario;
            }
            if (!$errores) {
                $ok = ejecutar(
                    $conn,
                    "UPDATE podcasts SET title = ?, image = ?, description = ?, state = ?, schedule_custom = ? WHERE id = ?",
                    'sssssi',
                    [$v['title'], $v['image'], $v['link'], $v['state'], $v['schedule_custom'], $id]
                );
            }
        } elseif ($type === $temporadas) {
            // El podcast de una temporada no se cambia desde aquí, solo el número
            $number = post_entero('number', 0, NUMERO_MAX);
            if ($number === null) {
                $errores['number'] = 'Ingresa un número entre 0 y ' . NUMERO_MAX . '.';
            } else {
                $dup = consultar(
                    $conn,
                    "SELECT id FROM seasons WHERE podcast_id = ? AND number = ? AND id <> ?",
                    'iii',
                    [(int) $registro['podcast_id'], $number, $id]
                );
                if ($dup) {
                    $errores['number'] = 'Ese podcast ya tiene una temporada con ese número.';
                }
            }
            if (!$errores) {
                $ok = ejecutar($conn, "UPDATE seasons SET number = ? WHERE id = ?", 'ii', [$number, $id]);
            }
        } elseif ($type === $episodios) {
            $seasonId = post_entero('season_id', 1, PHP_INT_MAX);
            $number   = post_entero('number', 0, NUMERO_MAX);
            $title    = post_texto('title');
            $duration = normalizar_duracion(post_texto('duration'));
            $fecha    = normalizar_fecha(post_texto('publish_date'));

            $podcastDeTemporada = null;
            foreach ($listaTemporadas as $t) {
                if ($seasonId !== null && (int) $t['id'] === $seasonId) {
                    $podcastDeTemporada = (int) $t['podcast_id'];
                }
            }
            if ($podcastDeTemporada === null) {
                $errores['season_id'] = 'Selecciona una temporada válida.';
            }
            if ($number === null) {
                $errores['number'] = 'Ingresa un número entre 0 y ' . NUMERO_MAX . '.';
            }
            if ($title === '' || mb_strlen($title) > TITULO_MAX) {
                $errores['title'] = 'El título es obligatorio (máx. ' . TITULO_MAX . ' caracteres).';
            }
            if ($duration === null) {
                $errores['duration'] = 'Duración inválida. Formato H:MM:SS, máximo 10:00:00.';
            }
            if ($fecha === null) {
                $errores['publish_date'] = 'Fecha y hora no válidas.';
            }
            if (!$errores) {
                $ok = ejecutar(
                    $conn,
                    "UPDATE episodes SET season_id = ?, podcast_id = ?, number = ?, title = ?, duration = ?, publish_date = ? WHERE id = ?",
                    'iiisssi',
                    [$seasonId, $podcastDeTemporada, $number, $title, $duration, $fecha, $id]
                );
            }
        }

        $mensajeExito = ''; // Inicializamos la variable de éxito

        if ($ok) {
            $mensajeExito = '¡Los cambios se han guardado correctamente!';
            // Opcional: si es un formulario de edición y quieres refrescar los datos 
            // que ve el usuario con los nuevos valores recién guardados:
            // $registro = ... (recargar datos si es necesario)
        } elseif (!$errores) {
            $errorGeneral = 'No se pudo guardar los cambios. Inténtalo de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar <?= h(ucfirst($type)) ?></title>
    <meta name="robots" content="noindex">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="container mt-5">
        <h2>Editar <?= h(ucfirst($type)) ?></h2>
        <p><a href="index.php?section=<?= h(urlencode($type)) ?>" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Volver</a></p>

        <?php if (!empty($mensajeExito)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= h($mensajeExito) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorGeneral)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= h($errorGeneral) ?>
            </div>
        <?php endif; ?>

        <form action="" method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

            <?php if ($type === $podcast): ?>

                <?php campo('Titulo', 'title', 'text', $form, $errores, 'maxlength="' . TITULO_MAX . '"'); ?>
                <div class="mb-3">
                    <label class="form-label" for="image">Imagen (Link) <span class="text-muted small">— opcional, si queda vacía usa la del canal</span></label>
                    <input type="text" class="form-control<?= isset($errores['image']) ? ' is-invalid' : '' ?>" id="image" name="image" value="<?= h($form['image'] ?? '') ?>">
                    <div class="invalid-feedback"><?= h($errores['image'] ?? '') ?></div>
                </div>
                <?php campo('Link de Youtube', 'link', 'url', $form, $errores); ?>

                <div class="mb-3">
                    <label class="form-label" for="state">Estado</label>
                    <select class="form-select<?= isset($errores['state']) ? ' is-invalid' : '' ?>" id="state" name="state" required>
                        <?php foreach (ESTADOS_PODCAST as $estado): ?>
                            <option value="<?= h($estado) ?>" <?= ($form['state'] ?? '') === $estado ? 'selected' : '' ?>><?= h(estado_label($estado)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback"><?= h($errores['state'] ?? '') ?></div>
                </div>

                <div class="mb-3">
                    <label for="schedule_custom" class="form-label">Horario Fijo Personalizado (Opcional)</label>
                    <input type="text" class="form-control<?= isset($errores['schedule_custom']) ? ' is-invalid' : '' ?>" id="schedule_custom" name="schedule_custom"
                        value="<?= h($form['schedule_custom'] ?? '') ?>"
                        placeholder="Ej: Martes:12:00,Jueves:12:30">
                    <div class="invalid-feedback"><?= h($errores['schedule_custom'] ?? '') ?></div>
                    <div class="form-text">Si lo dejas vacío, el sistema calculará el horario automáticamente basándose en los episodios. Si escribes días y horas, se respetarán tal cual.</div>
                </div>

            <?php elseif ($type === $temporadas): ?>

                <div class="mb-3">
                    <label class="form-label">Podcast</label>
                    <input type="text" class="form-control" value="<?= h($nombrePodcast) ?>" disabled>
                </div>
                <?php campo('Numero de Temporada', 'number', 'number', $form, $errores, 'min="0" max="' . NUMERO_MAX . '" step="1"'); ?>

            <?php elseif ($type === $episodios): ?>

                <div class="mb-3">
                    <label class="form-label" for="season_id">Temporada</label>
                    <div class="input-group">
                        <select class="form-select<?= isset($errores['season_id']) ? ' is-invalid' : '' ?>" id="season_id" name="season_id" required>
                            <?php foreach ($listaTemporadas as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= (string) ($form['season_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>>
                                    <?= h($s['title'] . ' Temporada ' . $s['number']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Botón para abrir el modal de nueva temporada -->
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalNuevaTemporada" title="Crear nueva temporada">
                            <i class="fas fa-plus"></i> Nueva
                        </button>
                    </div>
                    <div class="invalid-feedback"><?= h($errores['season_id'] ?? '') ?></div>
                </div>

                <div class="modal fade" id="modalNuevaTemporada" tabindex="-1" aria-labelledby="modalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="modalLabel">Crear Nueva Temporada</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted mb-0">¿Deseas crear la siguiente temporada automáticamente para este podcast?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="button" class="btn btn-primary" id="btnCrearTemporadaAjax">Sí, crear</button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php campo('N° Capitulo', 'number', 'number', $form, $errores, 'min="0" max="' . NUMERO_MAX . '" step="1"'); ?>
                <?php campo('Titulo', 'title', 'text', $form, $errores, 'maxlength="' . TITULO_MAX . '"'); ?>
                <?php campo('Duración (máx. 10:00:00)', 'duration', 'text', $form, $errores, 'maxlength="8" inputmode="numeric" autocomplete="off"'); ?>
                <?php // step="1" mantiene los segundos de la fecha al guardar 
                ?>
                <?php campo('Fecha de Publicacion', 'publish_date', 'datetime-local', $form, $errores, 'step="1"'); ?>

                <script>
                    (function() {
                        const input = document.getElementById('duration');
                        const feedback = input.nextElementSibling;
                        const MAX_SEG = <?= DURACION_MAX_SEG ?>;

                        // Inserta los ":" automáticamente (se escribe como HHMMSS)
                        function formatear(valor) {
                            let d = valor.replace(/\D/g, '').slice(0, 6);
                            if (d.length <= 2) return d;
                            if (d.length <= 4) return d.slice(0, -2) + ':' + d.slice(-2);
                            return d.slice(0, -4) + ':' + d.slice(-4, -2) + ':' + d.slice(-2);
                        }

                        // Devuelve el mensaje de error, o '' si es válido
                        function validar(valor) {
                            const m = /^(\d{1,2}):([0-5]\d):([0-5]\d)$/.exec(valor);
                            if (!m) return 'Duración inválida. Formato H:MM:SS';
                            const total = (+m[1]) * 3600 + (+m[2]) * 60 + (+m[3]);
                            return total > MAX_SEG ? 'La duración máxima es 10:00:00' : '';
                        }

                        input.addEventListener('input', function() {
                            input.value = formatear(input.value);
                            const msg = validar(input.value);
                            input.setCustomValidity(msg); // bloquea el envío si es inválido
                            input.classList.toggle('is-invalid', msg !== '');
                            feedback.textContent = msg;
                        });
                    })();
                </script>

            <?php endif; ?>

            <button type="submit" class="btn btn-success">Actualizar</button>
            <a href="index.php?section=<?= h(urlencode($type)) ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const btnCrearTemporada = document.getElementById('btnCrearTemporadaAjax');

        if (btnCrearTemporada) {
            btnCrearTemporada.addEventListener('click', function() {

                // Podcast de la temporada seleccionada (no depende de episodes.podcast_id, que puede estar vacío)
                const podcastPorTemporada = <?= json_encode(array_column($listaTemporadas, 'podcast_id', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const podcastId = podcastPorTemporada[document.getElementById('season_id').value] || <?= (int) ($registro['podcast_id'] ?? 0) ?>;

                const formData = new FormData();
                formData.append('action', 'crear_temporada_ajax');
                formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                formData.append('podcast_id', podcastId);

                // Evitar múltiples clics mientras se procesa
                btnCrearTemporada.disabled = true;

                fetch('edit.php?type=episodios&id=<?= (int) $id ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Error HTTP: ' + response.status);
                        }

                        return response.json();
                    })
                    .then(data => {

                        if (data.success) {

                            const select = document.getElementById('season_id');

                            if (!select) {
                                throw new Error('No se encontró el selector season_id');
                            }

                            // Crear nueva opción
                            const option = document.createElement('option');
                            option.value = data.id;
                            option.textContent = data.title + ' Temporada ' + data.number;
                            option.selected = true;

                            // Agregar y seleccionar automáticamente
                            select.appendChild(option);
                            select.value = data.id;

                            // Cerrar modal
                            const modalEl = document.getElementById('modalNuevaTemporada');

                            if (modalEl) {
                                const modal = bootstrap.Modal.getInstance(modalEl);
                                if (modal) {
                                    modal.hide();
                                }
                            }

                        } else {
                            alert(data.message || 'No se pudo crear la temporada.');
                        }

                    })
                    .catch(error => {
                        console.error('Error al crear temporada:', error);
                        alert('Ocurrió un error al crear la temporada.');
                    })
                    .finally(() => {
                        btnCrearTemporada.disabled = false;
                    });

            });
        }
    </script>


</body>

</html>