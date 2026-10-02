<?php // delete.php 
$type = isset($_GET['type']) ? $_GET['type'] : 'podcasts';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
include('bd.php');

if ($conn->connect_error) {
    die('Connection Error');
}

// Acepta tanto 'podcasts' como 'podcast' de forma segura
if ($type == 'podcasts' || $type == 'podcast') {
    $conn->query("DELETE FROM episodes WHERE season_id IN (SELECT id FROM seasons WHERE podcast_id = $id)");
    $conn->query("DELETE FROM seasons WHERE podcast_id = $id");
    $conn->query("DELETE FROM podcasts WHERE id = $id");
    $type = 'podcasts'; // Forzar redirección correcta
} elseif ($type == 'temporadas') {
    $conn->query("DELETE FROM seasons WHERE id = $id");
} elseif ($type == 'episodios') {
    $conn->query("DELETE FROM episodes WHERE id = $id");
}

$conn->close();
header('Location: index.php?section=' . $type);
exit;
