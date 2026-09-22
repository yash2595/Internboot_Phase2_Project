<?php
/**
 * src/core/helpers.php
 *
 * Shared utility helpers used across all modules.
 */

/**
 * Resolve the real client IP address.
 *
 * Railway (and most reverse-proxy deployments) append the original client IP
 * to the X-Forwarded-For header. The format is a comma-separated list where
 * the *left-most* entry is the originating client and each subsequent entry
 * is a proxy hop:
 *
 *   X-Forwarded-For: <client>, <proxy1>, <proxy2>
 *
 * Because Railway is a single trusted hop we take index [0] (the first /
 * left-most IP). We validate it is a valid IP before trusting it; if
 * validation fails or the header is absent we fall back to REMOTE_ADDR.
 *
 * Reference: Railway documentation — "Railway injects X-Forwarded-For with
 * the client's IP address." (https://docs.railway.app/reference/private-networking)
 *
 * In local development (no proxy) REMOTE_ADDR is the real client IP and
 * X-Forwarded-For is typically absent, so the fallback is correct there too.
 *
 * @return string  A valid dotted-decimal IPv4 or compressed IPv6 address.
 */
function get_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

    if ($forwarded !== '') {
        // Take the first (left-most) entry and strip any port suffix
        $first = trim(explode(',', $forwarded)[0]);

        // Strip IPv6-mapped IPv4 prefix if present (::ffff:1.2.3.4)
        if (str_starts_with(strtolower($first), '::ffff:')) {
            $first = substr($first, 7);
        }

        // Validate; only trust it if it is a well-formed IP address
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Completely destroy the current session, clearing memory, cookies, and storage.
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

/**
 * Retrieve the normalized current application environment string ('production', 'development', etc.).
 * Defaults to 'production' if unset or invalid.
 */
function get_app_env(): string
{
    $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    return strtolower(trim((string)$env));
}

/**
 * Determine whether the application is running in local development mode.
 * Safe by default: returns true ONLY if APP_ENV is explicitly 'development'.
 */
function is_dev_env(): bool
{
    return get_app_env() === 'development';
}

/**
 * Count the actual number of questions served for a given exam attempt (from attempt_questions).
 *
 * @param mysqli $conn
 * @param int $attemptId
 * @return int Number of served questions, or 0 if none yet assigned.
 */
function get_attempt_served_question_count(mysqli $conn, int $attemptId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM attempt_questions WHERE attempt_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $attemptId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int)$row['cnt'] : 0;
}

