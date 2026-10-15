<?php

declare(strict_types=1);

// Small same-origin endpoint for the Confessions Like button.
// Reuses the existing account-specific, transaction-backed Like helper.
require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/confession_interactions.php';

header('Cache-Control: no-store, private');
header('Vary: Cookie');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

// Do not return a login HTML page to a request expecting JSON.
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    json_response(['success' => false, 'error' => 'Session expired. Please log in again.'], 401);
}
refresh_session_expiry();

$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !validate_csrf_token($csrf)) {
    json_response(['success' => false, 'error' => 'Session verification failed. Refresh the page and try again.'], 403);
}

$idRaw = $_POST['confession_id'] ?? null;
if (!is_string($idRaw) || !ctype_digit($idRaw) || (int) $idRaw < 1) {
    json_response(['success' => false, 'error' => 'Invalid confession.'], 400);
}
$confessionId = (int) $idRaw;

$result = bushisa_toggle_confession_like($pdo, $userId, $confessionId);
if (!$result['success']) {
    json_response([
        'success' => false,
        'error' => $result['error'] ?? 'Unable to update your like.',
    ], 409);
}

// Read the authoritative count AFTER the committed database action.
$count = $pdo->prepare('SELECT COUNT(*) FROM confession_likes WHERE confession_id = :id');
$count->execute([':id' => $confessionId]);

json_response([
    'success' => true,
    'liked' => (bool) $result['liked'],
    'like_count' => (int) $count->fetchColumn(),
]);
