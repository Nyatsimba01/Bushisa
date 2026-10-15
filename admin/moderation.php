<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/admin_access.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/logger.php';
require_once __DIR__ . '/../php/frontend.php';

require_login();
bushisa_require_moderator($pdo);

$adminId = get_current_user_id();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $action = clean_enum((string) ($_POST['action'] ?? ''), [
        'unflag_confession',
        'delete_confession',
        'unsuspend_user',
    ]);

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($adminId === null) {
        $error = 'Unable to identify the current admin.';
    } elseif ($action === null) {
        $error = 'Invalid moderation action.';
    } elseif ($action === 'unflag_confession') {
        $confessionId = clean_int($_POST['confession_id'] ?? null);

        if ($confessionId === null || $confessionId < 1) {
            $error = 'Invalid confession.';
        } elseif (unflag_confession($pdo, $confessionId)) {
            log_action($pdo, $adminId, 'admin_unflag_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $adminId . ' unflagged confession ' . $confessionId, 'INFO');
            $success = 'Confession unflagged.';
        } else {
            $error = 'Unable to unflag confession.';
        }
    } elseif ($action === 'delete_confession') {
        $confessionId = clean_int($_POST['confession_id'] ?? null);

        if ($confessionId === null || $confessionId < 1) {
            $error = 'Invalid confession.';
        } elseif (delete_confession($pdo, $confessionId, $adminId)) {
            log_action($pdo, $adminId, 'admin_delete_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $adminId . ' deleted confession ' . $confessionId . ' from moderation page', 'INFO');
            $success = 'Confession deleted.';
        } else {
            $error = 'Unable to delete confession.';
        }
    } elseif ($action === 'unsuspend_user') {
        $userId = clean_int($_POST['user_id'] ?? null);

        if ($userId === null || $userId < 1) {
            $error = 'Invalid user.';
        } elseif (unsuspend_user($pdo, $userId, $adminId)) {
            log_action($pdo, $adminId, 'admin_unsuspend_user:' . $userId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $adminId . ' unsuspended user ' . $userId . ' from moderation page', 'INFO');
            $success = 'User unsuspended.';
        } else {
            $error = 'Unable to unsuspend user.';
        }
    }
}

$flagged = get_flagged_confessions($pdo);
$suspended_users = moderation_get_suspended_users($pdo);
$csrfToken = generate_csrf_token();

/**
 * Fetch suspended users with profile display names.
 *
 * @return array<int, array<string, mixed>>
 */
function moderation_get_suspended_users(PDO $pdo): array
{
    $emailColumn = operational_column_exists($pdo, 'users', 'email') ? 'email' : 'student_email';
    if (!operational_column_exists($pdo, 'users', 'is_suspended')) {
        return [];
    }

    $statement = $pdo->prepare(
        sprintf(
        'SELECT
            users.id,
            users.%1$s AS email,
            users.created_at,
            users.is_suspended,
            profiles.display_name
         FROM users
         LEFT JOIN profiles
            ON profiles.user_id = users.id
         WHERE users.is_suspended = 1
         ORDER BY users.created_at DESC, users.id DESC',
        $emailColumn
        )
    );
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/* Read-only live moderation summaries, without changing any existing action. */
$moderationPendingReports = null;
$moderationResolvedReports = null;
if (operational_table_exists($pdo, 'reports')) {
    $moderationReportCounts = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status IN ('reviewed', 'dismissed', 'action_taken')
                THEN 1 ELSE 0 END), 0) AS resolved
         FROM reports"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $moderationPendingReports = (int) ($moderationReportCounts['pending'] ?? 0);
    $moderationResolvedReports = (int) ($moderationReportCounts['resolved'] ?? 0);
}
$moderationMetrics = [
    ['Flagged confessions', count($flagged), 'Waiting for assessment'],
    ['Suspended accounts', count($suspended_users), 'Currently restricted'],
    ['Pending reports', $moderationPendingReports ?? '—', 'Linked safety reports'],
    ['Resolved cases', $moderationResolvedReports ?? '—', 'Reviewed or closed reports'],
];

$moderationSearch = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q']) : '';
$moderationSearch = function_exists('mb_substr')
    ? mb_substr($moderationSearch, 0, 90, 'UTF-8')
    : substr($moderationSearch, 0, 90);
$moderationView = isset($_GET['type']) && is_string($_GET['type'])
    && in_array($_GET['type'], ['all', 'flagged', 'suspended'], true)
        ? $_GET['type'] : 'all';

$moderationMatch = static function (array $entry, array $fields) use ($moderationSearch): bool {
    if ($moderationSearch === '') {
        return true;
    }
    $values = [];
    foreach ($fields as $key) {
        $values[] = (string) ($entry[$key] ?? '');
    }
    $contents = implode(' ', $values);
    return function_exists('mb_stripos')
        ? mb_stripos($contents, $moderationSearch, 0, 'UTF-8') !== false
        : stripos($contents, $moderationSearch) !== false;
};
$visibleFlagged = $moderationView === 'suspended'
    ? []
    : array_values(array_filter($flagged,
        static fn (array $row): bool => $moderationMatch($row, ['id', 'anon_handle', 'body'])));
