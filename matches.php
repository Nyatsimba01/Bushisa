<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$matches = [];

$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';
$matchTimeColumn = in_array('matched_at', $matchColumns, true) ? 'matched_at' : 'created_at';

$messageColumnsStatement = $pdo->query('SHOW COLUMNS FROM messages');
$messageColumns = $messageColumnsStatement !== false ? $messageColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$messageBodyColumn = in_array('body', $messageColumns, true) ? 'body' : 'message_text';
$messageTimeColumn = in_array('sent_at', $messageColumns, true) ? 'sent_at' : 'created_at';

$sql = sprintf(
    'SELECT
        m.id AS match_id,
        CASE WHEN m.%1$s = :select_user THEN m.%2$s ELSE m.%1$s END AS other_user_id,
        p.display_name,
        p.profile_photo_path,
        p.faculty,
        lm.body AS last_message,
        lm.sent_at AS last_message_time,
        m.%3$s AS matched_at
    FROM matches m
    INNER JOIN profiles p
        ON p.user_id = CASE WHEN m.%1$s = :join_user THEN m.%2$s ELSE m.%1$s END
    LEFT JOIN (
        SELECT msg.match_id, msg.%4$s AS body, msg.%5$s AS sent_at
        FROM messages msg
        INNER JOIN (
            SELECT match_id, MAX(id) AS last_message_id
            FROM messages
            GROUP BY match_id
        ) latest ON latest.last_message_id = msg.id
    ) lm ON lm.match_id = m.id
    WHERE m.%1$s = :where_user_a OR m.%2$s = :where_user_b
    ORDER BY COALESCE(lm.sent_at, m.%3$s) DESC, m.id DESC',
    $matchUserAColumn,
    $matchUserBColumn,
    $matchTimeColumn,
    $messageBodyColumn,
    $messageTimeColumn
);

$statement = $pdo->prepare($sql);
$statement->execute([
    ':select_user' => $currentUserId,
    ':join_user' => $currentUserId,
    ':where_user_a' => $currentUserId,
    ':where_user_b' => $currentUserId,
]);
$matches = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_page_shell_start('find', 'Find Match', null, $currentUserId, false, 'Questionnaire compatibility and consent-aware match actions');
?>
<section class="find-flow" aria-labelledby="find-title">
    <article class="find-card">
        <div class="find-progress" aria-label="Questionnaire progress">
            <span class="is-active"></span>
            <span class="is-active"></span>
            <span></span>
            <span></span>
        </div>
        <h2 id="find-title">Search with intention</h2>
        <p>Bushisa now has a baseline server-owned compatibility endpoint. Scores are deterministic profile/preference scores until the final questionnaire weights are approved.</p>
        <div class="answer-grid" role="group" aria-label="Sample matching preferences">
            <label class="answer-option">
                <input type="checkbox" disabled>
                <span>Relationship intent and preferred pace</span>
            </label>
            <label class="answer-option">
                <input type="checkbox" disabled>
                <span>Interests, social activity and communication style</span>
            </label>
            <label class="answer-option">
                <input type="checkbox" disabled>
                <span>Availability and faculty preferences</span>
            </label>
        </div>
        <p class="field-help">Operational note: `api/find_match.php` exposes the first scored-results contract; this page still needs a richer interactive results view.</p>
    </article>

    <section aria-labelledby="match-results-title">
        <h2 class="section-title" id="match-results-title">Established matches</h2>
        <?php if ($matches === []): ?>
            <div class="empty-state">
                <h2>No established matches yet</h2>
                <p>Mutual likes from Home will appear here. A match is separate from approved DM permission.</p>
                <a class="button-secondary" href="discover.php">Return Home</a>
            </div>
        <?php else: ?>
            <div class="match-list-page">
                <?php foreach ($matches as $match): ?>
                    <article class="match-row">
                        <div class="profile-summary">
                            <?= render_avatar($match['profile_photo_path'] ?? null, (string) ($match['display_name'] ?? 'Match')) ?>
                            <div>
                                <h3><?= e($match['display_name'] ?? 'Match') ?></h3>
                                <p class="muted"><?= e($match['faculty'] ?? 'NUST student') ?></p>
                                <?php if (!empty($match['last_message'])): ?>
                                    <p class="muted"><?= e($match['last_message']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-actions">
                            <a class="button-secondary" href="chat.php?match_id=<?= e($match['match_id'] ?? '') ?>">Open conversation</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php
render_page_shell_end();
