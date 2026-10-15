<?php
include('bd.php');

$host     = $servidor;
$db_user  = $usuario;
$db_pass  = $password;
$db_name  = $basededatos;
// Clave secreta para que nadie externo pueda insertar datos en tu BD
$tokenSecret = 'MI_CLAVE_SUPER_SECRETA_123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]));
}

// Leer los datos JSON que envía Selenium mediante fetch (además de permitir POST/GET tradicionales)
$inputData = json_decode(file_get_contents('php://input'), true) ?? [];

// 1. Validar Token de Seguridad
$token = $_POST['token'] ?? $_GET['token'] ?? $inputData['token'] ?? '';
if ($token !== $tokenSecret) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Acceso no autorizado']));
}

// 2. Leer Acción (buscando primero en el JSON recibido, luego en POST o GET)
$action = $inputData['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// ACCIÓN A: Buscar o Crear Podcast
if ($action === 'obtener_o_crear_podcast') {
    $nombreCanal = trim($inputData['nombre'] ?? $_POST['nombre'] ?? '');
    $channelUrl  = trim($inputData['channel_url'] ?? $_POST['channel_url'] ?? '');
    $imageUrl    = trim($inputData['image_url'] ?? $_POST['image_url'] ?? '');

    $nombreLimpio = mb_strtolower($nombreCanal, 'UTF-8');

    // 1. Buscar si ya existe el podcast vinculado a ese canal por nombre
    $stmt = $pdo->prepare("
        SELECT p.id as podcast_id 
        FROM channels c 
        JOIN podcasts p ON p.channel_id = c.id 
        WHERE LOWER(c.name) = ?
    ");
    $stmt->execute([$nombreLimpio]);
    $resultado = $stmt->fetch();

    if ($resultado) {
        echo json_encode(['status' => 'ok', 'podcast_id' => $resultado['podcast_id']]);
        exit;
    }

    // 2. Si no existe, crear el Canal primero
    $stmtInsert = $pdo->prepare("INSERT INTO channels (name, created_at, image) VALUES (?, NOW(), ?)");
    $stmtInsert->execute([$nombreCanal, $imageUrl]);
    $id_chanel = $pdo->lastInsertId();

    // 3. Crear el Podcast asociado al canal (con sus 4 placeholders correctos)
    $stmtPodcast = $pdo->prepare("INSERT INTO podcasts (channel_id, title, description, image) VALUES (?, ?, ?, ?)");
    $stmtPodcast->execute([$id_chanel, $nombreCanal, $channelUrl, $imageUrl]);
    $podcastId = $pdo->lastInsertId();

    // 4. Crear la Temporada 1 por defecto para ese podcast
    $stmtSeason = $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')");
    $stmtSeason->execute([$podcastId]);

    echo json_encode(['status' => 'created', 'podcast_id' => $podcastId]);
    exit;
}

// ACCIÓN C: Obtener todos los episodios del podcast (para la sincronización de la playlist)
if ($action === 'obtener_todos_episodios') {
    $stmt = $pdo->query("SELECT youtube_id, status, duration, title FROM episodes");
    $episodios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'episodios' => $episodios]);
    exit;
}
// ACCIÓN D: Actualizar el estado del episodio (PENDING o PUBLISHED) y registrar fecha de completado
if ($action === 'actualizar_estado') {
    $ytId        = $inputData['youtube_id'] ?? $_POST['youtube_id'] ?? '';
    $nuevoStatus = $inputData['status'] ?? $_POST['status'] ?? 'PUBLISHED';

    if ($nuevoStatus === 'PUBLISHED') {
        $stmt = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NOW() WHERE youtube_id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NULL WHERE youtube_id = ?");
    }
    $stmt->execute([$nuevoStatus, $ytId]);

    echo json_encode(['status' => 'updated', 'message' => 'Estado actualizado exitosamente']);
    exit;
}

