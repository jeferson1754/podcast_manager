<?php
$usuario  = "root";
$password = "";
$servidor = "localhost";
$basededatos = "epiz_32740026_r_user";


$conn = new mysqli($servidor, $usuario, $password, $basededatos);
$conn2 = new mysqli($servidor, $usuario, $password, $basededatos);



date_default_timezone_set('America/Santiago');
$año = date('Y');
$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i');

$podcast = "podcasts";
$temporadas = "temporadas";
$episodios = "episodios";
$calendario = "calendario";


$dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
$dia_actual = $dias[date('N') - 1];
