<?php
function require_positive_int($value, string $field): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < 1) {
        throw new InvalidArgumentException($field . ' must be a positive integer.');
    }
    return $number;
}

function sanitize_string(string $input): string
{
    return trim($input);
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
