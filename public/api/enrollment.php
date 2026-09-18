<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response('error', 'Use GET for enrollment lookup.', null, 405);
}

function demo_mode(): bool {
    return ($_ENV['M4_DEMO_MODE'] ?? '1') === '1';
}

function resolve_candidate_id(array $input = []): int {
    global $conn;

    $hasSession = isset($_SESSION['candidate_id']) || isset($_SESSION['user_id']);

    if (isset($_SESSION['candidate_id']) && ctype_digit((string)$_SESSION['candidate_id'])) {
        return (int)$_SESSION['candidate_id'];
    }

    if (isset($_SESSION['user_id']) && ctype_digit((string)$_SESSION['user_id'])) {
        $stmt = $conn->prepare('SELECT id FROM candidates WHERE user_id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';

    if (!$hasSession && demo_mode() && $appEnv !== 'production') {
        $cid = $input['candidate_id'] ?? $_GET['candidate_id'] ?? 1;
        if (ctype_digit((string)$cid)) return (int)$cid;
    }

    send_json_response('error', 'Candidate authentication/session is required.', null, 401);
    exit;
}

try {
    $candidateId = resolve_candidate_id($_GET);

    $stmt = $conn->prepare(
        'SELECT e.id, e.assessment_id, e.payment_id, e.batch_id, e.eligibility_status, e.created_at,
                a.title AS assessment_title
         FROM enrollments e
         JOIN assessments a ON a.id = e.assessment_id
         WHERE e.candidate_id = ? ORDER BY e.id DESC'
    );
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $result = $stmt->get_result();

    $enrollments = [];
    while ($row = $result->fetch_assoc()) {
        $enrollments[] = [
            'id' => (int)$row['id'],
            'enrollment_id' => 'ENR-' . $row['id'],
            'assessment_id' => (int)$row['assessment_id'],
            'assessment' => $row['assessment_title'],
            'payment_id' => $row['payment_id'] ? (int)$row['payment_id'] : null,
            'batch_id' => $row['batch_id'] ? (int)$row['batch_id'] : null,
            'eligibility_status' => $row['eligibility_status'],
            'created_at' => $row['created_at']
        ];
    }
    $stmt->close();

    send_json_response('success', 'Enrollments fetched successfully', $enrollments);
} catch (Throwable $e) {
    send_json_response('error', 'Failed to fetch enrollments: ' . $e->getMessage(), null, 500);
}
