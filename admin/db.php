<?php
/**
 * InternBoot M7 database bootstrap.
 *
 * Supports both the project's DB_* variables and all Railway MySQL variables:
 * MYSQL_DATABASE, MYSQL_PUBLIC_URL, MYSQL_ROOT_PASSWORD, MYSQL_URL,
 * MYSQLDATABASE, MYSQLHOST, MYSQLPASSWORD, MYSQLPORT, MYSQLUSER.
 */
function load_local_env(string $file): void
{
    if (!is_file($file)) return;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

load_local_env(__DIR__ . '/.env');

function env_value(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) return (string)$_ENV[$key];
    $value = getenv($key);
    return $value === false ? $default : (string)$value;
}

function parse_mysql_url(string $url): array
{
    $parts = parse_url(trim($url));
    if ($parts === false || empty($parts['host'])) return [];

    return [
        'host' => $parts['host'],
        'port' => isset($parts['port']) ? (int)$parts['port'] : 3306,
        'user' => isset($parts['user']) ? urldecode($parts['user']) : 'root',
        'password' => isset($parts['pass']) ? urldecode($parts['pass']) : '',
        'database' => isset($parts['path']) ? ltrim($parts['path'], '/') : 'railway',
    ];
}

/*
 * Priority for local development:
 * 1. Explicit DB_* variables.
 * 2. Railway MYSQL_PUBLIC_URL (public URL works from a local PC).
 * 3. Railway split variables.
 * 4. MYSQL_URL (private Railway URL; useful when the app itself runs on Railway).
 */
$publicUrl = env_value('MYSQL_PUBLIC_URL', '');
$privateUrl = env_value('MYSQL_URL', '');
$publicConfig = $publicUrl !== '' ? parse_mysql_url($publicUrl) : [];
$privateConfig = $privateUrl !== '' ? parse_mysql_url($privateUrl) : [];

$host = env_value('DB_HOST');
$port = env_value('DB_PORT');
$user = env_value('DB_USER');
$password = env_value('DB_PASSWORD');
$dbname = env_value('DB_NAME');

if ($host === null || $host === '') $host = $publicConfig['host'] ?? env_value('MYSQLHOST') ?? $privateConfig['host'] ?? '127.0.0.1';
if ($port === null || $port === '') $port = (string)($publicConfig['port'] ?? env_value('MYSQLPORT') ?? $privateConfig['port'] ?? 3306);
if ($user === null || $user === '') $user = $publicConfig['user'] ?? env_value('MYSQLUSER') ?? $privateConfig['user'] ?? 'root';
if ($password === null || $password === '') {
    $password = $publicConfig['password'] ?? env_value('MYSQL_ROOT_PASSWORD') ?? env_value('MYSQLPASSWORD') ?? $privateConfig['password'] ?? '';
}
if ($dbname === null || $dbname === '') $dbname = $publicConfig['database'] ?? env_value('MYSQL_DATABASE') ?? env_value('MYSQLDATABASE') ?? $privateConfig['database'] ?? 'railway';

/* The pasted Railway value can accidentally contain another variable assignment. */
if (str_starts_with((string)$password, 'MYSQL_') && str_contains((string)$password, '=')) {
    $password = env_value('MYSQL_ROOT_PASSWORD') ?? $publicConfig['password'] ?? '';
}

$port = (int)$port;
if ($port < 1 || $port > 65535) $port = 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $dbname, $port);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed. Check Railway public MySQL credentials and host/port in .env.',
            'data' => null
        ]);
        exit;
    }
    http_response_code(500);
    die('Database connection failed. Check DB_* or Railway MYSQL_* credentials in .env.');
}
