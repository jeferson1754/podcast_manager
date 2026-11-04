<?php
// change_state.php — cambia el estado de un podcast (solo POST + CSRF)
include('bd.php');
require_once 'sesion.php';
require_once 'helpers.php';

exigir_post();
csrf_exigir();

if ($conn->connect_error) {
    die('Connection Error');
}

$id    = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$state = $_POST['state'] ?? '';

if ($id === false || !in_array($state, ESTADOS_PODCAST, true)) {
    flash_set('danger', 'Estado no válido.');
} elseif (ejecutar($conn, "UPDATE podcasts SET state = ? WHERE id = ?", 'si', [$state, $id])) {
    flash_set('success', 'Estado actualizado a «' . estado_label($state) . '».');
} else {
    flash_set('danger', 'No se pudo actualizar el estado.');
}

$conn->close();
header('Location: index.php?section=' . urlencode($podcast));
exit;