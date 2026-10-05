<?php
// editar.php — Edición de podcasts, temporadas y episodios
// Compatible con PHP 7.4+

include 'bd.php';   // Debe definir: $conn, $podcast, $temporadas, $episodios

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($conn->connect_error) {
    http_response_code(500);
    die('Connection Error');
}

// ------------------------------------------------------------------
// CONSTANTES DE VALIDACIÓN
// ------------------------------------------------------------------
const ESTADOS_PODCAST = ['Activo', 'Inactivo', 'Finalizado'];
const DURACION_MAX_SEG = 36000;   // 10:00:00
const NUMERO_MAX = 9999;
const TITULO_MAX = 255;

// ------------------------------------------------------------------
// FUNCIONES AUXILIARES
// ------------------------------------------------------------------

/** Escapa un valor para imprimirlo en HTML. */
function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Lee un campo POST como texto sin espacios sobrantes ('' si no existe o es un array). */
function post_texto(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Lee un campo POST como entero dentro de un rango. Devuelve null si no es válido. */
function post_entero(string $campo, int $min, int $max): ?int
{
    $v = filter_var(post_texto($campo), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    return $v === false ? null : $v;
}

function url_valida(string $url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('#^https?://#i', $url) === 1;
}

/** Acepta H:MM:SS o HH:MM:SS y devuelve HH:MM:SS, o null si es inválida o supera el máximo. */
function normalizar_duracion(string $v): ?string
{
    if (!preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $v, $m)) {
        return null;
    }
    $total = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    if ($total > DURACION_MAX_SEG) {
        return null;
    }
    return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3]);
}

/** Convierte el valor de <input type="datetime-local"> a 'Y-m-d H:i:s'. Null si es inválido. */
function normalizar_fecha(string $v): ?string
{
    foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $formato) {
        $dt = DateTime::createFromFormat($formato, $v);
        $e = DateTime::getLastErrors();
        if ($dt !== false && (!$e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/** Convierte 'Y-m-d H:i:s' (BD) al formato que espera datetime-local. */
function fecha_para_input(?string $v): string
{
    if (!$v) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $v);
    return $dt ? $dt->format('Y-m-d\TH:i:s') : '';
}

/** SELECT con sentencia preparada. Devuelve un arreglo de filas. */
function consultar(mysqli $conn, string $sql, string $tipos = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('SQL prepare error: ' . $conn->error);
        return [];
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $filas;
}

/** UPDATE/INSERT con sentencia preparada. Devuelve true si se ejecutó bien. */
function ejecutar(mysqli $conn, string $sql, string $tipos, array $params): bool
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('SQL prepare error: ' . $conn->error);
        return false;
    }
    $stmt->bind_param($tipos, ...$params);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('SQL execute error: ' . $stmt->error);
    }
    $stmt->close();
    return $ok;
}

/** Imprime un campo de formulario con su mensaje de error (si lo hay). */
function campo(string $label, string $name, string $tipo, array $form, array $errores, string $extra = ''): void
{
    $invalido = isset($errores[$name]);
    ?>
    <div class="mb-3">
        <label class="form-label" for="<?= h($name) ?>"><?= h($label) ?></label>
        <input type="<?= h($tipo) ?>" class="form-control<?= $invalido ? ' is-invalid' : '' ?>"
            id="<?= h($name) ?>" name="<?= h($name) ?>" value="<?= h($form[$name] ?? '') ?>" required <?= $extra ?>>
        <div class="invalid-feedback"><?= h($errores[$name] ?? '') ?></div>
    </div>
    <?php
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
        "SELECT seasons.id, podcasts.title, seasons.number
         FROM seasons INNER JOIN podcasts ON podcasts.id = seasons.podcast_id
         ORDER BY podcasts.title, seasons.number"
    );
} elseif ($type === $temporadas) {
    $p = consultar($conn, "SELECT title FROM podcasts WHERE id = ?", 'i', [(int) $registro['podcast_id']]);
    $nombrePodcast = $p[0]['title'] ?? '(sin podcast)';
}

