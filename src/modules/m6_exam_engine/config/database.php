<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$rootDir = dirname(__DIR__, 4);
$dbBootstrap = $rootDir . '/db.php';

if (!file_exists($dbBootstrap)) {
    http_response_code(500);
    $errorMessage = "Database configuration error: central bootstrap file not found at '{$dbBootstrap}'. Refusing to continue with insecure fallback credentials.";
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'message' => $errorMessage,
            'data'    => null,
        ]);
        exit;
    }
    throw new RuntimeException($errorMessage);
}

require_once $dbBootstrap;

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    throw new RuntimeException("Database initialization failed: \$conn was not established by '{$dbBootstrap}'.");
}