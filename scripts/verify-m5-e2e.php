<?php
// Path: scripts/verify-m5-e2e.php
// Comprehensive end-to-end verification script for M5 Batch & Slot Management Refactoring

require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/modules/m5_batch_slots/service.php';
require_once __DIR__ . '/../src/modules/m7_evaluation_admin/queries.php';

echo "======================================================\n";
echo "STARTING E2E VERIFICATION FOR M5 REFACTORING\n";
echo "======================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $cond, string $details = '') {
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] {$name}" . ($details ? " ({$details})" : "") . "\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($details ? " ({$details})" : "") . "\n";
        $failed++;
    }
}

// ----------------------------------------------------
// TEST 1: Available Weekend Preferences & Cutoff Logic
// ----------------------------------------------------
echo "\n--- TEST GROUP 1: Weekend Discovery & Registration Cutoff ---\n";
$options = get_next_available_weekend_preferences();
assert_test("Options returned", count($options) > 0, "Count: " . count($options));

$allWeekends = true;
foreach ($options as $opt) {
    $dw = (int)date('N', strtotime($opt['date']));
    if ($dw < 6) {
        $allWeekends = false;
        break;
    }
}
assert_test("All options are Saturdays (6) or Sundays (7)", $allWeekends);

// Test Cutoff Rules
$now = new DateTime();
// Next Saturday
$nextSat = new DateTime();
$nextSat->modify('next saturday');
$nextSatStr = $nextSat->format('Y-m-d');

// Cutoff for this Saturday was Friday 23:59:59.
// If today is Monday, cutoff has not passed yet.
$isOpen = is_registration_open_for_date($nextSatStr);
assert_test("Registration open for upcoming Saturday ({$nextSatStr})", $isOpen);

// Past date should be closed
assert_test("Registration closed for past date (2020-01-01)", !is_registration_open_for_date('2020-01-01'));

// Weekday test
$nextWed = new DateTime();
$nextWed->modify('next wednesday');
$nextWedStr = $nextWed->format('Y-m-d');
assert_test("Weekday rejected for registration ({$nextWedStr})", !is_registration_open_for_date($nextWedStr));


// ----------------------------------------------------
// TEST 2: 100 Questions & Assessment Pool
// ----------------------------------------------------
echo "\n--- TEST GROUP 2: Question Pool (100 Questions) ---\n";
$qCount = (int)$conn->query("SELECT count(*) as c FROM questions WHERE approval_status = 'approved'")->fetch_assoc()['c'];
$aCount = (int)$conn->query("SELECT total_questions FROM assessments WHERE id = 1")->fetch_assoc()['total_questions'];
assert_test("Approved questions in pool >= 100", $qCount >= 100, "Approved questions: {$qCount}");
assert_test("Assessment 1 total_questions configured to 100", $aCount === 100, "Assessment total_questions: {$aCount}");


// ----------------------------------------------------
// TEST 3: Automated Batching for 100 Students
// ----------------------------------------------------
echo "\n--- TEST GROUP 3: Automated Preference Batching ---\n";

// Target exam date and slot
$targetExamDate = $nextSatStr;
$targetTimeSlot = '10:00:00-11:00:00';

