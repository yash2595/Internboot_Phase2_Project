<?php
// Path: src/modules/m5_batch_slots/controller.php

require_once __DIR__ . '/service.php';

/**
 * Controller handler for reserving an exam slot for an eligible candidate.
 * Matches API Contract: POST /api/slots/book.php
 *
 * Security (IDOR Protection):
 * Reads candidate identity from $_SESSION['candidate_id'] set at login by M3.
 * Rejects with 403 if candidate_id in payload conflicts with authenticated session.
 */
function handle_book_slot_request(array $input, mysqli $conn): void {
    $sessionCandidateId = !empty($_SESSION['candidate_id']) ? (int)$_SESSION['candidate_id'] : null;
    $bodyCandidateId = isset($input['candidate_id']) && is_numeric($input['candidate_id']) ? (int)$input['candidate_id'] : null;
    $assessmentId = isset($input['assessment_id']) ? (int)$input['assessment_id'] : 0;
    $examSlotId = isset($input['exam_slot_id']) ? (int)$input['exam_slot_id'] : 0;

    // 1. IDOR Authentication & Session Cross-Check
    if ($sessionCandidateId !== null) {
        if ($bodyCandidateId !== null && $bodyCandidateId !== $sessionCandidateId) {
            send_json_response('error', 'Forbidden: candidate_id does not match authenticated session', null, 403);
            return;
        }
        $candidateId = $sessionCandidateId;
    } elseif (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin' && $bodyCandidateId !== null && $bodyCandidateId > 0) {
        // Admin role allowed to specify candidate_id
        $candidateId = $bodyCandidateId;
    } else {
        // No authenticated session found
        send_json_response('error', 'Unauthorized: candidate authentication session required', null, 401);
        return;
    }

    // 2. Input Validation Checks
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
 *
 * Security: Enforces Admin role access (Fix #3 per Tech Lead review).
 */
function handle_auto_batch_request(array $input, mysqli $conn): void {
    // RBAC Security Check: Admin Access Only
    require_admin_access($conn);


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
        $batches = create_all_eligible_batches($assessmentId, $conn, $customThreshold);

        if (!empty($batches)) {
            $threshold = get_batch_threshold($conn, $customThreshold);
            $eligibleCount = get_unbatched_eligible_count($assessmentId, $conn);
            send_json_response('success', count($batches) . ' batch(es) and exam schedules formed successfully', [
                'assessment_id' => $assessmentId,
                'batches_formed' => count($batches),
                'batches' => $batches,
                'remaining_eligible_count' => $eligibleCount,
                'threshold' => $threshold
            ], 201);
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

    // If candidate is logged in via session and candidate_id wasn't explicitly provided, use session
    if ($candidateId === null && !empty($_SESSION['candidate_id'])) {
        $candidateId = (int)$_SESSION['candidate_id'];
    }

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
