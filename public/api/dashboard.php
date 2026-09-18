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

    /* 1. Merged Candidate + Latest Payment + Latest Enrollment + Assessment query */
    $stmt = $conn->prepare(
        'SELECT 
            c.id AS candidate_id, c.user_id, c.full_name, c.phone, c.profile_details, c.created_at AS candidate_created_at,
            u.email,
            p.id AS payment_id, p.assessment_id AS payment_assessment_id, p.amount AS payment_amount,
            p.status AS payment_status, p.reference_number, p.payment_date, p.created_at AS payment_created_at,
            e.id AS enrollment_id, e.assessment_id AS enrollment_assessment_id, e.payment_id AS enrollment_payment_id,
            e.batch_id, e.eligibility_status, e.created_at AS enrollment_created_at,
            ass.id AS assessment_id, ass.title AS assessment_title, ass.description AS assessment_description,
            ass.duration_minutes, ass.total_questions, ass.status AS assessment_status
        FROM candidates c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN (
            SELECT id, assessment_id, amount, status, reference_number, payment_date, created_at
            FROM payments
            WHERE candidate_id = ?
            ORDER BY id DESC
            LIMIT 1
        ) p ON 1=1
        LEFT JOIN (
            SELECT id, assessment_id, payment_id, batch_id, eligibility_status, created_at
            FROM enrollments
            WHERE candidate_id = ?
            ORDER BY id DESC
            LIMIT 1
        ) e ON 1=1
        LEFT JOIN assessments ass ON ass.id = COALESCE(NULLIF(e.assessment_id, 0), NULLIF(p.assessment_id, 0))
        WHERE c.id = ?
        LIMIT 1'
    );
    $stmt->bind_param('iii', $candidateId, $candidateId, $candidateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['candidate_id'])) {
        send_json_response('error', 'Candidate not found.', null, 404);
    }

    $candidate = [
        'id' => $row['candidate_id'],
        'user_id' => $row['user_id'],
        'full_name' => $row['full_name'],
        'phone' => $row['phone'],
        'profile_details' => $row['profile_details'],
        'created_at' => $row['candidate_created_at'],
        'email' => $row['email'],
    ];

    $profile = parse_profile_details($candidate['profile_details']);

    $paymentRow = ($row['payment_id'] !== null) ? [
        'id' => $row['payment_id'],
        'assessment_id' => $row['payment_assessment_id'],
        'amount' => $row['payment_amount'],
        'status' => $row['payment_status'],
        'reference_number' => $row['reference_number'],
        'payment_date' => $row['payment_date'],
        'created_at' => $row['payment_created_at'],
    ] : null;

    $enrollmentRow = ($row['enrollment_id'] !== null) ? [
        'id' => $row['enrollment_id'],
        'assessment_id' => $row['enrollment_assessment_id'],
        'payment_id' => $row['enrollment_payment_id'],
        'batch_id' => $row['batch_id'],
        'eligibility_status' => $row['eligibility_status'],
        'created_at' => $row['enrollment_created_at'],
        'assessment_title' => $row['assessment_title'],
    ] : null;

    $assessment = ($row['assessment_id'] !== null) ? [
        'id' => $row['assessment_id'],
        'title' => $row['assessment_title'],
        'description' => $row['assessment_description'],
        'duration_minutes' => $row['duration_minutes'],
        'total_questions' => $row['total_questions'],
        'status' => $row['assessment_status'],
    ] : null;

    /* 2. Batch (remains separate query as it depends on enrollment batch_id) */
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

    /* 3. Combined Result + Certificate query */
    $stmt = $conn->prepare(
        'SELECT 
            r.id AS result_id, r.total_score, r.percentage, r.level_assigned, r.attempt_id,
            c.certificate_number, c.level AS certificate_level, c.issue_date AS certificate_issue_date
         FROM (SELECT 1) _d
         LEFT JOIN (
             SELECT res.id, res.total_score, res.percentage, res.level_assigned, a.id AS attempt_id
             FROM results res
             INNER JOIN attempts a ON a.id = res.attempt_id
             WHERE a.candidate_id = ?
             ORDER BY res.id DESC
             LIMIT 1
         ) r ON 1=1
         LEFT JOIN (
             SELECT certificate_number, level, issue_date
             FROM certificates
             WHERE candidate_id = ?
             ORDER BY id DESC
             LIMIT 1
         ) c ON 1=1'
    );
    $stmt->bind_param('ii', $candidateId, $candidateId);
    $stmt->execute();
    $combinedRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $resultRow = ($combinedRow && $combinedRow['result_id'] !== null) ? [
        'id' => $combinedRow['result_id'],
        'total_score' => $combinedRow['total_score'],
        'percentage' => $combinedRow['percentage'],
        'level_assigned' => $combinedRow['level_assigned'],
        'attempt_id' => $combinedRow['attempt_id'],
    ] : null;

    $certificateRow = ($combinedRow && $combinedRow['certificate_number'] !== null) ? [
        'certificate_number' => $combinedRow['certificate_number'],
        'level' => $combinedRow['certificate_level'],
        'issue_date' => $combinedRow['certificate_issue_date'],
    ] : null;

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
