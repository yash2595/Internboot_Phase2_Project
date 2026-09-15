<?php
// Path: src/modules/m3_auth/queries.php

/**
 * Executes prepared statement query to fetch user record by email.
 */
function get_user_by_email(string $email, mysqli $conn): ?array {
    $sql = "SELECT id, email, password, role, is_active FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database query preparation failed");
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

/**
 * Executes prepared statement query to fetch candidate profile by user_id.
 */
function get_candidate_by_user_id(int $userId, mysqli $conn): ?array {
    $sql = "SELECT id, user_id, full_name, phone FROM candidates WHERE user_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database query preparation failed");
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    $stmt->close();

    return $candidate ?: null;
}
?>
