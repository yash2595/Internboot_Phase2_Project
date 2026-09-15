<?php
// Path: src/core/validator.php

/**
 * Sanitizes string inputs to prevent XSS.
 */
function sanitize_string(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validates email format.
 */
function is_valid_email(string $email): bool {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}
?>
