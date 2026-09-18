<?php
// Path: src/modules/m3_auth/controller.php

require_once __DIR__ . '/service.php';

/**
 * Handles POST /api/auth/register.php
 */
function handle_register_request(array $data, mysqli $conn): void {
    $fullName = sanitize_string($data['full_name'] ?? '');
    $email    = sanitize_string($data['email'] ?? '');
    $phone    = sanitize_string($data['phone'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $confirm  = (string) ($data['confirm_password'] ?? '');
    $role     = sanitize_string($data['role'] ?? 'candidate');

    if (!in_array($role, ['candidate', 'admin'], true)) {
        $role = 'candidate';
    }

    if ($fullName === '' || $email === '' || $phone === '' || $password === '') {
        send_json_response('error', 'All fields are required.', null, 422);
    }
    if (!is_valid_email($email)) {
        send_json_response('error', 'Enter a valid email address.', null, 422);
    }
    if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
        send_json_response('error', 'Enter a valid 10-digit phone number.', null, 422);
    }
    if (strlen($password) < 8) {
        send_json_response('error', 'Password must be at least 8 characters.', null, 422);
    }
    if ($password !== $confirm) {
        send_json_response('error', 'Passwords do not match.', null, 422);
    }

    $result = initiate_registration($conn, $fullName, $email, $phone, $password, $role);

    if (!$result['success']) {
        send_json_response('error', $result['message'], null, $result['code'] ?? 409);
    }

    send_json_response('success', 'Verification code sent to your email.', ['email' => $email], 200);
}

function handle_verify_otp_request(array $data, mysqli $conn): void {
    $email = sanitize_string($data['email'] ?? '');
    $otp   = sanitize_string($data['otp'] ?? '');

    if ($email === '' || $otp === '') {
        send_json_response('error', 'Email and verification code are required.', null, 422);
    }

    $result = complete_registration_with_otp($conn, $email, $otp);

    if (!$result['success']) {
        send_json_response('error', $result['message'], null, $result['code'] ?? 400);
    }

    send_json_response('success', 'Registration successful. Please log in.', ['user_id' => $result['user_id']], 201);
}

function handle_resend_otp_request(array $data, mysqli $conn): void {
    $email = sanitize_string($data['email'] ?? '');

    if ($email === '') {
        send_json_response('error', 'Email is required.', null, 422);
    }

    $stmt = $conn->prepare('SELECT * FROM email_verifications WHERE email = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pending) {
        send_json_response('error', 'No pending registration found for this email.', null, 404);
    }

    $otp = (string) random_int(100000, 999999);
    $saved = save_pending_registration($conn, $email, $otp, $pending['full_name'], $pending['phone'], $pending['password_hash'], $pending['role']);

    if (!$saved) {
        send_json_response('error', 'Could not resend code. Please try again.', null, 500);
    }

    try {
        require_once __DIR__ . '/../../core/Mailer.php';
        $sent = send_otp_email($email, $pending['full_name'], $otp);
        if (!$sent) {
            send_json_response('error', 'Could not send verification email. Contact support with your registration email.', null, 500);
        }
    } catch (RuntimeException $e) {
        error_log('Resend OTP mail error: ' . $e->getMessage());
        send_json_response('error', 'Could not send verification email. Contact support with your registration email.', null, 500);
    } catch (Throwable $e) {
        error_log('Resend OTP mail unexpected error: ' . $e->getMessage());
        send_json_response('error', 'Could not send verification email. Contact support with your registration email.', null, 500);
    }

    send_json_response('success', 'A new verification code has been sent.', null, 200);
}

function handle_login_request(array $data, mysqli $conn): void {
    $email        = sanitize_string($data['email'] ?? '');
    $password     = (string) ($data['password'] ?? '');
    $expectedRole = sanitize_string($data['role'] ?? '');

    if ($email === '' || $password === '') {
        send_json_response('error', 'Email and password are required.', null, 422);
    }
    if (!is_valid_email($email)) {
        send_json_response('error', 'Enter a valid email address.', null, 422);
    }

    $result = authenticate_candidate($conn, $email, $password);

    if (!$result['success']) {
        send_json_response('error', $result['message'], null, 401);
    }

    if ($expectedRole !== '' && $expectedRole !== $result['user']['role']) {
        $label = $expectedRole === 'admin' ? 'an Admin' : 'a Student';
        send_json_response('error', "This account is not registered as {$label}.", null, 403);
    }

    session_regenerate_id(true);

    $_SESSION['user_id']      = $result['user']['id'];
    $_SESSION['role']         = $result['user']['role'];
    $_SESSION['full_name']    = $result['user']['full_name'] ?? '';

    if ($result['user']['role'] === 'candidate' && empty($result['user']['candidate_id'])) {
        error_log("Data integrity issue: candidate-role user {$result['user']['id']} has no candidates row");
    }
    $_SESSION['candidate_id'] = $result['user']['candidate_id'] ?? null;

    send_json_response('success', 'Login successful.', [
        'role'     => $result['user']['role'],
        'redirect' => $result['user']['role'] === 'admin' ? '/admin/index.html' : '/dashboard.html',
    ], 200);
}




/**
 * Handles POST /api/auth/logout.php
 */
function handle_logout_request(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    send_json_response('success', 'Logged out.', null, 200);
}
?>
