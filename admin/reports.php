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
require_once __DIR__ . '/../php/report_handler.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/logger.php';
require_once __DIR__ . '/../php/frontend.php';
require_once __DIR__ . '/../php/operational.php';

require_login();
bushisa_require_moderator($pdo);
ensure_operational_schema($pdo);

$adminId = get_current_user_id();
$error = null;
$success = null;
$allowedStatusFilters = ['all', 'pending', 'reviewed', 'dismissed', 'action_taken'];
$status_filter = clean_enum((string) ($_GET['status'] ?? 'all'), $allowedStatusFilters) ?? 'all';

/** Existing reports from different schema versions all keep their unique ID. */
function admin_get_report(PDO $pdo, int $reportId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM reports WHERE id = :report_id LIMIT 1');
    $statement->execute([':report_id' => $reportId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function admin_report_confession_id(array $report): ?int
{
    $id = isset($report['confession_id']) ? (int) $report['confession_id'] : 0;
    if ($id <= 0 && ($report['target_type'] ?? '') === 'confession') {
        $id = isset($report['target_id']) ? (int) $report['target_id'] : 0;
    }
    return $id > 0 ? $id : null;
}

/** @return array<int, array<string, mixed>> */
function admin_get_reports(PDO $pdo, string $filter): array
{
    $confessionColumns = operational_confession_columns($pdo);
    $params = [];
    $where = '';
    if ($filter !== 'all') {
        $where = 'WHERE r.status = :status';
        $params[':status'] = $filter;
    }

    $query = sprintf(
        'SELECT
            r.id, r.reporter_id, r.reported_user_id,
            r.confession_id, r.target_type, r.target_id,
            r.reason, r.status, r.created_at,
            reporter_profiles.display_name AS reporter_display_name,
            reported_profiles.display_name AS reported_display_name,
            c.id AS reported_confession_id,
            c.%1$s AS confession_body,
            c.is_flagged AS confession_is_flagged
         FROM reports r
         LEFT JOIN profiles reporter_profiles ON reporter_profiles.user_id = r.reporter_id
         LEFT JOIN profiles reported_profiles ON reported_profiles.user_id = r.reported_user_id
         LEFT JOIN confessions c ON c.id = COALESCE(
             r.confession_id,
             CASE WHEN r.target_type = "confession" THEN r.target_id ELSE NULL END
         )
         %2$s
         ORDER BY CASE WHEN r.status = "pending" THEN 0 ELSE 1 END,
             r.created_at DESC, r.id DESC
         LIMIT 200',
        $confessionColumns['body'],
        $where
    );
    $statement = $pdo->prepare($query);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $reportId = clean_int($_POST['report_id'] ?? null);
    $action = clean_enum((string) ($_POST['action'] ?? ''), [
        'dismiss', 'reviewed', 'hide_confession', 'suspend_user',
    ]);

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($adminId === null) {
        $error = 'Unable to identify the current moderator.';
    } elseif ($reportId === null || $reportId < 1 || $action === null) {
        $error = 'Invalid report action.';
    } else {
        $report = admin_get_report($pdo, $reportId);
        if ($report === null) {
            $error = 'Report not found.';
        } elseif ($action === 'dismiss' || $action === 'reviewed') {
            $newStatus = $action === 'dismiss' ? 'dismissed' : 'reviewed';
            if (update_report_status($pdo, $reportId, $newStatus)) {
                log_action($pdo, $adminId, 'moderate_report_' . $newStatus . ':' . $reportId, $_SERVER['REMOTE_ADDR'] ?? null);
                $success = $action === 'dismiss' ? 'Report dismissed.' : 'Report marked as reviewed.';
            } else {
                $error = 'Could not update that report.';
            }
        } elseif ($action === 'hide_confession') {
            $confessionId = admin_report_confession_id($report);
            if ($confessionId === null) {
                $error = 'This report does not reference a confession.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $getPost = $pdo->prepare('SELECT id FROM confessions WHERE id = :id LIMIT 1');
                    $getPost->execute([':id' => $confessionId]);
                    if ($getPost->fetchColumn() === false) {
                        throw new RuntimeException('Reported post no longer exists.');
                    }
                    if (!flag_confession($pdo, $confessionId)
                        || !update_report_status($pdo, $reportId, 'reviewed')) {
                        throw new RuntimeException('Unable to hide reported post.');
                    }
                    $pdo->commit();
                    log_action($pdo, $adminId, 'hide_reported_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
                    log_to_file('Moderator hid reported confession ' . $confessionId, 'INFO');
                    $success = 'Confession hidden from the public feed and report marked reviewed.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    log_to_file('Moderator hide action failed: ' . $exception->getMessage(), 'ERROR');
                    $error = 'Could not hide this confession. It may have been removed already.';
                }
            }
        } elseif ($action === 'suspend_user') {
            $reportedUserId = isset($report['reported_user_id']) ? (int) $report['reported_user_id'] : 0;
            if ($reportedUserId < 1 || $reportedUserId === $adminId) {
                $error = 'This report does not reference a valid different user.';
            } elseif (suspend_user($pdo, $reportedUserId, $adminId)
                && update_report_status($pdo, $reportId, 'action_taken')) {
                log_action($pdo, $adminId, 'action_report_suspend_user:' . $reportId, $_SERVER['REMOTE_ADDR'] ?? null);
                $success = 'Account suspended and report marked as action taken.';
            } else {
                $error = 'Unable to suspend the reported account.';
            }
        }
    }
}

$reports = admin_get_reports($pdo, $status_filter);
$csrfToken = generate_csrf_token();


/* Read-only summary counts; all original report moderation handlers remain above. */
$reportCounts = $pdo->query(
    "SELECT COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
        COALESCE(SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END), 0) AS reviewed,
        COALESCE(SUM(CASE WHEN status = 'action_taken' THEN 1 ELSE 0 END), 0) AS action_taken
     FROM reports"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$reportSummary = [
    ['Pending review', (int) ($reportCounts['pending'] ?? 0), 'New reports to assess'],
    ['Reviewed', (int) ($reportCounts['reviewed'] ?? 0), 'Reviewed without action'],
    ['Action taken', (int) ($reportCounts['action_taken'] ?? 0), 'Reviewed with a response'],
    ['All reports', (int) ($reportCounts['total'] ?? 0), 'Recorded reports'],
];

$reportSearch = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$reportSearch = function_exists('mb_substr')
    ? mb_substr($reportSearch, 0, 100, 'UTF-8')
    : substr($reportSearch, 0, 100);
// Preserve the existing 200-record fetch cap. Search only this fetched set;
// the interface explicitly tells administrators the scope.
$visibleReports = array_values(array_filter($reports, static function (array $report) use ($reportSearch): bool {
    if ($reportSearch === '') {
        return true;
    }
    $haystack = implode(' ', [
        (string) ($report['id'] ?? ''),
        (string) ($report['reason'] ?? ''),
        (string) ($report['status'] ?? ''),
        (string) ($report['target_type'] ?? ''),
        (string) ($report['reported_confession_id'] ?? ''),
    ]);
    return function_exists('mb_stripos')
        ? mb_stripos($haystack, $reportSearch, 0, 'UTF-8') !== false
        : stripos($haystack, $reportSearch) !== false;
}));

$reportNav = [
    ['href' => 'dashboard.php', 'title' => 'Overview', 'icon' => 'overview'],
    ['href' => 'users.php', 'title' => 'Users', 'icon' => 'users'],
    ['href' => 'confessions.php', 'title' => 'Confessions', 'icon' => 'confessions'],
    ['href' => 'reports.php', 'title' => 'Reports', 'icon' => 'reports'],
    ['href' => 'moderation.php', 'title' => 'Moderation', 'icon' => 'moderation'],
];

render_document_head('Reports management', $csrfToken);
?>
<body class="bushisa-admin-dashboard bushisa-admin-reports">
<a href="#reports-main" class="admin-skip-link">Skip to report queue</a>
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
                <?php foreach ($reportNav as $item): ?>
                    <a href="<?= e($item['href']) ?>" class="admin-nav__link<?= $item['title'] === 'Reports' ? ' is-current' : '' ?>"
                       <?= $item['title'] === 'Reports' ? 'aria-current="page"' : '' ?>>
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

    <div class="admin-reports-mobile-top">
        <a href="dashboard.php" class="admin-reports-mobile-brand"><span aria-hidden="true">♡</span> Bushisa</a>
        <span>ADMIN</span>
    </div>

    <main class="admin-main admin-reports-main" id="reports-main">
        <header class="admin-page-header admin-reports-header">
            <div class="admin-page-header__copy">
                <p class="admin-eyebrow">ADMINISTRATION / REPORTS</p>
                <h1>Reports management</h1>
                <p class="admin-page-header__description">Private safety reports, reviewed thoughtfully.</p>
            </div>
            <div class="admin-preview-tag">
                <span class="admin-preview-tag__dot" aria-hidden="true"></span> LIVE / LOCAL DATA
            </div>
        </header>

        <section class="admin-reports-notice">
            <p class="admin-eyebrow">PRIVATE REPORTING</p>
            <p>Reports are confidential. Details are visible only to authorised moderators and administrators.</p>
        </section>

        <section class="admin-metrics admin-reports-metrics" aria-label="Report statistics">
            <?php foreach ($reportSummary as $metric): ?>
                <article class="admin-metric">
                    <h2><?= e($metric[0]) ?></h2>
                    <p class="admin-metric__value"><?= e($metric[1]) ?></p>
                    <p class="admin-metric__note"><?= e($metric[2]) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($error !== null || $success !== null): ?>
            <div class="admin-reports-flash" role="status"><?php render_flash($error, $success); ?></div>
        <?php endif; ?>

        <form class="admin-reports-filters" method="get" action="reports.php" role="search">
            <label for="admin-reports-search" class="admin-eyebrow">FIND A REPORT</label>
            <div class="admin-reports-filter-fields">
                <input id="admin-reports-search" name="q" type="search" maxlength="100"
                       placeholder="Search report ID or reason" value="<?= e($reportSearch) ?>">
                <label class="admin-reports-status-wrap" for="admin-reports-status">
                    <span class="sr-only">Report status</span>
                    <select name="status" id="admin-reports-status">
                        <?php foreach ($allowedStatusFilters as $option): ?>
                            <option value="<?= e($option) ?>" <?= $status_filter === $option ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $option))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Search</button>
            </div>
            <p class="admin-reports-filter-note">Filter by status and search within the latest 200 reports.</p>
        </form>

        <div class="admin-reports-panels">
            <section class="admin-reports-directory" aria-labelledby="report-queue-title">
                <div class="admin-reports-section-heading">
                    <div>
                        <p class="admin-eyebrow">REPORT QUEUE</p>
                        <h2 id="report-queue-title">Reports awaiting review</h2>
                    </div>
                    <span class="admin-reports-count"><?= e(count($visibleReports)) ?> shown</span>
                </div>
                <p class="admin-reports-explainer">
                    Review each report and its context before taking action.
                    Reports do not automatically remove content.
                </p>
                <?php if ($visibleReports === []): ?>
                    <div class="admin-reports-empty">
                        <span class="admin-reports-empty-icon" aria-hidden="true">✓</span>
                        <h3><?= $status_filter === 'pending' || $status_filter === 'all' ? 'All caught up' : 'No matching reports' ?></h3>
                        <p><?= $reportSearch !== ''
                            ? 'No reports in the current results match your search.'
                            : 'There are no reports matching this status in the latest results.' ?></p>
                    </div>
                <?php else: ?>
                    <div class="admin-reports-list">
                        <?php foreach ($visibleReports as $report): ?>
                            <?php
                                $isConfession = ($report['target_type'] ?? '') === 'confession'
                                    || (isset($report['confession_id']) && $report['confession_id'] !== null);
                                $reportId = (int) ($report['id'] ?? 0);
                                $status = (string) ($report['status'] ?? 'pending');
                            ?>
                            <article class="admin-reports-post" id="report-<?= e($reportId) ?>">
                                <div class="admin-reports-post-meta">
                                    <strong>Report #<?= e($reportId) ?></strong>
                                    <span class="admin-reports-status admin-reports-status--<?= e($status) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                                    </span>
                                </div>
                                <p class="admin-reports-post-reason"><?= e($report['reason'] ?? 'No reason provided') ?></p>
                                <p class="admin-reports-post-date"><?= $isConfession ? 'Confession' : 'Account / other' ?>
                                    · <?= e(format_time_ago((string) ($report['created_at'] ?? ''))) ?>
                                </p>
                                <details class="admin-reports-details">
                                    <summary>Open report details <span aria-hidden="true">→</span></summary>
                                    <div class="admin-reports-detail-content">
                                        <?php if ($isConfession): ?>
                                            <p class="admin-reports-muted">Post #<?= e($report['reported_confession_id'] ?? $report['confession_id'] ?? $report['target_id'] ?? '?') ?></p>
                                            <?php if ($report['reported_confession_id'] !== null): ?>
                                                <div class="admin-reports-post-preview">
                                                    <strong>Reported post content</strong>
                                                    <p><?= e((string) ($report['confession_body'] ?? '')) ?></p>
                                                    <?php if ((int) ($report['confession_is_flagged'] ?? 0) === 1): ?>
                                                        <p class="admin-reports-muted">This post is hidden from the public feed.</p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <p class="admin-reports-muted">This confession is no longer available.</p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php
                                            $reporterId = (int) ($report['reporter_id'] ?? 0);
                                            $reporterName = trim((string) ($report['reporter_display_name'] ?? ''));
                                            $reportedUserId = (int) ($report['reported_user_id'] ?? 0);
                                            $reportedUserName = trim((string) ($report['reported_display_name'] ?? ''));
                                            $reporterLabel = $reporterName !== '' ? $reporterName
                                                : ($reporterId > 0 ? 'Account #' . $reporterId : 'Unknown reporter');
                                            $reportedUserLabel = $reportedUserName !== '' ? $reportedUserName
                                                : 'Account #' . $reportedUserId;
                                        ?>
                                        <div class="admin-reports-identities" aria-label="Private report identity details">
                                            <div class="admin-reports-identity">
                                                <span class="admin-reports-identity-label">Reported by</span>
                                                <strong><?= e($reporterLabel) ?></strong>
                                                <?php if ($reporterName !== '' && $reporterId > 0): ?>
                                                    <span class="admin-reports-muted">Account #<?= e($reporterId) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($reportedUserId > 0): ?>
                                                <div class="admin-reports-identity">
                                                    <span class="admin-reports-identity-label">Reported account</span>
                                                    <strong><?= e($reportedUserLabel) ?></strong>
                                                    <?php if ($reportedUserName !== ''): ?>
                                                        <span class="admin-reports-muted">Account #<?= e($reportedUserId) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="admin-reports-muted">Reporter identities are confidential and visible only to authorised moderators.</p>

                                        <div class="admin-reports-actions">
                                            <?php foreach (['reviewed' => 'Mark reviewed', 'dismiss' => 'Dismiss report'] as $action => $label): ?>
                                                <form method="post" action="reports.php?status=<?= e($status_filter) ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="report_id" value="<?= e($reportId) ?>">
                                                    <input type="hidden" name="action" value="<?= e($action) ?>">
                                                    <button class="admin-reports-action-button" type="submit"><?= e($label) ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                            <?php if ($isConfession && $report['reported_confession_id'] !== null
                                                && (int) ($report['confession_is_flagged'] ?? 0) !== 1): ?>
                                                <form method="post" action="reports.php?status=<?= e($status_filter) ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="report_id" value="<?= e($reportId) ?>">
                                                    <input type="hidden" name="action" value="hide_confession">
                                                    <button type="submit" class="admin-reports-action-button is-danger"
                                                        onclick="return confirm('Hide this confession from the student feed?')">Hide confession</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ((int) ($report['reported_user_id'] ?? 0) > 0): ?>
                                                <form method="post" action="reports.php?status=<?= e($status_filter) ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="report_id" value="<?= e($reportId) ?>">
                                                    <input type="hidden" name="action" value="suspend_user">
                                                    <button type="submit" class="admin-reports-action-button is-danger"
                                                        onclick="return confirm('Suspend the reported student account?')">Suspend account</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </details>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="admin-reports-guidance">
                <p class="admin-eyebrow">REVIEW WORKFLOW</p>
                <h2>Decide with context</h2>
                <p>Review the reason and available evidence. Mark reports reviewed or dismissed, or take a justified action after confirmation.</p>
                <a href="moderation.php" class="admin-reports-guidance-button">
                    View moderation activity <span aria-hidden="true">→</span>
                </a>
            </aside>
        </div>
        <p class="admin-reports-footnote">Reports are private. Existing access checks, audit logging and CSRF protection remain in place.</p>
    </main>
    <nav class="admin-reports-bottom-nav" aria-label="Mobile admin navigation">
        <?php foreach ($reportNav as $item): ?>
            <a href="<?= e($item['href']) ?>" class="<?= $item['title'] === 'Reports' ? 'is-current' : '' ?>"
               <?= $item['title'] === 'Reports' ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
</body>
</html>
