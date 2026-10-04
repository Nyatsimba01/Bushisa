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

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$matchId = clean_int($_GET['match_id'] ?? null);

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

$messageColumnsStatement = $pdo->query('SHOW COLUMNS FROM messages');
$messageColumns = $messageColumnsStatement !== false ? $messageColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$messageBodyColumn = in_array('body', $messageColumns, true) ? 'body' : 'message_text';
$messageTimeColumn = in_array('sent_at', $messageColumns, true) ? 'sent_at' : 'created_at';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $afterId = clean_int($_GET['after_id'] ?? null);
    if ($afterId === null || $afterId < 0) {
        $afterId = 0;
    }

    $messagesStatement = $pdo->prepare(
        sprintf(
            'SELECT id, sender_id, %1$s AS body, %2$s AS sent_at, is_read
             FROM messages
             WHERE match_id = :match_id
               AND id > :after_id
             ORDER BY id ASC',
            $messageBodyColumn,
            $messageTimeColumn
        )
    );
    $messagesStatement->execute([
        ':match_id' => $matchId,
        ':after_id' => $afterId,
    ]);
    $messages = $messagesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
             FROM messages
             WHERE id = :id
             LIMIT 1',
            $messageBodyColumn,
            $messageTimeColumn
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