// Valores iniciales del formulario
$form = $registro;
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
    foreach (['title', 'image', 'link', 'state', 'number', 'season_id', 'duration', 'publish_date'] as $c) {
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
            ];
            if ($v['title'] === '' || mb_strlen($v['title']) > TITULO_MAX) {
                $errores['title'] = 'El título es obligatorio (máx. ' . TITULO_MAX . ' caracteres).';
            }
            if (!url_valida($v['image'])) {
                $errores['image'] = 'Ingresa un link válido que empiece con http:// o https://';
            }
            if (!url_valida($v['link'])) {
                $errores['link'] = 'Ingresa un link válido que empiece con http:// o https://';
            }
            if (!in_array($v['state'], ESTADOS_PODCAST, true)) {
                $errores['state'] = 'Estado no válido.';
            }
            if (!$errores) {
                $ok = ejecutar(
                    $conn,
                    "UPDATE podcasts SET title = ?, image = ?, description = ?, state = ? WHERE id = ?",
                    'ssssi',
                    [$v['title'], $v['image'], $v['link'], $v['state'], $id]
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

            $idsValidos = array_map('strval', array_column($listaTemporadas, 'id'));
            if ($seasonId === null || !in_array((string) $seasonId, $idsValidos, true)) {
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
                    "UPDATE episodes SET season_id = ?, number = ?, title = ?, duration = ?, publish_date = ? WHERE id = ?",
                    'iisssi',
                    [$seasonId, $number, $title, $duration, $fecha, $id]
                );
            }
        }

        if ($ok) {
            header('Location: index.php?section=' . urlencode($type));
            exit;
        }
        if (!$errores) {
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-5">
        <h2>Editar <?= h(ucfirst($type)) ?></h2>

        <?php if ($errorGeneral): ?>
            <div class="alert alert-danger"><?= h($errorGeneral) ?></div>
        <?php endif; ?>

        <form action="" method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

            <?php if ($type === $podcast): ?>

                <?php campo('Titulo', 'title', 'text', $form, $errores, 'maxlength="' . TITULO_MAX . '"'); ?>
                <?php campo('Imagen (Link)', 'image', 'url', $form, $errores); ?>
                <?php campo('Link de Youtube', 'link', 'url', $form, $errores); ?>

                <div class="mb-3">
                    <label class="form-label" for="state">Estado</label>
                    <select class="form-select<?= isset($errores['state']) ? ' is-invalid' : '' ?>" id="state" name="state" required>
                        <?php foreach (ESTADOS_PODCAST as $estado): ?>
                            <option value="<?= h($estado) ?>" <?= ($form['state'] ?? '') === $estado ? 'selected' : '' ?>><?= h($estado) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback"><?= h($errores['state'] ?? '') ?></div>
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
                    <select class="form-select<?= isset($errores['season_id']) ? ' is-invalid' : '' ?>" id="season_id" name="season_id" required>
                        <?php foreach ($listaTemporadas as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (string) ($form['season_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>>
                                <?= h($s['title'] . ' Temporada ' . $s['number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback"><?= h($errores['season_id'] ?? '') ?></div>
                </div>

                <?php campo('N° Capitulo', 'number', 'number', $form, $errores, 'min="0" max="' . NUMERO_MAX . '" step="1"'); ?>
                <?php campo('Titulo', 'title', 'text', $form, $errores, 'maxlength="' . TITULO_MAX . '"'); ?>
                <?php campo('Duración (máx. 10:00:00)', 'duration', 'text', $form, $errores, 'maxlength="8" inputmode="numeric" autocomplete="off"'); ?>
                <?php // step="1" mantiene los segundos de la fecha al guardar ?>
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
                            input.setCustomValidity(msg);   // bloquea el envío si es inválido
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
</body>

</html>