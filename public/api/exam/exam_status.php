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
        (!isset($_SESSION['candidate_id']) || !is_numeric($_SESSION['candidate_id'])) &&
        isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) &&
        isset($conn)
    ) {
        $userStmt = $conn->prepare("SELECT id FROM candidates WHERE user_id = ? LIMIT 1");
        if ($userStmt) {
            $uId = (int)$_SESSION['user_id'];
            $userStmt->bind_param("i", $uId);
            $userStmt->execute();
            $userRes = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if ($userRes) {
                $_SESSION['candidate_id'] = (int)$userRes['id'];
            }
        }
    }

    if (
        !isset($_SESSION['candidate_id']) ||
        !is_numeric($_SESSION['candidate_id'])
    ) {
        send_json_response('error', 'Candidate authentication required', null, 401);
    }

    $candidateId = (int) $_SESSION['candidate_id'];

    $attemptId = isset($_GET['attempt_id'])
        ? (int) $_GET['attempt_id']
        : 0;

    if ($attemptId <= 0) {
        send_json_response('error', 'Invalid attempt ID', null, 400);
    }

    /*
     * Retrieve the attempt.
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
     * Calculate remaining time using SERVER time.
     */
    $remainingSeconds = 0;

    if (!empty($attempt['end_time'])) {

        $now = new DateTime();
        $endTime = new DateTime($attempt['end_time']);

        $remainingSeconds = max(
            0,
            $endTime->getTimestamp() - $now->getTimestamp()
        );
    }

    /*
     * Automatically expire an in-progress attempt
     * when server time reaches end_time.
     */
    if (
        $attempt['status'] === 'in_progress' &&
        $remainingSeconds <= 0
    ) {

        $expireSql = "
            UPDATE attempts
            SET
                status = 'expired',
                submitted_at = NOW()
            WHERE id = ?
              AND status = 'in_progress'
        ";

        $expireStmt = $conn->prepare($expireSql);
        $expireStmt->bind_param("i", $attemptId);
        $expireStmt->execute();

        $attempt['status'] = 'expired';
        $attempt['submitted_at'] = date('Y-m-d H:i:s');
        $remainingSeconds = 0;
    }

    /*
     * Count answers already saved.
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

    /*
     * Get total number of questions for the assessment.
     */
    $questionSql = "
        SELECT total_questions
        FROM assessments
        WHERE id = ?
        LIMIT 1
    ";

    $questionStmt = $conn->prepare($questionSql);
    $questionStmt->bind_param(
        "i",
        $attempt['assessment_id']
    );
    $questionStmt->execute();

    $questionResult = $questionStmt->get_result();
    $assessment = $questionResult->fetch_assoc();

    $totalQuestions = $assessment
        ? (int) $assessment['total_questions']
        : 0;

    send_json_response('success', 'Exam status retrieved', [
        'success' => true,
        'attempt_id' => (int) $attempt['id'],
        'candidate_id' => (int) $attempt['candidate_id'],
        'assessment_id' => (int) $attempt['assessment_id'],
        'exam_slot_id' => (int) $attempt['exam_slot_id'],
        'status' => $attempt['status'],
        'start_time' => $attempt['start_time'],
        'end_time' => $attempt['end_time'],
        'submitted_at' => $attempt['submitted_at'],
        'remaining_seconds' => $remainingSeconds,
        'answered_count' => $answeredCount,
        'total_questions' => $totalQuestions
    ], 200);

} catch (Throwable $e) {
    error_log('exam_status error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}