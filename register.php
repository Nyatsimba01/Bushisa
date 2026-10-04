<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/password_policy.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/auth.php';
require_once __DIR__ . '/php/function.php';

$error = null;
$success = null;
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
        $formData = [
            'nust_student_id' => strtoupper(trim(str_replace("\0", '', (string) ($_POST['nust_student_id'] ?? '')))),
            'email' => clean_email((string) ($_POST['email'] ?? '')),
            'password' => isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '',
            'display_name' => trim(str_replace("\0", '', (string) ($_POST['display_name'] ?? ''))),
            'anon_handle' => ltrim(trim(str_replace("\0", '', (string) ($_POST['anon_handle'] ?? ''))), '@'),
            'gender' => strtolower(trim(str_replace("\0", '', (string) ($_POST['gender'] ?? '')))),
            'faculty' => trim(str_replace("\0", '', (string) ($_POST['faculty'] ?? ''))),
            'year_of_study' => trim(str_replace("\0", '', (string) ($_POST['year_of_study'] ?? ''))),
        ];

        $result = register_user($pdo, $formData);

        if (!empty($result['success'])) {
            $success = is_string($result['message'] ?? null) ? $result['message'] : 'Account created successfully.';
            $successRedirect = is_string($result['redirect'] ?? null) ? $result['redirect'] : null;
        } else {
            $error = is_string($result['error'] ?? null) ? $result['error'] : 'Registration failed.';
        }
    }
}

$csrfToken = generate_csrf_token();

// --- Frontend HTML will be added later ---