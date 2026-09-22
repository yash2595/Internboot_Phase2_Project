<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Use POST for payment operations.'], 405);
}

$input = request_body();
$action = $input['action'] ?? '';

if (!in_array($action, ['create', 'verify'], true)) {
    json_response(['success' => false, 'message' => 'Supported actions: create, verify.'], 400);
}

$candidateId = resolve_candidate_id($input);

if ($action === 'create') {
    $assessmentId = isset($input['assessment_id']) && ctype_digit((string)$input['assessment_id'])
        ? (int)$input['assessment_id'] : 0;

    if ($assessmentId <= 0) {
        json_response(['success' => false, 'message' => 'assessment_id is required.'], 422);
    }

    $assessment = get_assessment($assessmentId);
    if (!$assessment) {
        json_response(['success' => false, 'message' => 'Assessment not found.'], 404);
    }

    $amount = isset($input['amount']) && is_numeric($input['amount'])
        ? (float)$input['amount'] : 0.0;

    if ($amount <= 0) {
        /* Use the configured assessment fee when available. */
        $setting = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'exam_fee' LIMIT 1")->fetch_assoc();
        $amount = $setting ? (float)$setting['setting_value'] : 2999.0;
    }

    $reference = 'M4-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));

    $stmt = $conn->prepare(
        'INSERT INTO payments (candidate_id, assessment_id, amount, status, reference_number)
         VALUES (?, ?, ?, "pending", ?)'
    );
    $stmt->bind_param('iids', $candidateId, $assessmentId, $amount, $reference);
    $stmt->execute();
    $paymentId = $stmt->insert_id;
    $stmt->close();

    json_response([
        'success' => true,
        'message' => 'Sandbox payment created. Verification is required before enrollment.',
        'payment' => [
            'id' => $paymentId,
            'payment_id' => 'PAY-' . $paymentId,
            'reference_number' => $reference,
            'amount' => $amount,
            'status' => 'pending'
        ]
    ], 201);
}

/* Server-side verification: never trust a browser-supplied "success" flag. */
$paymentId = isset($input['payment_id']) && ctype_digit((string)$input['payment_id'])
    ? (int)$input['payment_id'] : 0;

if ($paymentId <= 0) {
    json_response(['success' => false, 'message' => 'payment_id is required.'], 422);
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        'SELECT id, candidate_id, assessment_id, amount, status, reference_number
         FROM payments WHERE id = ? AND candidate_id = ? FOR UPDATE'
    );
    $stmt->bind_param('ii', $paymentId, $candidateId);
    $stmt->execute();
    $paymentRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$paymentRow) {
        throw new RuntimeException('Payment not found for this candidate.');
    }

    if ((float)$paymentRow['amount'] <= 0) {
        throw new RuntimeException('Invalid payment amount.');
    }

    if ($paymentRow['status'] === 'failed') {
        throw new RuntimeException('This payment has failed and cannot be verified.');
    }

    /* Demo gateway verification happens here. In production this block is
       replaced by the gateway's server-to-server status/signature check. */
    $stmt = $conn->prepare(
        'UPDATE payments
         SET status = "success", payment_date = NOW()
         WHERE id = ? AND candidate_id = ? AND status = "pending"'
    );
    $stmt->bind_param('ii', $paymentId, $candidateId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    /* Idempotent: an already-successful payment can still complete enrollment. */
    $stmt = $conn->prepare(
        'SELECT id FROM enrollments WHERE candidate_id = ? AND assessment_id = ? LIMIT 1'
    );
    $assessmentId = (int)$paymentRow['assessment_id'];
    $stmt->bind_param('ii', $candidateId, $assessmentId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $enrollmentId = (int)$existing['id'];
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO enrollments (candidate_id, assessment_id, payment_id, eligibility_status)
             VALUES (?, ?, ?, "eligible")'
        );
        $stmt->bind_param('iii', $candidateId, $assessmentId, $paymentId);
        $stmt->execute();
        $enrollmentId = $stmt->insert_id;
        $stmt->close();
    }

    $conn->commit();

    json_response([
        'success' => true,
        'message' => 'Payment verified server-side and enrollment is ready.',
        'payment' => [
            'id' => $paymentId,
            'reference_number' => $paymentRow['reference_number'],
            'status' => 'success'
        ],
        'enrollment' => [
            'id' => $enrollmentId,
            'status' => 'eligible'
        ]
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 400);
}
