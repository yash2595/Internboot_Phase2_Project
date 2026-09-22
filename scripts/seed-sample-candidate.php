<?php
// Path: scripts/seed-sample-candidate.php
// Seeds a demo candidate account for easy manual testing

require_once __DIR__ . '/../src/core/bootstrap.php';

$email = 'candidate@internboot.com';
$password = 'CandidatePass123!';
$name = 'Demo Candidate';
$phone = '9876543210';
$assessmentId = 1;

echo "Checking demo candidate...\n";

// Check if candidate user exists
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($res) {
    echo "[OK] Demo candidate already exists (User ID: {$res['id']})\n";
    exit(0);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $conn->prepare("INSERT INTO users (email, password, role, is_active) VALUES (?, ?, 'candidate', 1)");
$stmt->bind_param("ss", $email, $hash);
$stmt->execute();
$userId = (int)$stmt->insert_id;
$stmt->close();

$stmt = $conn->prepare("INSERT INTO candidates (user_id, full_name, phone) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $userId, $name, $phone);
$stmt->execute();
$candidateId = (int)$stmt->insert_id;
$stmt->close();

// Create enrollment for assessment 1 as eligible
$stmt = $conn->prepare("INSERT INTO enrollments (candidate_id, assessment_id, eligibility_status) VALUES (?, ?, 'eligible')");
$stmt->bind_param("ii", $candidateId, $assessmentId);
$stmt->execute();
$enrollmentId = (int)$stmt->insert_id;
$stmt->close();

echo "[OK] Demo candidate created successfully!\n";
echo "     Email:       {$email}\n";
echo "     Password:    {$password}\n";
echo "     Candidate ID:{$candidateId}\n";
echo "     Enrollment ID:{$enrollmentId} (Status: eligible)\n";
