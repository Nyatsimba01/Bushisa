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

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$rawBody = '';
$payload = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $decodedPayload = json_decode($rawBody !== false ? $rawBody : '', true);
    $payload = is_array($decodedPayload) ? $decodedPayload : [];
}

$matchId = clean_int($_GET['match_id'] ?? $payload['match_id'] ?? null);

if ($matchId === null || $matchId <= 0) {
    json_response(['error' => 'Invalid match ID'], 400);
}

$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';

$matchStatement = $pdo->prepare(
    sprintf(
        'SELECT id, %1$s AS user_a_id, %2$s AS user_b_id
         FROM matches
         WHERE id = :match_id
           AND (%1$s = :current_user_id OR %2$s = :current_user_id)
         LIMIT 1',
        $matchUserAColumn,
        $matchUserBColumn
    )
);
$matchStatement->execute([
    ':match_id' => $matchId,
    ':current_user_id' => $currentUserId,
]);
$match = $matchStatement->fetch(PDO::FETCH_ASSOC);

if ($match === false) {
    json_response(['error' => 'Match not found'], 403);
}

$otherUserId = (int) ((int) $match['user_a_id'] === $currentUserId ? $match['user_b_id'] : $match['user_a_id']);

if (operational_is_blocked($pdo, $currentUserId, $otherUserId)) {
    json_response(['error' => 'This conversation is unavailable'], 403);
}

$messageColumnsStatement = $pdo->query('SHOW COLUMNS FROM messages');
$messageColumns = $messageColumnsStatement !== false ? $messageColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$messageBodyColumn = in_array('body', $messageColumns, true) ? 'body' : 'message_text';
$messageTimeColumn = in_array('sent_at', $messageColumns, true) ? 'sent_at' : 'created_at';
$hasUnsentAt = in_array('unsent_at', $messageColumns, true);
$hasUnsentBy = in_array('unsent_by', $messageColumns, true);
$hasModerationBody = in_array('moderation_body', $messageColumns, true);

$selectUnsentAt = $hasUnsentAt ? 'unsent_at' : 'NULL AS unsent_at';
$selectUnsentBy = $hasUnsentBy ? 'unsent_by' : 'NULL AS unsent_by';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!operational_has_message_approval($pdo, $matchId)) {
        json_response(['error' => 'Chat permission has not been approved'], 403);
    }

    $afterId = clean_int($_GET['after_id'] ?? null);
    if ($afterId === null || $afterId < 0) {
        $afterId = 0;
    }

    $messagesStatement = $pdo->prepare(
        sprintf(
            'SELECT id, sender_id, %1$s AS body, %2$s AS sent_at, is_read
                , %3$s, %4$s
             FROM messages
             WHERE match_id = :match_id
               AND id > :after_id
             ORDER BY id ASC',
            $messageBodyColumn,
            $messageTimeColumn,
            $selectUnsentAt,
            $selectUnsentBy
        )
    );
    $messagesStatement->execute([
        ':match_id' => $matchId,
        ':after_id' => $afterId,
    ]);
    $messages = $messagesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($messages as &$message) {
        if (!empty($message['unsent_at'])) {
            $message['body'] = 'Message unsent';
            $message['is_unsent'] = true;
        } else {
            $message['is_unsent'] = false;
        }
    }
    unset($message);

    if ($messages !== []) {
        $markReadStatement = $pdo->prepare(
            'UPDATE messages
             SET is_read = 1
             WHERE match_id = :match_id
               AND sender_id = :other_user_id
               AND id > :after_id'
        );
        $markReadStatement->execute([
            ':match_id' => $matchId,
            ':other_user_id' => $otherUserId,
            ':after_id' => $afterId,
        ]);
    }

    json_response([
        'success' => true,
        'data' => $messages,
    ]);
}

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

$action = isset($payload['action']) && is_string($payload['action']) ? clean_enum($payload['action'], ['send', 'unsend']) : 'send';
if ($action === null) {
    json_response(['error' => 'Invalid message action'], 400);
}