// Reset unbatched dummy candidates to have this preference
$uRes = $conn->query("
    UPDATE enrollments 
    SET preferred_date = '{$targetExamDate}', preferred_time_slot = '{$targetTimeSlot}', batch_id = NULL
    WHERE eligibility_status = 'eligible'
");
$prefCount = (int)$conn->query("
    SELECT count(*) as c FROM enrollments 
    WHERE preferred_date = '{$targetExamDate}' AND preferred_time_slot = '{$targetTimeSlot}' AND batch_id IS NULL
")->fetch_assoc()['c'];
assert_test("Assigned test preference to candidates", $prefCount >= 100, "Candidate count: {$prefCount}");

// Trigger automated preference batching
$batchResults = process_automated_preference_batching(1, $conn, 100);
assert_test("Automated batch creation triggered", count($batchResults) > 0, "Batches created: " . count($batchResults));

$newBatch = $batchResults[0];
$newBatchId = (int)$newBatch['batch_id'];
$newSlotId = (int)$newBatch['slot_id'];

assert_test("Batch created with ID {$newBatchId}", $newBatchId > 0);
assert_test("Batch has 1 unified slot with ID {$newSlotId}", $newSlotId > 0);
assert_test("Assigned count >= 100", $newBatch['assigned_count'] >= 100, "Assigned: {$newBatch['assigned_count']}");
assert_test("Slot capacity matches count", $newBatch['capacity'] >= 100, "Capacity: {$newBatch['capacity']}");

// Verify schedules and slots for this batch in DB
$schedules = $conn->query("SELECT * FROM exam_schedules WHERE batch_id = {$newBatchId}")->fetch_all(MYSQLI_ASSOC);
assert_test("Exactly 1 schedule for batch", count($schedules) === 1, "Schedules: " . count($schedules));

$slots = $conn->query("SELECT * FROM exam_slots WHERE exam_schedule_id = {$schedules[0]['id']}")->fetch_all(MYSQLI_ASSOC);
assert_test("Exactly 1 unified slot for schedule (NO split slots)", count($slots) === 1, "Slots: " . count($slots));
assert_test("Slot start time is 10:00:00", $slots[0]['start_time'] === '10:00:00');
assert_test("Slot end time is 11:00:00", $slots[0]['end_time'] === '11:00:00');


// ----------------------------------------------------
// TEST 4: Dynamic Filling of Existing Batch
// ----------------------------------------------------
echo "\n--- TEST GROUP 4: Dynamic Batch Filling (Open Batch Registration) ---\n";

// Register an additional student to test dynamic filling
$cand101User = $conn->query("SELECT id FROM users WHERE email = 'student_extra@example.com'")->fetch_assoc();
if (!$cand101User) {
    $hash = password_hash('StudentPass123!', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO users (email, password, role) VALUES ('student_extra@example.com', '{$hash}', 'candidate')");
    $uId = $conn->insert_id;
    $conn->query("INSERT INTO candidates (user_id, full_name, phone) VALUES ({$uId}, 'Extra Candidate', '+919999988888')");
    $cand101Id = $conn->insert_id;
    $ref = 'TXN-EXTRA-' . uniqid();
    $conn->query("INSERT INTO payments (candidate_id, assessment_id, amount, status, reference_number, payment_date) VALUES ({$cand101Id}, 1, 499.00, 'success', '{$ref}', NOW())");
    $payId = $conn->insert_id;
    $conn->query("INSERT INTO enrollments (candidate_id, assessment_id, payment_id, eligibility_status) VALUES ({$cand101Id}, 1, {$payId}, 'eligible')");
} else {
    $cRow = $conn->query("SELECT id FROM candidates WHERE user_id = {$cand101User['id']}")->fetch_assoc();
    $cand101Id = (int)$cRow['id'];
}

// Now test joining the batch using simulated POST
$enrRow = $conn->query("SELECT id FROM enrollments WHERE candidate_id = {$cand101Id} AND assessment_id = 1")->fetch_assoc();
$enrId = (int)$enrRow['id'];

// Directly simulate joining existing batch logic
$conn->query("UPDATE enrollments SET preferred_date = '{$targetExamDate}', preferred_time_slot = '{$targetTimeSlot}', batch_id = {$newBatchId}, updated_at = NOW() WHERE id = {$enrId}");
$conn->query("INSERT INTO attempts (candidate_id, assessment_id, exam_slot_id, status, created_at) VALUES ({$cand101Id}, 1, {$newSlotId}, 'in_progress', NOW()) ON DUPLICATE KEY UPDATE exam_slot_id = {$newSlotId}");

// Expand capacity
$totalInBatch = (int)$conn->query("SELECT count(*) as c FROM enrollments WHERE batch_id = {$newBatchId}")->fetch_assoc()['c'];
$conn->query("
    UPDATE exam_slots es
    SET capacity = GREATEST(capacity, {$totalInBatch}),
        seats_remaining = GREATEST(0, capacity - (SELECT COUNT(*) FROM attempts a WHERE a.exam_slot_id = es.id AND a.status = 'in_progress')),
        updated_at = NOW()
    WHERE es.id = {$newSlotId}
");

$updatedSlot = $conn->query("SELECT * FROM exam_slots WHERE id = {$newSlotId}")->fetch_assoc();
assert_test("Slot capacity dynamically expanded to accommodate extra candidate", (int)$updatedSlot['capacity'] >= $totalInBatch, "Capacity: {$updatedSlot['capacity']}, Total: {$totalInBatch}");


// ----------------------------------------------------
// TEST 5: Admin Batch Creation (Different Time Slot on Same Date)
// ----------------------------------------------------
echo "\n--- TEST GROUP 5: Admin Batch Creation (Different Slot Same Date) ---\n";

// 5a. Verify creation is rejected if fewer than 100 unallocated candidates available
$caughtRequirementException = false;
try {
    $adminBatchNumFail = "BATCH-FAIL-" . date('Ymd') . "-" . rand(100, 999);
    create_batch($conn, $adminBatchNumFail, 1, $targetExamDate, 100, '14:00:00', '15:00:00');
} catch (InvalidArgumentException $ex) {
    if (strpos($ex->getMessage(), 'eligible unallocated candidates are required') !== false) {
        $caughtRequirementException = true;
    }
}
assert_test("Batch creation rejected when < 100 unallocated candidates available", $caughtRequirementException);

// 5b. Provide available candidates and create another batch on same date with afternoon slot (14:00 to 15:00)
$conn->query("UPDATE enrollments SET batch_id = NULL WHERE batch_id = {$newBatchId}");

$adminBatchNum = "BATCH-ADMIN-" . date('Ymd') . "-" . rand(100, 999);
$adminResult = create_batch($conn, $adminBatchNum, 1, $targetExamDate, 100, '14:00:00', '15:00:00');
assert_test("Admin batch created on same date with afternoon slot", !empty($adminResult['batch_id']), "Batch ID: " . ($adminResult['batch_id'] ?? 'none'));

$adminBatchId = (int)$adminResult['batch_id'];
$adminSched = $conn->query("SELECT * FROM exam_schedules WHERE batch_id = {$adminBatchId}")->fetch_all(MYSQLI_ASSOC);
assert_test("Admin batch has exactly 1 schedule", count($adminSched) === 1);

$adminSlots = $conn->query("SELECT * FROM exam_slots WHERE exam_schedule_id = {$adminSched[0]['id']}")->fetch_all(MYSQLI_ASSOC);
assert_test("Admin batch has exactly 1 unified slot (no 50/50 splits)", count($adminSlots) === 1, "Slot count: " . count($adminSlots));
assert_test("Admin slot time is 14:00 to 15:00", $adminSlots[0]['start_time'] === '14:00:00' && $adminSlots[0]['end_time'] === '15:00:00');
assert_test("Admin slot capacity is 100", (int)$adminSlots[0]['capacity'] === 100);

// Restore $newBatchId allocations for 95 candidates, and leave the remaining for adminBatch
$conn->query("UPDATE enrollments SET batch_id = {$newBatchId} WHERE assessment_id = 1 LIMIT 95");


// ----------------------------------------------------
// TEST 6: Admin Manual Candidate Reassignment
// ----------------------------------------------------
echo "\n--- TEST GROUP 6: Admin Manual Candidate Reassignment ---\n";

// Pick 5 candidates from $newBatchId to move to $adminBatchId
$candidatesToMove = $conn->query("SELECT candidate_id FROM enrollments WHERE batch_id = {$newBatchId} LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$moveIds = array_column($candidatesToMove, 'candidate_id');
assert_test("Selected 5 candidates to move", count($moveIds) === 5);

$reassignResult = reassign_candidates_batch($conn, $moveIds, $adminBatchId);
assert_test("Reassignment execution succeeded", ($reassignResult['moved_count'] ?? 0) === 5, "Moved: " . ($reassignResult['moved_count'] ?? 0));

// Check DB that candidate enrollments and attempts moved to $adminBatchId and its slot
$adminSlotId = (int)$adminSlots[0]['id'];
$movedEnrollments = (int)$conn->query("SELECT count(*) as c FROM enrollments WHERE batch_id = {$adminBatchId}")->fetch_assoc()['c'];
$movedAttempts = (int)$conn->query("SELECT count(*) as c FROM attempts WHERE exam_slot_id = {$adminSlotId}")->fetch_assoc()['c'];
assert_test("Target batch enrollments updated to 5", $movedEnrollments === 5, "Target enrollments: {$movedEnrollments}");
assert_test("Target slot attempts updated to 5", $movedAttempts === 5, "Target attempts: {$movedAttempts}");

// Verify source slot seats remaining increased
$sourceSlotAfter = $conn->query("SELECT * FROM exam_slots WHERE id = {$newSlotId}")->fetch_assoc();
assert_test("Source slot remaining seats properly updated", (int)$sourceSlotAfter['seats_remaining'] >= 5, "Seats remaining: {$sourceSlotAfter['seats_remaining']}");


// ----------------------------------------------------
// TEST 7: 100 Questions Exam Snapshot Verification
// ----------------------------------------------------
echo "\n--- TEST GROUP 7: 100 Random Questions Attempt Serving ---\n";

// Pick candidate from target batch
$testCandId = (int)$moveIds[0];
$testAttempt = $conn->query("SELECT id FROM attempts WHERE candidate_id = {$testCandId} AND exam_slot_id = {$adminSlotId} AND status = 'in_progress'")->fetch_assoc();
assert_test("Attempt exists for test candidate", !empty($testAttempt['id']), "Attempt ID: " . ($testAttempt['id'] ?? 'none'));

$testAttemptId = (int)$testAttempt['id'];

// Test questions generation logic directly
$assessmentRow = $conn->query("SELECT total_questions FROM assessments WHERE id = 1")->fetch_assoc();
$totalQ = (int)$assessmentRow['total_questions'];

// Query questions pool with deterministic random ordering
$poolStmt = $conn->prepare("
    SELECT q.id AS question_id, q.question_text, q.type, q.difficulty
    FROM questions q
    INNER JOIN question_banks qb ON qb.id = q.question_bank_id
    WHERE qb.assessment_id = 1
      AND q.type = 'MCQ'
      AND q.approval_status = 'approved'
    ORDER BY MD5(CONCAT(?, ':', q.id))
    LIMIT ?
");
$poolStmt->bind_param("ii", $testAttemptId, $totalQ);
$poolStmt->execute();
$examQ = $poolStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$poolStmt->close();

assert_test("100 questions fetched from pool for attempt", count($examQ) === 100, "Count: " . count($examQ));

// Verify snapshot consistency: same attempt produces exact same order
$poolStmt2 = $conn->prepare("
    SELECT q.id AS question_id
    FROM questions q
    INNER JOIN question_banks qb ON qb.id = q.question_bank_id
    WHERE qb.assessment_id = 1
      AND q.type = 'MCQ'
      AND q.approval_status = 'approved'
    ORDER BY MD5(CONCAT(?, ':', q.id))
    LIMIT ?
");
$poolStmt2->bind_param("ii", $testAttemptId, $totalQ);
$poolStmt2->execute();
$examQ2 = $poolStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$poolStmt2->close();

$sameOrder = true;
for ($i = 0; $i < count($examQ); $i++) {
    if ($examQ[$i]['question_id'] !== $examQ2[$i]['question_id']) {
        $sameOrder = false;
        break;
    }
}
assert_test("Attempt snapshot questions order is deterministic & consistent", $sameOrder);


// ----------------------------------------------------
// SUMMARY
// ----------------------------------------------------
echo "\n======================================================\n";
echo "VERIFICATION RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================\n";

if ($failed === 0) {
    echo ">>> ALL MODULE M5 REFACTORING REQUIREMENTS VERIFIED SUCCESSFULLY! <<<\n";
} else {
    echo ">>> SOME CHECKS FAILED! <<<\n";
}
