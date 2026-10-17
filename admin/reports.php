<?php
declare(strict_types=1);
require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/admin_access.php';
require_once __DIR__ . '/../php/frontend.php';
require_once __DIR__ . '/../php/logger.php';
require_login();
bushisa_require_moderator($pdo);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

function report_input(array $source, string $key, string $default = ''): string {
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default;
}
function report_url(array $filters, array $changes = [], string $anchor = 'report-filters'): string {
    return 'reports.php?' . http_build_query(array_merge($filters, $changes)) . '#' . $anchor;
}
$statuses = ['all'=>'All reports','pending'=>'Pending','reviewed'=>'Reviewed','dismissed'=>'Dismissed','action_taken'=>'Action taken'];
$status = report_input($_GET, 'status', 'pending');
if (!isset($statuses[$status])) $status = 'pending';
$search = substr(trim(report_input($_GET, 'q')), 0, 100);
$page = max(1, min(1000000, (int) report_input($_GET, 'page', '1')));
$filters = ['status'=>$status, 'q'=>$search, 'page'=>$page];
$error = null;
$success = $_SESSION['reports_flash'] ?? null;
unset($_SESSION['reports_flash']);
$selected = null;
$actorId = (int) $_SESSION['user_id'];
$isAdmin = $_SESSION['role'] === 'admin';
try {
    ensure_operational_schema($pdo);
    // Lock and validate both the report and current permissions before any write.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token(report_input($_POST, 'csrf_token'))) {
            http_response_code(403);
            $error = 'The form expired. Please review the report and try again.';
        } else {
            try {
                $pdo->beginTransaction();
                $actor = $pdo->prepare('SELECT role, is_suspended FROM users WHERE id = ? FOR UPDATE');
                $actor->execute([$actorId]);
                $permission = $actor->fetch();
                if (!$permission || (int)$permission['is_suspended'] === 1 || !in_array($permission['role'], ['admin','moderator'], true)) {
                    throw new RuntimeException('Your review permissions have changed.');
                }
                $get = $pdo->prepare('SELECT * FROM reports WHERE id = ? FOR UPDATE');
                $get->execute([(int) report_input($_POST, 'report_id')]);
                $report = $get->fetch();
                if (!$report) throw new RuntimeException('Report not found.');
                if ($report['status'] !== report_input($_POST, 'expected_status')) throw new RuntimeException('This report has changed. Open it again before taking action.');
                $action = report_input($_POST, 'action');
                if (!in_array($action, ['reviewed','dismiss','hide_confession','suspend_user'], true)) throw new RuntimeException('Invalid review action.');
                if ($report['status'] !== 'pending') throw new RuntimeException('This report has already been reviewed.');
                $newStatus = $action === 'dismiss' ? 'dismissed' : 'reviewed';
                if (in_array($action, ['hide_confession','suspend_user'], true) && report_input($_POST,'confirm') !== 'yes') throw new RuntimeException('Confirm that you have reviewed the reason and intend to take this action.');
                if ($action === 'hide_confession') {
                    $postId = (int)($report['confession_id'] ?? 0);
                    if (!$postId && ($report['target_type'] ?? '') === 'confession') $postId = (int)$report['target_id'];
                    $post = $pdo->prepare('SELECT id FROM confessions WHERE id = ? FOR UPDATE');
                    $post->execute([$postId]);
                    if (!$post->fetchColumn()) throw new RuntimeException('The reported confession is no longer available.');
                    $pdo->prepare('UPDATE confessions SET is_flagged = 1 WHERE id = ?')->execute([$postId]);
                    $newStatus = 'action_taken';
                }
                if ($action === 'suspend_user') {
                    if ($permission['role'] !== 'admin') throw new RuntimeException('Only administrators can suspend reported accounts.');
                    $targetId = (int)($report['reported_user_id'] ?? 0);
                    $target = $pdo->prepare('SELECT role, is_suspended FROM users WHERE id = ? FOR UPDATE');
                    $target->execute([$targetId]);
                    $account = $target->fetch();
                    if (!$account || $targetId === $actorId || !in_array($account['role'], ['student','user'], true)) throw new RuntimeException('This account cannot be suspended from the report queue.');
                    if ((int)$account['is_suspended'] === 1) throw new RuntimeException('This account is already suspended.');
                    $pdo->prepare('UPDATE users SET is_suspended = 1 WHERE id = ?')->execute([$targetId]);
                    $newStatus = 'action_taken';
                }
                $pdo->prepare('UPDATE reports SET status = ? WHERE id = ?')->execute([$newStatus, $report['id']]);
                log_action($pdo, $actorId, 'moderate_report_' . $action . ':' . $report['id']);
                $pdo->commit();
                $_SESSION['reports_flash'] = 'Report #' . (int)$report['id'] . ' updated: ' . $statuses[$newStatus] . '.';
                header('Location: ' . report_url($filters), true, 303);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($exception instanceof PDOException) throw $exception;
                http_response_code(409);
                $error = $exception->getMessage();
            }
        }
    }
    $counts = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(status='pending'),0) pending, COALESCE(SUM(status='reviewed'),0) reviewed, COALESCE(SUM(status='action_taken'),0) action_taken FROM reports")->fetch();
    $where = []; $params = [];
    if ($status !== 'all') { $where[] = 'r.status = ?'; $params[] = $status; }
    if ($search !== '') {
        $where[] = "(r.reason LIKE ? ESCAPE '!' OR r.id = ?)";
        $params[] = '%' . strtr($search, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
        $params[] = ctype_digit(ltrim($search, '#')) ? (int)ltrim($search, '#') : 0;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM reports r' . $whereSql);
    $count->execute($params); $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / 20)); $page = min($page, $pages); $filters['page'] = $page;
    $offset = ($page - 1) * 20;
    $list = $pdo->prepare('SELECT r.id, r.reason, r.status, r.created_at, r.target_type, r.confession_id FROM reports r' . $whereSql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 20 OFFSET ' . $offset);
    $list->execute($params); $reports = $list->fetchAll();
    if (isset($_GET['report'])) {
        $body = operational_confession_columns($pdo)['body'];
        $detail = $pdo->prepare("SELECT r.*, c.id AS post_id, c.`$body` AS post_body, c.is_flagged, u.role AS target_role, u.is_suspended AS target_suspended FROM reports r LEFT JOIN confessions c ON c.id = COALESCE(NULLIF(r.confession_id,0), CASE WHEN r.target_type='confession' THEN r.target_id ELSE NULL END) LEFT JOIN users u ON u.id = r.reported_user_id WHERE r.id = ?");
        $detail->execute([(int)report_input($_GET, 'report')]); $selected = $detail->fetch();
        if (!$selected) { http_response_code(404); $error = 'Report not found.'; }
    }
} catch (Throwable $exception) {
    error_log('Reports queue unavailable: ' . $exception->getCode());
    http_response_code(503);
    exit('Reports are temporarily unavailable. Please try again later.');
}
$csrfToken = generate_csrf_token();
$nav = [
 ['href'=>'dashboard.php','title'=>'Overview','icon'=>'overview'],
 ['href'=>'users.php','title'=>'Users','icon'=>'users'],
 ['href'=>'../confessions.php','title'=>'Confessions','icon'=>'confessions'],
 ['href'=>'reports.php','title'=>'Reports','icon'=>'reports'],
 ['href'=>'moderation.php','title'=>'Moderation','icon'=>'moderation'],
];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reports management · Bushisa</title><link rel="stylesheet" href="../css/admin-reports.css"></head>
<body class="bushisa-admin-reports">
<a class="admin-skip-link" href="#reports-main">Skip to reports</a>
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
                <?php foreach ($nav as $item): ?>
                    <a class="admin-nav__link<?= $item['title'] === 'Reports' ? ' is-current' : '' ?>"
                       href="<?= e($item['href']) ?>" <?= $item['title'] === 'Reports' ? 'aria-current="page"' : '' ?>>
                        <span class="admin-nav__icon admin-nav__icon--<?= e($item['icon']) ?>" aria-hidden="true"></span>
                        <span><?= e($item['title']) ?></span>
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


    <header class="reports-mobile-top"><a href="dashboard.php"><span aria-hidden="true">♡</span> Bushisa</a><span>ADMIN</span></header>
    <main class="admin-main" id="reports-main">
        <header class="reports-header"><p class="eyebrow">ADMINISTRATION / REPORTS</p><h1>Reports management</h1><p>Private safety reports, reviewed thoughtfully.</p><span class="access-tag">● &nbsp; AUTHORISED ACCESS</span></header>
        <section class="glass privacy"><h2 class="eyebrow">PRIVATE REPORTING</h2><p>Reports are confidential. Details are visible only to authorised moderators and administrators.</p></section>
        <section class="metrics" aria-label="Report statistics">
        <?php foreach ([['Pending review','pending','New reports to assess'],['Reviewed','reviewed','Reports marked reviewed'],['Action taken','action_taken','Reviewed with a response'],['All reports','total','Recorded reports']] as [$label,$key,$note]): ?>
            <article class="glass metric"><h2><?= e($label) ?></h2><p class="metric-value"><?= e($counts[$key]) ?></p><p class="metric-note"><?= e($note) ?></p></article>
        <?php endforeach; ?>
        </section>
        <form method="get" action="reports.php#report-filters" id="report-filters" class="glass filters" role="search">
            <h2 class="eyebrow">FIND A REPORT</h2><div class="filter-fields">
            <label>Search reports<input name="q" type="search" maxlength="100" value="<?= e($search) ?>" placeholder="Reason or report #ID"></label>
            <label>Status<select name="status"><?php foreach ($statuses as $value=>$label): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <button type="submit">Search</button><a href="reports.php#report-filters">Reset</a></div>
            <p class="hint">Most recent first · Statistics include all reports.</p>
        </form>
        <?php if ($error): ?><p class="flash error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <?php if ($success): ?><p class="flash" role="status"><?= e($success) ?></p><?php endif; ?>
        <?php if ($selected): ?>
        <section class="glass report-detail" id="report-detail" aria-labelledby="detail-title">
            <a class="close-details" href="<?= e(report_url($filters)) ?>">← Back to queue</a>
            <p class="eyebrow">CONFIDENTIAL REPORT DETAILS</p><h2 id="detail-title">Report #<?= e($selected['id']) ?></h2>
            <dl><div><dt>Status</dt><dd><?= e($statuses[$selected['status']] ?? $selected['status']) ?></dd></div><div><dt>Submitted</dt><dd><?= e($selected['created_at']) ?></dd></div><div><dt>Reporter</dt><dd>Account #<?= e($selected['reporter_id']) ?> · private</dd></div><div><dt>Reported account</dt><dd><?= $selected['reported_user_id'] ? '#' . e($selected['reported_user_id']) : 'Not attached to this report' ?></dd></div></dl>
            <h3>Report reason</h3><p class="report-content"><?= e($selected['reason']) ?></p>
            <?php if ($selected['confession_id'] || $selected['target_type'] === 'confession'): ?>
                <h3>Reported confession</h3><p class="report-content"><?= e($selected['post_id'] ? $selected['post_body'] : 'This confession is no longer available.') ?></p>
                <?php if ((int)$selected['is_flagged'] === 1): ?><p class="hint">This post is hidden from the public feed.</p><?php endif; ?>
            <?php endif; ?>
            <p class="hint"><?= empty($selected['evidence_path']) ? 'No separate evidence attachment recorded.' : 'An evidence attachment is recorded. File preview is unavailable; its private storage location is not exposed.' ?></p>
            <?php if ($selected['status'] === 'pending'): ?>
            <div class="review-actions"><h3>Review decision</h3><p>Read the reason and available content before taking action. Reports alone do not establish a violation.</p>
            <form method="post" action="<?= e(report_url($filters, ['report'=>(int)$selected['id']], 'report-detail')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="report_id" value="<?= e($selected['id']) ?>"><input type="hidden" name="expected_status" value="<?= e($selected['status']) ?>">
                <div class="buttons"><button name="action" value="reviewed">Mark reviewed</button><button name="action" value="dismiss">Dismiss report</button></div>
            </form>
            <?php
            $canHide = $selected['post_id'] && (int)$selected['is_flagged'] !== 1;
            $canSuspend = $isAdmin && (int)$selected['reported_user_id'] !== $actorId && in_array($selected['target_role'], ['student','user'], true) && (int)$selected['target_suspended'] !== 1;
            ?>
            <?php if ($canHide || $canSuspend): ?>
            <form method="post" class="sensitive-action" action="<?= e(report_url($filters, ['report'=>(int)$selected['id']], 'report-detail')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="report_id" value="<?= e($selected['id']) ?>"><input type="hidden" name="expected_status" value="<?= e($selected['status']) ?>">
                <label class="confirmation"><input type="checkbox" name="confirm" value="yes" required> I have reviewed this report and confirm the selected action. Hiding removes the post from the feed; suspension prevents account access.</label>
                <div class="buttons"><?php if ($canHide): ?><button name="action" value="hide_confession">Hide reported post</button><?php endif; ?><?php if ($canSuspend): ?><button name="action" value="suspend_user">Suspend account #<?= e($selected['reported_user_id']) ?></button><?php endif; ?></div>
            </form>
            <?php endif; ?></div>
            <?php else: ?><p class="hint">This report has been reviewed. Its decision is preserved here for reference.</p><?php endif; ?>
        </section>
        <?php endif; ?>
        <div class="reports-columns">
        <section class="glass queue" aria-labelledby="queue-title"><p class="eyebrow">REPORT QUEUE</p><h2 id="queue-title"><?= $status === 'pending' ? 'Reports awaiting review' : e($statuses[$status]) ?></h2><p class="queue-intro">Open a report to review its reason and available evidence.</p>
        <?php if (!$reports): ?><div class="empty"><h3><?= $status === 'pending' && $search === '' ? 'All caught up' : 'No matching reports' ?></h3><p><?= $status === 'pending' && $search === '' ? 'There are no pending reports to review. New confidential reports will appear here.' : 'No reports match these filters. Try a different search or status.' ?></p></div><?php endif; ?>
        <?php foreach ($reports as $report): ?>
            <article class="report-row"><div class="report-row-top"><h3>Report #<?= e($report['id']) ?></h3><span class="status"><?= e($statuses[$report['status']] ?? $report['status']) ?></span></div><p class="hint"><?= $report['confession_id'] || $report['target_type'] === 'confession' ? 'Confession' : 'Account / other' ?> · <?= e($report['created_at']) ?></p><p class="reason-preview"><?= e($report['reason']) ?></p><a class="report-link" href="<?= e(report_url($filters, ['report'=>(int)$report['id']], 'report-detail')) ?>">Open report #<?= e($report['id']) ?> details <span aria-hidden="true">→</span></a></article>
        <?php endforeach; ?>
        <footer class="pagination"><span><?= e($total) ?> matching reports · Page <?= e($page) ?> of <?= e($pages) ?></span><div><?php if ($page > 1): ?><a href="<?= e(report_url($filters, ['page'=>$page-1])) ?>">← Previous</a><?php endif; ?> <?php if ($page < $pages): ?><a href="<?= e(report_url($filters, ['page'=>$page+1])) ?>">Next →</a><?php endif; ?></div></footer>
        </section>
        <aside class="glass workflow"><p class="eyebrow">REVIEW WORKFLOW</p><h2>Decide with context</h2><p>Mark reviewed, dismiss or hide a reported post when justified. Administrators can suspend reported student accounts after confirmation.</p><a class="report-link" href="moderation.php">View moderation workspace <span aria-hidden="true">→</span></a><p class="hint">Every decision is recorded in the audit log. Staff accounts are protected from suspension here.</p></aside>
        </div>
        <p class="page-note">A little more care, a safer campus. Keep report information confidential.</p>
    </main>
    <nav class="reports-bottom-nav" aria-label="Mobile administrator navigation"><?php foreach (array_merge(array_slice($nav, 1), [$nav[0]]) as $item): ?><a href="<?= e($item['href']) ?>" <?= $item['title'] === 'Reports' ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a><?php endforeach; ?><a href="../discover.php">Back to Bushisa</a><a href="../logout.php">Log out</a></nav>
</div></body></html>
