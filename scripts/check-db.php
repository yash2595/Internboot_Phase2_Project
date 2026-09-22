<?php
require_once __DIR__ . '/../src/core/bootstrap.php';

echo "=== Settings ===\n";
$res = $conn->query("SELECT * FROM settings");
while ($r = $res->fetch_assoc()) {
    echo "  " . $r['setting_key'] . " = " . $r['setting_value'] . "\n";
}

echo "\n=== Assessments ===\n";
$res = $conn->query("SELECT id, title, duration_minutes, total_questions, status FROM assessments");
while ($r = $res->fetch_assoc()) {
    echo "  ID: " . $r['id'] . " | " . $r['title'] . " | Status: " . $r['status'] . "\n";
}

echo "\n=== Batches ===\n";
$res = $conn->query("SELECT id, batch_number, assessment_id, creation_date FROM batches");
while ($r = $res->fetch_assoc()) {
    echo "  ID: " . $r['id'] . " | " . $r['batch_number'] . " | Assessment: " . $r['assessment_id'] . "\n";
}

echo "\n=== Enrollments Count ===\n";
$res = $conn->query("SELECT eligibility_status, COUNT(*) as cnt, SUM(CASE WHEN batch_id IS NULL THEN 1 ELSE 0 END) as unbatched FROM enrollments GROUP BY eligibility_status");
while ($r = $res->fetch_assoc()) {
    echo "  Status: " . $r['eligibility_status'] . " | Total: " . $r['cnt'] . " | Unbatched: " . $r['unbatched'] . "\n";
}
