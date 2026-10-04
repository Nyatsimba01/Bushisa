<?php

declare(strict_types=1);

/**
 * Validate password strength and return any rule violations.
 *
 * @param string $password
 * @return array<int, string>
 */
function validate_password(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }

    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one digit.';
    }

    return $errors;
}

/**
 * Hash a password using bcrypt.
 *
 * @param string $password
 * @return string
 */
function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify a password against a stored hash.
 *
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}
