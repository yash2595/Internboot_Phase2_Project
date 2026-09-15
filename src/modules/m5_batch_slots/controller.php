<?php
// Path: src/modules/m5_batch_slots/controller.php

require_once __DIR__ . '/service.php';

/**
 * Controller handler for reserving an exam slot for an eligible candidate.
 * Matches API Contract: POST /api/slots/book.php
 */
function handle_book_slot_request(array $input, mysqli $conn): void {
    $candidateId = isset($input['candidate_id']) ? (int)$input['candidate_id'] : 0;
    $assessmentId = isset($input['assessment_id']) ? (int)$input['assessment_id'] : 0;
    $examSlotId = isset($input['exam_slot_id']) ? (int)$input['exam_slot_id'] : 0;

    // Validation checks
    if ($candidateId <= 0) {
        send_json_response('error', 'A valid candidate_id is required', null, 400);
        return;
    }

    if ($assessmentId <= 0) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }

    if ($examSlotId <= 0) {
        send_json_response('error', 'A valid exam_slot_id is required', null, 400);
        return;
    }

    try {
        $bookingData = book_exam_slot($candidateId, $assessmentId, $examSlotId, $conn);
        send_json_response('success', 'Exam slot booked successfully', $bookingData, 200);
        return;
    } catch (Throwable $e) {
        send_json_response('error', $e->getMessage(), null, 400);
        return;
    }
}

/**
 * Controller handler for batch threshold check and automatic batch creation.
 * Matches API Contract: POST /api/slots/auto_batch.php
 */
function handle_auto_batch_request(array $input, mysqli $conn): void {
    $assessmentId = isset($input['assessment_id']) ? (int)$input['assessment_id'] : 0;
    $customThreshold = isset($input['threshold']) && is_numeric($input['threshold']) ? (int)$input['threshold'] : null;

    if ($assessmentId <= 0) {
        send_json_response('error', 'A valid assessment_id is required', null, 400);
        return;
    }

    if ($customThreshold !== null && $customThreshold <= 0) {
        send_json_response('error', 'Threshold must be a positive integer', null, 400);
        return;
    }

    try {
        $batchResult = check_and_create_batch($assessmentId, $conn, $customThreshold);

        if ($batchResult !== null) {
            send_json_response('success', 'Batch and exam schedules formed successfully', $batchResult, 201);
            return;
        } else {
            $threshold = get_batch_threshold($conn, $customThreshold);
            $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);
            $data = [
                'assessment_id' => $assessmentId,
                'eligible_count' => $eligibleCount,
                'threshold' => $threshold,
                'batch_formed' => false,
                'needed_to_form_batch' => max(0, $threshold - $eligibleCount)
            ];
            send_json_response('success', 'Candidate count below threshold. Batch not formed yet.', $data, 200);
            return;
        }
    } catch (Throwable $e) {
        send_json_response('error', $e->getMessage(), null, 400);
        return;
    }
}

/**
 * Controller handler for listing available exam slots.
 * Matches API Contract: GET /api/slots/available.php
 */
function handle_list_slots_request(array $input, mysqli $conn): void {
    $assessmentId = isset($input['assessment_id']) ? (int)$input['assessment_id'] : 0;
    $candidateId = isset($input['candidate_id']) && is_numeric($input['candidate_id']) ? (int)$input['candidate_id'] : null;

    if ($assessmentId <= 0) {
        send_json_response('error', 'A valid assessment_id parameter is required', null, 400);
        return;
    }

    try {
        $slots = fetch_available_slots($assessmentId, $candidateId, $conn);
        send_json_response('success', 'Available exam slots retrieved successfully', $slots, 200);
        return;
    } catch (Throwable $e) {
        send_json_response('error', $e->getMessage(), null, 500);
        return;
    }
}
?>
