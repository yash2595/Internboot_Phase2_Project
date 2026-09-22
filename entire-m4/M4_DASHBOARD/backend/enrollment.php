<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'message' => 'Use GET for enrollment lookup.'], 405);
}

$candidateId = resolve_candidate_id($_GET);

$stmt = $conn->prepare(
    'SELECT e.id, e.assessment_id, e.payment_id, e.batch_id, e.eligibility_status, e.created_at,
            a.title AS assessment_title
     FROM enrollments e
     JOIN assessments a ON a.id = e.assessment_id
     WHERE e.candidate_id = ?
     ORDER BY e.id DESC'
);
$stmt->bind_param('i', $candidateId);
$stmt->execute();
$result = $stmt->get_result();

$enrollments = [];
while ($row = $result->fetch_assoc()) {
    $enrollments[] = [
        'id' => (int)$row['id'],
        'enrollment_id' => 'ENR-' . $row['id'],
        'assessment_id' => (int)$row['assessment_id'],
        'assessment' => $row['assessment_title'],
        'payment_id' => $row['payment_id'] ? (int)$row['payment_id'] : null,
        'batch_id' => $row['batch_id'] ? (int)$row['batch_id'] : null,
        'eligibility_status' => $row['eligibility_status'],
        'created_at' => $row['created_at']
    ];
}
$stmt->close();

json_response([
    'success' => true,
    'data' => $enrollments
]);
