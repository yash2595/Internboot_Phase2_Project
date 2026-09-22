<?php
// Path: src/modules/m5_batch_slots/service.php

require_once __DIR__ . '/queries.php';

/**
 * Resolves the batch threshold from settings table or fallback default (100).
 * Supports custom override for testing / sandbox demo flows.
 */
function get_batch_threshold(mysqli $conn, ?int $customThreshold = null): int {
    if ($customThreshold !== null && $customThreshold > 0) {
        return $customThreshold;
    }

    try {
        $settingValue = get_setting_value('batch_threshold', $conn);
        if ($settingValue !== null && is_numeric($settingValue) && (int)$settingValue > 0) {
            return (int)$settingValue;
        }
    } catch (Throwable $e) {
        // Fallback gracefully to default if setting table is temporarily unpopulated
    }

    return 100;
}

/**
 * Calculates the next available Saturday and Sunday dates for exam scheduling,
 * enforcing a consistent minimum lead time (default 3 days) so candidates have
 * adequate time to prepare and book slots regardless of which day the batch forms.
 */
function calculate_next_weekend_dates(?string $fromDate = null, int $minLeadDays = 3): array {
    $baseTime = $fromDate ? strtotime($fromDate) : time();
    $todayMidnight = strtotime(date('Y-m-d 00:00:00', $baseTime));

    // Find next occurring Saturday
    $candidateSat = strtotime('next Saturday', $todayMidnight);
    if ((int)date('w', $todayMidnight) === 6) {
        $candidateSat = $todayMidnight;
    }

    // Days difference between today and candidate Saturday
    $diffDays = (int)round(($candidateSat - $todayMidnight) / 86400);
    if ($diffDays < $minLeadDays) {
        // Insufficient lead time (e.g., booking on Thu/Fri/Sat); schedule for the subsequent weekend
        $candidateSat = strtotime('+7 days', $candidateSat);
    }
    $candidateSun = strtotime('+1 day', $candidateSat);

    return [
        'saturday' => date('Y-m-d', $candidateSat),
        'sunday' => date('Y-m-d', $candidateSun)
    ];
}

/**
 * Registration cutoff validation:
 * - Saturday exams: open until Friday 11:59:59 PM (day before)
 * - Sunday exams: open until Saturday 11:59:59 PM (day before)
 */
function is_registration_open_for_date(string $examDate): bool {
    $ts = strtotime($examDate . ' 00:00:00');
    if ($ts === false) return false;
    $dayOfWeek = (int)date('N', $ts); // 6 = Saturday, 7 = Sunday
    if ($dayOfWeek !== 6 && $dayOfWeek !== 7) return false;

    // Cutoff is day before at 23:59:59
    $cutoff = strtotime($examDate . ' -1 day 23:59:59');
    return time() <= $cutoff;
}

/**
 * Returns upcoming weekend dates & slots with open registration windows.
 */
function get_next_available_weekend_preferences(): array {
    $options = [];
    $timeSlots = [
        '10:00:00-11:00:00' => 'Morning (10:00 AM – 11:00 AM)',
        '14:00:00-15:00:00' => 'Afternoon (02:00 PM – 03:00 PM)',
    ];

    for ($w = 0; $w < 4; $w++) {
        $satTs = strtotime("next Saturday +{$w} week");
        if ((int)date('w') === 6 && $w === 0) {
            $satTs = strtotime("today");
        }
        $sunTs = strtotime("+1 day", $satTs);

        $satDate = date('Y-m-d', $satTs);
        $sunDate = date('Y-m-d', $sunTs);

        if (is_registration_open_for_date($satDate)) {
            foreach ($timeSlots as $tsVal => $tsLabel) {
                $options[] = [
                    'date' => $satDate,
                    'day' => 'Saturday',
                    'formatted_date' => date('l, M j, Y', $satTs),
                    'time_slot' => $tsVal,
                    'time_label' => $tsLabel
                ];
            }
        }

        if (is_registration_open_for_date($sunDate)) {
            foreach ($timeSlots as $tsVal => $tsLabel) {
                $options[] = [
                    'date' => $sunDate,
                    'day' => 'Sunday',
                    'formatted_date' => date('l, M j, Y', $sunTs),
                    'time_slot' => $tsVal,
                    'time_label' => $tsLabel
                ];
            }
        }

        if (count($options) >= 4) break;
    }

    return $options;
}