$visibleSuspended = $moderationView === 'flagged'
    ? []
    : array_values(array_filter($suspended_users,
        static fn (array $row): bool => $moderationMatch($row, ['id', 'display_name'])));

$moderationNav = [
    ['href' => 'dashboard.php', 'title' => 'Overview', 'icon' => 'overview'],
    ['href' => 'users.php', 'title' => 'Users', 'icon' => 'users'],
    ['href' => 'confessions.php', 'title' => 'Confessions', 'icon' => 'confessions'],
    ['href' => 'reports.php', 'title' => 'Reports', 'icon' => 'reports'],
    ['href' => 'moderation.php', 'title' => 'Moderation', 'icon' => 'moderation'],
];
render_document_head('Moderation centre', $csrfToken);
?>
<body class="bushisa-admin-dashboard bushisa-admin-moderation">
<a href="#moderation-main" class="admin-skip-link">Skip to moderation queue</a>
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
                <?php foreach ($moderationNav as $item): ?>
                    <a href="<?= e($item['href']) ?>"
                       class="admin-nav__link<?= $item['title'] === 'Moderation' ? ' is-current' : '' ?>"
                       <?= $item['title'] === 'Moderation' ? 'aria-current="page"' : '' ?>>
                        <span class="admin-nav__icon admin-nav__icon--<?= e($item['icon']) ?>" aria-hidden="true"></span>
                        <span><?= e($item['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="admin-sidebar__footer">
            <p class="admin-sidebar__eyebrow">ACCOUNT</p>
            <a href="../discover.php" class="admin-sidebar__back">← Back to Bushisa</a>
            <a href="../logout.php" class="admin-sidebar__logout">↪ Log out</a>
            <p class="admin-sidebar__motto">A little more care,<br>a safer campus.</p>
        </div>
    </aside>

    <header class="admin-moderation-mobile-top">
        <a href="dashboard.php" class="admin-moderation-mobile-brand">
            <span aria-hidden="true">♡</span> Bushisa
        </a>
        <span>ADMIN</span>
    </header>

    <main class="admin-main admin-moderation-main" id="moderation-main">
        <header class="admin-page-header admin-moderation-header">
            <div class="admin-page-header__copy">
                <p class="admin-eyebrow">ADMINISTRATION / MODERATION</p>
                <h1>Moderation centre</h1>
                <p class="admin-page-header__description">A safer campus, one careful decision at a time.</p>
            </div>
            <div class="admin-preview-tag">
                <span class="admin-preview-tag__dot" aria-hidden="true"></span> LIVE / LOCAL DATA
            </div>
        </header>

        <section class="admin-moderation-notice">
            <p class="admin-eyebrow">CAREFUL MODERATION</p>
            <p>Moderation actions affect real student accounts and posts. Check context before confirming any action.</p>
        </section>

        <section class="admin-metrics admin-moderation-metrics" aria-label="Moderation statistics">
            <?php foreach ($moderationMetrics as $metric): ?>
                <article class="admin-metric">
                    <h2><?= e($metric[0]) ?></h2>
                    <p class="admin-metric__value"><?= e($metric[1]) ?></p>
                    <p class="admin-metric__note"><?= e($metric[2]) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($error !== null || $success !== null): ?>
            <div class="admin-moderation-flash" role="status"><?php render_flash($error, $success); ?></div>
        <?php endif; ?>

        <form class="admin-moderation-filters" action="moderation.php" method="get" role="search">
            <label class="admin-eyebrow" for="moderation-search">FIND A CASE</label>
            <div class="admin-moderation-filter-fields">
                <input id="moderation-search" name="q" type="search" maxlength="90"
                    value="<?= e($moderationSearch) ?>" placeholder="Search posts or account ID">
                <label for="moderation-type">
                    <span class="sr-only">Moderation category</span>
                    <select name="type" id="moderation-type">
                        <option value="all" <?= $moderationView === 'all' ? 'selected' : '' ?>>All cases</option>
                        <option value="flagged" <?= $moderationView === 'flagged' ? 'selected' : '' ?>>Flagged posts</option>
                        <option value="suspended" <?= $moderationView === 'suspended' ? 'selected' : '' ?>>Suspended users</option>
                    </select>
                </label>
                <button type="submit">Search</button>
            </div>
            <p class="admin-moderation-filter-note">
                Search the current flagged posts and suspended accounts. Select a category to narrow the queue.
            </p>
        </form>

        <div class="admin-moderation-panels">
            <section class="admin-moderation-directory" id="moderation-flagged" aria-labelledby="flagged-title">
                <div class="admin-moderation-section-heading">
                    <div>
                        <p class="admin-eyebrow">MODERATION QUEUE</p>
                        <h2 id="flagged-title">Flagged confession review</h2>
                    </div>
                    <span class="admin-moderation-count"><?= e(count($visibleFlagged)) ?> shown</span>
                </div>
                <p class="admin-moderation-explainer">
                    Check each flagged post and its context before taking action. Only anonymous handles are displayed.
                </p>
                <a href="#moderation-flagged-list" class="admin-moderation-action-link">
                    Review flagged content <span aria-hidden="true">→</span>
                </a>
                <div id="moderation-flagged-list" class="admin-moderation-list">
                    <?php if ($moderationView === 'suspended'): ?>
                        <div class="admin-moderation-empty">
                            <h3>Showing suspended accounts</h3>
                            <p>Change the category to All cases or Flagged posts to see the confession queue.</p>
                        </div>
                    <?php elseif ($visibleFlagged === []): ?>
                        <div class="admin-moderation-empty">
                            <span class="admin-moderation-empty-icon" aria-hidden="true">✓</span>
                            <h3><?= $moderationSearch === '' ? 'Your review queue is clear' : 'No matching flagged posts' ?></h3>
                            <p><?= $moderationSearch === ''
                                ? 'No flagged confessions await review. Suspended accounts remain in the account controls section.'
                                : 'Try a different post ID or search phrase.' ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($visibleFlagged as $confession): ?>
                            <article class="admin-moderation-case">
                                <div class="admin-moderation-case-header">
                                    <strong>Post #<?= e($confession['id'] ?? '') ?></strong>
                                    <span class="admin-moderation-status">Flagged</span>
                                </div>
                                <p class="admin-moderation-anon">@<?= e($confession['anon_handle'] ?: 'anonymous') ?></p>
                                <p class="admin-moderation-case-body"><?= e($confession['body'] ?? '') ?></p>
                                <p class="admin-moderation-muted">
                                    Flagged post · <?= e(format_time_ago((string) ($confession['created_at'] ?? ''))) ?>
                                </p>
                                <div class="admin-moderation-buttons">
                                    <form method="post" action="moderation.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="confession_id" value="<?= e($confession['id'] ?? '') ?>">
                                        <input type="hidden" name="action" value="unflag_confession">
                                        <button type="submit" class="admin-moderation-button"
                                            onclick="return confirm('Restore this confession to the public student feed?')">
                                            Unflag post
                                        </button>
                                    </form>
                                    <form method="post" action="moderation.php">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="confession_id" value="<?= e($confession['id'] ?? '') ?>">
                                        <input type="hidden" name="action" value="delete_confession">
                                        <button type="submit" class="admin-moderation-button is-danger"
                                            onclick="return confirm('Permanently delete this confession? This action cannot be undone.')">
                                            Delete post
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="admin-moderation-guidance" id="moderation-suspended" aria-labelledby="suspended-title">
                <p class="admin-eyebrow">ACCOUNT CONTROLS</p>
                <h2 id="suspended-title">Suspended users</h2>
                <p>Review account status carefully before restoring access.</p>
                <a class="admin-moderation-action-link" href="#moderation-suspended-list">
                    Review suspended users <span aria-hidden="true">→</span>
                </a>
                <div id="moderation-suspended-list" class="admin-moderation-users-list">
                    <?php if ($moderationView === 'flagged'): ?>
                        <div class="admin-moderation-empty admin-moderation-empty-small">
                            <p>Showing flagged posts only. Choose All cases or Suspended users to review accounts.</p>
                        </div>
                    <?php elseif ($visibleSuspended === []): ?>
                        <div class="admin-moderation-empty admin-moderation-empty-small">
                            <span class="admin-moderation-empty-icon" aria-hidden="true">✓</span>
                            <h3>No suspended users</h3>
                            <p><?= $moderationSearch === ''
                                ? 'No accounts are currently suspended.'
                                : 'No suspended accounts match your search.' ?></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($visibleSuspended as $user): ?>
                            <article class="admin-moderation-user">
                                <strong><?= e($user['display_name'] ?: 'Account') ?></strong>
                                <p class="admin-moderation-muted">Account #<?= e($user['id'] ?? '') ?> · Suspended</p>
                                <form method="post" action="moderation.php">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= e($user['id'] ?? '') ?>">
                                    <input type="hidden" name="action" value="unsuspend_user">
                                    <button type="submit" class="admin-moderation-button"
                                        onclick="return confirm('Restore access to this student account?')">
                                        Restore account
                                    </button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <a class="admin-moderation-textlink" href="reports.php">Review private reports →</a>
            </aside>
        </div>
        <p class="admin-moderation-footnote">
            Moderator access, CSRF validation, logging, and existing database actions are preserved.
        </p>
    </main>

    <nav class="admin-moderation-bottom-nav" aria-label="Mobile admin navigation">
        <?php foreach ($moderationNav as $item): ?>
            <a href="<?= e($item['href']) ?>"
               class="<?= $item['title'] === 'Moderation' ? 'is-current' : '' ?>"
               <?= $item['title'] === 'Moderation' ? 'aria-current="page"' : '' ?>>
                <?= e($item['title']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
</body>
</html>
