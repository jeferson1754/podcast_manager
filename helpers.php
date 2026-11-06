<?php
// helpers.php — funciones y constantes compartidas por index, add, edit, etc.

const ESTADOS_PODCAST  = ['Activo', 'Inactivo', 'Finalizado'];
const DURACION_MAX_SEG = 36000;   // 10:00:00
const NUMERO_MAX       = 9999;
const TITULO_MAX       = 255;
const DIAS_SEMANA      = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

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

/** Imagen opcional: vacía (hereda la del canal), URL http(s) o data:image. */
function imagen_valida(string $v): bool
{
    return $v === '' || url_valida($v) || preg_match('#^data:image/[a-z+.-]+;base64,#i', $v) === 1;
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

/**
 * Valida y normaliza el horario fijo: "Martes:12:00,Jueves:18:30".
 * Devuelve '' si está vacío, el texto normalizado si es válido, o null si es inválido.
 */
function normalizar_schedule_custom(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    $tildes = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
               'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'];
    $out = [];
    foreach (explode(',', $v) as $bloque) {
        $partes = explode(':', trim($bloque), 2);   // "Martes" y "12:30" (sin perder los minutos)
        if (count($partes) !== 2) {
            return null;
        }
        $dia = ucfirst(strtolower(strtr(trim($partes[0]), $tildes)));
        if (!in_array($dia, DIAS_SEMANA, true)) {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):([0-5]\d)$/', trim($partes[1]), $m) || (int) $m[1] > 23) {
            return null;
        }
        $out[$dia . sprintf(':%02d:%02d', $m[1], $m[2])] = true;
    }
    return implode(',', array_keys($out));
}

/** Extrae el ID de 11 caracteres de un link de YouTube (o acepta el ID directo). */
function extraer_youtube_id(string $link): ?string
{
    $link = trim($link);
    if ($link === '') {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $link)) {
        return $link;
    }
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|live|shorts)\/|.*[?&]v=)|youtu\.be\/)([A-Za-z0-9_-]{11})/i', $link, $m)) {
        return $m[1];
    }
    return null;
}

/** Texto visible del estado (en BD se guarda 'Inactivo'). */
function estado_label(?string $estado): string
{
    return ($estado === 'Inactivo' || $estado === 'Pausado') ? 'Pausado' : (string) $estado;
}

function estado_clase(?string $estado): string
{
    if ($estado === 'Activo') {
        return 'state-active';
    }
    return ($estado === 'Inactivo' || $estado === 'Pausado') ? 'state-inactive' : 'state-finished';
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

/** ¿Existe la columna episodes.live_status (migración aplicada)? */
function tiene_live_status(mysqli $conn): bool
{
    static $r = null;
    if ($r === null) {
        $res = $conn->query("SHOW COLUMNS FROM episodes LIKE 'live_status'");
        $r = $res && $res->num_rows > 0;
    }
    return $r;
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
// ENRUTAMIENTO DE EPISODIOS ENTRE PODCASTS DE UN MISMO CANAL
// ------------------------------------------------------------------

/** Minúsculas, sin tildes ni símbolos, con espacios a los lados: " quantum am 3 ". */
function normalizar_texto_busqueda(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n',
    ]);
    $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    return ' ' . $s . ' ';
}

/**
 * De los podcasts de un canal, elige el que se nombra en el título del episodio.
 * Ej.: "... | Quantum AM #3" -> el podcast "Quantum AM".
 * Misma regla que detectar_podcast() del script de Python: si el título menciona a varios,
 * gana el que termina más al final (la etiqueta suele ir al final); a igual posición, el nombre más largo
 * (así "Quantum AM" le gana a "Quantum"). Cada fila de $podcasts necesita al menos: 'title'.
 * Devuelve la fila elegida o null si ninguno se nombra.
 */
function elegir_podcast_por_titulo(array $podcasts, string $tituloEpisodio): ?array
{
    $texto = normalizar_texto_busqueda($tituloEpisodio);
    $textoCompacto = str_replace(' ', '', $texto);
    $mejor = null;
    $mejorClave = [-1, -1];

    foreach ($podcasts as $p) {
        $nombre = normalizar_texto_busqueda((string) ($p['title'] ?? ''));
        $largo = mb_strlen(trim($nombre));
        if ($largo < 2) {
            continue;
        }
        // 1) palabra(s) completas: " quantum am " (última aparición)
        $pos = mb_strrpos($texto, $nombre);
        $fin = $pos === false ? false : $pos + mb_strlen($nombre);
        // 2) respaldo sin espacios ("QuantumAM"), solo para nombres de 6+ letras
        if ($pos === false) {
            $compacto = str_replace(' ', '', $nombre);
            if (mb_strlen($compacto) >= 6) {
                $pos = mb_strrpos($textoCompacto, $compacto);
                $fin = $pos === false ? false : $pos + mb_strlen($compacto);
            }
        }
        if ($fin === false) {
            continue;
        }
        $clave = [$fin, $largo];
        if ($clave > $mejorClave) {
            $mejor = $p;
            $mejorClave = $clave;
        }
    }
    return $mejor;
}

/** Número de capítulo escrito tras el nombre del podcast: "Quantum AM #3" -> 3. */
function numero_en_titulo(string $tituloEpisodio, string $tituloPodcast): ?int
{
    $nombre = trim($tituloPodcast);
    if ($nombre === '') {
        return null;
    }
    if (preg_match('/' . preg_quote($nombre, '/') . '\s*#\s*(\d{1,4})\b/iu', $tituloEpisodio, $m)) {
        $n = (int) $m[1];
        return ($n >= 0 && $n <= NUMERO_MAX) ? $n : null;
    }
    return null;
}

/**
 * Mueve un episodio al podcast destino: última temporada del destino (se crea la 1 si no hay),
 * número del título ("Nombre #N") si está libre o el siguiente de la temporada.
 * No abre transacción: quien la llame decide.
 */
function mover_episodio_a_podcast(mysqli $conn, int $episodioId, int $destinoPodcastId, string $tituloEpisodio, string $tituloDestino): bool
{
    $temp = consultar($conn, "SELECT id FROM seasons WHERE podcast_id = ? ORDER BY number DESC, id DESC LIMIT 1", 'i', [$destinoPodcastId]);
    if ($temp) {
        $seasonId = (int) $temp[0]['id'];
    } else {
        if (!ejecutar($conn, "INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')", 'i', [$destinoPodcastId])) {
            return false;
        }
        $seasonId = (int) $conn->insert_id;
    }

    $numero = numero_en_titulo($tituloEpisodio, $tituloDestino);
    if ($numero !== null && consultar($conn, "SELECT 1 FROM episodes WHERE season_id = ? AND number = ? AND id <> ? LIMIT 1", 'iii', [$seasonId, $numero, $episodioId])) {
        $numero = null;   // ya está ocupado en esa temporada
    }
    if ($numero === null) {
        $numero = 1 + (int) (consultar($conn, "SELECT COALESCE(MAX(number), 0) AS m FROM episodes WHERE season_id = ?", 'i', [$seasonId])[0]['m'] ?? 0);
    }

    return ejecutar($conn, "UPDATE episodes SET season_id = ?, podcast_id = ?, number = ? WHERE id = ?", 'iiii', [$seasonId, $destinoPodcastId, $numero, $episodioId]);
}