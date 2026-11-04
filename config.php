<?php
// ------------------------------------------------------------------
// CONFIGURACIÓN PRIVADA (no imprime nada; solo define constantes)
// ------------------------------------------------------------------

// Token del endpoint de sincronización (sincronizar.php).
// Debe ser IGUAL a TOKEN_SECRET en el .env de Python. Cámbialo en ambos lados.
define('API_TOKEN', 'MI_CLAVE_SUPER_SECRETA_123');

// Horas sin sincronizar antes de mostrar la alerta roja en la barra superior
define('SYNC_ALERTA_HORAS', 12);

// true solo mientras desarrollas (muestra errores en pantalla)
define('APP_DEBUG', false);
