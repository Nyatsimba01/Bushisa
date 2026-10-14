<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/admin_access.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/frontend.php';

require_login();
bushisa_require_moderator($pdo);

$total_users = dashboard_count($pdo, 'SELECT COUNT(*) FROM users');
$active_users = dashboard_count_active_users($pdo);
$total_matches = dashboard_count($pdo, 'SELECT COUNT(*) FROM matches');
$total_messages = dashboard_count($pdo, 'SELECT COUNT(*) FROM messages');
$total_confessions = dashboard_count($pdo, 'SELECT COUNT(*) FROM confessions');
$pending_reports_count = dashboard_count($pdo, "SELECT COUNT(*) FROM reports WHERE status = 'pending'");
$flagged_confessions_count = dashboard_count($pdo, 'SELECT COUNT(*) FROM confessions WHERE is_flagged = 1');
$suspended_users_count = dashboard_column_exists($pdo, 'users', 'is_suspended')
    ? dashboard_count($pdo, 'SELECT COUNT(*) FROM users WHERE is_suspended = 1')
    : 0;
$new_users_today = dashboard_count($pdo, 'SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()');

$stats = [
    'total_users' => $total_users,
    'active_users' => $active_users,
    'total_matches' => $total_matches,
    'total_messages' => $total_messages,
    'total_confessions' => $total_confessions,
    'pending_reports_count' => $pending_reports_count,
    'flagged_confessions_count' => $flagged_confessions_count,
    'suspended_users_count' => $suspended_users_count,
    'new_users_today' => $new_users_today,
];

/**
 * Run a COUNT query and return its integer result.
 */
function dashboard_count(PDO $pdo, string $sql): int
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        return 0;
    }

    return (int) $statement->fetchColumn();
}

/**
 * Count users who swiped or sent a message in the last seven days.
 */
function dashboard_count_active_users(PDO $pdo): int
{
    $messageDateColumn = dashboard_column_exists($pdo, 'messages', 'sent_at') ? 'sent_at' : 'created_at';

    $statement = $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT swiper_id AS user_id
            FROM swipes
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            UNION
            SELECT sender_id AS user_id
            FROM messages
            WHERE ' . $messageDateColumn . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ) AS active_users'
    );

    if ($statement === false) {
        return 0;
    }

    return (int) $statement->fetchColumn();
}

/**
 * Check whether a known table contains a column.
 */
function dashboard_column_exists(PDO $pdo, string $table, string $column): bool
{
    $allowedTables = ['messages', 'users'];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    // SHOW COLUMNS ... LIKE does not support bound placeholders reliably on
    // MariaDB with native PDO prepares. Query information_schema instead.
    return operational_column_exists($pdo, $table, $column);
}

/**
 * Read the latest real post. The Figma sample text must never replace data.
 * Handles both legacy confession schemas without revealing the author.
 *
 * @return array{id:int, body:string}|null
 */
