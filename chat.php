<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/rate_limit.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/function.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$error = null;
$success = null;
$csrfToken = null;
$messages = [];
$other_user = null;

$matchId = clean_int($_GET['match_id'] ?? null);
if ($matchId === null || $matchId <= 0) {
    redirect('matches.php');
}

$matchStatement = $pdo->prepare(
    'SELECT m.id, m.user_a_id, m.user_b_id,
            p.user_id AS other_user_id, p.display_name, p.profile_photo_path, p.faculty
     FROM matches m
     INNER JOIN profiles p
         ON p.user_id = CASE
             WHEN m.user_a_id = :current_user_id THEN m.user_b_id
             ELSE m.user_a_id
         END
     WHERE m.id = :match_id
       AND (m.user_a_id = :current_user_id OR m.user_b_id = :current_user_id)
     LIMIT 1'
);
$matchStatement->execute([
    ':match_id' => $matchId,
    ':current_user_id' => $currentUserId,
]);
$matchRow = $matchStatement->fetch(PDO::FETCH_ASSOC);

if ($matchRow === false) {
    redirect('matches.php');
}

$other_user = [
    'user_id' => (int) $matchRow['other_user_id'],
    'display_name' => (string) $matchRow['display_name'],
    'profile_photo_path' => $matchRow['profile_photo_path'] ?? null,
    'faculty' => $matchRow['faculty'] ?? null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $rateLimitKey = (string) $currentUserId;
        if (!check_rate_limit($pdo, 'message', $rateLimitKey, 30, 60)) {
            $error = 'You are sending messages too quickly.';
        } else {
            $body = trim(clean((string) ($_POST['body'] ?? '')));
            if ($body === '') {
                $error = 'Message cannot be empty.';
            } else {
                try {
                    record_attempt($pdo, 'message', $rateLimitKey);

                    $insertMessage = $pdo->prepare(
                        'INSERT INTO messages (match_id, sender_id, body, is_read, sent_at)
                         VALUES (:match_id, :sender_id, :body, 0, NOW())'
                    );
                    $insertMessage->execute([
                        ':match_id' => $matchId,
                        ':sender_id' => $currentUserId,
                        ':body' => $body,
                    ]);

                    log_action($pdo, $currentUserId, 'message_sent', $_SERVER['REMOTE_ADDR'] ?? null);
                    log_to_file('User ' . $currentUserId . ' sent a message in match ' . $matchId, 'INFO');

                    $success = 'Message sent.';
                } catch (Throwable $throwable) {
                    log_to_file('Message send failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
                    $error = 'Unable to send your message right now.';
                }
            }
        }
    }
}

$messageStatement = $pdo->prepare(
    'SELECT id, match_id, sender_id, body, sent_at, is_read
     FROM messages
     WHERE match_id = :match_id
     ORDER BY sent_at ASC, id ASC'
);
$messageStatement->execute([':match_id' => $matchId]);
$messages = $messageStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$markReadStatement = $pdo->prepare(
    'UPDATE messages
     SET is_read = 1
     WHERE match_id = :match_id
       AND sender_id <> :current_user_id
       AND is_read = 0'
);
$markReadStatement->execute([
    ':match_id' => $matchId,
    ':current_user_id' => $currentUserId,
]);

$csrfToken = generate_csrf_token();

// --- Frontend HTML will be added later ---