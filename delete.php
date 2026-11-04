<?php
// delete.php — eliminación por POST + CSRF (antes era un enlace GET)
include('bd.php');
require_once 'sesion.php';
require_once 'helpers.php';

exigir_post();
csrf_exigir();

if ($conn->connect_error) {
    die('Connection Error');
}

$type = $_POST['type'] ?? '';
$id   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id === false || !in_array($type, ['podcasts', 'podcast', 'temporadas', 'episodios'], true)) {
    flash_set('danger', 'Solicitud no válida.');
    header('Location: index.php');
    exit;
}
if ($type === 'podcast') {
    $type = 'podcasts';
}

try {
    if ($type === 'podcasts') {
        $conn->begin_transaction();
        ejecutar($conn, "DELETE FROM episodes WHERE season_id IN (SELECT id FROM seasons WHERE podcast_id = ?) OR podcast_id = ?", 'ii', [$id, $id]);
        ejecutar($conn, "DELETE FROM schedule WHERE podcast_id = ?", 'i', [$id]);
        ejecutar($conn, "DELETE FROM seasons WHERE podcast_id = ?", 'i', [$id]);
        ejecutar($conn, "DELETE FROM podcasts WHERE id = ?", 'i', [$id]);
        $conn->commit();
        flash_set('success', 'Podcast eliminado junto con sus temporadas y episodios.');
    } elseif ($type === 'temporadas') {
        // No se borran temporadas con episodios: evita dejar episodios huérfanos
        $n = (int) (consultar($conn, "SELECT COUNT(*) AS n FROM episodes WHERE season_id = ?", 'i', [$id])[0]['n'] ?? 0);
        if ($n > 0) {
            flash_set('warning', "La temporada tiene $n episodio(s). Elimínalos o muévelos a otra temporada antes de borrarla.");
        } else {
            ejecutar($conn, "DELETE FROM seasons WHERE id = ?", 'i', [$id]);
            flash_set('success', 'Temporada eliminada.');
        }
    } else {
        ejecutar($conn, "DELETE FROM episodes WHERE id = ?", 'i', [$id]);
        flash_set('success', 'Episodio eliminado.');
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $e2) {
    }
    error_log('delete.php: ' . $e->getMessage());
    flash_set('danger', 'No se pudo eliminar. Inténtalo de nuevo.');
}

$conn->close();
header('Location: index.php?section=' . urlencode($type));
exit;