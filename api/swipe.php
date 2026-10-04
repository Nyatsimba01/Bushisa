<?php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/rate_limit.php';
require_once __DIR__ . '/../php/logger.php';
require_once __DIR__ . '/../php/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody !== false ? $rawBody : '', true);

if (!is_array($payload)) {
    json_response(['error' => 'Invalid request body'], 400);
}

$targetId = clean_int($payload['target_id'] ?? null);
$direction = isset($payload['direction']) && is_string($payload['direction']) ? clean_enum($payload['direction'], ['like', 'pass']) : null;

if ($targetId === null || $targetId <= 0 || $direction === null) {
    json_response(['error' => 'Invalid swipe payload'], 400);
}

$rateLimitKey = (string) $currentUserId;
if (!check_rate_limit($pdo, 'swipe', $rateLimitKey, 100, 3600)) {
    json_response(['error' => 'Rate limit exceeded'], 429);
}

if ($targetId === $currentUserId) {
    json_response(['error' => 'Cannot swipe yourself'], 400);
}

$targetStatement = $pdo->prepare('SELECT id, is_suspended FROM users WHERE id = :id LIMIT 1');
$targetStatement->execute([':id' => $targetId]);
$targetUser = $targetStatement->fetch(PDO::FETCH_ASSOC);

if ($targetUser === false || ((int) ($targetUser['is_suspended'] ?? 0)) === 1) {
    json_response(['error' => 'Target user is unavailable'], 404);
}

$existingSwipe = $pdo->prepare('SELECT id FROM swipes WHERE swiper_id = :swiper_id AND swiped_id = :swiped_id LIMIT 1');
$existingSwipe->execute([
    ':swiper_id' => $currentUserId,
    ':swiped_id' => $targetId,
]);

if ($existingSwipe->fetchColumn() !== false) {
    json_response(['error' => 'You have already swiped on this user'], 409);
}

$response = ['match' => false];

try {
    $pdo->beginTransaction();

    record_attempt($pdo, 'swipe', $rateLimitKey);

    $insertSwipe = $pdo->prepare(
        'INSERT INTO swipes (swiper_id, swiped_id, direction, created_at)
         VALUES (:swiper_id, :swiped_id, :direction, NOW())'
    );
    $insertSwipe->execute([
        ':swiper_id' => $currentUserId,
        ':swiped_id' => $targetId,
        ':direction' => $direction,
    ]);

    if ($direction === 'like') {
        $reverseSwipe = $pdo->prepare(
            'SELECT id FROM swipes
             WHERE swiper_id = :target_id
               AND swiped_id = :current_user_id
               AND direction = "like"
             LIMIT 1'
        );
        $reverseSwipe->execute([
            ':target_id' => $targetId,
            ':current_user_id' => $currentUserId,
        ]);

        if ($reverseSwipe->fetchColumn() !== false) {
            $userA = min($currentUserId, $targetId);
            $userB = max($currentUserId, $targetId);

            $existingMatch = $pdo->prepare(
                'SELECT id FROM matches
                 WHERE (user_a_id = :user_a AND user_b_id = :user_b)
                    OR (user_a_id = :user_b AND user_b_id = :user_a)
                 LIMIT 1'
            );
            $existingMatch->execute([
                ':user_a' => $userA,
                ':user_b' => $userB,
            ]);
            $matchId = $existingMatch->fetchColumn();

            if ($matchId === false) {
                $insertMatch = $pdo->prepare(
                    'INSERT INTO matches (user_a_id, user_b_id, created_at)
                     VALUES (:user_a_id, :user_b_id, NOW())'
                );
                $insertMatch->execute([
                    ':user_a_id' => $userA,
                    ':user_b_id' => $userB,
                ]);
                $matchId = (int) $pdo->lastInsertId();
            } else {
                $matchId = (int) $matchId;
            }

            $response = [
                'match' => true,
                'match_id' => $matchId,
            ];
        }
    }

    $pdo->commit();
    log_action($pdo, $currentUserId, 'swipe_' . $direction, $_SERVER['REMOTE_ADDR'] ?? null);
    log_to_file('User ' . $currentUserId . ' swiped ' . $direction . ' on ' . $targetId, 'INFO');
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_to_file('Swipe failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
    json_response(['error' => 'Unable to process swipe'], 500);
}

json_response($response);
