<?php

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {

    /*
     * 1. Candidate authentication
     * M5 shares candidate_id through the PHP session.
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
     * 2. Attempt ID supplied by M5
     */
    $attemptId = isset($_GET['attempt_id'])
        ? (int) $_GET['attempt_id']
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
     * 3. Fetch attempt + official slot timing + assessment duration.
     *
     * Ownership is checked using:
     *     a.id = ?
     *     a.candidate_id = ?
     *
     * This prevents IDOR/tampering.
     */
    $sql = "
        SELECT
            a.id AS attempt_id,
            a.candidate_id,
            a.assessment_id,
            a.exam_slot_id,
            a.status,
            a.start_time,
            a.end_time,
            a.submitted_at,

            es.start_time AS slot_start_time,
            es.end_time AS slot_end_time,

            sch.exam_date,
            sch.status AS schedule_status,

            ass.duration_minutes,
            ass.total_questions

        FROM attempts a

        INNER JOIN exam_slots es
            ON es.id = a.exam_slot_id

        INNER JOIN exam_schedules sch
            ON sch.id = es.exam_schedule_id

        INNER JOIN assessments ass
            ON ass.id = a.assessment_id

        WHERE a.id = ?
          AND a.candidate_id = ?

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Failed to prepare attempt query');
    }

    $stmt->bind_param(
        "ii",
        $attemptId,
        $candidateId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $attempt = $result->fetch_assoc();

    $stmt->close();

    /*
     * 4. Attempt must exist and belong to logged-in candidate.
     */
    if (!$attempt) {

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Attempt not found or access denied'
        ]);

        exit;
    }

    /*
     * 5. Completed attempts cannot be restarted.
     */
    if (
        $attempt['status'] === 'submitted' ||
        $attempt['status'] === 'expired'
    ) {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'This attempt is no longer available',
            'status' => $attempt['status']
        ]);

        exit;
    }

    /*
     * 6. If attempt has not started yet,
     *    M6 starts it using SERVER time.
     *
     *    M5's integration document explicitly specifies
     *    server NOW() as the start time.
     */
    if (empty($attempt['start_time'])) {

        $startTime = date('Y-m-d H:i:s');

        /*
         * Assessment duration comes from the assessment.
         * Current M6 requirement = 60 minutes.
         */
        $durationMinutes = (int) $attempt['duration_minutes'];

        if ($durationMinutes <= 0) {
            $durationMinutes = 60;
        }

        $endTime = date(
            'Y-m-d H:i:s',
            strtotime(
                $startTime . " +{$durationMinutes} minutes"
            )
        );

        /*
         * Atomically initialize the attempt.
         */
        $updateSql = "
            UPDATE attempts
            SET
                status = 'in_progress',
                start_time = ?,
                end_time = ?
            WHERE id = ?
              AND candidate_id = ?
              AND status = 'in_progress'
              AND start_time IS NULL
        ";

        $updateStmt = $conn->prepare($updateSql);

        if (!$updateStmt) {
            throw new Exception('Failed to prepare attempt update');
        }

        $updateStmt->bind_param(
            "ssii",
            $startTime,
            $endTime,
            $attemptId,
            $candidateId
        );

        $updateStmt->execute();

        /*
         * Re-read the values that were just committed.
         */
        $attempt['start_time'] = $startTime;
        $attempt['end_time'] = $endTime;

        $updateStmt->close();
    }

    /*
     * 7. Calculate remaining time using SERVER time.
     */
    $now = new DateTime();

    $end = new DateTime(
        $attempt['end_time']
    );

    $remainingSeconds = max(
        0,
        $end->getTimestamp() - $now->getTimestamp()
    );

    /*
     * 8. Automatically expire if time has ended.
     */
    if ($remainingSeconds <= 0) {

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
     * 9. Successful response.
     */
    echo json_encode([
        'success' => true,
        'message' => 'Exam started successfully',

        'attempt_id' => (int) $attempt['attempt_id'],
        'candidate_id' => (int) $attempt['candidate_id'],
        'assessment_id' => (int) $attempt['assessment_id'],
        'exam_slot_id' => (int) $attempt['exam_slot_id'],

        'status' => $attempt['status'],

        'start_time' => $attempt['start_time'],
        'end_time' => $attempt['end_time'],

        'slot_start_time' => $attempt['slot_start_time'],
        'slot_end_time' => $attempt['slot_end_time'],
        'exam_date' => $attempt['exam_date'],

        'duration_minutes' => (int) $attempt['duration_minutes'],
        'total_questions' => (int) $attempt['total_questions'],

        'remaining_seconds' => $remainingSeconds
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}