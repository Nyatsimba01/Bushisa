<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/function.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();

$currentUserId = (int) $_SESSION['user_id'];

$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';
$matchTimeColumn = in_array('created_at', $matchColumns, true) ? 'created_at' : (in_array('matched_at', $matchColumns, true) ? 'matched_at' : 'created_at');

$messageColumnsStatement = $pdo->query('SHOW COLUMNS FROM messages');
$messageColumns = $messageColumnsStatement !== false ? $messageColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$messageBodyColumn = in_array('body', $messageColumns, true) ? 'body' : 'message_text';
$messageTimeColumn = in_array('sent_at', $messageColumns, true) ? 'sent_at' : 'created_at';

$sql = sprintf(
    'SELECT
        m.id AS match_id,
        CASE
            WHEN m.%1$s = :current_user_id THEN m.%2$s
            ELSE m.%1$s
        END AS other_user_id,
        p.display_name,
        p.profile_photo_path AS photo,
        p.faculty,
        lm.body AS last_message,
        lm.sent_at AS last_message_time,
        COALESCE(lm.unread_count, 0) AS unread_count,
        COALESCE(lm.last_activity, m.%3$s) AS sort_time
     FROM matches m
     INNER JOIN profiles p
        ON p.user_id = CASE
            WHEN m.%1$s = :current_user_id THEN m.%2$s
            ELSE m.%1$s
        END
     LEFT JOIN (
         SELECT
             msg.match_id,
             MAX(msg.id) AS last_message_id,
             COUNT(CASE WHEN msg.is_read = 0 AND msg.sender_id <> :current_user_id THEN 1 END) AS unread_count,
             MAX(CASE WHEN msg.id = latest_msg.last_message_id THEN msg.%4$s END) AS body,
             MAX(CASE WHEN msg.id = latest_msg.last_message_id THEN msg.%5$s END) AS sent_at,
             MAX(msg.%5$s) AS last_activity
         FROM messages msg
         INNER JOIN (
             SELECT match_id, MAX(id) AS last_message_id
             FROM messages
             GROUP BY match_id
         ) latest_msg ON latest_msg.match_id = msg.match_id
         GROUP BY msg.match_id
     ) lm ON lm.match_id = m.id
     WHERE m.%1$s = :current_user_id OR m.%2$s = :current_user_id
     ORDER BY COALESCE(lm.last_activity, m.%3$s) DESC, m.id DESC',
    $matchUserAColumn,
    $matchUserBColumn,
    $matchTimeColumn,
    $messageBodyColumn,
    $messageTimeColumn
);

$statement = $pdo->prepare($sql);
$statement->execute([':current_user_id' => $currentUserId]);
$matches = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

json_response([
    'success' => true,
    'data' => $matches,
]);
