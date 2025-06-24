<?php
$conn = new mysqli('localhost', 'root', '', 'podcast_db');
$conn2 = new mysqli('localhost', 'root', '', 'podcast_db');



date_default_timezone_set('America/Santiago');
$año = date('Y');
$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i');

$dias = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
$dia_actual = $dias[date('N') - 1];
