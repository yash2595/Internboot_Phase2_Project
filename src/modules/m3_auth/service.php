<?php
// Path: src/modules/m3_auth/service.php

require_once __DIR__ . '/queries.php';

// Local email validator — intentionally NOT in shared core/validator.php,
// so this module never breaks if that file changes upstream.
if (!function_exists('is_valid_email')) {
    function is_valid_email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

// Strips spaces/dashes/parentheses and a leading country code (91 / 0091 / trunk 0),
// so every valid input format collapses to the same 10-digit number before
// validation, duplicate-checking, and storage.
function normalize_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw);

    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 14 && str_starts_with($digits, '0091')) {
        $digits = substr($digits, 4);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    return $digits;
}

/**
 * Registers a new candidate: creates the `users` row and the `candidates`
 * row together. Password is hashed here — never stored plain text.
 */
function register_candidate(mysqli $conn, string $fullName, string $email, string $phone, string $password, string $role = 'candidate'): array {
    if (find_user_by_email($conn, $email)) {
        return ['success' => false, 'message' => 'An account with this email already exists.', 'code' => 409];
    }
    if (candidate_phone_exists($conn, $phone)) {
        return ['success' => false, 'message' => 'This phone number is already registered.', 'code' => 409];
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $result = insert_user_and_candidate($conn, $email, $passwordHash, $fullName, $phone, $role);

    if (!$result['success']) {
        return $result;
    }

    return ['success' => true, 'user_id' => $result['user_id']];
}

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

    $otp = (string) random_int(100000, 999999);
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    $saved = save_pending_registration($conn, $email, $otp, $fullName, $phone, $passwordHash, $role);
    if (!$saved) {
        return ['success' => false, 'message' => 'Could not initiate registration. Please try again.', 'code' => 500];
    }

    require_once __DIR__ . '/../../core/Mailer.php';
    $sent = send_otp_email($email, $fullName, $otp);
    if (!$sent) {
        return ['success' => false, 'message' => 'Could not send verification email. Please try again.', 'code' => 500];
    }

    return ['success' => true];
}

function complete_registration_with_otp(mysqli $conn, string $email, string $otp): array {
    $pending = find_pending_registration($conn, $email, $otp);

    if (!$pending) {
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

    mark_pending_registration_used($conn, (int) $pending['id']);

    return ['success' => true, 'user_id' => $result['user_id']];
}

?>
