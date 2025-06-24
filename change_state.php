
<?php // change_state.php 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) $_POST['id'];
    $state = $_POST['state'];
    include('bd.php');
    if ($conn->connect_error) {
        die('Connection Error');
    }
    $stmt = $conn->prepare("UPDATE podcasts SET state = ? WHERE id = ?");
    $stmt->bind_param("si", $state, $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    header('Location: index.php?section=podcasts');
    exit;
}
?>