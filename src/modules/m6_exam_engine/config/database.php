<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$rootDir = dirname(__DIR__, 4);
$dbBootstrap = $rootDir . '/db.php';

if (!file_exists($dbBootstrap)) {
    http_response_code(500);
    $errorMessage = "Database configuration error: central bootstrap file not found at '{$dbBootstrap}'. Refusing to continue with insecure fallback credentials.";
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $prefersHtml = str_contains($accept, 'text/html') || str_contains($accept, 'application/pdf');
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') && !$prefersHtml) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'message' => $errorMessage,
            'data'    => null,
        ]);
        exit;
    }
    if ($prefersHtml) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Database Error</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box}.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:32px;max-width:480px;width:100%;text-align:center}h1{font-size:20px;margin:0 0 12px;color:#f87171}p{font-size:14px;color:#94a3b8;line-height:1.6;margin:0}</style></head><body><div class="card"><h1>Database Error</h1><p>' . htmlspecialchars($errorMessage) . '</p></div></body></html>';
        exit;
    }
    throw new RuntimeException($errorMessage);
}

require_once $dbBootstrap;

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    throw new RuntimeException("Database initialization failed: \$conn was not established by '{$dbBootstrap}'.");
}