<?php
// Path: public/api/slots/preference.php
// Endpoint for candidate to view or set preferred exam date & time slot,
// and trigger automated batching once threshold (100 students) is reached.

require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/service.php';
require_once __DIR__ . '/../../../src/core/candidate_resolver.php';

header('Content-Type: application/json; charset=utf-8');

$sessionCandidateId = validate_candidate_session($conn);
$role = resolve_admin_role($conn);

if ($sessionCandidateId === null && $role !== 'admin') {
    send_json_response('error', 'Unauthorized: candidate authentication session required', null, 401);
}

$candidateId = $sessionCandidateId;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return available upcoming weekend options and candidate's current preference
    $assessmentId = isset($_GET['assessment_id']) && ctype_digit((string)$_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 1;
    
    // Fetch candidate enrollment
    $stmt = $conn->prepare("SELECT id, batch_id, preferred_date, preferred_time_slot, eligibility_status FROM enrollments WHERE candidate_id = ? AND assessment_id = ? LIMIT 1");
    $stmt->bind_param('ii', $candidateId, $assessmentId);
    $stmt->execute();
    $enrollment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $upcomingOptions = get_next_available_weekend_preferences();
    
    // Fetch counts for each option to display filling status on UI
    foreach ($upcomingOptions as &$opt) {
        $dateStr = $opt['date'];
        $timeStr = $opt['time_slot'];
        
        $cStmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE assessment_id = ? AND preferred_date = ? AND preferred_time_slot = ? AND eligibility_status = 'eligible' AND batch_id IS NULL");
        $cStmt->bind_param('iss', $assessmentId, $dateStr, $timeStr);
        $cStmt->execute();
        $opt['interested_candidates'] = (int)$cStmt->get_result()->fetch_column();
        $cStmt->close();

        // Check if a batch already exists for this date and time
        $bStmt = $conn->prepare("
            SELECT b.id, b.batch_number, es.capacity, es.seats_remaining,
                   (SELECT COUNT(*) FROM enrollments e WHERE e.batch_id = b.id) as enrolled_count
            FROM batches b
            JOIN exam_schedules s ON s.batch_id = b.id
            JOIN exam_slots es ON es.exam_schedule_id = s.id
            WHERE b.assessment_id = ? AND s.exam_date = ? AND es.start_time = ? AND s.status = 'scheduled'
            LIMIT 1
        ");
        $parts = explode('-', $timeStr);
        $startTime = trim($parts[0]);
        if (strlen($startTime) === 5) $startTime .= ':00';
        $bStmt->bind_param('iss', $assessmentId, $dateStr, $startTime);
        $bStmt->execute();
        $existingBatch = $bStmt->get_result()->fetch_assoc();
        $bStmt->close();

        if ($existingBatch) {
            $opt['batch_formed'] = true;
            $opt['batch_id'] = (int)$existingBatch['id'];
            $opt['batch_number'] = $existingBatch['batch_number'];
            $opt['enrolled_count'] = (int)$existingBatch['enrolled_count'];
            $opt['status_label'] = 'Batch Confirmed — Still Filling (' . $existingBatch['enrolled_count'] . ' Enrolled)';
        } else {
            $opt['batch_formed'] = false;
            $opt['status_label'] = $opt['interested_candidates'] . ' / 100 candidates joined';
        }
    }
    unset($opt);

    send_json_response('success', 'Preferences retrieved', [
        'candidate_id' => $candidateId,
        'assessment_id' => $assessmentId,
        'current_preference' => $enrollment ? [
            'preferred_date' => $enrollment['preferred_date'],
            'preferred_time_slot' => $enrollment['preferred_time_slot'],
            'batch_id' => $enrollment['batch_id'],
            'eligibility_status' => $enrollment['eligibility_status']
        ] : null,
        'available_options' => $upcomingOptions
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];

    $assessmentId = isset($input['assessment_id']) && is_numeric($input['assessment_id']) ? (int)$input['assessment_id'] : 1;
    $prefDate = trim((string)($input['preferred_date'] ?? ''));
    $prefTime = trim((string)($input['preferred_time_slot'] ?? '10:00:00-11:00:00'));

    if (empty($prefDate)) {
        send_json_response('error', 'Please select a preferred exam date.', null, 400);
    }

    // Validate weekend date
    $d = DateTime::createFromFormat('!Y-m-d', $prefDate);
    if (!$d || $d->format('Y-m-d') !== $prefDate) {
        send_json_response('error', 'Invalid date format. Expected YYYY-MM-DD.', null, 400);
    }
    $dayOfWeek = (int)$d->format('N'); // 6 = Sat, 7 = Sun
    if ($dayOfWeek < 6) {
        send_json_response('error', 'Preferred test date must be a Saturday or Sunday.', null, 400);
    }

    // Validate registration cutoff
    // Saturday cutoff: Friday 11:59:59 PM
    // Sunday cutoff: Saturday 11:59:59 PM
    if (!is_registration_open_for_date($prefDate)) {
        $cutoffMsg = ($dayOfWeek === 6)
            ? 'Registration for this Saturday closed on Friday at 11:59:59 PM. Please choose the following weekend.'
            : 'Registration for this Sunday closed on Saturday at 11:59:59 PM. Please choose the following weekend.';
        send_json_response('error', $cutoffMsg, null, 400);
    }

    // Verify candidate enrollment exists and is eligible (paid)
    $stmt = $conn->prepare("SELECT id, eligibility_status, batch_id FROM enrollments WHERE candidate_id = ? AND assessment_id = ? LIMIT 1");
    $stmt->bind_param('ii', $candidateId, $assessmentId);
    $stmt->execute();
    $enrollment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$enrollment) {
        send_json_response('error', 'You are not enrolled in this assessment.', null, 404);
    }
    if ($enrollment['eligibility_status'] !== 'eligible') {
        send_json_response('error', 'Course fee payment required before setting exam preference.', null, 403);
    }

    // If candidate is already in a confirmed batch and wants to change, check if batch locked
    if (!empty($enrollment['batch_id'])) {
        // Can reassign if registration is still open
    }

    // Update enrollment preference
    $stmt = $conn->prepare("UPDATE enrollments SET preferred_date = ?, preferred_time_slot = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $prefDate, $prefTime, $enrollment['id']);
    $stmt->execute();
    $stmt->close();

    // Check if an existing open batch is already active on that date & slot
    // If so, join it immediately!
    $batchResult = null;
    $parts = explode('-', $prefTime);
    $startTime = trim($parts[0]);
    if (strlen($startTime) === 5) $startTime .= ':00';

    $bStmt = $conn->prepare("
        SELECT b.id, b.batch_number, es.id as slot_id, es.capacity
        FROM batches b
        JOIN exam_schedules s ON s.batch_id = b.id
        JOIN exam_slots es ON es.exam_schedule_id = s.id
        WHERE b.assessment_id = ? AND s.exam_date = ? AND es.start_time = ? AND s.status = 'scheduled'
        LIMIT 1
    ");
    $bStmt->bind_param('iss', $assessmentId, $prefDate, $startTime);
    $bStmt->execute();
    $existingBatch = $bStmt->get_result()->fetch_assoc();
    $bStmt->close();

    if ($existingBatch) {
        // Join existing open batch
        $batchId = (int)$existingBatch['id'];
        $slotId = (int)$existingBatch['slot_id'];
        
        $uStmt = $conn->prepare("UPDATE enrollments SET batch_id = ?, updated_at = NOW() WHERE id = ?");
        $uStmt->bind_param('ii', $batchId, $enrollment['id']);
        $uStmt->execute();
        $uStmt->close();

        // Ensure active attempt exists for slot
        $attQ = $conn->prepare("SELECT id FROM attempts WHERE candidate_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
        $attQ->bind_param('i', $candidateId);
        $attQ->execute();
        $existingAtt = $attQ->get_result()->fetch_assoc();
        $attQ->close();

        if ($existingAtt) {
            $uAtt = $conn->prepare("UPDATE attempts SET exam_slot_id = ?, updated_at = NOW() WHERE id = ?");
            $uAtt->bind_param('ii', $slotId, $existingAtt['id']);
            $uAtt->execute();
            $uAtt->close();
        } else {
            $attStmt = $conn->prepare("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, created_at) VALUES (?, ?, ?, 'in_progress', NOW())");
            $attStmt->bind_param('iii', $candidateId, $assessmentId, $slotId);
            $attStmt->execute();
            $attStmt->close();
        }

        // Dynamically expand slot capacity to accommodate all students joining
        $conn->query("
            UPDATE exam_slots es
            SET capacity = GREATEST(capacity, (SELECT COUNT(*) FROM enrollments e WHERE e.batch_id = {$batchId})),
                seats_remaining = GREATEST(0, capacity - (SELECT COUNT(*) FROM attempts a WHERE a.exam_slot_id = es.id AND a.status = 'in_progress')),
                updated_at = NOW()
            WHERE es.id = {$slotId}
        ");

        $batchResult = [
            'action' => 'joined_existing_batch',
            'batch_id' => $batchId,
            'batch_number' => $existingBatch['batch_number'],
            'slot_id' => $slotId
        ];
    } else {
        // Trigger automated batch creation check (creates batch if 100 students share preference)
        $batchResult = process_automated_preference_batching($assessmentId, $conn);
    }

    send_json_response('success', 'Exam date and time preference saved successfully.', [
        'preferred_date' => $prefDate,
        'preferred_time_slot' => $prefTime,
        'batch_result' => $batchResult
    ]);
}
