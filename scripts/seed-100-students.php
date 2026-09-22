<?php
// Path: scripts/seed-100-students.php
// Registers 100 dummy students with paid course fees for Assessment 1.

require_once __DIR__ . '/../src/core/bootstrap.php';

echo "=================================================\n";
echo "InternBoot: Registering 100 Dummy Students\n";
echo "=================================================\n\n";

// 1. Verify Active Assessment
$assessmentId = 1;
$stmt = $conn->prepare("SELECT id, title, duration_minutes, total_questions FROM assessments WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $assessmentId);
$stmt->execute();
$assessment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assessment) {
    echo "[ERROR] Active Assessment ID {$assessmentId} not found.\n";
    exit(1);
}
echo "[OK] Target Assessment: ID {$assessmentId} - '{$assessment['title']}'\n";

// 2. Fetch Exam Fee from Settings
$feeStmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'exam_fee' LIMIT 1");
$feeRow = $feeStmt ? $feeStmt->fetch_assoc() : null;
$examFee = $feeRow ? (float)$feeRow['setting_value'] : 2999.00;
echo "[OK] Course / Exam Fee: Rs. " . number_format($examFee, 2) . "\n";

// 3. Batch Threshold from Settings
$threshStmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'batch_threshold' LIMIT 1");
$threshRow = $threshStmt ? $threshStmt->fetch_assoc() : null;
$batchThreshold = $threshRow ? (int)$threshRow['setting_value'] : 100;
echo "[OK] Configured Batch Threshold: {$batchThreshold} candidates\n\n";

$passwordPlain = 'StudentPass123!';
$passwordHash = password_hash($passwordPlain, PASSWORD_BCRYPT);
$profileJson = json_encode([
    'education' => 'B.Tech Computer Science',
    'institution' => 'Demo Institute of Technology',
    'graduation_year' => 2026,
    'city' => 'Bangalore'
]);

$createdCount = 0;
$updatedCount = 0;

$conn->begin_transaction();

