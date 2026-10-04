<?php

declare(strict_types=1);

const BUSHISA_SESSION_TIMEOUT = 1800;

/**
 * Configure secure session cookie parameters.
 *
 * @return array<string, mixed>
 */
function bushisa_session_cookie_params(): array
{
    return [
        'lifetime' => BUSHISA_SESSION_TIMEOUT,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

/**
 * Refresh the session cookie expiry and inactivity timestamp.
 *
 * @return void
 */
function refresh_session_expiry(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['last_activity'] = time();

    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + BUSHISA_SESSION_TIMEOUT,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Start the session with secure defaults.
 *
 * @return void
 */
function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    session_set_cookie_params(bushisa_session_cookie_params());
    session_start();

    if (!isset($_SESSION['last_activity'])) {
        refresh_session_expiry();
        return;
    }

    $lastActivity = (int) $_SESSION['last_activity'];
    if ($lastActivity > 0 && (time() - $lastActivity) > BUSHISA_SESSION_TIMEOUT) {
        destroy_session();
        return;
    }

    refresh_session_expiry();
}

/**
 * Regenerate the session identifier after authentication or privilege changes.
 *
 * @return void
 */
function regenerate_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        refresh_session_expiry();
    }
}

/**
 * Destroy the current session and remove the session cookie.
 *
 * @return void
 */
function destroy_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    session_destroy();
}

/**
 * Ensure the user is authenticated before continuing.
 *
 * @return void
 */
function require_login(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }

    if (!isset($_SESSION['user_id'])) {
        destroy_session();
        header('Location: /login.php');
        exit;
    }

    refresh_session_expiry();
}

start_secure_session();
