<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$rootDir = dirname(__DIR__, 3);
if (file_exists($rootDir . '/db.php')) {
    require_once $rootDir . '/db.php';
} else {
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = (int)($_ENV['DB_PORT'] ?? 3306);
    $username = $_ENV['DB_USER'] ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';
    $database = $_ENV['DB_NAME'] ?? 'railway';

    $conn = new mysqli($host, $username, $password, $database, $port);
    if ($conn->connect_error) {
        http_response_code(500);
        die("Database connection failed");
    }
    $conn->set_charset("utf8mb4");
}