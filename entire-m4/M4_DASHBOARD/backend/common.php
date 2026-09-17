<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['success' => false, 'message' => 'Invalid JSON request body.'], 400);
    return $data;
}

/*
 * During M4 development, allow a candidate_id to be supplied explicitly.
 * Once M3 authentication is connected, set M4_DEMO_MODE=0 and resolve the
 * candidate from $_SESSION['user_id'] instead.
 */
function demo_mode(): bool {
    return ($_ENV['M4_DEMO_MODE'] ?? '1') === '1';
}

function resolve_candidate_id(array $input = []): int {
    global $conn;

    if (isset($_SESSION['user_id']) && ctype_digit((string)$_SESSION['user_id'])) {
        $stmt = $conn->prepare('SELECT id FROM candidates WHERE user_id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id'];
    }

    if (demo_mode() && isset($input['candidate_id']) && ctype_digit((string)$input['candidate_id'])) {
        return (int)$input['candidate_id'];
    }

    json_response(['success' => false, 'message' => 'Candidate authentication/session is required.'], 401);
}

function get_candidate(int $candidateId): ?array {
    global $conn;
    $stmt = $conn->prepare(
        'SELECT id, user_id, full_name, phone, profile_details, created_at
         FROM candidates WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function get_assessment(int $assessmentId): ?array {
    global $conn;
    $stmt = $conn->prepare(
        'SELECT id, title, description, duration_minutes, total_questions, status
         FROM assessments WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function parse_profile_details(?string $raw): array {
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
