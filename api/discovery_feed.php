<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/block_handler.php';
require_once __DIR__ . '/../php/discovery_feed.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method not allowed'], 405);
}

// APIs return a 401 rather than redirecting to an HTML login page.
$viewerId = (int) ($_SESSION['user_id'] ?? 0);
if ($viewerId <= 0) {
    json_response(['error' => 'Please sign in to continue.'], 401);
}

$afterId = null;
if (isset($_GET['after'])) {
    $rawAfter = $_GET['after'];
    if (!is_string($rawAfter) || !ctype_digit($rawAfter)
        || strlen($rawAfter) > strlen((string) PHP_INT_MAX)
        || (float) $rawAfter > PHP_INT_MAX) {
        json_response(['error' => 'Invalid pagination cursor'], 400);
    }
    $afterId = (int) $rawAfter;
}

try {
    $preferences = get_preferences($pdo, $viewerId);
    json_response(bushisa_discovery_batch($pdo, $viewerId, $preferences, $afterId));
} catch (Throwable $throwable) {
    error_log('Bushisa discovery fetch failed: ' . $throwable->getMessage());
    json_response(['error' => 'Unable to load profiles right now.'], 500);
}
