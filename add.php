<?php
include('bd.php');
require_once 'sesion.php';
require_once 'helpers.php';


// Tipo: solo valores conocidos (antes se usaba $podcast antes de incluir bd.php)
$type = $_GET['type'] ?? $podcast;
if (!is_string($type) || !in_array($type, [$podcast, $temporadas, $episodios], true)) {
    http_response_code(400);
    die('Tipo no válido');
}
if ($conn->connect_error) {
    http_response_code(500);
    die('Connection Error');
}

$etiquetas = [$podcast => 'Podcast', $temporadas => 'Temporada', $episodios => 'Episodio'];

// ------------------------------------------------------------------
// Datos para los selects
// ------------------------------------------------------------------
$canales = $type === $podcast
    ? consultar($conn, "SELECT id, name FROM channels ORDER BY name ASC, id ASC")
    : [];

$listaPodcasts = $type === $temporadas
    ? consultar($conn, "SELECT p.id, p.title, COALESCE(MAX(s.number), 0) + 1 AS siguiente
                        FROM podcasts p LEFT JOIN seasons s ON s.podcast_id = p.id
                        GROUP BY p.id, p.title ORDER BY p.title")
    : [];

// Última temporada de cada podcast + número del siguiente capítulo
$listaTemporadas = $type === $episodios
    ? consultar($conn, "SELECT s.id AS season_id, p.id AS podcast_id, p.title, s.number AS temporada,
                               COALESCE(MAX(e.number), 0) + 1 AS capitulo
                        FROM podcasts p
                        INNER JOIN seasons s ON s.podcast_id = p.id
                        LEFT JOIN episodes e ON e.season_id = s.id
                        WHERE s.number = (SELECT MAX(s2.number) FROM seasons s2 WHERE s2.podcast_id = p.id)
                        GROUP BY s.id, p.id, p.title, s.number
                        ORDER BY p.title")
    : [];

// ------------------------------------------------------------------
// Valores iniciales y procesamiento
// ------------------------------------------------------------------
$form = [
    'channel_id' => '', 'title' => '', 'image' => '', 'link' => '', 'state' => 'Activo',
    'podcast_id' => '', 'number' => '',
    'season_id' => '', 'duration' => '', 'publish_date' => date('Y-m-d\TH:i'), 'youtube_link' => '',
];
$errores = [];
$errorGeneral = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_exigir();
    foreach (array_keys($form) as $c) {
        if (isset($_POST[$c]) && is_string($_POST[$c])) {
            $form[$c] = trim($_POST[$c]);
        }
    }
    $ok = false;

    if ($type === $podcast) {
        $channelId = post_entero('channel_id', 1, PHP_INT_MAX);
        $title = post_texto('title');
        $image = post_texto('image');
        $link  = post_texto('link');
        $state = post_texto('state');

        if ($channelId === null || !consultar($conn, "SELECT id FROM channels WHERE id = ?", 'i', [$channelId])) {
            $errores['channel_id'] = 'Selecciona un canal válido.';
        }
        if ($title === '' || mb_strlen($title) > TITULO_MAX) {
            $errores['title'] = 'El título es obligatorio (máx. ' . TITULO_MAX . ' caracteres).';
        }
        if (!imagen_valida($image)) {
            $errores['image'] = 'Ingresa un link http(s) válido o déjalo vacío para usar la imagen del canal.';
        }
        if (!url_valida($link)) {
            $errores['link'] = 'Ingresa un link válido que empiece con http:// o https://';
        }
        if (!in_array($state, ESTADOS_PODCAST, true)) {
            $errores['state'] = 'Estado no válido.';
        }

        if (!$errores) {
            $conn->begin_transaction();
            $ok = ejecutar(
                $conn,
                "INSERT INTO podcasts (channel_id, title, image, description, state) VALUES (?, ?, ?, ?, ?)",
                'issss',
                [$channelId, $title, $image, $link, $state]
            );
            if ($ok) {
                $nuevoId = $conn->insert_id;
                // Temporada 1 automática
                $ok = ejecutar($conn, "INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')", 'i', [$nuevoId]);
            }
            $ok ? $conn->commit() : $conn->rollback();
        }
    } elseif ($type === $temporadas) {
        $podcastId = post_entero('podcast_id', 1, PHP_INT_MAX);
        $existe = $podcastId !== null ? consultar($conn, "SELECT id FROM podcasts WHERE id = ?", 'i', [$podcastId]) : [];
        if (!$existe) {
            $errores['podcast_id'] = 'Selecciona un podcast válido.';
        } else {
            $number = post_texto('number') === ''
                ? (int) (consultar($conn, "SELECT COALESCE(MAX(number), 0) + 1 AS n FROM seasons WHERE podcast_id = ?", 'i', [$podcastId])[0]['n'] ?? 1)
                : post_entero('number', 0, NUMERO_MAX);
            if ($number === null) {
                $errores['number'] = 'Ingresa un número entre 0 y ' . NUMERO_MAX . '.';
            } elseif (consultar($conn, "SELECT id FROM seasons WHERE podcast_id = ? AND number = ?", 'ii', [$podcastId, $number])) {
                $errores['number'] = 'Ese podcast ya tiene la temporada ' . $number . '.';
            } else {
                $ok = ejecutar(
                    $conn,
                    "INSERT INTO seasons (podcast_id, number, title) VALUES (?, ?, ?)",
                    'iis',
                    [$podcastId, $number, 'Temporada ' . $number]
                );
            }
        }
    } elseif ($type === $episodios) {
        $seasonId = post_entero('season_id', 1, PHP_INT_MAX);
        $number   = post_entero('number', 0, NUMERO_MAX);
        $title    = post_texto('title');
        $duration = normalizar_duracion(post_texto('duration'));
        $fecha    = normalizar_fecha(post_texto('publish_date'));
        $ytLink   = post_texto('youtube_link');
        $ytId     = extraer_youtube_id($ytLink);

        $podcastDeTemporada = null;
        foreach ($listaTemporadas as $t) {
            if ($seasonId !== null && (int) $t['season_id'] === $seasonId) {
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
        if ($ytLink !== '' && $ytId === null) {
            $errores['youtube_link'] = 'No se reconoció el link de YouTube.';
        } elseif ($ytId !== null && consultar($conn, "SELECT id FROM episodes WHERE youtube_id = ?", 's', [$ytId])) {
            $errores['youtube_link'] = 'Ese video ya está registrado como episodio.';
        }

        if (!$errores) {
            // podcast_id se guarda también (antes quedaba NULL y el calendario no veía estos episodios)
            $ok = ejecutar(
                $conn,
                "INSERT INTO episodes (season_id, podcast_id, number, title, duration, publish_date, youtube_id, status, detected_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'PUBLISHED', NOW(), NOW())",
                'iiissss',
                [$seasonId, $podcastDeTemporada, $number, $title, $duration, $fecha, $ytId]
            );
        }
    }

    if ($ok) {
        flash_set('success', $etiquetas[$type] . ' creado correctamente.');
        header('Location: index.php?section=' . urlencode($type));
        exit;
    }
    if (!$errores) {
        $errorGeneral = 'No se pudo guardar. Inténtalo de nuevo.';
    }
}

$capitulos = [];
foreach ($listaTemporadas as $t) {
    $capitulos[$t['season_id']] = (int) $t['capitulo'];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Agregar <?= h($etiquetas[$type]) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5 mb-5" style="max-width: 760px;">
        <h2>Agregar <?= h($etiquetas[$type]) ?></h2>

        <?php if ($errorGeneral): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= h($errorGeneral) ?></div>
        <?php endif; ?>

        <form action="" method="post" novalidate>
            <?= csrf_field() ?>

            <?php if ($type === $podcast): ?>
                <div class="mb-3">
                    <label class="form-label" for="channel_id">Canal de YouTube / Cuenta</label>
                    <select name="channel_id" id="channel_id" class="<?= isset($errores['channel_id']) ? 'is-invalid' : '' ?>" required placeholder="Busca un canal…">
                        <option value="">Busca un canal…</option>
                        <?php foreach ($canales as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (string) $form['channel_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> · #<?= (int) $c['id'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback d-block"><?= h($errores['channel_id'] ?? '') ?></div>
                    <div class="form-text">Si el canal no aparece, regístralo antes en <a href="channels.php">Gestión de Canales</a>.</div>
                </div>

                <?php campo('Título del Podcast (Serie)', 'title', 'text', $form, $errores, 'maxlength="' . TITULO_MAX . '"'); ?>

                <div class="mb-3">
                    <label class="form-label" for="image">Imagen (Link) <span class="text-muted small">— opcional, si queda vacía usa la del canal</span></label>
                    <input type="text" class="form-control<?= isset($errores['image']) ? ' is-invalid' : '' ?>" id="image" name="image" value="<?= h($form['image']) ?>">
                    <div class="invalid-feedback"><?= h($errores['image'] ?? '') ?></div>
                </div>

                <?php campo('Link de YouTube del podcast', 'link', 'url', $form, $errores); ?>

                <div class="mb-3">
                    <label class="form-label" for="state">Estado</label>
                    <select class="form-select<?= isset($errores['state']) ? ' is-invalid' : '' ?>" name="state" id="state" required>
                        <?php foreach (ESTADOS_PODCAST as $est): ?>
                            <option value="<?= h($est) ?>" <?= $form['state'] === $est ? 'selected' : '' ?>><?= h(estado_label($est)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback"><?= h($errores['state'] ?? '') ?></div>
                </div>

            <?php elseif ($type === $temporadas): ?>
                <div class="mb-3">
                    <label class="form-label" for="podcast_id">Podcast</label>
                    <select name="podcast_id" id="podcast_id" required placeholder="Busca un podcast…">
                        <option value="">Busca un podcast…</option>
                        <?php foreach ($listaPodcasts as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (string) $form['podcast_id'] === (string) $p['id'] ? 'selected' : '' ?>><?= h($p['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback d-block"><?= h($errores['podcast_id'] ?? '') ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="number">Número de temporada</label>
                    <input type="number" class="form-control<?= isset($errores['number']) ? ' is-invalid' : '' ?>" id="number" name="number"
                        min="0" max="<?= NUMERO_MAX ?>" step="1" value="<?= h($form['number']) ?>" placeholder="Vacío = la siguiente">
                    <div class="invalid-feedback"><?= h($errores['number'] ?? '') ?></div>
                    <div class="form-text" id="numHint">Si lo dejas vacío se crea la siguiente temporada del podcast.</div>
                </div>

            <?php elseif ($type === $episodios): ?>
                <div class="mb-3 p-3 bg-light border rounded">
                    <label class="form-label fw-bold text-primary" for="youtube_link"><i class="fab fa-youtube me-1"></i> Autocompletar con API de YouTube</label>
                    <div class="input-group">
                        <input type="url" class="form-control<?= isset($errores['youtube_link']) ? ' is-invalid' : '' ?>" id="youtube_link" name="youtube_link"
                            value="<?= h($form['youtube_link']) ?>" placeholder="https://www.youtube.com/watch?v=...">
                        <button class="btn btn-outline-primary" type="button" id="btnAutoFill"><i class="fas fa-magic me-1"></i> Cargar con API</button>
                        <div class="invalid-feedback"><?= h($errores['youtube_link'] ?? '') ?></div>
                    </div>
                    <div class="form-text" id="autoFillFeedback">Pega el link para traer título, duración y fecha exacta (zona horaria Santiago).</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="season_id">Temporada</label>
                    <select name="season_id" id="season_id" required placeholder="Busca un podcast…">
                        <option value="">Busca un podcast…</option>
                        <?php foreach ($listaTemporadas as $t): ?>
                            <option value="<?= (int) $t['season_id'] ?>" <?= (string) $form['season_id'] === (string) $t['season_id'] ? 'selected' : '' ?>>
                                <?= h($t['title']) ?> — Temporada <?= h($t['temporada']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback d-block"><?= h($errores['season_id'] ?? '') ?></div>
                    <div class="form-text">Para otra temporada de un podcast, créala antes en la sección Temporadas.</div>
                </div>

                <?php campo('Número de Episodio', 'number', 'number', $form, $errores, 'min="0" max="' . NUMERO_MAX . '" step="1"'); ?>
                <?php campo('Título', 'title', 'text', $form, $errores, 'maxlength="' . TITULO_MAX . '"'); ?>
                <?php campo('Duración (máx. 10:00:00)', 'duration', 'text', $form, $errores, 'maxlength="8" inputmode="numeric" autocomplete="off" placeholder="H:MM:SS"'); ?>
                <?php campo('Fecha de Publicación', 'publish_date', 'datetime-local', $form, $errores); ?>
            <?php endif; ?>

            <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Guardar</button>
            <a href="index.php?section=<?= h($type) ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script>
        <?php if ($type === $podcast): ?>
            new TomSelect('#channel_id', {
                maxOptions: null
            });
        <?php elseif ($type === $temporadas): ?>
            new TomSelect('#podcast_id', {
                maxOptions: null
            });
        <?php elseif ($type === $episodios): ?>
            const capitulos = <?= json_encode($capitulos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const numInput = document.getElementById('number');
            new TomSelect('#season_id', {
                maxOptions: null,
                onChange: function(v) {
                    if (v && capitulos[v] !== undefined) {
                        numInput.value = capitulos[v]; // sugiere el siguiente capítulo
                    }
                }
            });

            // Duración: inserta ":" solo y valida
            (function() {
                const input = document.getElementById('duration');
                const feedback = input.nextElementSibling;
                const MAX_SEG = <?= DURACION_MAX_SEG ?>;

                function formatear(valor) {
                    let d = valor.replace(/\D/g, '').slice(0, 6);
                    if (d.length <= 2) return d;
                    if (d.length <= 4) return d.slice(0, -2) + ':' + d.slice(-2);
                    return d.slice(0, -4) + ':' + d.slice(-4, -2) + ':' + d.slice(-2);
                }

                function validar(valor) {
                    const m = /^(\d{1,2}):([0-5]\d):([0-5]\d)$/.exec(valor);
                    if (!m) return 'Duración inválida. Formato H:MM:SS';
                    return ((+m[1]) * 3600 + (+m[2]) * 60 + (+m[3])) > MAX_SEG ? 'La duración máxima es 10:00:00' : '';
                }
                input.addEventListener('input', function() {
                    input.value = formatear(input.value);
                    const msg = validar(input.value);
                    input.setCustomValidity(msg);
                    input.classList.toggle('is-invalid', msg !== '');
                    feedback.textContent = msg;
                });
            })();

            // Autocompletar con la API de YouTube (get_video_info.php)
            document.getElementById('btnAutoFill').addEventListener('click', function() {
                const url = document.getElementById('youtube_link').value.trim();
                const feedback = document.getElementById('autoFillFeedback');
                if (!url) {
                    alert('Por favor ingresa un link de YouTube primero.');
                    return;
                }
                feedback.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin me-1"></i> Consultando a la API de YouTube...</span>';

                fetch('get_video_info.php?url=' + encodeURIComponent(url), {
                        credentials: 'same-origin'
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) throw new Error(data.error || 'Sin datos');
                        document.getElementById('title').value = data.title;
                        const d = document.getElementById('duration');
                        d.value = data.duration;
                        d.dispatchEvent(new Event('input'));
                        document.getElementById('publish_date').value = data.publish_date;
                        feedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i> ¡Datos cargados con éxito!</span>';
                    })
                    .catch(err => {
                        const s = document.createElement('span');
                        s.className = 'text-danger';
                        s.textContent = 'Error: ' + err.message;
                        feedback.replaceChildren(s);
                    });
            });
        <?php endif; ?>
    </script>
</body>

</html>