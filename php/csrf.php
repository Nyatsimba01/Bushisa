<?php

declare(strict_types=1);

/**
 * Generate and store a CSRF token in the current session.
 *
 * @return string
 */
function generate_csrf_token(): string
{
    if (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '') {
        return $_SESSION['csrf_token'];
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Build a hidden CSRF input field for forms.
 *
 * @return string
 */
function csrf_input_field(): string
{
    $token = generate_csrf_token();

    return sprintf(
        '<input type="hidden" name="csrf_token" value="%s">',
        htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );
}

/**
 * Validate a submitted CSRF token against the session token.
 *
 * @param string $token
 * @return bool
 */
function validate_csrf_token(string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}
