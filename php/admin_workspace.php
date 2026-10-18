<?php
declare(strict_types=1);
require_once __DIR__ . '/frontend.php';

/** Shared presentation for the new admin pages; no database or session side effects. */
function admin_workspace_start(string $section, string $title, string $subtitle, string $noticeTitle, string $notice, array $metrics): void {
    $nav = [
        ['href'=>'dashboard.php','title'=>'Overview','icon'=>'overview'],
        ['href'=>'users.php','title'=>'Users','icon'=>'users'],
        ['href'=>'confessions.php','title'=>'Confessions','icon'=>'confessions'],
        ['href'=>'reports.php','title'=>'Reports','icon'=>'reports'],
        ['href'=>'moderation.php','title'=>'Moderation','icon'=>'moderation'],
    ];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> · Bushisa</title><link rel="stylesheet" href="../css/admin-reports.css"></head>
<body class="bushisa-admin-reports admin-content-workspace"><a class="admin-skip-link" href="#workspace-main">Skip to content</a><div class="admin-layout">
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
                    <a class="admin-nav__link<?= $item['title'] === $section ? ' is-current' : '' ?>"
                       href="<?= e($item['href']) ?>" <?= $item['title'] === $section ? 'aria-current="page"' : '' ?>>
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
<main class="admin-main" id="workspace-main">
<header class="reports-header"><p class="eyebrow">ADMINISTRATION / <?= e(strtoupper($section)) ?></p><h1><?= e($title) ?></h1><p><?= e($subtitle) ?></p><span class="access-tag">● &nbsp; AUTHORISED ACCESS</span></header>
<section class="glass privacy"><h2 class="eyebrow"><?= e($noticeTitle) ?></h2><p><?= e($notice) ?></p></section>
<section class="metrics" aria-label="<?= e($section) ?> statistics"><?php foreach ($metrics as [$label,$value,$note]): ?><article class="glass metric"><h2><?= e($label) ?></h2><p class="metric-value"><?= e($value) ?></p><p class="metric-note"><?= e($note) ?></p></article><?php endforeach; ?></section>
<?php }
function admin_workspace_end(string $section): void { ?>
<p class="page-note">A little more care, a safer campus. Keep moderation information confidential.</p></main>
<nav class="reports-bottom-nav" aria-label="Mobile administrator navigation"><?php
$links = $section === 'Confessions'
    ? ['Overview'=>'dashboard.php','Users'=>'users.php','Confessions'=>'confessions.php','Reports'=>'reports.php','Moderation'=>'moderation.php']
    : ['Users'=>'users.php','Confessions'=>'confessions.php','Reports'=>'reports.php','Moderation'=>'moderation.php','Overview'=>'dashboard.php'];
foreach ($links as $label=>$href): ?><a href="<?= e($href) ?>" <?= $section === $label ? 'aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?><a href="../discover.php">Back to Bushisa</a><a href="../logout.php">Log out</a></nav>
</div></body></html>
<?php }
function admin_workspace_flash(?string $error, ?string $success): void { ?>
<?php if ($error): ?><p class="flash error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="flash" role="status"><?= e($success) ?></p><?php endif; ?>
<?php }
function admin_workspace_pages(string $route, array $filters, int $total, int $page, int $pages): void { ?>
<footer class="pagination"><span><?= e($total) ?> matching records · Page <?= e($page) ?> of <?= e($pages) ?></span><div><?php if ($page>1): ?><a href="<?= e(admin_content_url($route,$filters,['page'=>$page-1])) ?>">← Previous</a><?php endif; ?> <?php if ($page<$pages): ?><a href="<?= e(admin_content_url($route,$filters,['page'=>$page+1])) ?>">Next →</a><?php endif; ?></div></footer>
<?php }
