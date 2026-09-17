<?php

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {

    /*
     * 1. Candidate authentication
     */
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

    /*
     * 2. Only POST is allowed.
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'POST request required'
        ]);

        exit;
    }

    /*
     * 3. Read JSON body.
     */
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON body'
        ]);

        exit;
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

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid answer data'
        ]);

        exit;
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

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Attempt not found or access denied'
        ]);

        exit;
    }

    if ($attempt['status'] !== 'in_progress') {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'This attempt is no longer active',
            'status' => $attempt['status']
        ]);

        exit;
    }

    /*
     * 5. Server-side expiry check.
     */
    if (empty($attempt['end_time'])) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Attempt timing is not initialized'
        ]);

        exit;
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

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Exam time has expired',
            'status' => 'expired'
        ]);

        exit;
    }

    /*
     * 6. Get the assessment's configured question count.
     */
    $assessmentSql = "
        SELECT
            total_questions
        FROM assessments
        WHERE id = ?
          AND status = 'active'
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

    if (!$assessment) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Assessment not found'
        ]);

        exit;
    }

    $totalQuestions = (int) $assessment['total_questions'];

    if ($totalQuestions <= 0) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid assessment question configuration'
        ]);

        exit;
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
          AND qb.status = 'approved'
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

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Question is not assigned to this attempt'
        ]);

        exit;
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

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid option for this question'
        ]);

        exit;
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
     * 10. Success response.
     */
    echo json_encode([
        'success' => true,
        'message' => 'Answer saved successfully',
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'selected_option_id' => $selectedOptionId
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}