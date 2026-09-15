<?php
// Path: src/modules/m3_auth/controller.php

require_once __DIR__ . '/service.php';

/**
 * Controller handler for user login API requests.
 */
function handle_login_request(array $input, mysqli $conn): void {
    $email = trim($input['email'] ?? '');
    $password = trim($input['password'] ?? '');

    // Validation checks
    if (empty($email) || empty($password)) {
        send_json_response('error', 'Email and password fields are required', null, 400);
    }

    if (!is_valid_email($email)) {
        send_json_response('error', 'Invalid email address format', null, 400);
    }

    try {
        $userData = authenticate_user($email, $password, $conn);
        send_json_response('success', 'Login successful', $userData, 200);
    } catch (Exception $e) {
        send_json_response('error', $e->getMessage(), null, 401);
    }
}
?>
