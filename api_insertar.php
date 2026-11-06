<?php
include('bd.php');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$host     = $servidor;
$db_user  = $usuario;
$db_pass  = $password;
$db_name  = $basededatos;

// Debe coincidir con TOKEN_SECRET del .env de Python
$tokenSecret = defined('API_TOKEN') ? API_TOKEN : 'MI_CLAVE_SUPER_SECRETA_123';   // se define en config.php

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Misma zona horaria que el resto de la app (NOW() = hora de Chile)
    if (isset($offset_mysql)) {
        $pdo->exec("SET time_zone = '" . $offset_mysql . "'");
    }
} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]));
}

// Datos JSON enviados por Selenium (fetch) + soporte POST/GET tradicional
$inputData = json_decode(file_get_contents('php://input'), true) ?? [];

/** Lee un parámetro desde JSON, POST o GET (en ese orden). */
function param(string $key, $default = '')
{
    global $inputData;
    return $inputData[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
}

function responder(array $data): void
{
    echo json_encode($data);
    exit;
}

// 1. Validar token
if (!hash_equals($tokenSecret, (string) param('token'))) {
    http_response_code(403);
    responder(['status' => 'error', 'message' => 'Acceso no autorizado']);
}

// 2. Acción
$action = param('action');

try {

    // ------------------------------------------------------------------
    // Buscar o crear podcast (por nombre de canal)
    // ------------------------------------------------------------------
    if ($action === 'obtener_o_crear_podcast') {
        $nombreCanal = trim(param('nombre'));
        $channelUrl  = trim(param('channel_url'));
        $imageUrl    = trim(param('image_url'));

        $stmt = $pdo->prepare("
            SELECT p.id AS podcast_id
            FROM channels c
            JOIN podcasts p ON p.channel_id = c.id
            WHERE LOWER(c.name) = ?
        ");
        $stmt->execute([mb_strtolower($nombreCanal, 'UTF-8')]);
        $resultado = $stmt->fetch();

        if ($resultado) {
            responder(['status' => 'ok', 'podcast_id' => $resultado['podcast_id']]);
        }

        $pdo->prepare("INSERT INTO channels (name, created_at, image) VALUES (?, NOW(), ?)")
            ->execute([$nombreCanal, $imageUrl]);
        $canalId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO podcasts (channel_id, title, description, image) VALUES (?, ?, ?, ?)")
            ->execute([$canalId, $nombreCanal, $channelUrl, $imageUrl]);
        $podcastId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')")
            ->execute([$podcastId]);

        responder(['status' => 'created', 'podcast_id' => $podcastId]);
    }

    // ------------------------------------------------------------------
    // Obtener todos los episodios (incluye publish_date, status, duration)
    // ------------------------------------------------------------------
    if ($action === 'obtener_todos_episodios') {
        // Cada episodio con su podcast ACTUAL (el de su temporada), canal y youtube_channel_id
        $episodios = $pdo->query("
            SELECT e.*,
                   p.id   AS podcast_id_actual,
                   p.title AS podcast_title,
                   c.name AS channel_name,
                   c.youtube_channel_id AS channel_youtube_id
            FROM episodes e
            LEFT JOIN seasons  s ON s.id = e.season_id
            LEFT JOIN podcasts p ON p.id = COALESCE(s.podcast_id, e.podcast_id)
            LEFT JOIN channels c ON c.id = p.channel_id
        ")->fetchAll();
        responder(['status' => 'success', 'episodios' => $episodios]);
    }

    // ------------------------------------------------------------------
    // Actualizar estado (PENDING / PUBLISHED) y completed_at
    // ------------------------------------------------------------------
    if ($action === 'actualizar_estado') {
        $ytId        = param('youtube_id');
        $nuevoStatus = strtoupper((string) param('status', 'PENDING'));

        if ($nuevoStatus === 'PUBLISHED') {
            $stmt = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NOW() WHERE youtube_id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NULL WHERE youtube_id = ?");
        }
        $stmt->execute([$nuevoStatus, $ytId]);

        responder(['status' => 'updated', 'message' => 'Estado actualizado exitosamente']);
    }

    // ------------------------------------------------------------------
    // Actualizar duración (HH:MM:SS)
    // ------------------------------------------------------------------
    if ($action === 'actualizar_duracion') {
        $ytId     = param('youtube_id');
        $duracion = param('duration');

        if ($ytId === '' || $duracion === '') {
            responder(['status' => 'error', 'message' => 'Faltan datos', 'youtube_id' => $ytId]);
        }

        $stmt = $pdo->prepare("UPDATE episodes SET duration = ? WHERE youtube_id = ?");
        $stmt->execute([$duracion, $ytId]);

        responder(['status' => 'success', 'message' => 'Duración actualizada']);
    }

    // ------------------------------------------------------------------
    // Actualizar fecha de publicación (hora de inicio del directo)
    // ------------------------------------------------------------------
    if ($action === 'actualizar_fecha') {
        $ytId        = param('youtube_id');
        $publishDate = param('publish_date');

        if ($ytId === '' || $publishDate === '') {
            responder([
                'status' => 'error',
                'message' => 'Faltan datos',
                'recibido_id' => $ytId,
                'recibido_fecha' => $publishDate,
            ]);
        }

        $stmt = $pdo->prepare("UPDATE episodes SET publish_date = ? WHERE youtube_id = ?");
        $stmt->execute([$publishDate, $ytId]);

        // rowCount() = 0 si la fecha ya era igual; no es un error
        responder(['status' => 'success', 'message' => 'Actualizado correctamente', 'filas' => $stmt->rowCount()]);
    }

    // ------------------------------------------------------------------
    // Guardar episodio (inserta o actualiza) con autodetección de podcast
    // ------------------------------------------------------------------
    if ($action === 'guardar_episodio') {
        $ytId         = param('youtube_id');
        $titulo       = param('title');
        $duracion     = param('duration', '00:00:00');
        $status       = strtoupper((string) param('status', 'PUBLISHED'));
        $publishDate  = param('publish_date', date('Y-m-d H:i:s'));
        $channelId    = param('channel_id');
        $channelName  = param('channel_name', 'Canal Desconocido');
        $channelImage = param('channel_image');
        $liveStatus   = param('live_status', 'none');
        $liveStatus   = in_array($liveStatus, ['none', 'upcoming', 'live'], true) ? $liveStatus : 'none';

        if ($ytId === '') {
            responder(['status' => 'error', 'message' => 'Falta youtube_id']);
        }

        $podcastId = null;
        $canalDbId = null;
        $nombreNorm = mb_strtolower(trim($channelName), 'UTF-8');

        // 1. Podcasts vinculados al canal (por youtube_channel_id y, como respaldo, por nombre).
        //    Si el canal tiene varios (p. ej. "Quantum AM" y "Quantum FM"), se elige el que se
        //    nombra en el título del episodio. Si ninguno se nombra, se usa el primero 'Activo'.
        $sqlPodcasts = "
            SELECT p.id AS podcast_id, p.title, p.state, c.id AS canal_id
            FROM channels c
            JOIN podcasts p ON p.channel_id = c.id
            WHERE %s
            ORDER BY (p.state = 'Activo') DESC, p.id ASC
        ";

        $podcastsCanal = [];
        if ($channelId !== '') {
            $st = $pdo->prepare(sprintf($sqlPodcasts, 'c.youtube_channel_id = ?'));
            $st->execute([$channelId]);
            $podcastsCanal = $st->fetchAll();
        }
        if (!$podcastsCanal && $nombreNorm !== '') {
            $st = $pdo->prepare(sprintf($sqlPodcasts, 'LOWER(c.name) = ?'));
            $st->execute([$nombreNorm]);
            $podcastsCanal = $st->fetchAll();
        }
        if ($podcastsCanal) {
            $canalDbId = $podcastsCanal[0]['canal_id'];
            $elegido   = null;

            // 1) Python ya decidió el podcast (lee la BD y entiende alias): se respeta si existe en este canal
            $podcastNombre = mb_strtolower(trim((string) param('podcast_name', '')), 'UTF-8');
            if ($podcastNombre !== '') {
                foreach ($podcastsCanal as $pc) {
                    if (mb_strtolower(trim($pc['title']), 'UTF-8') === $podcastNombre) {
                        $elegido = $pc;
                        break;
                    }
                }
            }
            // 2) Respaldo: elegir por el título del episodio (si el canal tiene varios podcasts)
            if (!$elegido && count($podcastsCanal) > 1) {
                $elegido = elegir_podcast_por_titulo($podcastsCanal, $titulo);
            }
            $podcastId = ($elegido ?? $podcastsCanal[0])['podcast_id'];
        }

        // Completar datos del canal que falten (ID de YouTube e imagen)
        if ($canalDbId) {
            $pdo->prepare("
                UPDATE channels
                SET youtube_channel_id = COALESCE(NULLIF(youtube_channel_id, ''), NULLIF(?, '')),
                    image = COALESCE(NULLIF(image, ''), NULLIF(?, ''))
                WHERE id = ?
            ")->execute([$channelId, $channelImage, $canalDbId]);
        }

        // 2. Fallback: podcast_id explícito
        if (!$podcastId) {
            $podcastId = param('podcast_id', null) ?: null;
        }

        // 3. Crear lo que falte: canal (si no existe) + podcast + temporada 1
        if (!$podcastId && ($channelId !== '' || $nombreNorm !== '')) {
            $nombreCanal = $channelName !== '' ? $channelName : 'Podcast Nuevo de YouTube';

            // ¿Existe el canal pero sin podcast? Se reutiliza en vez de duplicarlo.
            $canalDbId = null;
            if ($channelId !== '') {
                $st = $pdo->prepare("SELECT id FROM channels WHERE youtube_channel_id = ? ORDER BY id ASC LIMIT 1");
                $st->execute([$channelId]);
                $canalDbId = $st->fetch()['id'] ?? null;
            }
            if (!$canalDbId && $nombreNorm !== '') {
                $st = $pdo->prepare("SELECT id FROM channels WHERE LOWER(name) = ? ORDER BY id ASC LIMIT 1");
                $st->execute([$nombreNorm]);
                $canalDbId = $st->fetch()['id'] ?? null;
            }

            if ($canalDbId) {
                $pdo->prepare("
                    UPDATE channels
                    SET youtube_channel_id = COALESCE(NULLIF(youtube_channel_id, ''), NULLIF(?, '')),
                        image = COALESCE(NULLIF(image, ''), NULLIF(?, ''))
                    WHERE id = ?
                ")->execute([$channelId, $channelImage, $canalDbId]);
            } else {
                $pdo->prepare("INSERT INTO channels (name, youtube_channel_id, created_at, image) VALUES (?, ?, NOW(), ?)")
                    ->execute([$nombreCanal, $channelId !== '' ? $channelId : null, $channelImage]);
                $canalDbId = $pdo->lastInsertId();
            }

            $urlCanal = $channelId !== '' ? 'https://www.youtube.com/channel/' . $channelId : '';
            $pdo->prepare("INSERT INTO podcasts (channel_id, title, description, image) VALUES (?, ?, ?, ?)")
                ->execute([$canalDbId, $nombreCanal, $urlCanal, $channelImage]);
            $podcastId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')")
                ->execute([$podcastId]);
        }

        if (!$podcastId) {
            responder(['status' => 'error', 'message' => 'No se pudo asociar ni crear el podcast para este video']);
        }

        // 4. ¿Ya existe el episodio?
        $stmtCheck = $pdo->prepare("SELECT id, status FROM episodes WHERE youtube_id = ?");
        $stmtCheck->execute([$ytId]);
        $existente = $stmtCheck->fetch();

        if ($existente) {
            if ($status === 'PUBLISHED') {
                $stmtUpd = $pdo->prepare("
                    UPDATE episodes
                    SET title = ?, duration = ?, status = ?, completed_at = COALESCE(completed_at, NOW())
                    WHERE id = ?
                ");
            } else {
                $stmtUpd = $pdo->prepare("
                    UPDATE episodes
                    SET title = ?, duration = ?, status = ?, completed_at = NULL
                    WHERE id = ?
                ");
            }
            $stmtUpd->execute([$titulo, $duracion, $status, $existente['id']]);

            if (tiene_live_status($conn)) {
                $pdo->prepare("UPDATE episodes SET live_status = ? WHERE id = ?")->execute([$liveStatus, $existente['id']]);
            }

            responder(['status' => 'updated', 'message' => 'Episodio sincronizado correctamente']);
        }

        // 5. Última temporada del podcast (o crearla)
        $stmtSeason = $pdo->prepare("SELECT id FROM seasons WHERE podcast_id = ? ORDER BY number DESC, id DESC LIMIT 1");
        $stmtSeason->execute([$podcastId]);
        $season   = $stmtSeason->fetch();
        $seasonId = $season['id'] ?? null;

        if (!$seasonId) {
            $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')")
                ->execute([$podcastId]);
            $seasonId = $pdo->lastInsertId();
        }

        // 6. Siguiente número de episodio
        $stmtNum = $pdo->prepare("SELECT MAX(number) AS max_num FROM episodes WHERE season_id = ?");
        $stmtNum->execute([$seasonId]);
        $numEpisodio = ((int) ($stmtNum->fetch()['max_num'] ?? 0)) + 1;

        // Si el título trae "Nombre del podcast #N", se usa ese N (siempre que esté libre en la temporada)
        $stPod = $pdo->prepare("SELECT title FROM podcasts WHERE id = ?");
        $stPod->execute([$podcastId]);
        $nTitulo = numero_en_titulo($titulo, (string) ($stPod->fetch()['title'] ?? ''));
        if ($nTitulo !== null) {
            $stLibre = $pdo->prepare("SELECT 1 FROM episodes WHERE season_id = ? AND number = ? LIMIT 1");
            $stLibre->execute([$seasonId, $nTitulo]);
            if (!$stLibre->fetch()) {
                $numEpisodio = $nTitulo;
            }
        }

        // 7. Insertar
        $completedAt = ($status === 'PUBLISHED') ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("
            INSERT INTO episodes (youtube_id, podcast_id, season_id, number, title, duration, status, publish_date, detected_at, completed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ")->execute([$ytId, $podcastId, $seasonId, $numEpisodio, $titulo, $duracion, $status, $publishDate, $completedAt]);

        if (tiene_live_status($conn)) {
            $pdo->prepare("UPDATE episodes SET live_status = ? WHERE youtube_id = ?")->execute([$liveStatus, $ytId]);
        }

        responder(['status' => 'inserted', 'message' => 'Episodio insertado exitosamente']);
    }

    // ------------------------------------------------------------------
    // Episodios pendientes (compatibilidad)
    // ------------------------------------------------------------------
    if ($action === 'obtener_episodios_pendientes') {
        $episodios = $pdo->query("SELECT id, youtube_id FROM episodes WHERE status = 'PENDING'")->fetchAll();
        responder(['status' => 'success', 'episodios' => $episodios]);
    }

    // ------------------------------------------------------------------
    // Actualizar título y duración de un pendiente (compatibilidad)
    // ------------------------------------------------------------------
    if ($action === 'actualizar_pendientes_youtube') {
        $ytId     = param('youtube_id');
        $titulo   = param('title');
        $duracion = param('duration', '00:00:00');

        if ($ytId === '' || $titulo === '') {
            responder(['status' => 'error', 'message' => 'Faltan datos obligatorios']);
        }

        $pdo->prepare("UPDATE episodes SET title = ?, duration = ? WHERE youtube_id = ?")
            ->execute([$titulo, $duracion, $ytId]);

        responder(['status' => 'success', 'message' => 'Título y duración actualizados']);
    }

    // ------------------------------------------------------------------
    // Registrar fin de sincronización
    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    // Lista de podcasts con su canal (para detectar canales con varios podcasts y revisar carátulas)
    // ------------------------------------------------------------------
    if ($action === 'obtener_todos_podcasts') {
        $podcasts = $pdo->query("
            SELECT p.id, p.title, p.state, p.image, p.description AS link,
                   p.channel_id AS channel_db_id,
                   c.youtube_channel_id, c.name AS channel_name
            FROM podcasts p
            LEFT JOIN channels c ON c.id = p.channel_id
            ORDER BY p.id
        ")->fetchAll();
        responder(['status' => 'ok', 'podcasts' => $podcasts]);
    }

    // ------------------------------------------------------------------
    // Mover un episodio a otro podcast DEL MISMO CANAL, por nombre del podcast
    // ------------------------------------------------------------------
    if ($action === 'actualizar_podcast') {
        $ytId   = trim((string) param('youtube_id'));
        $nombre = trim((string) param('podcast_name'));
        if ($ytId === '' || $nombre === '') {
            responder(['status' => 'error', 'message' => 'Faltan youtube_id o podcast_name']);
        }

        $ep = consultar($conn, "
            SELECT e.id, e.title, COALESCE(s.podcast_id, e.podcast_id) AS podcast_id, p.channel_id
            FROM episodes e
            LEFT JOIN seasons  s ON s.id = e.season_id
            LEFT JOIN podcasts p ON p.id = COALESCE(s.podcast_id, e.podcast_id)
            WHERE e.youtube_id = ? LIMIT 1", 's', [$ytId]);
        if (!$ep) {
            responder(['status' => 'error', 'message' => 'Episodio no encontrado']);
        }
        $ep = $ep[0];
        if (empty($ep['channel_id'])) {
            responder(['status' => 'error', 'message' => 'El podcast actual no tiene canal asignado']);
        }

        // El destino debe pertenecer al mismo canal (evita mover episodios entre canales distintos)
        $dest = consultar($conn, "SELECT id, title FROM podcasts WHERE channel_id = ? AND TRIM(title) = ? LIMIT 1", 'is', [(int) $ep['channel_id'], $nombre]);
        if (!$dest) {
            responder(['status' => 'error', 'message' => 'El podcast destino no existe en el mismo canal']);
        }
        $dest = $dest[0];
        if ((int) $dest['id'] === (int) $ep['podcast_id']) {
            responder(['status' => 'success', 'message' => 'Sin cambios: ya está en ese podcast']);
        }

        $conn->begin_transaction();
        $ok = mover_episodio_a_podcast($conn, (int) $ep['id'], (int) $dest['id'], (string) $ep['title'], (string) $dest['title']);
        $ok ? $conn->commit() : $conn->rollback();

        responder($ok
            ? ['status' => 'success', 'message' => 'Episodio movido a ' . $dest['title']]
            : ['status' => 'error', 'message' => 'No se pudo mover el episodio']);
    }

    // ------------------------------------------------------------------
    // Actualizar la carátula de un podcast
    // ------------------------------------------------------------------
    if ($action === 'actualizar_imagen_podcast') {
        $podcastId = filter_var(param('podcast_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $imagen    = trim((string) param('image'));
        if ($podcastId === false || !url_valida($imagen)) {
            responder(['status' => 'error', 'message' => 'podcast_id o image inválidos']);
        }
        $pdo->prepare("UPDATE podcasts SET image = ? WHERE id = ?")->execute([$imagen, $podcastId]);
        responder(['status' => 'success', 'message' => 'Carátula actualizada']);
    }

    // ------------------------------------------------------------------
    // Estado de directo: none | upcoming | live
    // ------------------------------------------------------------------
    if ($action === 'actualizar_live_status') {
        $ytId = param('youtube_id');
        $live = param('live_status', 'none');
        if ($ytId === '' || !in_array($live, ['none', 'upcoming', 'live'], true)) {
            responder(['status' => 'error', 'message' => 'Datos inválidos']);
        }
        if (!tiene_live_status($conn)) {
            responder(['status' => 'error', 'message' => 'Falta la columna live_status (ejecuta migracion.sql)']);
        }
        $pdo->prepare("UPDATE episodes SET live_status = ? WHERE youtube_id = ?")->execute([$live, $ytId]);
        responder(['status' => 'success', 'message' => 'Estado de directo actualizado']);
    }

    // ------------------------------------------------------------------
    // Recalcular horarios del calendario (se llama al final de cada sincronización)
    // ------------------------------------------------------------------
    if ($action === 'recalcular_horarios') {
        require_once __DIR__ . '/horarios.php';
        $n = recalcular_horarios($conn);
        responder(['status' => 'success', 'message' => "Horarios recalculados ($n podcasts)"]);
    }

    if ($action === 'registrar_fin_sincronizacion') {
        $pdo->prepare("REPLACE INTO site_settings (setting_key, setting_value) VALUES ('last_sync', ?)")
            ->execute([date('Y-m-d H:i:s')]);

        responder(['status' => 'success', 'message' => 'Última sincronización registrada']);
    }

    responder(['status' => 'error', 'message' => 'Acción no válida']);

} catch (Throwable $e) {
    http_response_code(500);
    responder(['status' => 'error', 'message' => 'Error en acción ' . $action . ': ' . $e->getMessage()]);
}