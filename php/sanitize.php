<?php

declare(strict_types=1);

/**
 * Sanitize general input for safe output and storage.
 *
 * @param string $input
 * @return string
 */
function clean(string $input): string
{
    $normalized = trim(str_replace("\0", '', $input));

    return htmlspecialchars($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Sanitize an email address and validate its format.
 *
 * @param string $email
 * @return string
 */
function clean_email(string $email): string
{
    $normalized = strtolower(trim(str_replace("\0", '', $email)));
    $validated = filter_var($normalized, FILTER_VALIDATE_EMAIL);

    return is_string($validated) ? $validated : '';
}

/**
 * Validate and normalize an integer-like value.
 *
 * @param mixed $value
 * @return int|null
 */
function clean_int($value): ?int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return null;
    }

    return (int) $value;
}

/**
 * Validate a value against an allowed list.
 *
 * @param string $value
 * @param array<int, string> $allowed
 * @return string|null
 */
function clean_enum(string $value, array $allowed): ?string
{
    return in_array($value, $allowed, true) ? $value : null;
}
