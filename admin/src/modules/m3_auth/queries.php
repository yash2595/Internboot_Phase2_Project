<?php
// Path: src/modules/m3_auth/queries.php
//
// Matches the real schema.sql from M1:
//   users(id, email, password, role, is_active, created_at, updated_at)
//   candidates(id, user_id FK->users.id, full_name, phone UNIQUE, profile_details, ...)
//
// Registration is a two-table write (users + candidates), so it runs inside
// a transaction. There is no status/progress field on users — eligibility
// (paid/enrolled) lives in `enrollments.eligibility_status`, which belongs
// to M4/M5, not this module.

/**
 * Fetch a user (joined with their candidate profile) by email.
 * Returns null if not found.
 */
function find_user_by_email(mysqli $conn, string $email): ?array {
    $stmt = $conn->prepare(
        'SELECT u.id, u.email, u.password, u.role, u.is_active,
                c.full_name, c.phone
         FROM users u
         LEFT JOIN candidates c ON c.user_id = u.id
         WHERE u.email = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

/**
 * Whether a phone number is already taken (candidates.phone is UNIQUE).
 */
function candidate_phone_exists(mysqli $conn, string $phone): bool {
    $stmt = $conn->prepare('SELECT id FROM candidates WHERE phone = ? LIMIT 1');
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Creates the users row + candidates row together in one transaction.
 * Returns ['success' => bool, 'user_id' => int, 'message' => string, 'code' => int]
 *
 * Pre-checking email/phone in service.php avoids most duplicate-key hits,
 * but this still wraps the two inserts in a transaction and rolls back on
 * any failure (e.g. a race between the pre-check and the insert) so a
 * candidate row is never left orphaned without its user row, or vice versa.
 */
function insert_user_and_candidate(mysqli $conn, string $email, string $passwordHash, string $fullName, string $phone, string $role = 'candidate'): array {
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $email, $passwordHash, $role);
        $stmt->execute();

        if ($conn->errno) {
            throw new mysqli_sql_exception($conn->error, $conn->errno);
        }

        $userId = (int) $stmt->insert_id;
        $stmt->close();

        $stmt2 = $conn->prepare('INSERT INTO candidates (user_id, full_name, phone) VALUES (?, ?, ?)');
        $stmt2->bind_param('iss', $userId, $fullName, $phone);
        $stmt2->execute();

        if ($conn->errno) {
            throw new mysqli_sql_exception($conn->error, $conn->errno);
        }
        $stmt2->close();

        $conn->commit();
        return ['success' => true, 'user_id' => $userId];
    } catch (Throwable $e) {
        $conn->rollback();

        // 1062 = MySQL duplicate-entry error code
        $isDuplicate = (method_exists($e, 'getCode') && (int) $e->getCode() === 1062) || $conn->errno === 1062;
        if ($isDuplicate) {
            $onPhone = str_contains($e->getMessage(), 'phone') || str_contains($conn->error, 'phone');
            return [
                'success' => false,
                'code' => 409,
                'message' => $onPhone
                    ? 'This phone number is already registered.'
                    : 'An account with this email already exists.',
            ];
        }

        return ['success' => false, 'code' => 500, 'message' => 'Could not complete registration. Please try again.'];
    }
}

function save_pending_registration(mysqli $conn, string $email, string $otp, string $fullName, string $phone, string $passwordHash, string $role): bool {
    // Clear any old pending entries for this email first
    $del = $conn->prepare('DELETE FROM email_verifications WHERE email = ?');
    $del->bind_param('s', $email);
    $del->execute();
    $del->close();

    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    $stmt = $conn->prepare(
        'INSERT INTO email_verifications (email, otp_code, full_name, phone, password_hash, role, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssss', $email, $otp, $fullName, $phone, $passwordHash, $role, $expiresAt);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function find_pending_registration(mysqli $conn, string $email, string $otp): ?array {
    $stmt = $conn->prepare(
        'SELECT * FROM email_verifications
         WHERE email = ? AND otp_code = ? AND is_used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function mark_pending_registration_used(mysqli $conn, int $id): void {
    $stmt = $conn->prepare('UPDATE email_verifications SET is_used = 1 WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

?>
