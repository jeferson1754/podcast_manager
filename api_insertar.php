<?php
include('bd.php');

header('Content-Type: application/json; charset=utf-8');

$host     = $servidor;
$db_user  = $usuario;
$db_pass  = $password;
$db_name  = $basededatos;

// Debe coincidir con TOKEN_SECRET del .env de Python
$tokenSecret = 'MI_CLAVE_SUPER_SECRETA_123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
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

        // Si el canal tiene varios podcasts, se puede indicar cuál con 'podcast_name'.
        $podcastNorm = mb_strtolower(trim((string) param('podcast_name', '')), 'UTF-8');

        $stmt = $pdo->prepare("
            SELECT p.id AS podcast_id
            FROM channels c
            JOIN podcasts p ON p.channel_id = c.id
            WHERE LOWER(c.name) = ?
            ORDER BY (LOWER(p.title) = ?) DESC, (p.state = 'Activo') DESC, p.id ASC
            LIMIT 1
        ");
        $stmt->execute([mb_strtolower($nombreCanal, 'UTF-8'), $podcastNorm]);
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
    // + título del podcast actual y youtube_channel_id del canal
    // ------------------------------------------------------------------
    if ($action === 'obtener_todos_episodios') {
        $episodios = $pdo->query("
            SELECT e.*,
                   p.title              AS podcast_title,
                   c.youtube_channel_id AS channel_youtube_id,
                   c.name               AS channel_name
            FROM episodes e
            LEFT JOIN podcasts p ON p.id = e.podcast_id
            LEFT JOIN channels c ON c.id = p.channel_id
            ORDER BY e.id
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
    // Listado de podcasts (estaba duplicado; ahora hay un solo bloque)
    // ------------------------------------------------------------------
    if ($action === 'obtener_todos_podcasts') {
        $stmt = $pdo->prepare("
            SELECT p.id, p.title, p.description AS link, p.image, p.state,
                   p.channel_id AS channel_db_id,
                   c.name AS channel_name,
                   c.youtube_channel_id
            FROM podcasts p
            LEFT JOIN channels c ON p.channel_id = c.id
        ");
        $stmt->execute();
        $podcasts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responder(['status' => 'ok', 'podcasts' => $podcasts]);
    }

    // ------------------------------------------------------------------
    // Actualizar imagen de un podcast (antes usaba $conn/mysqli, que este
    // archivo no define; ahora usa PDO como el resto)
    // ------------------------------------------------------------------
    if ($action === 'actualizar_imagen_podcast') {
        $podcast_id   = (int) param('podcast_id');
        $nueva_imagen = trim((string) param('image'));

        $stmt = $pdo->prepare("UPDATE podcasts SET image = ? WHERE id = ?");
        $stmt->execute([$nueva_imagen, $podcast_id]);

        responder(['status' => 'success', 'success' => true]);
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
    // Mover un episodio a otro podcast DEL MISMO CANAL (por nombre de podcast).
    // Reasigna también season_id y number dentro del podcast destino.
    // ------------------------------------------------------------------
    if ($action === 'actualizar_podcast') {
        $ytId   = param('youtube_id');
        $nombre = mb_strtolower(trim((string) param('podcast_name')), 'UTF-8');

        if ($ytId === '' || $nombre === '') {
            responder(['status' => 'error', 'message' => 'Faltan datos']);
        }

        $st = $pdo->prepare("
            SELECT e.id, e.podcast_id, p.channel_id
            FROM episodes e
            JOIN podcasts p ON p.id = e.podcast_id
            WHERE e.youtube_id = ?
        ");
        $st->execute([$ytId]);
        $ep = $st->fetch();
        if (!$ep) {
            responder(['status' => 'error', 'message' => 'Episodio no encontrado']);
        }

        // Solo se puede mover entre podcasts del mismo canal
        $st = $pdo->prepare("
            SELECT id FROM podcasts
            WHERE channel_id = ? AND LOWER(title) = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $st->execute([$ep['channel_id'], $nombre]);
        $destino = $st->fetch();
        if (!$destino) {
            responder(['status' => 'error', 'message' => 'No existe ese podcast en el canal del episodio']);
        }

        if ((int) $destino['id'] === (int) $ep['podcast_id']) {
            responder(['status' => 'success', 'message' => 'Ya estaba en ese podcast', 'filas' => 0]);
        }

        $pdo->beginTransaction();
        try {
            // Última temporada del podcast destino (o crearla)
            $st = $pdo->prepare("SELECT id FROM seasons WHERE podcast_id = ? ORDER BY number DESC, id DESC LIMIT 1");
            $st->execute([$destino['id']]);
            $seasonId = $st->fetch()['id'] ?? null;

            if (!$seasonId) {
                $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')")
                    ->execute([$destino['id']]);
                $seasonId = $pdo->lastInsertId();
            }

            // Siguiente número de episodio en esa temporada
            $st = $pdo->prepare("SELECT MAX(number) AS max_num FROM episodes WHERE season_id = ?");
            $st->execute([$seasonId]);
            $numero = ((int) ($st->fetch()['max_num'] ?? 0)) + 1;

            $pdo->prepare("UPDATE episodes SET podcast_id = ?, season_id = ?, number = ? WHERE id = ?")
                ->execute([$destino['id'], $seasonId, $numero, $ep['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        responder(['status' => 'success', 'message' => 'Podcast actualizado', 'filas' => 1]);
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

        if ($ytId === '') {
            responder(['status' => 'error', 'message' => 'Falta youtube_id']);
        }

        $podcastId = null;
        $canalDbId = null;
        $nombreNorm = mb_strtolower(trim($channelName), 'UTF-8');

        // Nombre del podcast detectado por Python a partir del título
        // (p. ej. "Quantum AM"). Vacío = canal con un solo podcast.
        $podcastNorm = mb_strtolower(trim((string) param('podcast_name', '')), 'UTF-8');

        // 1. Podcast ya vinculado al canal, buscando por youtube_channel_id
        //    y como respaldo por nombre. Si hay varios podcasts en el mismo
        //    canal, se prefiere el que coincide con podcast_name, luego el
        //    'Activo' y luego el más antiguo.
        $sqlPodcast = "
            SELECT p.id AS podcast_id, c.id AS canal_id
            FROM channels c
            JOIN podcasts p ON p.channel_id = c.id
            WHERE %s
            ORDER BY (LOWER(p.title) = ?) DESC, (p.state = 'Activo') DESC, p.id ASC
            LIMIT 1
        ";

        if ($channelId !== '') {
            $st = $pdo->prepare(sprintf($sqlPodcast, 'c.youtube_channel_id = ?'));
            $st->execute([$channelId, $podcastNorm]);
            if ($row = $st->fetch()) {
                $podcastId = $row['podcast_id'];
                $canalDbId = $row['canal_id'];
            }
        }
        if (!$podcastId && $nombreNorm !== '') {
            $st = $pdo->prepare(sprintf($sqlPodcast, 'LOWER(c.name) = ?'));
            $st->execute([$nombreNorm, $podcastNorm]);
            if ($row = $st->fetch()) {
                $podcastId = $row['podcast_id'];
                $canalDbId = $row['canal_id'];
            }
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

        // 7. Insertar
        $completedAt = ($status === 'PUBLISHED') ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("
            INSERT INTO episodes (youtube_id, podcast_id, season_id, number, title, duration, status, publish_date, detected_at, completed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ")->execute([$ytId, $podcastId, $seasonId, $numEpisodio, $titulo, $duracion, $status, $publishDate, $completedAt]);

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
