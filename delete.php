<?php // delete.php 
$type = isset($_GET['type']) ? $_GET['type'] : 'podcasts';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
include('bd.php');
if ($conn->connect_error) {
    die('Connection Error');
} // Deleting an item; note that cascading deletions should be handled carefully. 
if ($type == 'podcasts') { // Delete related seasons and episodes first 
    $conn->query("DELETE FROM episodes WHERE season_id IN (SELECT id FROM seasons WHERE podcast_id = $id)");
    $conn->query("DELETE FROM seasons WHERE podcast_id = $id");
    $conn->query("DELETE FROM podcasts WHERE id = $id");
} elseif ($type == 'seasons') { // Delete related episodes 
    $conn->query("DELETE FROM episodes WHERE season_id = $id");
    $conn->query("DELETE FROM seasons WHERE id = $id");
} elseif ($type == 'episodes') {
    $conn->query("DELETE FROM episodes WHERE id = $id");
}
$conn->close();
header('Location: index.php?section=' . $type);
exit;
