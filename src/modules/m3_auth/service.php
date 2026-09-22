<?php
// Path: src/modules/m3_auth/service.php

require_once __DIR__ . '/queries.php';

/**
 * Verifies email + password against the stored hash, and checks the

 * account hasn't been deactivated (users.is_active).
 */
function authenticate_candidate(mysqli $conn, string $email, string $password): array {
    $user = find_user_by_email($conn, $email);

    if (!$user || !password_verify($password, $user['password'])) {
        // Same generic message either way — don't reveal whether the email exists.
        return ['success' => false, 'message' => 'Incorrect email or password.'];
    }

    if ((int) $user['is_active'] === 0) {
        return ['success' => false, 'message' => 'This account has been deactivated. Please contact support.'];
    }

    unset($user['password']); // never let the hash leave this layer
    return ['success' => true, 'user' => $user];
}

function initiate_registration(mysqli $conn, string $fullName, string $email, string $phone, string $password, string $role): array {
    if (find_user_by_email($conn, $email)) {
        return ['success' => false, 'message' => 'An account with this email already exists.', 'code' => 409];
    }
    if (candidate_phone_exists($conn, $phone)) {
        return ['success' => false, 'message' => 'This phone number is already registered.', 'code' => 409];
    }

    $pending = find_latest_pending_verification($conn, $email);
    if ($pending && isset($pending['age_seconds']) && $pending['age_seconds'] !== null) {
        $age = (int)$pending['age_seconds'];
        if ($age < OTP_RESEND_COOLDOWN_SECONDS) {
            $remaining = OTP_RESEND_COOLDOWN_SECONDS - $age;
            if (!headers_sent()) {
                header('Retry-After: ' . $remaining);
            }
            return [
                'success' => false,
                'message' => "Please wait {$remaining} seconds before requesting a new code.",
                'code' => 429
            ];
        }
    }

    $otp = (string) random_int(100000, 999999);
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $saved = save_pending_registration($conn, $email, $otp, $fullName, $phone, $passwordHash, $role);
    if (!$saved) {
        return ['success' => false, 'message' => 'Could not initiate registration. Please try again.', 'code' => 500];
    }

    try {
        require_once __DIR__ . '/../../core/Mailer.php';
        $sent = send_otp_email($email, $fullName, $otp);
        if (!$sent) {
            return ['success' => false, 'message' => 'Registration succeeded but verification email could not be sent. Contact support with your registration email.', 'code' => 500];
        }
    } catch (RuntimeException $e) {
        error_log('Registration mail error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Registration succeeded but verification email could not be sent. Contact support with your registration email.', 'code' => 500];
    } catch (Throwable $e) {
        error_log('Registration mail unexpected error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Registration succeeded but verification email could not be sent. Contact support with your registration email.', 'code' => 500];
    }

    return ['success' => true];
}

function complete_registration_with_otp(mysqli $conn, string $email, string $otp): array {
    $pending = find_latest_pending_verification($conn, $email);

    if (!$pending) {
        return ['success' => false, 'message' => 'Invalid or expired verification code.', 'code' => 400];
    }

    $id = (int)$pending['id'];

    $affected = consume_verification_attempt($conn, $id);

    if ($affected !== 1) {
        $check = find_latest_pending_verification($conn, $email);
        if ($check && (int)$check['id'] === $id && (int)$check['is_unexpired'] === 1 && (int)$check['attempts'] >= OTP_MAX_ATTEMPTS) {
            return [
                'success' => false,
                'message' => 'Too many incorrect attempts. Please request a new code.',
                'code' => 429
            ];
        }
        return ['success' => false, 'message' => 'Invalid or expired verification code.', 'code' => 400];
    }

    if (!hash_equals((string)$pending['otp_code'], (string)$otp)) {
        return ['success' => false, 'message' => 'Invalid or expired verification code.', 'code' => 400];
    }

    $result = insert_user_and_candidate(
        $conn,
        $pending['email'],
        $pending['password_hash'],
        $pending['full_name'],
        $pending['phone'],
        $pending['role']
    );

    if (!$result['success']) {
        return $result;
    }

    mark_pending_registration_used($conn, $id);

    return ['success' => true, 'user_id' => $result['user_id']];
}

?>
