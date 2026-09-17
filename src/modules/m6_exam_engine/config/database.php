<?php
// Deprecated: Forwarding to centralized bootstrap.php for team integration.
// Database connection ($conn) is now dynamically initialized from .env (Railway Cloud DB).

if (file_exists(__DIR__ . '/../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../src/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../src/core/bootstrap.php';
} else {
    require_once dirname(__DIR__) . '/db.php';
}