// ACCIÓN: Actualizar la duración del episodio
// ACCIÓN: Actualizar el estado del episodio (PENDING o PUBLISHED)
if ($action === 'actualizar_estado') {
    $ytId        = $inputData['youtube_id'] ?? $_POST['youtube_id'] ?? '';
    $nuevoStatus = $inputData['status'] ?? $_POST['status'] ?? 'PENDING';

    // Si el nuevo estado es PUBLISHED, actualizamos el estado y forzamos la fecha de completado
    if (strtoupper($nuevoStatus) === 'PUBLISHED') {
        $stmt = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NOW() WHERE youtube_id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NULL WHERE youtube_id = ?");
    }
    $stmt->execute([$nuevoStatus, $ytId]);

    echo json_encode(['status' => 'updated', 'message' => 'Estado y fecha actualizados exitosamente']);
    exit;
}
// ACCIÓN B: Insertar o Actualizar Episodio con autodetección de Podcast
if ($action === 'guardar_episodio') {
    $ytId         = $inputData['youtube_id'] ?? $_POST['youtube_id'] ?? '';
    $titulo       = $inputData['title'] ?? $_POST['title'] ?? '';
    $duracion     = $inputData['duration'] ?? $_POST['duration'] ?? '00:00:00';
    $status       = $inputData['status'] ?? $_POST['status'] ?? 'PUBLISHED';
    $publishDate  = $inputData['publish_date'] ?? $_POST['publish_date'] ?? date('Y-m-d H:i:s');
    $channelId    = $inputData['channel_id'] ?? $_POST['channel_id'] ?? '';
    $channelName  = $inputData['channel_name'] ?? $_POST['channel_name'] ?? 'Canal Desconocido';
    $channelImage = $inputData['channel_image'] ?? $_POST['channel_image'] ?? ''; // <-- Recibiendo la imagen del canal

    $podcastId = null;

    // 1. Intentar buscar el podcast en la base de datos usando el channel_id o nombre
    if (!empty($channelId)) {
        $stmtPod = $pdo->prepare("
            SELECT p.id as podcast_id 
            FROM channels c 
            JOIN podcasts p ON p.channel_id = c.id 
            WHERE c.id = ? OR LOWER(c.name) LIKE ?
        ");
        // Buscamos tanto por ID exacto de canal como por coincidencia en el nombre
        $stmtPod->execute([$channelId, '%' . mb_strtolower($channelName, 'UTF-8') . '%']);
        $podcast = $stmtPod->fetch();

        if ($podcast) {
            $podcastId = $podcast['podcast_id'];
        }
    }

    // Fallback: Si no lo encontró por channel_id pero mandaron un podcast_id explícito
    if (!$podcastId) {
        $podcastId = $inputData['podcast_id'] ?? $_POST['podcast_id'] ?? null;
    }

    // 🚀 NUEVO: Si aún no existe el podcast pero tenemos el nombre y el ID del canal, ¡lo creamos al vuelo!
    if (!$podcastId && !empty($channelId)) {
        $nombreCanalToInsert = !empty($channelName) ? $channelName : 'Podcast Nuevo de YouTube';
        $urlCanalPorDefecto = "https://www.youtube.com/channel/" . $channelId;

        // Corregido NOW() con paréntesis
        $stmtInsertChan = $pdo->prepare("INSERT INTO channels (name, created_at, image) VALUES (?, NOW(), ?)");
        $stmtInsertChan->execute([$nombreCanalToInsert, $channelImage]);
        $canalId = $pdo->lastInsertId(); // O $pdo->lastInsertId() si tu id de canal es autoincremental

        $stmtInsertPod = $pdo->prepare("INSERT INTO podcasts (channel_id, title, description) VALUES (?, ?, ?)");
        $stmtInsertPod->execute([$canalId, $nombreCanalToInsert, $urlCanalPorDefecto]);

        // Recuperamos el ID recién creado del podcast
        $podcastId = $pdo->lastInsertId();

        $stmtSeason = $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')");
        $stmtSeason->execute([$podcastId]);
    }
    // Si de plano no hay canal ni ID y no se pudo crear
    if (!$podcastId) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo asociar ni crear el podcast para este video']);
        exit;
    }

    // Definir si se debe registrar completed_at basado en el estado
    $completedAtValue = ($status === 'PUBLISHED') ? date('Y-m-d H:i:s') : null;

    // 2. Verificar si el episodio ya existe
    $stmtCheck = $pdo->prepare("SELECT id, status FROM episodes WHERE youtube_id = ?");
    $stmtCheck->execute([$ytId]);
    $existente = $stmtCheck->fetch();

    if ($existente) {
        // Actualizar si antes estaba LIVE y ahora terminó, o si cambia a PUBLISHED
        if ($existente['status'] === 'LIVE' && $status === 'PUBLISHED') {
            $stmtUpdate = $pdo->prepare("UPDATE episodes SET duration = ?, status = 'PUBLISHED', completed_at = NOW(), title = ? WHERE id = ?");
            $stmtUpdate->execute([$duracion, $titulo, $existente['id']]);
            echo json_encode(['status' => 'updated', 'message' => 'Directo finalizado y actualizado']);
        } else {
            // Actualizar el estado y sincronizar completed_at si pasa a PUBLISHED
            if ($status === 'PUBLISHED') {
                $stmtUpdate = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?");
                $stmtUpdate->execute([$status, $existente['id']]);
            } else {
                $stmtUpdate = $pdo->prepare("UPDATE episodes SET status = ?, completed_at = NULL WHERE id = ?");
                $stmtUpdate->execute([$status, $existente['id']]);
            }
            echo json_encode(['status' => 'exists', 'message' => 'Ya registrado y estado sincronizado']);
        }
        exit;
    }

    // 3. Obtener la última temporada del podcast
    $stmtSeason = $pdo->prepare("SELECT id FROM seasons WHERE podcast_id = ? ORDER BY number DESC LIMIT 1");
    $stmtSeason->execute([$podcastId]);
    $season = $stmtSeason->fetch();
    $seasonId = $season['id'] ?? 1;

    // 4. Obtener el siguiente número de episodio
    $stmtNum = $pdo->prepare("SELECT MAX(number) as max_num FROM episodes WHERE season_id = ?");
    $stmtNum->execute([$seasonId]);
    $rowNum = $stmtNum->fetch();
    $numEpisodio = ($rowNum['max_num'] ?? 0) + 1;

    // 5. Insertar nuevo episodio asociado a su podcast correcto
    $stmtInsert = $pdo->prepare("
        INSERT INTO episodes (youtube_id, podcast_id, season_id, number, title, duration, status, publish_date, completed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsert->execute([$ytId, $podcastId, $seasonId, $numEpisodio, $titulo, $duracion, $status, $publishDate, $completedAtValue]);

    echo json_encode(['status' => 'inserted', 'message' => 'Episodio insertado exitosamente']);
    exit;
} elseif ($action === 'actualizar_fecha') {
    // Si Python los manda como JSON o POST tradicional, los atrapamos de ambas formas:
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);

    $youtube_id = $inputData['youtube_id'] ?? $_POST['youtube_id'] ?? '';
    $publish_date = $inputData['publish_date'] ?? $_POST['publish_date'] ?? '';

    if (!empty($youtube_id) && !empty($publish_date)) {
        $stmt = $conn->prepare("UPDATE episodes SET publish_date = ? WHERE youtube_id = ?");
        $stmt->bind_param("ss", $publish_date, $youtube_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Actualizado correctamente']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos', 'recibido_id' => $youtube_id, 'recibido_fecha' => $publish_date]);
    }
    exit;
} // 1. Obtener la lista de episodios pendientes
elseif ($action === 'obtener_episodios_pendientes') {
    $stmt = $conn->prepare("SELECT id, youtube_id FROM episodes WHERE status = 'pending'");
    $stmt->execute();
    $result = $stmt->get_result();

    $episodios = [];
    while ($row = $result->fetch_assoc()) {
        $episodios[] = $row;
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'episodios' => $episodios]);
    exit;
}

// 2. Actualizar el título y la duración del episodio pendiente
elseif ($action === 'actualizar_pendientes_youtube') {
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);

    $youtube_id = $inputData['youtube_id'] ?? $_POST['youtube_id'] ?? '';
    $title = $inputData['title'] ?? $_POST['title'] ?? '';
    $duration = $inputData['duration'] ?? $_POST['duration'] ?? 0;

    if (!empty($youtube_id) && !empty($title)) {
        // Actualizamos título, duración y opcionalmente podemos cambiar el estado a 'processed' o mantenerlo
        $stmt = $conn->prepare("UPDATE episodes SET title = ?, duration = ? WHERE youtube_id = ?");
        $stmt->bind_param("sis", $title, $duration, $youtube_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Título y duración actualizados']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
