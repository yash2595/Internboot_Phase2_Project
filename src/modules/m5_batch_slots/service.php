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
 * Calculates the next available Saturday and Sunday dates for exam scheduling.
 */
function calculate_next_weekend_dates(?string $fromDate = null): array {
    $baseTime = $fromDate ? strtotime($fromDate) : time();
    $dayOfWeek = (int)date('w', $baseTime); // 0 = Sunday, 6 = Saturday

    if ($dayOfWeek === 6) {
        // Today is Saturday; schedule for next weekend to provide proper prep time
        $nextSat = date('Y-m-d', strtotime('+7 days', $baseTime));
        $nextSun = date('Y-m-d', strtotime('+8 days', $baseTime));
    } elseif ($dayOfWeek === 0) {
        // Today is Sunday; schedule for next weekend
        $nextSat = date('Y-m-d', strtotime('next Saturday', $baseTime));
        $nextSun = date('Y-m-d', strtotime('+7 days', $baseTime));
    } else {
        // Monday through Friday: schedule for the upcoming weekend
        $nextSat = date('Y-m-d', strtotime('next Saturday', $baseTime));
        $nextSun = date('Y-m-d', strtotime('next Sunday', $baseTime));
    }

    return [
        'saturday' => $nextSat,
        'sunday' => $nextSun
    ];
}

/**
 * Checks eligible unbatched candidates for an assessment.
 * If eligible count meets or exceeds the threshold, auto-creates a new batch,
 * generates weekend exam schedules, and provisions exam time slots.
 *
 * @param int $assessmentId Assessment ID
 * @param mysqli $conn Database connection
 * @param int|null $customThreshold Optional override for demo testing
 * @return array|null Batch details array if batch formed, null if threshold not reached
 */
function check_and_create_batch(int $assessmentId, mysqli $conn, ?int $customThreshold = null): ?array {
    $threshold = get_batch_threshold($conn, $customThreshold);

    // Initial check of unbatched eligible candidates count
    $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);
    if ($eligibleCount < $threshold) {
        return null; // Not enough eligible candidates to form a batch
    }

    // Begin atomic transaction to prevent race conditions during batch formation
    $conn->begin_transaction();

    try {
        // Lock and fetch candidate enrollments meeting the threshold
        $candidates = get_unbatched_eligible_enrollments($assessmentId, $threshold, $conn);
        if (count($candidates) < $threshold) {
            $conn->rollback();
            return null;
        }

        $enrollmentIds = array_column($candidates, 'id');

        // Generate unique batch identifier
        $uniqueSuffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $batchNumber = sprintf("BATCH-A%d-%s-%s", $assessmentId, date('Ymd'), $uniqueSuffix);

        // Insert new batch record
        $batchId = insert_batch($batchNumber, $assessmentId, $conn);

        // Assign candidates to the newly created batch
        assign_batch_to_enrollments($batchId, $enrollmentIds, $conn);

        // Determine next weekend exam dates
        $weekends = calculate_next_weekend_dates();

        // Standard slot configuration: 2 slots per day, 50 seats each (total 100 per day)
        $slotTimings = [
            ['start' => '10:00:00', 'end' => '11:00:00', 'capacity' => 50],
            ['start' => '14:00:00', 'end' => '15:00:00', 'capacity' => 50],
        ];

        $createdSchedules = [];

        // 1. Saturday Schedule & Slots
        $satScheduleId = insert_exam_schedule($batchId, $weekends['saturday'], $conn);
        $satSlots = [];
        foreach ($slotTimings as $slot) {
            $slotId = insert_exam_slot($satScheduleId, $slot['start'], $slot['end'], $slot['capacity'], $conn);
            $satSlots[] = [
                'slot_id' => $slotId,
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'capacity' => $slot['capacity'],
                'seats_remaining' => $slot['capacity']
            ];
        }
        $createdSchedules[] = [
            'schedule_id' => $satScheduleId,
            'exam_date' => $weekends['saturday'],
            'day' => 'Saturday',
            'slots' => $satSlots
        ];

        // 2. Sunday Schedule & Slots
        $sunScheduleId = insert_exam_schedule($batchId, $weekends['sunday'], $conn);
        $sunSlots = [];
        foreach ($slotTimings as $slot) {
            $slotId = insert_exam_slot($sunScheduleId, $slot['start'], $slot['end'], $slot['capacity'], $conn);
            $sunSlots[] = [
                'slot_id' => $slotId,
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'capacity' => $slot['capacity'],
                'seats_remaining' => $slot['capacity']
            ];
        }
        $createdSchedules[] = [
            'schedule_id' => $sunScheduleId,
            'exam_date' => $weekends['sunday'],
            'day' => 'Sunday',
            'slots' => $sunSlots
        ];

        // Commit batch and scheduling transaction
        $conn->commit();

        $remainingEligible = max(0, $eligibleCount - count($enrollmentIds));

        return [
            'batch_id' => $batchId,
            'batch_number' => $batchNumber,
            'assessment_id' => $assessmentId,
            'assigned_candidates_count' => count($enrollmentIds),
            'threshold' => $threshold,
            'schedules' => $createdSchedules,
            'overflow_candidates_count' => $remainingEligible,
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
        if ($enrollment && !empty($enrollment['batch_id'])) {
            return get_available_slots_by_batch((int)$enrollment['batch_id'], $conn);
        }
    }

    return get_available_slots_by_assessment($assessmentId, $conn);
}
?>
