<?php
// db.php - Secure Environment-based Database Connection Handler

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Read environment variables with fallback options
$host     = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port     = (int)($_ENV['DB_PORT'] ?? 3306);
$user     = $_ENV['DB_USER']     ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$dbname   = $_ENV['DB_NAME']     ?? 'railway';

// Enable strict MySQLi error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $dbname, $port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>
