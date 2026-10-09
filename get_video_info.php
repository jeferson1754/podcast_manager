<?php
header('Content-Type: application/json');

// Configura aquí tu API Key de Google/YouTube
$API_KEY = "AIzaSyCBPEY8Kj3LBQUepGxNrqzr8weFc0KSbkM";

if (isset($_GET['url'])) {
    $youtube_link = trim($_GET['url']);
    $youtube_id = null;

    // Extraer el ID del video de la URL de YouTube
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $youtube_link, $match)) {
        $youtube_id = $match[1];
    }

    if (!$youtube_id) {
        echo json_encode(['success' => false, 'error' => 'URL de YouTube inválida']);
        exit;
    }

    // Consultar la API de YouTube v3 (snippets para título/fecha y contentDetails para duración)
    $api_url = "https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails&id=" . $youtube_id . "&key=" . $API_KEY;
    
    $response = @file_get_contents($api_url);
    if (!$response) {
        echo json_encode(['success' => false, 'error' => 'Error al conectar con la API de YouTube']);
        exit;
    }

    $data = json_decode($response, true);

    if (!empty($data['items'])) {
        $item = $data['items'][0];
        $snippet = $item['snippet'];
        $contentDetails = $item['contentDetails'];

        // 1. Título
        $title = $snippet['title'] ?? '';

        // 2. Fecha de publicación (viene en UTC como 2026-10-06T15:30:00Z)
        $published_at = $snippet['publishedAt'] ?? '';
        $publish_date_formatted = '';
        if ($published_at) {
            // Convertimos la fecha UTC a la zona horaria de Chile (America/Santiago)
            $dt = new DateTime($published_at, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('America/Santiago'));
            $publish_date_formatted = $dt->format('Y-m-d\TH:i');
        }

        // 3. Duración (viene en formato ISO 8601 ej: PT1H2M10S)
        $iso_duration = $contentDetails['duration'] ?? 'PT0S';
        $duration_str = "00:00:00";
        
        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso_duration, $matches)) {
            $horas = isset($matches[1]) ? intval($matches[1]) : 0;
            $minutos = isset($matches[2]) ? intval($matches[2]) : 0;
            $segundos = isset($matches[3]) ? intval($matches[3]) : 0;
            $duration_str = sprintf("%02d:%02d:%02d", $horas, $minutos, $segundos);
        }

        echo json_encode([
            'success' => true,
            'title' => $title,
            'duration' => $duration_str,
            'publish_date' => $publish_date_formatted
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Video no encontrado']);