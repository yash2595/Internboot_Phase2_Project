<?php

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {

    if (
    !isset($_SESSION['candidate_id']) ||
    !is_numeric($_SESSION['candidate_id'])
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Candidate authentication required'
    ]);

    exit;
}

$candidateId = (int) $_SESSION['candidate_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'POST request required'
        ]);
        exit;
    }

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    $attemptId = isset($input['attempt_id'])
        ? (int) $input['attempt_id']
        : 0;

    if ($attemptId <= 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid attempt ID'
        ]);

        exit;
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
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Attempt not found or access denied'
        ]);

        exit;
    }

    /*
     * Prevent duplicate submission.
     */
    if (
        $attempt['status'] === 'submitted' ||
        $attempt['status'] === 'expired'
    ) {
        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Attempt has already been closed',
            'status' => $attempt['status']
        ]);

        exit;
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

        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Attempt could not be submitted'
        ]);

        exit;
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

    echo json_encode([
        'success' => true,
        'message' => 'Exam submitted successfully',
        'attempt_id' => $attemptId,
        'candidate_id' => $candidateId,
        'assessment_id' => (int) $attempt['assessment_id'],
        'status' => 'submitted',
        'submitted_at' => date('Y-m-d H:i:s'),
        'answered_count' => $answeredCount,
        'evaluation_pending' => true
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}