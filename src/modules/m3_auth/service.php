<?php
// Path: src/modules/m3_auth/service.php

require_once __DIR__ . '/queries.php';

/**
 * Service function containing business logic for authenticating user and establishing session.
 */
function authenticate_user(string $email, string $password, mysqli $conn): array {
    // Query database for user account
    $user = get_user_by_email($email, $conn);

    if (!$user) {
        throw new Exception('Invalid email address or password');
    }

    if ((int)$user['is_active'] !== 1) {
        throw new Exception('Account is inactive. Please contact administration.');
    }

    // Secure password verification against stored Bcrypt hash
    if (!password_verify($password, $user['password'])) {
        throw new Exception('Invalid email address or password');
    }

    // Fetch associated candidate profile details if user is candidate
    $candidateId = null;
    $fullName = null;

    if ($user['role'] === 'candidate') {
        $candidate = get_candidate_by_user_id((int)$user['id'], $conn);
        if ($candidate) {
            $candidateId = (int)$candidate['id'];
            $fullName = $candidate['full_name'];
        }
    } else {
        $fullName = ucfirst($user['role']) . ' User';
    }

    // Establish secure session state
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['candidate_id'] = $candidateId;
    $_SESSION['full_name'] = $fullName;

    // Return sanitized data payload matching API Contract specifications
    return [
        'user_id' => (int)$user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'candidate_id' => $candidateId,
        'full_name' => $fullName
    ];
}
?>
