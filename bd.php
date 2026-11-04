<?php
$usuario  = "root";
$password = "";
$servidor = "localhost";
$basededatos = "epiz_32740026_r_user";

require_once __DIR__ . '/config.php';

// Errores: se registran en el log; en pantalla solo si APP_DEBUG = true
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// Zona horaria única para PHP y MySQL (así NOW()/CURDATE() coinciden con la hora de Chile)
date_default_timezone_set('America/Santiago');
$offset_mysql = (new DateTime('now', new DateTimeZone('America/Santiago')))->format('P'); // -03:00 / -04:00

$conn  = new mysqli($servidor, $usuario, $password, $basededatos);
$conn2 = new mysqli($servidor, $usuario, $password, $basededatos);

foreach ([$conn, $conn2] as $c) {
    if (!$c->connect_error) {
        $c->set_charset('utf8mb4');
        $c->query("SET time_zone = '" . $offset_mysql . "'");
    }
}

$año = date('Y');
$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i');

$podcast = "podcasts";
$temporadas = "temporadas";
$episodios = "episodios";
$calendario = "calendario";

$dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
$dia_actual = $dias[date('N') - 1];