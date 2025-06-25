<?php

$usuario  = "epiz_32740026";
$password = "eJWcVk2au5gqD";
$servidor = "sql208.epizy.com";
$basededatos = "epiz_32740026_r_user";

$conn = new mysqli($servidor, $usuario, $password, $basededatos);
$conn2 = new mysqli($servidor, $usuario, $password, $basededatos);



date_default_timezone_set('America/Santiago');
$año = date('Y');
$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i');

$dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
$dia_actual = $dias[date('N') - 1];
