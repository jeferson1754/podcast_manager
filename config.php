<?php
// ------------------------------------------------------------------
// CONFIGURACIÓN PRIVADA (no imprime nada; solo define constantes)
// ------------------------------------------------------------------

// Token del endpoint de sincronización (sincronizar.php).
// Debe ser IGUAL a TOKEN_SECRET en el .env de Python. Cámbialo en ambos lados.
define('API_TOKEN', 'MI_CLAVE_SUPER_SECRETA_123');
 
// Horas sin sincronizar antes de mostrar la alerta roja en la barra superior
define('SYNC_ALERTA_HORAS', 12);
 
// Minutos de tolerancia antes de marcar un episodio como "Atrasado" en el calendario
define('CALENDARIO_GRACIA_MIN', 60);
 
// Estadísticas: si un mismo día acumula este número de episodios marcados como escuchados o más,
// se considera una carga masiva (p. ej. la importación inicial) y se excluye de los gráficos
define('ESTADISTICAS_UMBRAL_CARGA', 25);
 
// Calendario: un podcast activo sin episodios en estos días deja de generar avisos (atrasado / no se subió)
define('CALENDARIO_INACTIVO_DIAS', 30);
 
// true solo mientras desarrollas (muestra errores en pantalla)
define('APP_DEBUG', false);
 