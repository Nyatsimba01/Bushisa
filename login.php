<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/password_policy.php';
require_once __DIR__ . '/php/rate_limit.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/auth.php';
require_once __DIR__ . '/php/function.php';

$error = null;
$successRedirect = null;
$csrfToken = null;

if (isset($_SESSION['user_id'])) {
    redirect('discover.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = clean_email((string) ($_POST['email'] ?? ''));
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $result = attempt_login($pdo, $email, $password, $identifier);

        if (!empty($result['success'])) {
            $successRedirect = is_string($result['redirect'] ?? null) ? $result['redirect'] : 'discover.php';
            redirect($successRedirect);
        }

        $error = is_string($result['error'] ?? null) ? $result['error'] : 'Unable to log in.';
    }
}

$csrfToken = generate_csrf_token();

// --- Frontend HTML will be added later ---