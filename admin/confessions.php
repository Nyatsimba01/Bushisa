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

$route = 'confessions.php';
$states = ['all'=>'All posts','published'=>'Published','flagged'=>'Flagged / hidden'];
$state = admin_content_input($_GET,'state','all');
if (!isset($states[$state])) $state = 'all';
$filters = ['state'=>$state,'q'=>$search,'page'=>$page];
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

    $counts = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(is_flagged=1),0) flagged, COALESCE(SUM(is_flagged=0),0) published FROM confessions')->fetch();
    $removed = $pdo->query("SELECT COUNT(DISTINCT SUBSTRING_INDEX(action,':',-1)) FROM audit_log WHERE action REGEXP '^(admin_)?delete_confession:[0-9]+$'")->fetchColumn();
    $body = operational_confession_columns($pdo)['body'];
    $where = []; $params = [];
    if ($state !== 'all') { $where[] = 'is_flagged = ?'; $params[] = $state === 'flagged' ? 1 : 0; }
    if ($search !== '') { $where[] = "(`$body` LIKE ? ESCAPE '!' OR id = ?)"; $params[] = admin_content_like($search); $params[] = admin_content_search_id($search); }
    $whereSql = $where ? ' WHERE ' . implode(' AND ',$where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM confessions' . $whereSql); $count->execute($params); $total = (int)$count->fetchColumn();
    $pages = max(1,(int)ceil($total/20)); $page = min($page,$pages); $filters['page']=$page;
    $query = $pdo->prepare("SELECT id, `$body` AS body, is_flagged, created_at FROM confessions" . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET ' . (($page-1)*20));
    $query->execute($params); $rows = $query->fetchAll();
    if (isset($_GET['post'])) {
        $selected = admin_content_post($pdo,admin_content_id($_GET,'post'));
        if (!$selected) { http_response_code(404); $error = 'Confession not found.'; }
        else $related = admin_content_related_reports($pdo,(int)$selected['id']);
    }

} catch (Throwable $exception) {
    error_log('Admin content unavailable: ' . $exception->getCode());
    http_response_code(503);
    exit('This workspace is temporarily unavailable. Please try again later.');
}

admin_workspace_start('Confessions','Confessions management','Review anonymous campus posts with care and context.','ANONYMOUS BY DESIGN','Students stay anonymous on the public feed. Administrative controls belong in this workspace.',[
    ['Total confessions',$counts['total'],'Posts in your database'],
    ['Flagged posts',$counts['flagged'],'Hidden, needing review'],
    ['Published',$counts['published'],'Visible to students'],
    ['Recorded removals',$removed,'Distinct post IDs in retained audit log'],
]);
?>
<form method="get" action="confessions.php#content-filters" id="content-filters" class="glass filters" role="search"><h2 class="eyebrow">FIND A CONFESSION</h2><div class="filter-fields"><label>Search confessions<input name="q" type="search" value="<?= e($search) ?>" maxlength="100" placeholder="Post text or #ID"></label><label>Status<select name="state"><?php foreach ($states as $value=>$label): ?><option value="<?= e($value) ?>" <?= $state === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button type="submit">Search</button><a href="confessions.php#content-filters">Reset</a></div><p class="hint">Most recent first · Statistics include all posts.</p></form>
<?php admin_workspace_flash($error,$success); ?>
<?php if ($selected): ?>
<section class="glass report-detail" id="content-detail" aria-labelledby="detail-title"><a class="close-details" href="<?= e(admin_content_url($route,$filters)) ?>">← Back to directory</a><p class="eyebrow">CONFESSION DETAILS</p><h2 id="detail-title">Post #<?= e($selected['id']) ?></h2><p class="hint"><?= (int)$selected['is_flagged'] === 1 ? 'Flagged · hidden from the public feed' : 'Published' ?> · <?= e($selected['created_at']) ?></p><p class="report-content"><?= e($selected['body']) ?></p>
<?php admin_content_reports($related); ?>
<p class="hint">Hiding preserves the post and its reports. Restoring makes it visible to students again.</p>
<?php $hidden=(int)$selected['is_flagged']===1; admin_content_form($selected,$hidden?'unflag_confession':'hide_confession',$hidden?'Restore post #'.$selected['id']:'Hide post #'.$selected['id'],admin_content_url($route,$filters,['post'=>$selected['id']],'content-detail')); ?>
</section>
<?php endif; ?>
<div class="reports-columns"><section class="glass queue" aria-labelledby="queue-title"><p class="eyebrow">CONFESSION DIRECTORY</p><h2 id="queue-title">Campus confessions</h2><p class="queue-intro">Search by confession text or post ID. View a confession before making a moderation decision.</p>
<?php if (!$rows): ?><div class="empty"><h3>No matching confessions</h3><p>No posts match this search and status. New posts appear here automatically.</p></div><?php endif; ?>
<?php foreach ($rows as $row): ?><article class="report-row"><div class="report-row-top"><h3>Post #<?= e($row['id']) ?></h3><span class="status"><?= (int)$row['is_flagged']===1?'Flagged / hidden':'Published' ?></span></div><p class="hint"><?= e($row['created_at']) ?> · Anonymous post</p><p class="reason-preview"><?= e($row['body']) ?></p><a class="report-link" href="<?= e(admin_content_url($route,$filters,['post'=>$row['id']],'content-detail')) ?>">View post #<?= e($row['id']) ?> details <span aria-hidden="true">→</span></a></article><?php endforeach; ?>
<?php admin_workspace_pages($route,$filters,$total,$page,$pages); ?></section>
<aside class="glass workflow"><p class="eyebrow">COMMUNITY SAFETY</p><h2>Moderate thoughtfully</h2><p>Anonymity should be preserved for students. Review context, confirm decisions, and keep actions auditable.</p><a class="report-link" href="moderation.php">Open moderation queue <span aria-hidden="true">→</span></a><p class="hint">This workspace uses reversible hiding and restoration. Recorded removals reflect historical audit entries; deleted content is not reconstructed.</p></aside></div>
<?php admin_workspace_end('Confessions'); ?>
