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

    /*
     * 1. Candidate authentication
     */
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

    /*
     * 2. Only POST is allowed.
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_response('error', 'POST request required', null, 405);
    }

    /*
     * 3. Read JSON body.
     */
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        send_json_response('error', 'Invalid JSON body', null, 400);
    }

    $attemptId = isset($input['attempt_id'])
        ? (int) $input['attempt_id']
        : 0;

    $questionId = isset($input['question_id'])
        ? (int) $input['question_id']
        : 0;

    $selectedOptionId = isset($input['selected_option_id'])
        ? (int) $input['selected_option_id']
        : 0;

    if (
        $attemptId <= 0 ||
        $questionId <= 0 ||
        $selectedOptionId <= 0
    ) {
        send_json_response('error', 'Invalid answer data', null, 400);
    }

    /*
     * 4. Verify attempt ownership and status.
     */
    $attemptSql = "
        SELECT
            id,
            assessment_id,
            status,
            end_time
        FROM attempts
        WHERE id = ?
          AND candidate_id = ?
        LIMIT 1
    ";

    $attemptStmt = $conn->prepare($attemptSql);

    if (!$attemptStmt) {
        throw new Exception('Failed to prepare attempt query');
    }

    $attemptStmt->bind_param(
        "ii",
        $attemptId,
        $candidateId
    );

    $attemptStmt->execute();

    $attemptResult = $attemptStmt->get_result();

    $attempt = $attemptResult->fetch_assoc();

    $attemptStmt->close();

    if (!$attempt) {
        send_json_response('error', 'Attempt not found or access denied', null, 404);
    }

    if ($attempt['status'] !== 'in_progress') {
        send_json_response('error', 'This attempt is no longer active', [
            'status' => $attempt['status']
        ], 403);
    }

    /*
     * 5. Server-side expiry check.
     */
    if (empty($attempt['end_time'])) {
        send_json_response('error', 'Attempt timing is not initialized', null, 500);
    }

    $now = new DateTime();

    $endTime = new DateTime(
        $attempt['end_time']
    );

    if ($now >= $endTime) {

        $expireSql = "
            UPDATE attempts
            SET
                status = 'expired',
                submitted_at = NOW()
            WHERE id = ?
              AND candidate_id = ?
              AND status = 'in_progress'
        ";

        $expireStmt = $conn->prepare($expireSql);

        if (!$expireStmt) {
            throw new Exception('Failed to prepare expiry update');
        }

        $expireStmt->bind_param(
            "ii",
            $attemptId,
            $candidateId
        );

        $expireStmt->execute();

        $expireStmt->close();

        send_json_response('error', 'Exam time has expired', [
            'status' => 'expired'
        ], 403);
    }

    /*
     * 6. Get the assessment's configured question count.
     */
    $assessmentSql = "
        SELECT
            total_questions
        FROM assessments
        WHERE id = ?
        LIMIT 1
    ";

    $assessmentStmt = $conn->prepare($assessmentSql);

    if (!$assessmentStmt) {
        throw new Exception('Failed to prepare assessment query');
    }

    $assessmentStmt->bind_param(
        "i",
        $attempt['assessment_id']
    );

    $assessmentStmt->execute();

    $assessmentResult = $assessmentStmt->get_result();

    $assessment = $assessmentResult->fetch_assoc();

    $assessmentStmt->close();

    $totalQuestions = ($assessment && !empty($assessment['total_questions']))
        ? (int) $assessment['total_questions']
        : 50;

    if ($totalQuestions <= 0) {
        $totalQuestions = 50;
    }

    /*
     * 7. IMPORTANT:
     *
     * Verify that the question is actually part of THIS
     * attempt's deterministic randomized question set.
     *
     * This uses exactly the same ordering algorithm as
     * get_questions.php.
     */
    $assignedQuestionSql = "
        SELECT
            q.id
        FROM questions q
        INNER JOIN question_banks qb
            ON qb.id = q.question_bank_id
        WHERE qb.assessment_id = ?
          AND q.type = 'MCQ'
          AND q.approval_status = 'approved'
        ORDER BY MD5(CONCAT(?, ':', q.id))
        LIMIT ?
    ";

    $assignedStmt = $conn->prepare(
        $assignedQuestionSql
    );

    if (!$assignedStmt) {
        throw new Exception(
            'Failed to prepare assigned question query'
        );
    }

    $assignedStmt->bind_param(
        "iii",
        $attempt['assessment_id'],
        $attemptId,
        $totalQuestions
    );

    $assignedStmt->execute();

    $assignedResult = $assignedStmt->get_result();

    $questionAssigned = false;

    while ($assignedQuestion = $assignedResult->fetch_assoc()) {

        if ((int) $assignedQuestion['id'] === $questionId) {
            $questionAssigned = true;
            break;
        }
    }

    $assignedStmt->close();

    if (!$questionAssigned) {
        send_json_response('error', 'Question is not assigned to this attempt', null, 400);
    }

    /*
     * 8. Verify selected option belongs to this question.
     *
     * is_correct is intentionally NOT selected.
     */
    $optionSql = "
        SELECT
            id
        FROM options
        WHERE id = ?
          AND question_id = ?
        LIMIT 1
    ";

    $optionStmt = $conn->prepare($optionSql);

    if (!$optionStmt) {
        throw new Exception('Failed to prepare option query');
    }

    $optionStmt->bind_param(
        "ii",
        $selectedOptionId,
        $questionId
    );

    $optionStmt->execute();

    $optionResult = $optionStmt->get_result();

    $validOption = $optionResult->fetch_assoc();

    $optionStmt->close();

    if (!$validOption) {
        send_json_response('error', 'Invalid option for this question', null, 400);
    }

    /*
     * 9. Insert or update the answer.
     *
     * M6 stores the selected option.
     * M7 handles correctness/evaluation.
     */
    $answerSql = "
        INSERT INTO answers
        (
            attempt_id,
            question_id,
            selected_option_id
        )
        VALUES
        (
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            selected_option_id = VALUES(selected_option_id),
            updated_at = CURRENT_TIMESTAMP
    ";

    $answerStmt = $conn->prepare($answerSql);

    if (!$answerStmt) {
        throw new Exception('Failed to prepare answer query');
    }

    $answerStmt->bind_param(
        "iii",
        $attemptId,
        $questionId,
        $selectedOptionId
    );

    $answerStmt->execute();

    $answerStmt->close();

    /*
     * 10. Success response using standardized helper.
     */
    send_json_response('success', 'Answer saved successfully', [
        'success' => true,
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'selected_option_id' => $selectedOptionId
    ], 200);

} catch (Throwable $e) {
    error_log('save_answer error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}