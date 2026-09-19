<?php
// Path: public/api/slots/auto_batch.php

// Require core bootstrap initializer
require_once __DIR__ . '/../../../src/core/bootstrap.php';
require_once __DIR__ . '/../../../src/modules/m5_batch_slots/controller.php';

// Ensure HTTP method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response('error', 'Only POST request method is allowed', null, 405);
}

// RBAC Security Check: Strictly Admin Access Only
$role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;
if ($role !== 'admin') {
    send_json_response('error', 'Unauthorized: admin access required', null, 403);
}

// Read raw JSON body payload
$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true);

if (!is_array($requestData)) {
    send_json_response('error', 'Invalid JSON body payload provided', null, 400);
}

// Delegate execution to M5 Batch Slots Controller
handle_auto_batch_request($requestData, $conn);
?>
