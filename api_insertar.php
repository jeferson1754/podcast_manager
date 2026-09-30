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

// 1. Validar Token de Seguridad
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if ($token !== $tokenSecret) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Acceso no autorizado']));
}

// 2. Leer Acción
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ACCIÓN A: Buscar o Crear Podcast
if ($action === 'obtener_o_crear_podcast') {
    $nombreCanal = trim($_POST['nombre'] ?? '');
    $channelUrl  = trim($_POST['channel_url'] ?? '');
    $imageUrl    = trim($_POST['image_url'] ?? '');

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

// ACCIÓN B: Insertar o Actualizar Episodio
if ($action === 'guardar_episodio') {
    $ytId        = $_POST['youtube_id'] ?? '';
    $podcastId   = $_POST['podcast_id'] ?? 0;
    $titulo      = $_POST['title'] ?? '';
    $duracion    = $_POST['duration'] ?? '00:00:00';
    $status      = $_POST['status'] ?? 'PUBLISHED';
    $publishDate = $_POST['publish_date'] ?? date('Y-m-d H:i:s');

    // 1. Verificar si el episodio ya existe
    $stmtCheck = $pdo->prepare("SELECT id, status FROM episodes WHERE youtube_id = ?");
    $stmtCheck->execute([$ytId]);
    $existente = $stmtCheck->fetch();

    if ($existente) {
        // Actualizar si antes estaba LIVE y ahora terminó
        if ($existente['status'] === 'LIVE' && $status === 'PUBLISHED') {
            $stmtUpdate = $pdo->prepare("UPDATE episodes SET duration = ?, status = 'PUBLISHED', title = ? WHERE id = ?");
            $stmtUpdate->execute([$duracion, $titulo, $existente['id']]);
            echo json_encode(['status' => 'updated', 'message' => 'Directo finalizado y actualizado']);
        } else {
            echo json_encode(['status' => 'exists', 'message' => 'Ya registrado']);
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

    // 4. Insertar nuevo episodio
    $stmtInsert = $pdo->prepare("
        INSERT INTO episodes (youtube_id, podcast_id, season_id, number, title, duration, status, publish_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsert->execute([$ytId, $podcastId, $seasonId, $numEpisodio, $titulo, $duracion, $status, $publishDate]);

    echo json_encode(['status' => 'inserted', 'message' => 'Episodio insertado exitosamente']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
