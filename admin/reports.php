<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/report_handler.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/logger.php';
require_once __DIR__ . '/../php/frontend.php';
require_once __DIR__ . '/../php/operational.php';

require_login();
if (!is_moderator()) {
    redirect('../discover.php');
}
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

render_auth_start('Reports', $csrfToken);
?>
<section class="auth-card" aria-labelledby="reports-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="reports-title">Safety reports</h1>
    <p class="auth-card__lead">Private moderator queue. Read the post and reason before taking action. Reports do not automatically remove content.</p>
    <?php render_flash($error, $success); ?>

    <nav class="card-actions" aria-label="Filter safety reports">
        <?php foreach ($allowedStatusFilters as $filter): ?>
            <a class="button-secondary" href="reports.php?status=<?= e($filter) ?>" <?= $filter === $status_filter ? 'aria-current="page"' : '' ?>><?= e(ucwords(str_replace('_', ' ', $filter))) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="form-grid">
        <?php if ($reports === []): ?>
            <article class="empty-state">
                <h2>No reports</h2>
                <p>No reports match this filter.</p>
            </article>
        <?php else: ?>
            <?php foreach ($reports as $report): ?>
                <?php $isConfession = ($report['target_type'] ?? '') === 'confession'
                    || (isset($report['confession_id']) && $report['confession_id'] !== null); ?>
                <article class="section-card">
                    <h2>Report #<?= e($report['id'] ?? '') ?> <?= $isConfession ? '· Confession' : '· Account / other' ?></h2>
                    <p class="muted">Status: <?= e($report['status'] ?? 'pending') ?> · Submitted <?= e(format_time_ago((string) ($report['created_at'] ?? ''))) ?></p>
                    <?php if ($isConfession): ?>
                        <p class="muted">Post #<?= e($report['reported_confession_id'] ?? $report['confession_id'] ?? $report['target_id'] ?? '?') ?></p>
                        <?php if ($report['reported_confession_id'] !== null): ?>
                            <div class="section-card">
                                <strong>Reported post content</strong>
                                <p style="overflow-wrap:anywhere;white-space:pre-wrap"><?= e((string) ($report['confession_body'] ?? '')) ?></p>
                                <?php if ((int) ($report['confession_is_flagged'] ?? 0) === 1): ?>
                                    <p class="muted">This post is hidden from the public feed.</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="muted">This confession was removed or is no longer available.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <p><strong>Report reason:</strong> <?= e($report['reason'] ?? '') ?></p>
                    <p class="muted">Submitted by account #<?= e((string) ($report['reporter_id'] ?? '')) ?>
                        <?php if ($report['reported_user_id'] !== null): ?>
                            · Reported account #<?= e((string) $report['reported_user_id']) ?>
                        <?php endif; ?>
                        (only visible to moderators)
                    </p>
                    <div class="card-actions">
                        <?php foreach (['reviewed' => 'Mark reviewed', 'dismiss' => 'Dismiss'] as $action => $label): ?>
                            <form method="post" action="reports.php?status=<?= e($status_filter) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="report_id" value="<?= e($report['id'] ?? '') ?>">
                                <input type="hidden" name="action" value="<?= e($action) ?>">
                                <button class="button-secondary" type="submit"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                        <?php if ($isConfession && $report['reported_confession_id'] !== null && (int) ($report['confession_is_flagged'] ?? 0) !== 1): ?>
                            <form method="post" action="reports.php?status=<?= e($status_filter) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="report_id" value="<?= e($report['id'] ?? '') ?>">
                                <input type="hidden" name="action" value="hide_confession">
                                <button class="button-danger" type="submit">Hide post from feed</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((int) ($report['reported_user_id'] ?? 0) > 0): ?>
                            <form method="post" action="reports.php?status=<?= e($status_filter) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="report_id" value="<?= e($report['id'] ?? '') ?>">
                                <input type="hidden" name="action" value="suspend_user">
                                <button class="button-danger" type="submit" onclick="return confirm('Suspend this user account?')">Suspend user</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <p class="auth-switch"><a href="moderation.php">Flagged posts</a> · <a href="dashboard.php">Back to dashboard</a></p>
</section>
<?php
render_auth_end();