if ($action === 'unsend') {
    $messageId = clean_int($payload['message_id'] ?? null);
    if ($messageId === null || $messageId <= 0) {
        json_response(['error' => 'Invalid message ID'], 400);
    }

    if (!$hasUnsentAt || !$hasUnsentBy || !$hasModerationBody) {
        json_response(['error' => 'Unsend is not available until message columns are migrated'], 501);
    }

    try {
        $statement = $pdo->prepare(
            sprintf(
                'UPDATE messages
                 SET moderation_body = CASE WHEN moderation_body IS NULL THEN %1$s ELSE moderation_body END,
                     %1$s = "",
                     unsent_at = NOW(),
                     unsent_by = :current_user_id
                 WHERE id = :message_id
                   AND match_id = :match_id
                   AND sender_id = :current_user_id
                   AND unsent_at IS NULL',
                $messageBodyColumn
            )
        );
        $statement->execute([
            ':message_id' => $messageId,
            ':match_id' => $matchId,
            ':current_user_id' => $currentUserId,
        ]);

        if ($statement->rowCount() < 1) {
            json_response(['error' => 'Message cannot be unsent'], 403);
        }

        log_action($pdo, $currentUserId, 'message_unsent:' . $messageId, $_SERVER['REMOTE_ADDR'] ?? null);
        json_response(['success' => true, 'data' => ['id' => $messageId, 'is_unsent' => true, 'body' => 'Message unsent']]);
    } catch (Throwable $throwable) {
        log_to_file('Message unsend failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
        json_response(['error' => 'Unable to unsend message'], 500);
    }
}

if (!operational_has_message_approval($pdo, $matchId)) {
    json_response(['error' => 'Chat permission has not been approved'], 403);
}

$rateLimitKey = (string) $currentUserId;
if (!check_rate_limit($pdo, 'message', $rateLimitKey, 30, 60)) {
    json_response(['error' => 'Rate limit exceeded'], 429);
}

$body = isset($payload['body']) && is_string($payload['body']) ? trim(clean($payload['body'])) : '';
if ($body === '') {
    json_response(['error' => 'Message cannot be empty'], 400);
}

$body = function_exists('mb_substr') ? mb_substr($body, 0, 1000) : substr($body, 0, 1000);

try {
    $pdo->beginTransaction();
    record_attempt($pdo, 'message', $rateLimitKey);

    $insertStatement = $pdo->prepare(
        sprintf(
            'INSERT INTO messages (match_id, sender_id, %1$s, %2$s, is_read)
             VALUES (:match_id, :sender_id, :body, NOW(), 0)',
            $messageBodyColumn,
            $messageTimeColumn
        )
    );
    $insertStatement->execute([
        ':match_id' => $matchId,
        ':sender_id' => $currentUserId,
        ':body' => $body,
    ]);

    $messageId = (int) $pdo->lastInsertId();

    $messageFetchStatement = $pdo->prepare(
        sprintf(
            'SELECT id, sender_id, %1$s AS body, %2$s AS sent_at, is_read
                , %3$s, %4$s
             FROM messages
             WHERE id = :id
             LIMIT 1',
            $messageBodyColumn,
            $messageTimeColumn,
            $selectUnsentAt,
            $selectUnsentBy
        )
    );
    $messageFetchStatement->execute([':id' => $messageId]);
    $newMessage = $messageFetchStatement->fetch(PDO::FETCH_ASSOC);

    if ($newMessage === false) {
        throw new RuntimeException('Unable to load new message.');
    }

    $pdo->commit();

    log_action($pdo, $currentUserId, 'message_sent', $_SERVER['REMOTE_ADDR'] ?? null);
    log_to_file('User ' . $currentUserId . ' sent message in match ' . $matchId, 'INFO');

    json_response([
        'success' => true,
        'data' => $newMessage,
    ]);
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_to_file('Message send failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
    json_response(['error' => 'Unable to send message'], 500);
}
