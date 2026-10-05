<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/frontend.php';

require_login();

if (!is_moderator()) {
    redirect('/discover.php');
}

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

    $statement = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column_name');
    $statement->execute([':column_name' => $column]);

    return $statement->fetch(PDO::FETCH_ASSOC) !== false;
}

render_auth_start('Admin Dashboard');
?>
<section class="auth-card" aria-labelledby="admin-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="admin-title">Admin Dashboard</h1>
    <p class="auth-card__lead">Operational overview for Bushisa moderation.</p>
    <div class="card-grid">
        <?php foreach ($stats as $label => $value): ?>
            <article class="section-card">
                <h2><?= e(str_replace('_', ' ', ucwords($label, '_'))) ?></h2>
                <p class="hero-statement"><?= e($value) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="card-actions">
        <a class="button-secondary" href="reports.php">Review reports</a>
        <a class="button-secondary" href="moderation.php">Moderation queue</a>
        <a class="button-secondary" href="../discover.php">Back to app</a>
    </div>
</section>
<?php
render_auth_end();
