<?php
// sesion.php — sesión, protección CSRF y mensajes flash (sin login)
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_validar(): bool
{
    $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}

/** Corta la ejecución si el token CSRF no es válido. */
function csrf_exigir(): void
{
    if (!csrf_validar()) {
        http_response_code(419);
        die('La sesión expiró o el formulario no es válido. Vuelve atrás y recarga la página.');
    }
}

/** Solo permite POST. */
function exigir_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        die('Método no permitido');
    }
}

// Mensajes que sobreviven a una redirección ('success', 'danger', 'warning', 'info')
function flash_set(string $tipo, string $mensaje): void
{
    $_SESSION['flash'] = [$tipo, $mensaje];
}

function flash_pull(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
