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
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/operational.php';

require_login();
ensure_operational_schema($pdo);

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

$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';

$matchStatement = $pdo->prepare(
    sprintf(
    'SELECT m.id, m.%1$s AS user_a_id, m.%2$s AS user_b_id,
            p.user_id AS other_user_id, p.display_name, p.profile_photo_path, p.faculty
     FROM matches m
     INNER JOIN profiles p
         ON p.user_id = CASE
             WHEN m.%1$s = :current_user_id THEN m.%2$s
             ELSE m.%1$s
         END
     WHERE m.id = :match_id
       AND (m.%1$s = :current_user_id OR m.%2$s = :current_user_id)
     LIMIT 1',
    $matchUserAColumn,
    $matchUserBColumn
    )
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

$messageColumnsStatement = $pdo->query('SHOW COLUMNS FROM messages');
$messageColumns = $messageColumnsStatement !== false ? $messageColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$messageBodyColumn = in_array('body', $messageColumns, true) ? 'body' : 'message_text';
$messageTimeColumn = in_array('sent_at', $messageColumns, true) ? 'sent_at' : 'created_at';
$selectUnsentAt = in_array('unsent_at', $messageColumns, true) ? 'unsent_at' : 'NULL AS unsent_at';
$selectUnsentBy = in_array('unsent_by', $messageColumns, true) ? 'unsent_by' : 'NULL AS unsent_by';
$permissionState = operational_message_request_status($pdo, $matchId, $currentUserId, (int) $other_user['user_id']);
$chatApproved = $permissionState['status'] === 'approved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $action = clean_enum((string) ($_POST['action'] ?? 'send_message'), ['send_message', 'request_chat', 'approve_chat', 'decline_chat', 'revoke_chat']);

        if ($action === null) {
            $error = 'Invalid conversation action.';
        } elseif ($action === 'request_chat') {
            $statement = $pdo->prepare(
                'INSERT INTO message_requests (match_id, requester_id, recipient_id, status, requested_at)
                 VALUES (:match_id, :requester_id, :recipient_id, "pending", NOW())
                 ON DUPLICATE KEY UPDATE
                    status = CASE WHEN status = "revoked" THEN "pending" ELSE status END,
                    requested_at = CASE WHEN status = "revoked" THEN NOW() ELSE requested_at END,
                    revoked_at = CASE WHEN status = "revoked" THEN NULL ELSE revoked_at END'
            );
            $statement->execute([
                ':match_id' => $matchId,
                ':requester_id' => $currentUserId,
                ':recipient_id' => (int) $other_user['user_id'],
            ]);
            operational_write_notification(
                $pdo,
                (int) $other_user['user_id'],
                'message_request',
                'Message request',
                'An established match requested permission to chat.',
                'chat.php?match_id=' . $matchId,
                'message_request:' . $matchId . ':' . (int) $other_user['user_id']
            );
            $success = 'Message request sent.';
        } elseif ($action === 'approve_chat' || $action === 'decline_chat') {
            if (!$permissionState['is_recipient'] || $permissionState['status'] !== 'pending') {
                $error = 'No pending message request is available.';
            } else {
                $nextStatus = $action === 'approve_chat' ? 'approved' : 'declined';
                $statement = $pdo->prepare(
                    'UPDATE message_requests
                     SET status = :status, responded_at = NOW(), revoked_at = NULL
                     WHERE match_id = :match_id
                       AND recipient_id = :current_user_id
                       AND requester_id = :other_user_id
                       AND status = "pending"'
                );
                $statement->execute([
                    ':status' => $nextStatus,
                    ':match_id' => $matchId,
                    ':current_user_id' => $currentUserId,
                    ':other_user_id' => (int) $other_user['user_id'],
                ]);
                $success = $nextStatus === 'approved' ? 'Chat permission approved.' : 'Chat request declined.';
            }
        } elseif ($action === 'revoke_chat') {
            $statement = $pdo->prepare(
                'UPDATE message_requests
                 SET status = "revoked", revoked_at = NOW()
                 WHERE match_id = :match_id
                   AND status IN ("pending", "approved")'
            );
            $statement->execute([':match_id' => $matchId]);
            $success = 'Chat permission revoked.';
        } elseif (!$chatApproved) {
            $error = 'Chat permission has not been approved yet.';
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
                        sprintf(
                            'INSERT INTO messages (match_id, sender_id, %1$s, is_read, %2$s)
                             VALUES (:match_id, :sender_id, :body, 0, NOW())',
                            $messageBodyColumn,
                            $messageTimeColumn
                        )
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

    $permissionState = operational_message_request_status($pdo, $matchId, $currentUserId, (int) $other_user['user_id']);
    $chatApproved = $permissionState['status'] === 'approved';
    }
}

