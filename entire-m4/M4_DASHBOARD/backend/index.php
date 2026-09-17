<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'module' => 'M4 Payment, Enrollment & Dashboard',
    'endpoints' => [
        'GET backend/dashboard.php?candidate_id=ID',
        'GET backend/enrollment.php?candidate_id=ID',
        'POST backend/payment.php  { "action":"create", "candidate_id":ID, "assessment_id":ID }',
        'POST backend/payment.php  { "action":"verify", "candidate_id":ID, "payment_id":ID }'
    ]
], JSON_PRETTY_PRINT);
