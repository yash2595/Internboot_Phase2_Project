<?php

// Load central bootstrap
if (file_exists(dirname(__DIR__, 3) . '/src/core/bootstrap.php')) {
    require_once dirname(__DIR__, 3) . '/src/core/bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../src/core/bootstrap.php')) {
    require_once __DIR__ . '/../../../src/core/bootstrap.php';
} else {
    require_once __DIR__ . '/../src/core/bootstrap.php';
}

try {

    if (
        !isset($_SESSION['candidate_id']) ||
        !is_numeric($_SESSION['candidate_id'])
    ) {
        send_json_response('error', 'Candidate authentication required', null, 401);
    }

    $candidateId = (int) $_SESSION['candidate_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_response('error', 'POST request required', null, 405);
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $attemptId = isset($input['attempt_id'])
        ? (int) $input['attempt_id']
        : 0;

    if ($attemptId <= 0) {
        send_json_response('error', 'Invalid attempt ID', null, 400);
    }

    /*
     * Retrieve and validate the attempt.
     */
    $sql = "
        SELECT
            id,
            candidate_id,
            assessment_id,
            exam_slot_id,
            status,
            start_time,
            end_time,
            submitted_at
        FROM attempts
        WHERE id = ?
          AND candidate_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $attemptId, $candidateId);
    $stmt->execute();

    $result = $stmt->get_result();
    $attempt = $result->fetch_assoc();

    if (!$attempt) {
        send_json_response('error', 'Attempt not found or access denied', null, 404);
    }

    /*
     * Prevent duplicate submission.
     */
    if (
        $attempt['status'] === 'submitted' ||
        $attempt['status'] === 'expired'
    ) {
        send_json_response('error', 'Attempt has already been closed', [
            'status' => $attempt['status']
        ], 409);
    }

    /*
     * Lock the attempt atomically.
     *
     * Only an in-progress attempt can be submitted.
     */
    $submitSql = "
        UPDATE attempts
        SET
            status = 'submitted',
            submitted_at = NOW()
        WHERE id = ?
          AND candidate_id = ?
          AND status = 'in_progress'
    ";

    $submitStmt = $conn->prepare($submitSql);

    $submitStmt->bind_param(
        "ii",
        $attemptId,
        $candidateId
    );

    $submitStmt->execute();

    /*
     * If no row was updated, another request may have
     * already submitted/expired the attempt.
     */
    if ($submitStmt->affected_rows !== 1) {
        send_json_response('error', 'Attempt could not be submitted', null, 409);
    }

    /*
     * Count answers for the final handoff to M7.
     *
     * M6 does NOT calculate score or level.
     */
    $answerSql = "
        SELECT COUNT(*) AS answered_count
        FROM answers
        WHERE attempt_id = ?
          AND selected_option_id IS NOT NULL
    ";

    $answerStmt = $conn->prepare($answerSql);
    $answerStmt->bind_param("i", $attemptId);
    $answerStmt->execute();

    $answerResult = $answerStmt->get_result();
    $answerData = $answerResult->fetch_assoc();

    $answeredCount = (int) $answerData['answered_count'];

    send_json_response('success', 'Exam submitted successfully', [
        'success' => true,
        'attempt_id' => $attemptId,
        'candidate_id' => $candidateId,
        'assessment_id' => (int) $attempt['assessment_id'],
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'answered_count' => $answeredCount,
        'evaluation_pending' => true
    ], 200);

} catch (Throwable $e) {
    send_json_response('error', 'Internal server error: ' . $e->getMessage(), null, 500);
}