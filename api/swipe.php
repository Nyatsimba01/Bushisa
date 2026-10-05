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
require_once __DIR__ . '/../php/operational.php';

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

$submittedToken = '';
if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (isset($payload['csrf_token']) && is_string($payload['csrf_token'])) {
    $submittedToken = $payload['csrf_token'];
}

if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
    json_response(['error' => 'Invalid CSRF token'], 403);
}

ensure_operational_schema($pdo);

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

if (operational_is_blocked($pdo, $currentUserId, $targetId)) {
    json_response(['error' => 'This profile is unavailable'], 403);
}

$targetStatement = $pdo->prepare('SELECT id, is_suspended FROM users WHERE id = :id LIMIT 1');
$targetStatement->execute([':id' => $targetId]);
$targetUser = $targetStatement->fetch(PDO::FETCH_ASSOC);

if ($targetUser === false || ((int) ($targetUser['is_suspended'] ?? 0)) === 1) {
    json_response(['error' => 'Target user is unavailable'], 404);
}

$swipeColumns = operational_swipe_columns($pdo);
$matchColumns = operational_match_columns($pdo);

$existingSwipe = $pdo->prepare(
    sprintf(
        'SELECT id FROM swipes WHERE swiper_id = :swiper_id AND %1$s = :target_id LIMIT 1',
        $swipeColumns['target']
    )
);
$existingSwipe->execute([
    ':swiper_id' => $currentUserId,
    ':target_id' => $targetId,
]);

if ($existingSwipe->fetchColumn() !== false) {
    json_response(['error' => 'You have already swiped on this user'], 409);
}

$response = ['match' => false];

try {
    $pdo->beginTransaction();

    record_attempt($pdo, 'swipe', $rateLimitKey);

    $insertSwipe = $pdo->prepare(
        sprintf(
            'INSERT INTO swipes (swiper_id, %1$s, %2$s, created_at)
             VALUES (:swiper_id, :target_id, :direction, NOW())',
            $swipeColumns['target'],
            $swipeColumns['direction']
        )
    );
    $insertSwipe->execute([
        ':swiper_id' => $currentUserId,
        ':target_id' => $targetId,
        ':direction' => $direction,
    ]);

    if ($direction === 'like') {
        $reverseSwipe = $pdo->prepare(
            sprintf(
            'SELECT id FROM swipes
             WHERE swiper_id = :target_id
               AND %1$s = :current_user_id
               AND %2$s = "like"
             LIMIT 1',
            $swipeColumns['target'],
            $swipeColumns['direction']
            )
        );
        $reverseSwipe->execute([
            ':target_id' => $targetId,
            ':current_user_id' => $currentUserId,
        ]);

        if ($reverseSwipe->fetchColumn() !== false) {
            $userA = min($currentUserId, $targetId);
            $userB = max($currentUserId, $targetId);

            $existingMatch = $pdo->prepare(
                sprintf(
                    'SELECT id FROM matches
                     WHERE (%1$s = :user_a AND %2$s = :user_b)
                        OR (%1$s = :user_b AND %2$s = :user_a)
                     LIMIT 1',
                    $matchColumns['user_a'],
                    $matchColumns['user_b']
                )
            );
            $existingMatch->execute([
                ':user_a' => $userA,
                ':user_b' => $userB,
            ]);
            $matchId = $existingMatch->fetchColumn();

            if ($matchId === false) {
                $insertMatch = $pdo->prepare(
                    sprintf(
                        'INSERT INTO matches (%1$s, %2$s, %3$s)
                         VALUES (:user_a_id, :user_b_id, NOW())',
                        $matchColumns['user_a'],
                        $matchColumns['user_b'],
                        $matchColumns['created_at']
                    )
                );
                $insertMatch->execute([
                    ':user_a_id' => $userA,
                    ':user_b_id' => $userB,
                ]);
                $matchId = (int) $pdo->lastInsertId();
            } else {
                $matchId = (int) $matchId;
            }

            operational_write_notification(
                $pdo,
                $targetId,
                'match',
                'New match',
                'You have a new established match on Bushisa.',
                'matches.php',
                'match:' . $matchId . ':' . $targetId
            );
            operational_write_notification(
                $pdo,
                $currentUserId,
                'match',
                'New match',
                'You have a new established match on Bushisa.',
                'matches.php',
                'match:' . $matchId . ':' . $currentUserId
            );

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
