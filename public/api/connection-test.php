<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $result = $conn->query('SELECT DATABASE() AS database_name, VERSION() AS server_version');
    $row = $result->fetch_assoc();

    send_json_response('success', 'M4 is connected to the configured MySQL database.', [
        'database' => $row['database_name'],
        'server_version' => $row['server_version']
    ]);
} catch (Throwable $e) {
    send_json_response('error', 'Database connection test failed: ' . $e->getMessage(), null, 500);
}
