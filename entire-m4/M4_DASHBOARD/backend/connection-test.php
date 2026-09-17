<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$result = $conn->query('SELECT DATABASE() AS database_name, VERSION() AS server_version');
$row = $result->fetch_assoc();

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'M4 is connected to the configured MySQL database.',
    'database' => $row['database_name'],
    'server_version' => $row['server_version']
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
