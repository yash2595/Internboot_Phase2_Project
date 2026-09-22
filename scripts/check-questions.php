<?php
require_once __DIR__ . '/../src/core/bootstrap.php';

$res = $conn->query("SELECT COUNT(*) as q_count FROM questions WHERE approval_status = 'approved'");
$count = $res ? (int)$res->fetch_assoc()['q_count'] : 0;
echo "Total approved questions in DB: {$count}\n";

$aRes = $conn->query("SELECT id, title, total_questions FROM assessments WHERE id = 1");
$aRow = $aRes ? $aRes->fetch_assoc() : null;
if ($aRow) {
    echo "Assessment 1: total_questions = {$aRow['total_questions']}\n";
}
