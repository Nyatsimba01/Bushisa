<?php
declare(strict_types=1);
require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/admin_access.php';
require_once __DIR__ . '/../php/admin_workspace.php';
require_once __DIR__ . '/../php/admin_content.php';
require_login();
bushisa_require_moderator($pdo);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$error = null;
$success = $_SESSION['content_flash'] ?? null;
unset($_SESSION['content_flash']);
$selected = null;
$related = [];
$search = substr(trim(admin_content_input($_GET,'q')),0,100);
$page = max(1,min(1000000,(int)admin_content_input($_GET,'page','1')));

$route = 'moderation.php';
$views = ['flagged'=>'Flagged posts','suspended'=>'Suspended accounts','activity'=>'Moderation activity'];
$view = admin_content_input($_GET,'view','flagged');
if (!isset($views[$view])) $view = 'flagged';
$filters = ['view'=>$view,'q'=>$search,'page'=>$page];
$isAdmin = $_SESSION['role'] === 'admin';
$accountSelected = isset($_GET['account']);
try {
    ensure_operational_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $_SESSION['content_flash'] = admin_content_action($pdo,(int)$_SESSION['user_id'],$_POST);
            header('Location: ' . admin_content_url($route,$filters),true,303);
            exit;
        } catch (DomainException $exception) {
            http_response_code(403); $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            if ($exception instanceof PDOException) throw $exception;
            http_response_code(409); $error = $exception->getMessage();
        }
    }

    $flagged = (int)$pdo->query('SELECT COUNT(*) FROM confessions WHERE is_flagged=1')->fetchColumn();
    $suspended = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_suspended=1')->fetchColumn();
    $stats = $pdo->query("SELECT COALESCE(SUM(status='pending'),0) pending, COALESCE(SUM(status IN ('reviewed','dismissed','action_taken')),0) resolved FROM reports")->fetch();
    $params = [];
    if ($view === 'suspended') {
        $from = ' FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE u.is_suspended=1';
        $columns = 'u.id, u.role, u.created_at, p.display_name'; $order = 'u.created_at DESC, u.id DESC';
        if ($search !== '') { $from .= " AND (p.display_name LIKE ? ESCAPE '!' OR u.id=?)"; $params = [admin_content_like($search),admin_content_search_id($search)]; }
    } elseif ($view === 'activity') {
        // Whitelist moderation event shapes; do not expose arbitrary log payloads or IPs.
        $from = " FROM audit_log WHERE action REGEXP '^(admin_(hide_confession|unflag_confession|unsuspend_user)|moderate_report_(hide_confession|suspend_user|reviewed|dismiss)):[0-9]+$'";
        $columns = 'id, user_id, action, timestamp AS created_at'; $order = 'timestamp DESC, id DESC';
        if ($search !== '') { $from .= " AND (action LIKE ? ESCAPE '!' OR id=?)"; $params = [admin_content_like($search),admin_content_search_id($search)]; }
    } else {
        $body = operational_confession_columns($pdo)['body'];
        $from = ' FROM confessions WHERE is_flagged=1';
        $columns = "id, `$body` AS body, is_flagged, created_at"; $order='created_at DESC, id DESC';
        if ($search !== '') { $from .= " AND (`$body` LIKE ? ESCAPE '!' OR id=?)"; $params = [admin_content_like($search),admin_content_search_id($search)]; }
    }
    $count = $pdo->prepare('SELECT COUNT(*)'.$from); $count->execute($params); $total=(int)$count->fetchColumn();
    $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages); $filters['page']=$page;
    $query=$pdo->prepare('SELECT '.$columns.$from.' ORDER BY '.$order.' LIMIT 20 OFFSET '.(($page-1)*20)); $query->execute($params); $rows=$query->fetchAll();
    if ($accountSelected || isset($_GET['post'])) {
        $selected=$accountSelected ? admin_content_account($pdo,admin_content_id($_GET,'account')) : admin_content_post($pdo,admin_content_id($_GET,'post'));
        if (!$selected) { http_response_code(404); $error='Record not found.'; }
        else $related=admin_content_related_reports($pdo,(int)$selected['id'],$accountSelected);
    }

} catch (Throwable $exception) {
    error_log('Admin content unavailable: ' . $exception->getCode());
    http_response_code(503);
    exit('This workspace is temporarily unavailable. Please try again later.');
}

