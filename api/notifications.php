<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/operational.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $statement = $pdo->prepare(
        'SELECT id, category, title, body, deep_link, read_at, created_at
         FROM notifications
         WHERE recipient_id = :recipient_id
         ORDER BY created_at DESC, id DESC
         LIMIT 30'
    );
    $statement->execute([':recipient_id' => $currentUserId]);

    json_response([
        'success' => true,
        'data' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ]);
}

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

$action = isset($payload['action']) && is_string($payload['action'])
    ? clean_enum($payload['action'], ['mark_read', 'save_preferences'])
    : null;

if ($action === 'mark_read') {
    $notificationId = clean_int($payload['notification_id'] ?? null);
    if ($notificationId === null) {
        $statement = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE recipient_id = :recipient_id AND read_at IS NULL');
        $statement->execute([':recipient_id' => $currentUserId]);
    } else {
        $statement = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND recipient_id = :recipient_id');
        $statement->execute([
            ':id' => $notificationId,
            ':recipient_id' => $currentUserId,
        ]);
    }

    json_response(['success' => true, 'data' => null]);
}

if ($action === 'save_preferences') {
    $categories = ['match', 'message_request', 'message', 'confession_reference', 'profile_view', 'milestone'];
    foreach ($categories as $category) {
        $enabled = !empty($payload[$category]) ? 1 : 0;
        $statement = $pdo->prepare(
            'INSERT INTO notification_preferences (user_id, category, is_enabled)
             VALUES (:user_id, :category, :is_enabled)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)'
        );
        $statement->execute([
            ':user_id' => $currentUserId,
            ':category' => $category,
            ':is_enabled' => $enabled,
        ]);
    }

    json_response(['success' => true, 'data' => null]);
}

json_response(['error' => 'Invalid notification action'], 400);