$messages = [];
if ($chatApproved) {
    $messageStatement = $pdo->prepare(
        sprintf(
        'SELECT id, match_id, sender_id, %1$s AS body, %2$s AS sent_at, is_read, %3$s, %4$s
         FROM messages
         WHERE match_id = :match_id
         ORDER BY %2$s ASC, id ASC',
        $messageBodyColumn,
        $messageTimeColumn,
        $selectUnsentAt,
        $selectUnsentBy
        )
    );
    $messageStatement->execute([':match_id' => $matchId]);
    $messages = $messageStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($messages as &$message) {
        if (!empty($message['unsent_at'])) {
            $message['body'] = 'Message unsent';
            $message['is_unsent'] = true;
        } else {
            $message['is_unsent'] = false;
        }
    }
    unset($message);
}

if ($chatApproved) {
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
}

$csrfToken = generate_csrf_token();

render_page_shell_start('chat', (string) ($other_user['display_name'] ?? 'Conversation'), $csrfToken, $currentUserId, false, 'Approved conversation state');
?>
<?php render_flash($error, $success); ?>

<section class="message-card">
    <div class="chat-header">
        <a class="button-secondary" href="matches.php">Back</a>
        <div class="profile-summary">
            <?= render_avatar($other_user['profile_photo_path'] ?? null, (string) ($other_user['display_name'] ?? 'Match')) ?>
            <div>
                <h2><?= e($other_user['display_name'] ?? 'Match') ?></h2>
                <p class="muted"><?= e($other_user['faculty'] ?? 'NUST student') ?></p>
            </div>
        </div>
        <a class="button-secondary" href="community_guidelines.php">Safety</a>
    </div>
    <p class="field-help">A match is not chat permission. Messages are available only after a recipient approves the request.</p>
</section>

<section class="message-card chat-thread" aria-labelledby="thread-title">
    <h2 class="sr-only" id="thread-title">Conversation messages</h2>
    <?php if (!$chatApproved): ?>
        <div class="empty-state">
            <?php if ($permissionState['status'] === 'none' || $permissionState['status'] === 'declined' || $permissionState['status'] === 'revoked'): ?>
                <h2>Ask before chatting</h2>
                <p>Send a message request first. The other person can approve or decline without pressure.</p>
                <form method="post" action="chat.php?match_id=<?= e($matchId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="request_chat">
                    <button class="button" type="submit">Request message permission</button>
                </form>
            <?php elseif ($permissionState['status'] === 'pending' && $permissionState['is_requester']): ?>
                <h2>Request pending</h2>
                <p>Your match has not approved chat permission yet.</p>
                <form method="post" action="chat.php?match_id=<?= e($matchId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="revoke_chat">
                    <button class="button-secondary" type="submit">Cancel request</button>
                </form>
            <?php elseif ($permissionState['status'] === 'pending' && $permissionState['is_recipient']): ?>
                <h2>Message request</h2>
                <p>This established match wants permission to chat. Approving enables this conversation.</p>
                <div class="card-actions">
                    <form method="post" action="chat.php?match_id=<?= e($matchId) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="approve_chat">
                        <button class="button" type="submit">Approve</button>
                    </form>
                    <form method="post" action="chat.php?match_id=<?= e($matchId) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="decline_chat">
                        <button class="button-secondary" type="submit">Decline</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="message-list">
        <?php if ($messages === []): ?>
            <div class="empty-state">
                <h2>No messages yet</h2>
                <p>Start only when both people are comfortable. Connection is not consent to chat.</p>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $message): ?>
                <?php $mine = (int) ($message['sender_id'] ?? 0) === $currentUserId; ?>
                <article class="message-bubble<?= $mine ? ' message-bubble--mine' : '' ?>">
                    <p><?= e($message['body'] ?? '') ?></p>
                    <time class="message-time" datetime="<?= e($message['sent_at'] ?? '') ?>"><?= e(format_time_ago((string) ($message['sent_at'] ?? ''))) ?></time>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <form class="chat-composer" method="post" action="chat.php?match_id=<?= e($matchId) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="send_message">
        <label class="sr-only" for="message-body">Message</label>
        <textarea id="message-body" name="body" rows="2" maxlength="1000" required placeholder="Write a respectful message..."></textarea>
        <button class="chat-composer__send" type="submit">Send</button>
    </form>
    <?php endif; ?>
</section>
<?php
render_page_shell_end();
