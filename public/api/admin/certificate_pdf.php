<?php
declare(strict_types=1);

function render_certificate_error_page(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Unavailable — InternBoot</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 32px; max-width: 480px; width: 100%; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        h1 { font-size: 20px; margin: 0 0 12px; color: #f87171; }
        p { font-size: 14px; line-height: 1.6; color: #94a3b8; margin: 0 0 24px; }
        .btn { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 500; font-size: 14px; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Certificate Unavailable</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <a href="javascript:window.close()" class="btn">Close Window</a>
    </div>
</body>
</html>';
    exit;
}

try {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/queries.php';
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
    require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/pdf.php';

    require_admin_access_or_throw($conn, 'Administrator access required to view or download certificates.');


    $resultId = require_positive_int($_GET['result_id'] ?? null, 'result_id');
    $data = generate_certificate($conn, $resultId);
    output_certificate_pdf($data);
} catch (RuntimeException $e) {
    render_certificate_error_page($e->getMessage(), 403);
} catch (InvalidArgumentException $e) {
    render_certificate_error_page($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('InternBoot M7 certificate PDF error: ' . $e->getMessage());
    $dev = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') === 'development';
    render_certificate_error_page($dev ? $e->getMessage() : 'Certificate could not be generated. Please try again or contact support.', 500);
}


