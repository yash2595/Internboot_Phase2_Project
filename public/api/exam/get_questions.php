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
    $candidateId = require_candidate_auth($conn);

    /*
     * 2. Attempt ID
     */
    $attemptId = isset($_GET['attempt_id'])
        ? (int) $_GET['attempt_id']
        : 0;

    if ($attemptId <= 0) {
        send_json_response('error', 'Invalid attempt ID', null, 400);
    }

    /*
     * 3. Validate attempt ownership
     */
    $attemptSql = "
        SELECT
            id,
            candidate_id,
            assessment_id,
            status,
            start_time,
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

    /*
     * 4. Only in-progress attempts can load questions.
     */
    if ($attempt['status'] !== 'in_progress') {
        send_json_response('error', 'Questions are not available for this attempt', [
            'status' => $attempt['status']
        ], 403);
    }

    /*
     * 5. Server-side expiry check
     * Note: Initial start timing (start_time & end_time) is set exclusively
     * by start_exam.php after passing the scheduled date/window gate.
     */
    if (empty($attempt['end_time'])) {
        send_json_response('error', 'Attempt timing is not initialized', null, 500);
    }

    $now = new DateTime();
    $endTime = new DateTime($attempt['end_time']);

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

        require_once __DIR__ . '/../../../src/modules/m7_evaluation_admin/service.php';
        try {
            evaluate_attempt($conn, $attemptId, false);
        } catch (Throwable $evalError) {
            error_log('Auto-evaluation failed for attempt ' . $attemptId . ': ' . $evalError->getMessage());
        }

        send_json_response('error', 'Exam time has expired', [
            'status' => 'expired'
        ], 403);
    }


    /*
     * 6. Get assessment configuration
     */
    $assessmentSql = "
        SELECT
            id,
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
     * 7. Select deterministic randomized questions.
     *
     * Same attempt = same order after refresh.
     * Different attempt = different order.
     */
    $conn->begin_transaction();
    try {
        // Try to read an existing snapshot, locking it against concurrent builds.
        $snapshotSql = "
            SELECT q.id AS question_id, q.question_text, q.type, q.difficulty
            FROM attempt_questions aq
            JOIN questions q ON q.id = aq.question_id
            WHERE aq.attempt_id = ?
            ORDER BY aq.position ASC
            FOR UPDATE
        ";
        $snapStmt = $conn->prepare($snapshotSql);
        $snapStmt->bind_param("i", $attemptId);
        $snapStmt->execute();
        $fetchedQuestions = $snapStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $snapStmt->close();

        if (empty($fetchedQuestions)) {
            // First call for this attempt — build the snapshot once from the live pool.
            $poolSql = "
                SELECT q.id AS question_id, q.question_text, q.type, q.difficulty
                FROM questions q
                INNER JOIN question_banks qb ON qb.id = q.question_bank_id
                WHERE qb.assessment_id = ?
                  AND q.type = 'MCQ'
                  AND q.approval_status = 'approved'
                ORDER BY MD5(CONCAT(?, ':', q.id))
                LIMIT ?
            ";
            $poolStmt = $conn->prepare($poolSql);
            $poolStmt->bind_param("iii", $attempt['assessment_id'], $attemptId, $totalQuestions);
            $poolStmt->execute();
            $fetchedQuestions = $poolStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $poolStmt->close();

            $insertSql = "INSERT INTO attempt_questions (attempt_id, question_id, position) VALUES (?, ?, ?)";
            $insertStmt = $conn->prepare($insertSql);
            foreach ($fetchedQuestions as $pos => $q) {
                $position = $pos + 1;
                $qId = (int)$q['question_id'];
                $insertStmt->bind_param("iii", $attemptId, $qId, $position);
                $insertStmt->execute();
            }
            $insertStmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $questions = [];

    foreach ($fetchedQuestions as $question) {

        $questionId = (int) $question['question_id'];

        /*
         * 8. Get options.
         *
         * IMPORTANT:
         * is_correct is NEVER returned.
         */
        $optionSql = "
            SELECT
                id AS option_id,
                option_text
            FROM options
            WHERE question_id = ?
            ORDER BY MD5(CONCAT(?, ':', id))
        ";

        $optionStmt = $conn->prepare($optionSql);

        if (!$optionStmt) {
            throw new Exception('Failed to prepare option query');
        }

        $optionStmt->bind_param(
            "ii",
            $questionId,
            $attemptId
        );

        $optionStmt->execute();

        $optionResult = $optionStmt->get_result();

        $options = [];

        while ($option = $optionResult->fetch_assoc()) {

            $options[] = [
                'option_id' => (int) $option['option_id'],
                'option_text' => $option['option_text']
            ];
        }

        $optionStmt->close();

        /*
         * 9. Load previously saved answer.
         *
         * Only selected_option_id is returned.
         * is_correct remains server-side for M7.
         */
        $answerSql = "
            SELECT
                selected_option_id
            FROM answers
            WHERE attempt_id = ?
              AND question_id = ?
            LIMIT 1
        ";

        $answerStmt = $conn->prepare($answerSql);

        if (!$answerStmt) {
            throw new Exception('Failed to prepare answer query');
        }

        $answerStmt->bind_param(
            "ii",
            $attemptId,
            $questionId
        );

        $answerStmt->execute();

        $answerResult = $answerStmt->get_result();

        $savedAnswer = $answerResult->fetch_assoc();

        $answerStmt->close();

        $selectedOptionId = null;

        if (
            $savedAnswer &&
            $savedAnswer['selected_option_id'] !== null
        ) {
            $selectedOptionId = (int) $savedAnswer['selected_option_id'];
        }

        /*
         * 10. Build question response.
         */
        $questions[] = [
            'question_id' => $questionId,
            'question_text' => $question['question_text'],
            'type' => $question['type'],
            'difficulty' => $question['difficulty'],
            'options' => $options,
            'selected_option_id' => $selectedOptionId
        ];
    }

    /*
     * 11. Return questions using standardized helper.
     */
    send_json_response('success', 'Questions retrieved successfully', [
        'success' => true,
        'attempt_id' => $attemptId,
        'assessment_id' => (int) $attempt['assessment_id'],
        'total_questions' => count($questions),
        'questions' => $questions
    ], 200);

} catch (Throwable $e) {
    error_log('get_questions error: ' . $e->getMessage());
    send_json_response('error', 'Internal server error', null, 500);
}