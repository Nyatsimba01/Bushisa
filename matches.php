<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/function.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$matches = [];

$sql = <<<'SQL'
SELECT
    m.id AS match_id,
    CASE
        WHEN m.user_a_id = :current_user_id THEN m.user_b_id
        ELSE m.user_a_id
    END AS other_user_id,
    p.display_name,
    p.profile_photo_path,
    p.faculty,
    lm.body AS last_message,
    lm.sent_at AS last_message_time,
    m.created_at AS matched_at
FROM matches m
INNER JOIN profiles p
    ON p.user_id = CASE
        WHEN m.user_a_id = :current_user_id THEN m.user_b_id
        ELSE m.user_a_id
    END
LEFT JOIN (
    SELECT msg.match_id, msg.body, msg.sent_at
    FROM messages msg
    INNER JOIN (
        SELECT match_id, MAX(id) AS last_message_id
        FROM messages
        GROUP BY match_id
    ) latest ON latest.last_message_id = msg.id
) lm ON lm.match_id = m.id
WHERE m.user_a_id = :current_user_id
   OR m.user_b_id = :current_user_id
ORDER BY COALESCE(lm.sent_at, m.created_at) DESC, m.id DESC
SQL;

$statement = $pdo->prepare($sql);
$statement->execute([':current_user_id' => $currentUserId]);
$matches = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

// --- Frontend HTML will be added later ---