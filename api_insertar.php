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

    // Buscar si existe
    $stmt = $pdo->prepare("SELECT id FROM podcasts WHERE LOWER(title) = ?");
    $stmt->execute([$nombreLimpio]);
    $podcast = $stmt->fetch();

    if ($podcast) {
        echo json_encode(['status' => 'ok', 'podcast_id' => $podcast['id']]);
        exit;
    }

    // Si no existe, crear podcast y temporada 1
    $stmtInsert = $pdo->prepare("INSERT INTO podcasts (title, description, image) VALUES (?, ?, ?)");
    $stmtInsert->execute([$nombreCanal, $channelUrl, $imageUrl]);
    $podcastId = $pdo->lastInsertId();

    $stmtSeason = $pdo->prepare("INSERT INTO seasons (podcast_id, number, title) VALUES (?, 1, 'Temporada 1')");
    $stmtSeason->execute([$podcastId]);

    echo json_encode(['status' => 'created', 'podcast_id' => $podcastId]);
    exit;
}

// ACCIÓN C: Obtener todos los episodios del podcast (para la sincronización de la playlist)
if ($action === 'obtener_episodios_podcast') {
    $podcastId = $inputData['podcast_id'] ?? $_POST['podcast_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT youtube_id, title, status FROM episodes WHERE podcast_id = ?");
    $stmt->execute([$podcastId]);
    $episodios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'ok', 'episodios' => $episodios]);
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

// ACCIÓN B: Insertar o Actualizar Episodio
if ($action === 'guardar_episodio') {
    $ytId        = $inputData['youtube_id'] ?? $_POST['youtube_id'] ?? '';
    $podcastId   = $inputData['podcast_id'] ?? $_POST['podcast_id'] ?? 0;
    $titulo      = $inputData['title'] ?? $_POST['title'] ?? '';
    $duracion    = $inputData['duration'] ?? $_POST['duration'] ?? '00:00:00';
    $status      = $inputData['status'] ?? $_POST['status'] ?? 'PUBLISHED';
    $publishDate = $inputData['publish_date'] ?? $_POST['publish_date'] ?? date('Y-m-d H:i:s');

    // Definir si se debe registrar completed_at basado en el estado
    $completedAtValue = ($status === 'PUBLISHED') ? date('Y-m-d H:i:s') : null;

    // 1. Verificar si el episodio ya existe
    $stmtCheck = $pdo->prepare("SELECT id, status FROM episodes WHERE youtube_id = ?");
    $stmtCheck->execute([$ytId]);
    $existente = $stmtCheck->fetch();

    if ($existente) {
        // Actualizar si antes estaba LIVE y ahora terminó, o si cambia a PUBLISHED
        if ($existente['status'] === 'LIVE' && $status === 'PUBLISHED') {
            $stmtUpdate = $pdo->prepare("UPDATE episodes SET duration = ?, status = 'PUBLISHED', completed_at = NOW(), title = ? WHERE id = ?");
            $stmtUpdate->execute([$duracion, $titulo, $existente['id']]);
            echo json_encode(['status' => 'updated', 'message' => 'Directo finalizado, completado y actualizado']);
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

    // 2. Obtener la última temporada del podcast
    $stmtSeason = $pdo->prepare("SELECT id FROM seasons WHERE podcast_id = ? ORDER BY number DESC LIMIT 1");
    $stmtSeason->execute([$podcastId]);
    $season = $stmtSeason->fetch();
    $seasonId = $season['id'] ?? 1;

    // 3. Obtener el siguiente número de episodio
    $stmtNum = $pdo->prepare("SELECT MAX(number) as max_num FROM episodes WHERE season_id = ?");
    $stmtNum->execute([$seasonId]);
    $rowNum = $stmtNum->fetch();
    $numEpisodio = ($rowNum['max_num'] ?? 0) + 1;

    // 4. Insertar nuevo episodio incluyendo completed_at si nace como PUBLISHED
    $stmtInsert = $pdo->prepare("
        INSERT INTO episodes (youtube_id, podcast_id, season_id, number, title, duration, status, publish_date, completed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsert->execute([$ytId, $podcastId, $seasonId, $numEpisodio, $titulo, $duracion, $status, $publishDate, $completedAtValue]);

    echo json_encode(['status' => 'inserted', 'message' => 'Episodio insertado exitosamente']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