try {
    for ($i = 1; $i <= 100; $i++) {
        $email = sprintf("student%03d@example.com", $i); // student001@example.com ... student100@example.com
        $fullName = sprintf("Dummy Student %03d", $i);
        $phone = sprintf("+9198765%05d", $i); // +919876500001 ... +919876500100

        // Check if user exists
        $uStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uStmt->bind_param('s', $email);
        $uStmt->execute();
        $userRow = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();

        if ($userRow) {
            $userId = (int)$userRow['id'];
        } else {
            $insUser = $conn->prepare("INSERT INTO users (email, password, role, is_active) VALUES (?, ?, 'candidate', 1)");
            $insUser->bind_param('ss', $email, $passwordHash);
            $insUser->execute();
            $userId = (int)$insUser->insert_id;
            $insUser->close();
        }

        // Check or insert candidate profile
        $cStmt = $conn->prepare("SELECT id FROM candidates WHERE user_id = ? LIMIT 1");
        $cStmt->bind_param('i', $userId);
        $cStmt->execute();
        $candRow = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        if ($candRow) {
            $candidateId = (int)$candRow['id'];
        } else {
            $insCand = $conn->prepare("INSERT INTO candidates (user_id, full_name, phone, profile_details) VALUES (?, ?, ?, ?)");
            $insCand->bind_param('isss', $userId, $fullName, $phone, $profileJson);
            $insCand->execute();
            $candidateId = (int)$insCand->insert_id;
            $insCand->close();
        }

        // Check or insert successful payment
        $pStmt = $conn->prepare("SELECT id, status FROM payments WHERE candidate_id = ? AND assessment_id = ? AND status = 'success' LIMIT 1");
        $pStmt->bind_param('ii', $candidateId, $assessmentId);
        $pStmt->execute();
        $payRow = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();

        if ($payRow) {
            $paymentId = (int)$payRow['id'];
        } else {
            $refNumber = sprintf("IB-PAY-MOCK-%04d-%s", $i, strtoupper(bin2hex(random_bytes(3))));
            $insPay = $conn->prepare("INSERT INTO payments (candidate_id, assessment_id, amount, status, reference_number, payment_date) VALUES (?, ?, ?, 'success', ?, NOW())");
            $insPay->bind_param('iids', $candidateId, $assessmentId, $examFee, $refNumber);
            $insPay->execute();
            $paymentId = (int)$insPay->insert_id;
            $insPay->close();
        }

        // Check or insert enrollment with eligibility_status = 'eligible' and batch_id = NULL
        $eStmt = $conn->prepare("SELECT id, batch_id, eligibility_status FROM enrollments WHERE candidate_id = ? AND assessment_id = ? LIMIT 1");
        $eStmt->bind_param('ii', $candidateId, $assessmentId);
        $eStmt->execute();
        $enrRow = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();

        if ($enrRow) {
            $enrollmentId = (int)$enrRow['id'];
            // Reset to unbatched eligible if needed
            $updEnr = $conn->prepare("UPDATE enrollments SET payment_id = ?, batch_id = NULL, eligibility_status = 'eligible' WHERE id = ?");
            $updEnr->bind_param('ii', $paymentId, $enrollmentId);
            $updEnr->execute();
            $updEnr->close();
            $updatedCount++;
        } else {
            $insEnr = $conn->prepare("INSERT INTO enrollments (candidate_id, assessment_id, payment_id, batch_id, eligibility_status) VALUES (?, ?, ?, NULL, 'eligible')");
            $insEnr->bind_param('iii', $candidateId, $assessmentId, $paymentId);
            $insEnr->execute();
            $enrollmentId = (int)$insEnr->insert_id;
            $insEnr->close();
            $createdCount++;
        }
    }

    $conn->commit();
    echo "[OK] Successfully processed 100 dummy students!\n";
    echo "     - Newly created: {$createdCount}\n";
    echo "     - Updated:        {$updatedCount}\n\n";

} catch (Throwable $e) {
    $conn->rollback();
    echo "[FATAL ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

// 4. Verification Check
$res = $conn->query("
    SELECT 
        COUNT(*) as total_eligible,
        SUM(CASE WHEN batch_id IS NULL THEN 1 ELSE 0 END) as unbatched_eligible,
        SUM(CASE WHEN batch_id IS NOT NULL THEN 1 ELSE 0 END) as batched
    FROM enrollments 
    WHERE assessment_id = {$assessmentId} AND eligibility_status = 'eligible'
");
$stats = $res->fetch_assoc();

echo "=================================================\n";
echo "Current Enrollment Statistics for Assessment {$assessmentId}:\n";
echo "  Total Eligible Candidates:    {$stats['total_eligible']}\n";
echo "  Unbatched Candidates:         {$stats['unbatched_eligible']}\n";
echo "  Already Batched Candidates:   {$stats['batched']}\n";
echo "  Batch Threshold Requirement:  {$batchThreshold}\n";
echo "=================================================\n";

if ((int)$stats['unbatched_eligible'] >= $batchThreshold) {
    echo " READY FOR BATCH CREATION!\n";
    echo "  The unbatched candidate count ({$stats['unbatched_eligible']}) meets or exceeds the threshold ({$batchThreshold}).\n";
    echo "  You can now trigger batch creation either:\n";
    echo "    1. Through the Admin Dashboard: http://localhost:8000/admin/batches.html\n";
    echo "    2. Or via API: POST /api/slots/auto_batch.php with {\"assessment_id\": 1}\n";
} else {
    echo "  Need " . ($batchThreshold - (int)$stats['unbatched_eligible']) . " more candidate(s) to reach threshold.\n";
}

echo "\nLogin Credentials for Dummy Students:\n";
echo "  Emails:   student001@example.com to student100@example.com\n";
echo "  Password: {$passwordPlain}\n";
echo "=================================================\n";
