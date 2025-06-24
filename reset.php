<?php // reset.php 
// WARNING: This will clear all data. Use with caution. 
$conn = new mysqli('localhost', 'username', 'password', 'podcast_db');
if ($conn->connect_error) {
    die('Connection Error');
}
if (isset($_GET['full'])) { // Full reset: drop all tables and recreate 
    $conn->query("DROP TABLE IF EXISTS episodes");
    $conn->query("DROP TABLE IF EXISTS seasons");
    $conn->query("DROP TABLE IF EXISTS podcasts"); // Recreate tables 
    $conn->query("CREATE TABLE podcasts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255), description TEXT, state VARCHAR(20))");
    $conn->query("CREATE TABLE seasons (id INT AUTO_INCREMENT PRIMARY KEY, podcast_id INT, number INT, title VARCHAR(255), description TEXT)");
    $conn->query("CREATE TABLE episodes (id INT AUTO_INCREMENT PRIMARY KEY, season_id INT, number INT, title VARCHAR(255), duration VARCHAR(20), publish_date DATE)");
} else { // Partial reset: delete all rows 
    $conn->query("DELETE FROM episodes");
    $conn->query("DELETE FROM seasons");
    $conn->query("DELETE FROM podcasts");
}
$conn->close();
header('Location: index.php');
exit;
