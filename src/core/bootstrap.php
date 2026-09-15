<?php
// Path: src/core/bootstrap.php

// 1. Root directory resolution
$rootDir = dirname(__DIR__, 2);

// 2. Load environment variables via Dotenv
require_once $rootDir . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

// 3. Environment & Error Reporting Configuration
$appEnv = $_ENV['APP_ENV'] ?? 'production';

if ($appEnv === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// 4. Centralized Session Management (Headers safe)
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_same_site' => 'Lax'
    ]);
}

// 5. Require Core Infrastructure Files
require_once $rootDir . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validator.php';
?>