admin_workspace_start('Moderation','Moderation centre','A safer campus, one careful decision at a time.','CAREFUL MODERATION','Moderation actions affect real student accounts and posts. Check context before confirming any action.',[
    ['Flagged confessions',$flagged,'Hidden, waiting for assessment'],['Suspended accounts',$suspended,'Currently restricted'],['Pending reports',$stats['pending'],'Safety reports awaiting review'],['Resolved cases',$stats['resolved'],'Reviewed, dismissed or action taken'],
]);
?>
<form method="get" action="moderation.php#content-filters" id="content-filters" class="glass filters" role="search"><h2 class="eyebrow">FIND A CASE</h2><div class="filter-fields"><label>Search cases<input name="q" type="search" maxlength="100" value="<?= e($search) ?>" placeholder="Text, account name or #ID"></label><label>Queue<select name="view"><?php foreach($views as $value=>$label): ?><option value="<?= e($value) ?>" <?= $view===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button type="submit">Search</button><a href="moderation.php#content-filters">Reset</a></div><p class="hint">Search applies to the selected queue · Statistics cover all records.</p></form>
<?php admin_workspace_flash($error,$success); ?>
<?php if ($selected): ?>
<section class="glass report-detail" id="content-detail" aria-labelledby="detail-title"><a class="close-details" href="<?= e(admin_content_url($route,$filters)) ?>">← Back to queue</a><p class="eyebrow">PRIVATE CASE REVIEW</p><h2 id="detail-title"><?= $accountSelected?'Account':'Post' ?> #<?= e($selected['id']) ?></h2>
<?php if ($accountSelected): ?><dl><div><dt>Role</dt><dd><?= e(ucfirst($selected['role'])) ?></dd></div><div><dt>Status</dt><dd><?= (int)$selected['is_suspended']===1?'Suspended':'Active' ?></dd></div><div><dt>Joined</dt><dd><?= e($selected['created_at']) ?></dd></div></dl>
<?php else: ?><p class="hint"><?= (int)$selected['is_flagged']===1?'Flagged · hidden from the public feed':'Published' ?> · <?= e($selected['created_at']) ?></p><p class="report-content"><?= e($selected['body']) ?></p><?php endif; ?>
<?php admin_content_reports($related); ?>
<?php if ($accountSelected): ?>
    <?php if ($isAdmin && in_array($selected['role'],['student','user'],true) && (int)$selected['is_suspended']===1 && (int)$selected['id'] !== (int)$_SESSION['user_id']): ?>
        <p class="hint">Restoring access lets this student sign in again. Previous reports and audit history are preserved.</p>
        <?php admin_content_form($selected,'unsuspend_user','Restore account #'.$selected['id'],admin_content_url($route,$filters,['account'=>$selected['id']],'content-detail')); ?>
    <?php else: ?><p class="hint">Account restoration requires an administrator and a currently suspended non-staff account.</p><?php endif; ?>
<?php elseif ((int)$selected['is_flagged']===1): ?>
    <p class="hint">Restoring this confession makes it visible on the student feed. Related reports keep their existing decisions.</p>
    <?php admin_content_form($selected,'unflag_confession','Restore post #'.$selected['id'],admin_content_url($route,$filters,['post'=>$selected['id']],'content-detail')); ?>
<?php else: ?><p class="hint">This post is no longer flagged. Its current status has been refreshed.</p><?php endif; ?>
</section>
<?php endif; ?>
<div class="reports-columns"><section class="glass queue" aria-labelledby="queue-title"><p class="eyebrow">MODERATION QUEUE</p><h2 id="queue-title"><?= $view==='flagged'?'Flagged confession review':e($views[$view]) ?></h2><p class="queue-intro"><?= $view==='flagged'?'Review the complete post and related reports before restoring it.':($view==='suspended'?'Review the account and its related reports before restoring access.':'Confirmed moderation decisions, recorded without exposing private log payloads.') ?></p>
<?php if (!$rows): ?><div class="empty"><h3><?= $search==='' && $view==='flagged'?'Your review queue is clear':'No matching records' ?></h3><p><?= $view==='flagged'?'No flagged confessions match this queue. Review suspended accounts separately.':'No records match this queue and search.' ?></p></div><?php endif; ?>
<?php foreach ($rows as $row): ?><article class="report-row">
<?php if ($view==='activity'): ?>
    <?php [$event,$target]=explode(':',$row['action'],2); $labels=['admin_hide_confession'=>'Post hidden','admin_unflag_confession'=>'Post restored','admin_unsuspend_user'=>'Account restored','moderate_report_hide_confession'=>'Reported post hidden','moderate_report_suspend_user'=>'Reported account suspended','moderate_report_reviewed'=>'Report reviewed','moderate_report_dismiss'=>'Report dismissed']; ?>
    <h3><?= e($labels[$event] ?? 'Moderation decision') ?></h3><p class="hint">Event #<?= e($row['id']) ?> · <?= e($row['created_at']) ?> · Staff account #<?= e($row['user_id']) ?></p><p>Target <?= str_starts_with($event,'moderate_report_')?'report':($event==='admin_unsuspend_user'?'account':'post') ?> #<?= e($target) ?></p>
<?php elseif ($view==='suspended'): ?>
    <div class="report-row-top"><h3><?= e($row['display_name'] ?: 'Account #'.$row['id']) ?></h3><span class="status">Suspended</span></div><p class="hint">Account #<?= e($row['id']) ?> · <?= e(ucfirst($row['role'])) ?></p><a class="report-link" href="<?= e(admin_content_url($route,$filters,['account'=>$row['id']],'content-detail')) ?>">Review account #<?= e($row['id']) ?> <span aria-hidden="true">→</span></a>
<?php else: ?>
    <div class="report-row-top"><h3>Post #<?= e($row['id']) ?></h3><span class="status">Flagged / hidden</span></div><p class="hint"><?= e($row['created_at']) ?> · Anonymous post</p><p class="reason-preview"><?= e($row['body']) ?></p><a class="report-link" href="<?= e(admin_content_url($route,$filters,['post'=>$row['id']],'content-detail')) ?>">Review post #<?= e($row['id']) ?> <span aria-hidden="true">→</span></a>
<?php endif; ?></article><?php endforeach; ?>
<?php admin_workspace_pages($route,$filters,$total,$page,$pages); ?></section>
<aside class="glass workflow"><p class="eyebrow">ACCOUNT CONTROLS</p><h2>Suspended users</h2><p>View suspended accounts and their current status. Restore access only after reviewing the case.</p><a class="report-link" href="moderation.php?view=suspended#content-filters">Review suspended users <span aria-hidden="true">→</span></a><a class="report-link" href="moderation.php?view=activity#content-filters">View moderation activity <span aria-hidden="true">→</span></a><a class="report-link" href="reports.php">Open safety reports <span aria-hidden="true">→</span></a><p class="hint">Post restoration is available to moderators. Account restoration is restricted to administrators. These actions preserve database records.</p></aside></div>
<?php admin_workspace_end('Moderation'); ?>