function dashboard_latest_confession(PDO $pdo): ?array
{
    try {
        if (!operational_table_exists($pdo, 'confessions')) {
            return null;
        }
        $column = operational_confession_columns($pdo)['body'];
        $stmt = $pdo->query(
            'SELECT id, ' . $column . ' AS body FROM confessions
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? ['id' => (int) $row['id'], 'body' => (string) $row['body']] : null;
    } catch (Throwable $exception) {
        error_log('Admin confession preview could not load: ' . $exception->getMessage());
        return null;
    }
}

$latest_confession = dashboard_latest_confession($pdo);
$needs_review = $pending_reports_count > 0 || $flagged_confessions_count > 0;
$metrics = [
    ['key'=>'users','title'=>'Total users','count'=>$total_users,'note'=>'Accounts in the database'],
    ['key'=>'confessions','title'=>'Total confessions','count'=>$total_confessions,'note'=>'Posts in Confessions'],
    ['key'=>'activity','title'=>'Active users','count'=>$active_users,'note'=>'Swipes or messages · past 7 days'],
    ['key'=>'matches','title'=>'Total matches','count'=>$total_matches,'note'=>'Connections recorded'],
];
$snapshot = [
    ['title'=>'Pending reports','count'=>$pending_reports_count],
    ['title'=>'Flagged confessions','count'=>$flagged_confessions_count],
    ['title'=>'Suspended users','count'=>$suspended_users_count],
    ['title'=>'Total messages','count'=>$total_messages],
    ['title'=>'New users today','count'=>$new_users_today],
];
$navigation = [
    ['href'=>'dashboard.php','label'=>'Overview','icon'=>'overview','active'=>true],
    ['href'=>'#admin-users','label'=>'Users','icon'=>'users','active'=>false],
    ['href'=>'#latest-confession','label'=>'Confessions','icon'=>'confessions','active'=>false],
    ['href'=>'reports.php','label'=>'Reports','icon'=>'reports','active'=>false],
    ['href'=>'moderation.php','label'=>'Moderation','icon'=>'moderation','active'=>false],
];

render_document_head('Admin Dashboard');
?>
<body class="bushisa-admin-dashboard">
<a href="#admin-main" class="admin-skip-link">Skip to dashboard content</a>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Administrator navigation">
        <div class="admin-brand">
            <span class="admin-brand__heart" aria-hidden="true">♡</span>
            <div>
                <span class="admin-brand__title">Bushisa</span>
                <span class="admin-brand__subtitle">ADMIN WORKSPACE</span>
            </div>
        </div>
        <div class="admin-sidebar__middle">
            <p class="admin-sidebar__eyebrow">WORKSPACE</p>
            <nav class="admin-nav" aria-label="Admin sections">
                <?php foreach ($navigation as $item): ?>
                    <a class="admin-nav__link<?= $item['active'] ? ' is-current' : '' ?>" href="<?= e($item['href']) ?>" <?= $item['active'] ? 'aria-current="page"' : '' ?>>
                        <span class="admin-nav__icon admin-nav__icon--<?= e($item['icon']) ?>" aria-hidden="true"></span>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="admin-sidebar__footer">
            <p class="admin-sidebar__eyebrow">ACCOUNT</p>
            <a href="../discover.php" class="admin-sidebar__back"><span aria-hidden="true">←</span> Back to Bushisa</a>
            <a href="../logout.php" class="admin-sidebar__logout"><span aria-hidden="true">↪</span> Log out</a>
            <p class="admin-sidebar__motto">A little more care,<br>a safer campus.</p>
        </div>
    </aside>
    <main class="admin-main" id="admin-main">
        <header class="admin-page-header">
            <div class="admin-page-header__copy">
                <p class="admin-eyebrow">ADMINISTRATION / OVERVIEW</p>
                <h1>Admin dashboard</h1>
                <p class="admin-page-header__description">Moderation, people and activity at a glance.</p>
            </div>
            <div class="admin-preview-tag"><span class="admin-preview-tag__dot" aria-hidden="true"></span> DEVELOPMENT PREVIEW</div>
        </header>
        <section class="admin-alert" aria-labelledby="admin-status-title">
            <div class="admin-alert__message">
                <p class="admin-eyebrow">RIGHT NOW</p>
                <h2 id="admin-status-title"><?= $needs_review ? 'There are items to review.' : 'Your moderation queue is clear.' ?></h2>
            </div>
            <p class="admin-alert__totals">
                <a href="reports.php?status=pending"><?= e($pending_reports_count) ?> pending <?= $pending_reports_count === 1 ? 'report' : 'reports' ?></a>
                <span aria-hidden="true">·</span>
                <a href="moderation.php"><?= e($flagged_confessions_count) ?> flagged <?= $flagged_confessions_count === 1 ? 'confession' : 'confessions' ?></a>
            </p>
        </section>
        <section class="admin-metrics" id="admin-users" aria-label="Key statistics">
            <?php foreach ($metrics as $metric): ?>
                <article class="admin-metric" data-metric="<?= e($metric['key']) ?>">
                    <h2><?= e($metric['title']) ?></h2>
                    <p class="admin-metric__value"><?= e($metric['count']) ?></p>
                    <p class="admin-metric__note"><?= e($metric['note']) ?></p>
                </article>
            <?php endforeach; ?>
        </section>
        <div class="admin-panels">
            <section class="admin-panel admin-panel--reports" aria-labelledby="moderation-heading">
                <div class="admin-panel__heading">
                    <div>
                        <p class="admin-eyebrow">MODERATION QUEUE</p>
                        <h2 id="moderation-heading"><?= $needs_review ? 'Your review queue' : 'Nothing needs your review' ?></h2>
                    </div>
                    <a class="admin-small-button" href="reports.php">Open reports <span aria-hidden="true">→</span></a>
                </div>
                <?php if ($needs_review): ?>
                    <div class="admin-review-queue" role="status">
                        <span class="admin-review-queue__icon" aria-hidden="true">!</span>
                        <h3>Some items need attention</h3>
                        <p><?= e($pending_reports_count) ?> pending <?= $pending_reports_count === 1 ? 'report' : 'reports' ?> and <?= e($flagged_confessions_count) ?> flagged <?= $flagged_confessions_count === 1 ? 'confession' : 'confessions' ?>.</p>
                        <div class="admin-review-queue__links">
                            <a href="reports.php?status=pending">Review reports →</a>
                            <a href="moderation.php">Open flagged posts →</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="admin-empty-queue">
                        <span class="admin-empty-queue__icon" aria-hidden="true">✓</span>
                        <h3>All caught up</h3>
                        <p>There are no reported or flagged posts awaiting review.</p>
                    </div>
                <?php endif; ?>
                <p class="admin-panel__footnote">Reports stay private to authorised moderators.</p>
            </section>
            <section class="admin-panel admin-panel--snapshot" aria-labelledby="snapshot-heading">
                <div class="admin-panel__heading">
                    <div>
                        <p class="admin-eyebrow">SYSTEM SNAPSHOT</p>
                        <h2 id="snapshot-heading">What's happening</h2>
                    </div>
                </div>
                <dl class="admin-snapshot-list">
                    <?php foreach ($snapshot as $row): ?>
                        <div class="admin-snapshot-list__row">
                            <dt><?= e($row['title']) ?></dt>
                            <dd><?= e($row['count']) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
            <section class="admin-panel admin-panel--latest" id="latest-confession" aria-labelledby="latest-heading">
                <div class="admin-panel__heading">
                    <div>
                        <p class="admin-eyebrow">CONFESSIONS</p>
                        <h2 id="latest-heading">Latest confession</h2>
                    </div>
                </div>
                <?php if ($latest_confession !== null): ?>
                    <?php
                        $preview = preg_replace('/\s+/u', ' ', trim($latest_confession['body'])) ?? '';
                        if (function_exists('mb_strlen') && mb_strlen($preview, 'UTF-8') > 105) {
                            $preview = mb_substr($preview, 0, 105, 'UTF-8') . '…';
                        } elseif (!function_exists('mb_strlen') && strlen($preview) > 105) {
                            $preview = substr($preview, 0, 105) . '…';
                        }
                    ?>
                    <blockquote class="admin-latest-quote">“<?= e($preview) ?>”</blockquote>
                    <p class="admin-panel__meta">Post #<?= e($latest_confession['id']) ?> · Latest in the local Confessions database</p>
                <?php else: ?>
                    <p class="admin-latest-quote admin-latest-quote--empty">No confessions have been posted yet.</p>
                    <p class="admin-panel__meta">Posts will appear here as your test database changes.</p>
                <?php endif; ?>
            </section>
            <section class="admin-panel admin-panel--actions" aria-labelledby="actions-heading">
                <div class="admin-panel__heading">
                    <div>
                        <p class="admin-eyebrow">SHORTCUTS</p>
                        <h2 id="actions-heading">Keep things moving</h2>
                    </div>
                </div>
                <nav class="admin-actions" aria-label="Quick actions">
                    <a href="reports.php">Review reports <span aria-hidden="true">→</span></a>
                    <a href="moderation.php">Open moderation queue <span aria-hidden="true">→</span></a>
                </nav>
            </section>
        </div>
    </main>
</div>
</body>
</html>