/**
 * Rollovers unbatched candidates whose preference cutoff has passed without forming a batch
 * to the same weekend day and time slot for the following week.
 */
function rollover_expired_unbatched_preferences(mysqli $conn): int {
    $stmt = $conn->prepare("
        SELECT id, preferred_date, preferred_time_slot
        FROM enrollments
        WHERE eligibility_status = 'eligible' AND batch_id IS NULL AND preferred_date IS NOT NULL
    ");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rolledOver = 0;
    foreach ($rows as $r) {
        if (!is_registration_open_for_date($r['preferred_date'])) {
            $newDate = date('Y-m-d', strtotime($r['preferred_date'] . ' +7 days'));
            $u = $conn->prepare("UPDATE enrollments SET preferred_date = ?, updated_at = NOW() WHERE id = ?");
            $u->bind_param('si', $newDate, $r['id']);
            $u->execute();
            $u->close();
            $rolledOver++;
        }
    }

    return $rolledOver;
}

/**
 * Automated batching trigger:
 * Checks unbatched eligible candidates grouped by (preferred_date, preferred_time_slot).
 * When count reaches threshold (min 100), creates the batch with ONE unified slot and bulk-assigns them.
 */
function process_automated_preference_batching(int $assessmentId, mysqli $conn, ?int $customThreshold = null): array {
    $threshold = get_batch_threshold($conn, $customThreshold);
    rollover_expired_unbatched_preferences($conn);

    $sql = "
        SELECT preferred_date, preferred_time_slot, COUNT(*) as cnt
        FROM enrollments
        WHERE assessment_id = ? AND eligibility_status = 'eligible' AND batch_id IS NULL
          AND preferred_date IS NOT NULL AND preferred_time_slot IS NOT NULL
        GROUP BY preferred_date, preferred_time_slot
        HAVING cnt >= ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $assessmentId, $threshold);
    $stmt->execute();
    $eligibleGroups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $batchesCreated = [];

    foreach ($eligibleGroups as $group) {
        $prefDate = $group['preferred_date'];
        $prefSlot = $group['preferred_time_slot'];
        $candidateCount = (int)$group['cnt'];

        $parts = explode('-', $prefSlot);
        $startTime = trim($parts[0]);
        if (strlen($startTime) === 5) $startTime .= ':00';
        $endTime = isset($parts[1]) ? trim($parts[1]) : date('H:i:s', strtotime($startTime . ' +1 hour'));
        if (strlen($endTime) === 5) $endTime .= ':00';

        $conn->begin_transaction();
        try {
            $fStmt = $conn->prepare("
                SELECT id, candidate_id
                FROM enrollments
                WHERE assessment_id = ? AND eligibility_status = 'eligible' AND batch_id IS NULL
                  AND preferred_date = ? AND preferred_time_slot = ?
                ORDER BY id ASC
                LIMIT ?
                FOR UPDATE
            ");
            $fStmt->bind_param('issi', $assessmentId, $prefDate, $prefSlot, $candidateCount);
            $fStmt->execute();
            $candidates = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $fStmt->close();

            if (count($candidates) < $threshold) {
                $conn->rollback();
                continue;
            }

            $enrollmentIds = array_column($candidates, 'id');
            $uniqueSuffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $batchNumber = sprintf("BATCH-A%d-%s-%s", $assessmentId, date('Ymd', strtotime($prefDate)), $uniqueSuffix);

            $batchId = insert_batch($batchNumber, $assessmentId, $conn);
            assign_batch_to_enrollments($batchId, $enrollmentIds, $conn);
            $scheduleId = insert_exam_schedule($batchId, $prefDate, $conn);

            // Exactly ONE unified slot with dynamic capacity
            $capacity = max($threshold, count($candidates));
            $slotId = insert_exam_slot($scheduleId, $startTime, $endTime, $capacity, $conn);

            // Create or update attempts for assigned candidates
            $selAtt = $conn->prepare("SELECT id FROM attempts WHERE candidate_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
            $updAtt = $conn->prepare("UPDATE attempts SET exam_slot_id = ?, updated_at = NOW() WHERE id = ?");
            $insAtt = $conn->prepare("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, created_at) VALUES (?, ?, ?, 'in_progress', NOW())");
            foreach ($candidates as $c) {
                $cid = (int)$c['candidate_id'];
                $selAtt->bind_param('i', $cid);
                $selAtt->execute();
                $exAtt = $selAtt->get_result()->fetch_assoc();
                if ($exAtt) {
                    $updAtt->bind_param('ii', $slotId, $exAtt['id']);
                    $updAtt->execute();
                } else {
                    $insAtt->bind_param('iii', $cid, $assessmentId, $slotId);
                    $insAtt->execute();
                }
            }
            $selAtt->close();
            $updAtt->close();
            $insAtt->close();

            $conn->commit();

            $batchesCreated[] = [
                'batch_id' => $batchId,
                'batch_number' => $batchNumber,
                'assessment_id' => $assessmentId,
                'exam_date' => $prefDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'slot_id' => $slotId,
                'assigned_count' => count($candidates),
                'capacity' => $capacity,
                'status' => 'Batch Confirmed — Still Filling'
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Preference auto-batch error: " . $e->getMessage());
        }
    }

    return $batchesCreated;
}

/**
 * Checks eligible unbatched candidates for an assessment.
 * Creates a batch with ONE unified slot once threshold (>= 100) is reached.
 */
function check_and_create_batch(int $assessmentId, mysqli $conn, ?int $customThreshold = null): ?array {
    // 1. First trigger preference-based batching
    $prefBatches = process_automated_preference_batching($assessmentId, $conn, $customThreshold);
    if (!empty($prefBatches)) {
        return $prefBatches[0];
    }

    $threshold = get_batch_threshold($conn, $customThreshold);
    $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);
    if ($eligibleCount < $threshold) {
        return null;
    }

    $conn->begin_transaction();
    try {
        $candidates = get_unbatched_eligible_enrollments($assessmentId, $threshold, $conn);
        if (count($candidates) < $threshold) {
            $conn->rollback();
            return null;
        }

        $enrollmentIds = array_column($candidates, 'id');
        $uniqueSuffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $batchNumber = sprintf("BATCH-A%d-%s-%s", $assessmentId, date('Ymd'), $uniqueSuffix);
        $batchId = insert_batch($batchNumber, $assessmentId, $conn);
        assign_batch_to_enrollments($batchId, $enrollmentIds, $conn);

        $weekends = calculate_next_weekend_dates();
        $chosenDate = $weekends['saturday'];
        $scheduleId = insert_exam_schedule($batchId, $chosenDate, $conn);

        // 1 Batch = 1 Unified Slot (10:00 - 11:00, full capacity >= threshold)
        $capacity = max($threshold, count($enrollmentIds));
        $startTime = '10:00:00';
        $endTime = '11:00:00';
        $slotId = insert_exam_slot($scheduleId, $startTime, $endTime, $capacity, $conn);

        $insAtt = $conn->prepare("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, created_at) VALUES (?, ?, ?, 'in_progress', NOW())");
        foreach ($candidates as $c) {
            $cid = (int)$c['candidate_id'];
            $insAtt->bind_param('iii', $cid, $assessmentId, $slotId);
            $insAtt->execute();
        }
        $insAtt->close();

        $conn->commit();

        return [
            'batch_id' => $batchId,
            'batch_number' => $batchNumber,
            'assessment_id' => $assessmentId,
            'assigned_candidates_count' => count($enrollmentIds),
            'threshold' => $threshold,
            'schedules' => [
                [
                    'schedule_id' => $scheduleId,
                    'exam_date' => $chosenDate,
                    'day' => 'Saturday',
                    'slots' => [
                        [
                            'slot_id' => $slotId,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'capacity' => $capacity,
                            'seats_remaining' => 0
                        ]
                    ]
                ]
            ],
            'overflow_candidates_count' => max(0, $eligibleCount - count($enrollmentIds)),
            'overflow_policy' => 'FIFO queue: remaining candidates wait for subsequent registrations to reach batch threshold.'
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw new Exception("Batch auto-creation failed: " . $e->getMessage());
    }
}

/**
 * Processes all eligible candidates for an assessment, auto-creating as many
 * full batches as the eligible candidate count allows (e.g. 210 candidates -> 2 batches).
 * Any remaining candidates (< threshold) remain in the FIFO queue.
 */
function create_all_eligible_batches(int $assessmentId, mysqli $conn, ?int $customThreshold = null): array {
    $batches = [];
    while (true) {
        $batch = check_and_create_batch($assessmentId, $conn, $customThreshold);
        if ($batch === null) {
            break;
        }
        $batches[] = $batch;
    }
    return $batches;
}

/**
 * Books an exam slot for an eligible candidate with query-level concurrency protection.
 *
 * Anti-race-condition guarantees:
 * 1. Checks candidate does not already hold a slot/attempt for the assessment.
 * 2. Locks candidate enrollment (FOR UPDATE) inside the transaction to serialize concurrent requests.
 * 3. Double-checks attempt table (FOR UPDATE) inside the transaction to guard against parallel attempts across different slots.
 * 4. Decrements capacity atomically in the database query (WHERE seats_remaining > 0).
 * 5. Enclosed in an ACID database transaction.
 *
 * @param int $candidateId Candidate profile ID
 * @param int $assessmentId Assessment configuration ID
 * @param int $examSlotId Desired exam slot ID
 * @param mysqli $conn Database connection
 * @return array Standardized booking response data payload
 */
function book_exam_slot(int $candidateId, int $assessmentId, int $examSlotId, mysqli $conn): array {
    // 1. Initial Validation: Candidate Enrollment & Eligibility
    $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
    if (!$enrollment) {
        throw new Exception("Candidate is not enrolled in the specified assessment");
    }

    if ($enrollment['eligibility_status'] !== 'eligible') {
        throw new Exception("Candidate is not eligible to book an exam slot (Status: " . $enrollment['eligibility_status'] . ")");
    }

    if (empty($enrollment['batch_id'])) {
        throw new Exception("Candidate has not yet been assigned to an exam batch. Awaiting batch formation.");
    }

    $candidateBatchId = (int)$enrollment['batch_id'];

    // 2. Initial Pre-flight Check: Prevent duplicate attempt
    $existingAttempt = get_candidate_booked_attempt($candidateId, $assessmentId, $conn);
    if ($existingAttempt) {
        throw new Exception("Candidate already has a booked slot for this assessment (Attempt ID: " . $existingAttempt['id'] . ")");
    }

    // 3. Validate Exam Slot details and Batch ownership
    $slot = get_slot_details($examSlotId, $conn);
    if (!$slot) {
        throw new Exception("Exam slot not found");
    }

    if ((int)$slot['assessment_id'] !== $assessmentId) {
        throw new Exception("Exam slot does not belong to the specified assessment");
    }

    if ((int)$slot['batch_id'] !== $candidateBatchId) {
        throw new Exception("Exam slot belongs to Batch #" . $slot['batch_id'] . ", but candidate is assigned to Batch #" . $candidateBatchId);
    }

    if ($slot['schedule_status'] !== 'scheduled') {
        throw new Exception("Exam schedule is no longer open for booking (Status: " . $slot['schedule_status'] . ")");
    }

    $currentDate = date('Y-m-d');
    if (strtotime($slot['exam_date']) < strtotime($currentDate)) {
        throw new Exception("Exam slot date is in the past and cannot be booked");
    }

    if (!is_registration_open_for_date($slot['exam_date'])) {
        throw new Exception("Registration for this exam slot has closed (Cutoff is 11:59:59 PM the day prior)");
    }

    if ($slot['exam_date'] === $currentDate && strtotime($slot['end_time']) <= time()) {
        throw new Exception("Exam slot time has already passed today and cannot be booked");
    }

    if ((int)$slot['seats_remaining'] <= 0) {
        throw new Exception("Selected exam slot is fully booked. No seats remaining.");
    }

    // 4. Begin Database Transaction for atomic checks, seat decrement & attempt creation
    $conn->begin_transaction();

    try {
        // Concurrency Guard 1: Acquire exclusive row lock on candidate's enrollment
        // Serializes concurrent booking requests for the same candidate and assessment
        $lockedEnrollment = get_candidate_enrollment_for_update($candidateId, $assessmentId, $conn);
        if (!$lockedEnrollment) {
            throw new Exception("Candidate is not enrolled in the specified assessment");
        }

        // Concurrency Guard 2: In-transaction check for any attempt already created for this assessment
        // Crucial fix: prevents parallel race condition if two requests target different slots for the same candidate
        $existingAttemptInTx = get_candidate_booked_attempt_for_update($candidateId, $assessmentId, $conn);
        if ($existingAttemptInTx) {
            throw new Exception("Candidate already has a booked slot for this assessment (Attempt ID: " . $existingAttemptInTx['id'] . ")");
        }

        // Concurrency Guard 3: Query-level concurrency check - atomically decrement only if seats_remaining > 0
        $affectedRows = decrement_slot_capacity($examSlotId, $conn);
        if ($affectedRows === 0) {
            throw new Exception("Selected exam slot is fully booked. No seats remaining.");
        }

        // Insert new exam attempt record
        $attemptId = insert_attempt($candidateId, $assessmentId, $examSlotId, $conn);

        // Commit transaction
        $conn->commit();

        // Fetch refreshed slot information for verified remaining seats count
        $refreshedSlot = get_slot_details($examSlotId, $conn);
        $remainingSeats = $refreshedSlot ? (int)$refreshedSlot['seats_remaining'] : ((int)$slot['seats_remaining'] - 1);

        return [
            'attempt_id' => $attemptId,
            'candidate_id' => $candidateId,
            'exam_slot_id' => $examSlotId,
            'exam_date' => $slot['exam_date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'seats_remaining' => $remainingSeats
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Fetches available slots for a candidate or an entire assessment.
 */
function fetch_available_slots(int $assessmentId, ?int $candidateId, mysqli $conn): array {
    if ($candidateId !== null && $candidateId > 0) {
        $enrollment = get_candidate_enrollment($candidateId, $assessmentId, $conn);
        if (!$enrollment || empty($enrollment['batch_id'])) {
            return [];
        }
        return get_available_slots_by_batch((int)$enrollment['batch_id'], $conn);
    }

    return get_available_slots_by_assessment($assessmentId, $conn);
}

/**
 * Cancels a candidate's booked slot attempt and restores slot capacity.
 * Restricted strictly to attempts that were booked but NEVER started (start_time IS NULL).
 */
function cancel_slot_booking(int $candidateId, int $assessmentId, mysqli $conn): bool {
    $conn->begin_transaction();
    try {
        $attempt = get_candidate_latest_attempt_for_update($candidateId, $assessmentId, $conn);
        if (!$attempt) {
            $conn->rollback();
            throw new Exception("No active booking found to cancel");
        }

        // If the exam has already started or completed, it cannot be cancelled
        if (!empty($attempt['start_time']) || $attempt['status'] !== 'in_progress') {
            $conn->rollback();
            throw new Exception("This exam has already started and cannot be cancelled.");
        }

        $slotId = (int)$attempt['exam_slot_id'];
        $attemptId = (int)$attempt['id'];

        // Prepared statement for updating slot seats_remaining
        $updateSlotStmt = $conn->prepare("UPDATE exam_slots SET seats_remaining = seats_remaining + 1 WHERE id = ?");
        if (!$updateSlotStmt) {
            throw new Exception("Failed to prepare slot capacity update query: " . $conn->error);
        }
        $updateSlotStmt->bind_param("i", $slotId);
        $updateSlotStmt->execute();
        $updateSlotStmt->close();

        // Prepared statement for deleting attempt
        $deleteAttemptStmt = $conn->prepare("DELETE FROM attempts WHERE id = ?");
        if (!$deleteAttemptStmt) {
            throw new Exception("Failed to prepare attempt deletion query: " . $conn->error);
        }
        $deleteAttemptStmt->bind_param("i", $attemptId);
        $deleteAttemptStmt->execute();
        $deleteAttemptStmt->close();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
