<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/password_policy.php';
require_once __DIR__ . '/../php/anonymiser.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/operational.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody !== false ? $rawBody : '', true);
$payload = is_array($payload) ? $payload : $_POST;

$submittedToken = '';
if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (isset($payload['csrf_token']) && is_string($payload['csrf_token'])) {
    $submittedToken = $payload['csrf_token'];
}

if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
    json_response(['error' => 'Invalid CSRF token'], 403);
}

$action = isset($payload['action']) && is_string($payload['action']) ? clean_enum($payload['action'], ['delete_account']) : null;
if ($action !== 'delete_account') {
    json_response(['error' => 'Invalid account action'], 400);
}

$password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';
if ($password === '') {
    json_response(['error' => 'Password confirmation is required'], 400);
}

$user = find_user_by_id($pdo, $currentUserId);
if ($user === null || !isset($user['password_hash']) || !is_string($user['password_hash']) || !verify_password($password, $user['password_hash'])) {
    json_response(['error' => 'Password confirmation failed'], 403);
}

if (!anonymise_user($pdo, $currentUserId, $currentUserId)) {
    json_response(['error' => 'Unable to delete account right now'], 500);
}

destroy_session();
json_response(['success' => true, 'data' => ['redirect' => 'login.php']]);

