<?php
$rootDir = dirname(__DIR__, 2);

require_once $rootDir . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validator.php';

$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log(sprintf("[%s] Unhandled Exception: %s in %s on line %d\nStack trace:\n%s", 
        date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
    
    if (!headers_sent()) {
        send_json_response('error', 'An internal server error occurred.', null, 500);
    }
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log(sprintf("[%s] PHP Error [%d]: %s in %s on line %d", 
        date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline));
    return true;
});

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ]);
}

function require_admin_access(mysqli $conn): void
{
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if ($userId > 0 && !$role) {
        $stmt = $conn->prepare('SELECT role,is_active FROM users WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($user && (int)$user['is_active'] === 1) $role = $user['role'];
    }

    if (in_array($role, ['admin', 'staff'], true)) return;

    /* Development mode deliberately permits the standalone M7 UI before M3 auth is merged. */
    if ($appEnv === 'development' && $userId === 0 && $role === null) return;

    send_json_response('error', 'Administrator access required.', null, 403);
}

function csrf_token(): string
{
    if (empty($_SESSION['m7_csrf'])) {
        $_SESSION['m7_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['m7_csrf'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['m7_csrf'] ?? '';
    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        send_json_response('error', 'Invalid or missing security token. Refresh the page and try again.', null, 419);
    }
}
