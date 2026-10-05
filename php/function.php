<?php

declare(strict_types=1);

/**
 * Redirect the client to a new URL and stop execution.
 *
 * @param string $url
 * @return void
 */
function redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Determine whether the current request is an AJAX request.
 *
 * @return bool
 */
function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Emit a JSON response and stop execution.
 *
 * @param array<string, mixed> $data
 * @param int $status_code
 * @return void
 */
function json_response(array $data, int $status_code = 200): void
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get the current authenticated user's ID from session.
 *
 * @return int|null
 */
function get_current_user_id(): ?int
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}

/**
 * Check whether the current user is an administrator.
 *
 * @return bool
 */
function is_admin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Check whether the current user is an administrator or moderator.
 *
 * @return bool
 */
function is_moderator(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'moderator'], true);
}

/**
 * Format a datetime string as relative time.
 *
 * @param string $datetime
 * @return string
 */
function format_time_ago(string $datetime): string
{
    $time = strtotime($datetime);
    if ($time === false) {
        return '';
    }

    $diff = time() - $time;
    if ($diff < 60) {
        return 'Just now';
    }

    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    $days = (int) floor($diff / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

/**
 * Validate a NUST student email address.
 *
 * @param string $email
 * @return bool
 */
function validate_nust_email(string $email): bool
{
    return str_ends_with(strtolower(trim($email)), '@students.nust.ac.zw');
}