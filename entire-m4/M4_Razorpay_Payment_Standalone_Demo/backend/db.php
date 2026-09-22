<?php
declare(strict_types=1);

function load_env_file(string $file): array
{
    if (!is_file($file)) return [];
    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}

$env = load_env_file(__DIR__ . '/../.env');
$host = $env['DB_HOST'] ?? '';
$port = (int)($env['DB_PORT'] ?? 3306);
$user = $env['DB_USER'] ?? '';
$password = $env['DB_PASSWORD'] ?? '';
$dbname = $env['DB_NAME'] ?? '';

if ($host === '' || $user === '' || $dbname === '') {
    http_response_code(500);
    die('Database configuration missing in .env');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $password, $dbname, $port);
$conn->set_charset('utf8mb4');
