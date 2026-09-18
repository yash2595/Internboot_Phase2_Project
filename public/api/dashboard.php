<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response('error', 'Method not allowed.', null, 405);
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

function get_candidate(int $candidateId): ?array {
    global $conn;
    $stmt = $conn->prepare(
        'SELECT c.id, c.user_id, c.full_name, c.phone, c.profile_details, c.created_at, u.email
         FROM candidates c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.id = ? LIMIT 1'
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

try {
    $candidateId = resolve_candidate_id($_GET);
    $candidate = get_candidate($candidateId);

    if (!$candidate) {
        send_json_response('error', 'Candidate not found.', null, 404);
    }

    $profile = parse_profile_details($candidate['profile_details']);

    /* Latest Payment */
    $stmt = $conn->prepare(
        'SELECT id, assessment_id, amount, status, reference_number, payment_date, created_at
         FROM payments WHERE candidate_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $paymentRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Latest Enrollment */
    $stmt = $conn->prepare(
        'SELECT e.id, e.assessment_id, e.payment_id, e.batch_id, e.eligibility_status, e.created_at,
                a.title AS assessment_title
         FROM enrollments e
         LEFT JOIN assessments a ON a.id = e.assessment_id
         WHERE e.candidate_id = ? ORDER BY e.id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $enrollmentRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $assessmentId = 0;
    if ($enrollmentRow) {
        $assessmentId = (int)$enrollmentRow['assessment_id'];
    } elseif ($paymentRow) {
        $assessmentId = (int)$paymentRow['assessment_id'];
    }
    $assessment = $assessmentId > 0 ? get_assessment($assessmentId) : null;

    /* Batch */
    $batchRow = null;
    if ($enrollmentRow && $enrollmentRow['batch_id'] !== null) {
        $batchId = (int)$enrollmentRow['batch_id'];
        $stmt = $conn->prepare(
            'SELECT b.id, b.batch_number,
                (SELECT COUNT(*) FROM enrollments e2 WHERE e2.batch_id = b.id) AS candidate_count,
                es.exam_date, es.status AS exam_status, sl.start_time, sl.end_time
             FROM batches b
             LEFT JOIN exam_schedules es ON es.batch_id = b.id
             LEFT JOIN exam_slots sl ON sl.exam_schedule_id = es.id
             WHERE b.id = ? ORDER BY es.exam_date ASC, sl.start_time ASC LIMIT 1'
        );
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $batchRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    /* Result */
    $stmt = $conn->prepare(
        'SELECT r.id, r.total_score, r.percentage, r.level_assigned, a.id AS attempt_id
         FROM results r
         INNER JOIN attempts a ON a.id = r.attempt_id
         WHERE a.candidate_id = ? ORDER BY r.id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $resultRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Certificate */
    $stmt = $conn->prepare(
        'SELECT certificate_number, level, issue_date FROM certificates WHERE candidate_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $candidateId);
    $stmt->execute();
    $certificateRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $payment = [
        'totalFee' => '—', 'paidAmount' => '—', 'status' => 'Pending',
        'paymentId' => '—', 'transactionId' => '—', 'paymentDate' => '—',
        'method' => 'Sandbox / Database', 'verification' => 'Not Verified'
    ];
    if ($paymentRow) {
        $status = strtolower((string)$paymentRow['status']);
        $amount = (float)$paymentRow['amount'];
        $paymentStatus = match ($status) {
            'success' => 'Paid', 'failed' => 'Failed', 'pending' => 'Pending', default => ucfirst($status)
        };
        $payment = [
            'totalFee' => '₹' . number_format($amount, 2),
            'paidAmount' => $status === 'success' ? '₹' . number_format($amount, 2) : '₹0.00',
            'status' => $paymentStatus,
            'paymentId' => 'PAY-' . $paymentRow['id'],
            'transactionId' => $paymentRow['reference_number'] ?: '—',
            'paymentDate' => !empty($paymentRow['payment_date']) ? date('d M Y', strtotime($paymentRow['payment_date'])) : '—',
            'method' => 'Sandbox / Database',
            'verification' => $status === 'success' ? 'Verified' : 'Not Verified'
        ];
    }

    $enrollment = ['id' => '—', 'date' => '—', 'status' => 'Not Enrolled'];
    if ($enrollmentRow) {
        $eligibility = strtolower((string)$enrollmentRow['eligibility_status']);
        $enrollmentStatus = match ($eligibility) {
            'eligible' => 'Enrolled', 'pending' => 'Pending', default => ucfirst($eligibility)
        };
        $enrollment = [
            'id' => 'ENR-' . $enrollmentRow['id'],
            'date' => !empty($enrollmentRow['created_at']) ? date('d M Y', strtotime($enrollmentRow['created_at'])) : '—',
            'status' => $enrollmentStatus
        ];
    }

    $batch = ['name' => 'Not Assigned', 'id' => '—', 'mentor' => '—', 'status' => 'Pending', 'candidates' => '—'];
    if ($batchRow) {
        $examStatus = strtolower((string)($batchRow['exam_status'] ?? ''));
        $batchStatus = $examStatus !== '' ? ucwords(str_replace('_', ' ', $examStatus)) : 'Assigned';
        $batch = [
            'name' => $batchRow['batch_number'] ?: 'Assigned Batch',
            'id' => 'BATCH-' . $batchRow['id'],
            'mentor' => '—',
            'status' => $batchStatus,
            'candidates' => (string)($batchRow['candidate_count'] ?? 0) . ' registered'
        ];
    }

    $exam = [
        'name' => $assessment['title'] ?? 'Assessment',
        'date' => '—', 'time' => '—',
        'duration' => ($assessment['duration_minutes'] ?? 0) . ' min',
        'questions' => (string)($assessment['total_questions'] ?? 0),
        'mode' => 'Online Assessment', 'status' => 'Upcoming'
    ];
    if ($batchRow && !empty($batchRow['exam_date'])) {
        $exam['date'] = date('d M Y', strtotime($batchRow['exam_date']));
        if (!empty($batchRow['start_time']) && !empty($batchRow['end_time'])) {
            $exam['time'] = date('h:i A', strtotime($batchRow['start_time'])) . ' – ' . date('h:i A', strtotime($batchRow['end_time']));
        }
        if (!empty($batchRow['exam_status'])) {
            $exam['status'] = ucwords(str_replace('_', ' ', $batchRow['exam_status']));
        }
    }

    $result = ['score' => '— / 100', 'level' => '—', 'status' => 'Pending', 'evaluation' => 'Pending'];
    if ($resultRow) {
        $percentage = (float)$resultRow['percentage'];
        $level = $resultRow['level_assigned'];
        $result = [
            'score' => number_format($percentage, 2) . ' / 100',
            'level' => $level !== null && $level !== '' ? 'Level ' . $level : '—',
            'status' => 'Available',
            'evaluation' => 'Evaluated'
        ];
    }

    $certificate = ['number' => 'Not issued', 'level' => 'Not assigned', 'issueDate' => '—', 'status' => 'Pending'];
    if ($certificateRow) {
        $certificate = [
            'number' => $certificateRow['certificate_number'],
            'level' => 'Level ' . $certificateRow['level'],
            'issueDate' => !empty($certificateRow['issue_date']) ? date('d M Y', strtotime($certificateRow['issue_date'])) : '—',
            'status' => 'Issued'
        ];
    }

    $profileStatus = !empty($candidate['profile_details']) ? 'Verified' : 'Basic Profile';

    send_json_response('success', 'Dashboard data retrieved successfully', [
        'candidate' => [
            'name' => $candidate['full_name'],
            'email' => $candidate['email'] ?? '—',
            'phone' => $candidate['phone'] ?: '—',
            'dateOfBirth' => $profile['dateOfBirth'] ?? $profile['date_of_birth'] ?? '—',
            'gender' => $profile['gender'] ?? '—',
            'address' => $profile['address'] ?? '—',
            'candidateId' => 'IB-CAN-' . $candidate['id'],
            'registrationDate' => !empty($candidate['created_at']) ? date('d M Y', strtotime($candidate['created_at'])) : '—',
            'level' => $resultRow && $resultRow['level_assigned'] !== null ? 'Level ' . $resultRow['level_assigned'] : '—',
            'accountStatus' => 'Active', 'profileStatus' => $profileStatus
        ],
        'payment' => $payment,
        'enrollment' => $enrollment,
        'batch' => $batch,
        'exam' => $exam,
        'result' => $result,
        'certificate' => $certificate
    ]);
} catch (Throwable $e) {
    send_json_response('error', 'Unable to fetch dashboard data: ' . $e->getMessage(), null, 500);
}
