<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/admin_access.php';
require_once __DIR__ . '/../php/frontend.php';

require_login();
bushisa_require_moderator($pdo);
header('Cache-Control: no-store, private');

/**
 * Administrative confession directory. Read-only by design: all actual
 * moderation actions remain in the existing protected moderation tools.
 * Keep student identities private; use anonymous handles only.
 */
function admin_confessions_query(array $changes = []): string
{
    global $search, $filter;
    $params = array_merge(['q' => $search, 'filter' => $filter], $changes);
    $params = array_filter($params, static fn (mixed $value): bool => $value !== '' && $value !== null);
    return 'confessions.php' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
$filter = isset($_GET['filter']) && is_string($_GET['filter']) ? $_GET['filter'] : 'all';
if (!in_array($filter, ['all', 'published', 'flagged'], true)) {
    $filter = 'all';
}
$page = isset($_GET['page']) && is_string($_GET['page']) && ctype_digit($_GET['page'])
    ? max(1, min(10000, (int) $_GET['page'])) : 1;
$view = isset($_GET['view']) && is_string($_GET['view']) && ctype_digit($_GET['view'])
    ? (int) $_GET['view'] : 0;
$perPage = 10;

$hasConfessions = operational_table_exists($pdo, 'confessions');
$stats = ['total' => 0, 'flagged' => 0, 'published' => 0];
$records = [];
$selected = null;
$resultCount = 0;
$totalPages = 1;
$notice = null;

if ($hasConfessions) {
    // The helper supports either historical schema: body/content, author_id/user_id.
    $columns = operational_confession_columns($pdo);
    $body = $columns['body'];
    $author = $columns['author'];
    $hasAnonHandles = operational_table_exists($pdo, 'profiles')
        && operational_column_exists($pdo, 'profiles', 'anon_handle');
    $join = $hasAnonHandles ? ' LEFT JOIN profiles p ON p.user_id = c.' . $author : '';
    $handle = $hasAnonHandles ? "NULLIF(p.anon_handle, '')" : 'NULL';

    $counts = $pdo->query(
        'SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN is_flagged = 1 THEN 1 ELSE 0 END), 0) AS flagged,
            COALESCE(SUM(CASE WHEN is_flagged = 0 THEN 1 ELSE 0 END), 0) AS published
         FROM confessions'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($stats as $key => $_) {
        $stats[$key] = (int) ($counts[$key] ?? 0);
    }

    $where = [];
    $params = [];
    if ($filter === 'flagged') {
        $where[] = 'c.is_flagged = 1';
    } elseif ($filter === 'published') {
        $where[] = 'c.is_flagged = 0';
    }
    if ($search !== '') {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $pattern = '%' . $escaped . '%';
        $where[] = "(c.$body LIKE :body_search ESCAPE '!' OR CAST(c.id AS CHAR) LIKE :id_search ESCAPE '!')";
        $params[':body_search'] = $pattern;
        $params[':id_search'] = $pattern;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM confessions c' . $whereSql);
    $countStmt->execute($params);
    $resultCount = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($resultCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT c.id, c.' . $body . ' AS body, c.is_flagged, c.created_at,
            ' . $handle . ' AS anon_handle
         FROM confessions c' . $join . $whereSql . '
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
    );
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($view > 0) {
        $detailStmt = $pdo->prepare(
            'SELECT c.id, c.' . $body . ' AS body, c.is_flagged, c.created_at,
                ' . $handle . ' AS anon_handle
             FROM confessions c' . $join . ' WHERE c.id = :id LIMIT 1'
        );
        $detailStmt->execute([':id' => $view]);
        $detailRow = $detailStmt->fetch(PDO::FETCH_ASSOC);
        $selected = is_array($detailRow) ? $detailRow : null;
        if ($selected === null) {
            $notice = 'That confession is no longer available.';
        }
    }
} else {
    $notice = 'The confessions table is not available in this database.';
}

$nav = [
    ['href' => 'dashboard.php', 'title' => 'Overview', 'icon' => 'overview'],
    ['href' => 'users.php', 'title' => 'Users', 'icon' => 'users'],
    ['href' => 'confessions.php', 'title' => 'Confessions', 'icon' => 'confessions'],
    ['href' => 'reports.php', 'title' => 'Reports', 'icon' => 'reports'],
    ['href' => 'moderation.php', 'title' => 'Moderation', 'icon' => 'moderation'],
];

render_document_head('Confessions management');
?>
<body class="bushisa-admin-dashboard bushisa-admin-confessions">
<a class="admin-skip-link" href="#confessions-main">Skip to confessions</a>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Administrator navigation">
        <div class="admin-brand">
            <span class="admin-brand__heart" aria-hidden="true">♡</span>
            <div><span class="admin-brand__title">Bushisa</span><span class="admin-brand__subtitle">ADMIN WORKSPACE</span></div>
        </div>
        <div class="admin-sidebar__middle">
            <p class="admin-sidebar__eyebrow">WORKSPACE</p>
            <nav class="admin-nav" aria-label="Admin sections">
                <?php foreach ($nav as $item): ?>
                    <a class="admin-nav__link<?= $item['title'] === 'Confessions' ? ' is-current' : '' ?>"
                       href="<?= e($item['href']) ?>" <?= $item['title'] === 'Confessions' ? 'aria-current="page"' : '' ?>>
                        <span class="admin-nav__icon admin-nav__icon--<?= e($item['icon']) ?>" aria-hidden="true"></span>
                        <span><?= e($item['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="admin-sidebar__footer">
            <p class="admin-sidebar__eyebrow">ACCOUNT</p>
            <a class="admin-sidebar__back" href="../discover.php">← Back to Bushisa</a>
            <a class="admin-sidebar__logout" href="../logout.php">↪ Log out</a>
            <p class="admin-sidebar__motto">A little more care,<br>a safer campus.</p>
        </div>
    </aside>

    <header class="admin-confessions-mobile-top">
        <a href="dashboard.php" class="admin-confessions-mobile-brand"><span aria-hidden="true">♡</span> Bushisa</a>
        <span>ADMIN</span>
    </header>

    <main class="admin-main admin-confessions-main" id="confessions-main">
        <header class="admin-page-header admin-confessions-header">
            <div class="admin-page-header__copy">
                <p class="admin-eyebrow">ADMINISTRATION / CONFESSIONS</p>
                <h1>Confessions management</h1>
                <p class="admin-page-header__description">Review anonymous campus posts with care and context.</p>
            </div>
            <div class="admin-preview-tag"><span class="admin-preview-tag__dot" aria-hidden="true"></span> LIVE / LOCAL DATA</div>
        </header>

        <section class="admin-confessions-notice">
            <div><p class="admin-eyebrow">ANONYMOUS BY DESIGN</p>
                <p>Students stay anonymous on the public feed. Administrative controls belong in this workspace.</p>
            </div>
        </section>
        <section class="admin-metrics admin-confessions-metrics" aria-label="Confession statistics">
            <?php foreach ([
                ['Total confessions', $stats['total'], 'Posts in your database'],
                ['Flagged posts', $stats['flagged'], 'Needing review'],
                ['Published', $stats['published'], 'Visible to students'],
                ['Removed', '—', 'Not tracked by this schema'],
            ] as $metric): ?>
                <article class="admin-metric">
                    <h2><?= e($metric[0]) ?></h2>
                    <p class="admin-metric__value"><?= e($metric[1]) ?></p>
                    <p class="admin-metric__note"><?= e($metric[2]) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <form class="admin-confessions-filters" action="confessions.php" method="get" role="search">
            <label class="admin-eyebrow" for="confession-search">FIND A CONFESSION</label>
            <div class="admin-confessions-filter-fields">
                <input type="search" name="q" id="confession-search" maxlength="100"
                    placeholder="Search by post text or ID" value="<?= e($search) ?>">
                <label class="admin-confessions-filter-select" for="confession-filter">
                    <span class="sr-only">Post status</span>
                    <select id="confession-filter" name="filter">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All posts</option>
                        <option value="published" <?= $filter === 'published' ? 'selected' : '' ?>>Published</option>
                        <option value="flagged" <?= $filter === 'flagged' ? 'selected' : '' ?>>Flagged</option>
                    </select>
                </label>
                <button type="submit">Search</button>
            </div>
            <p class="admin-confessions-filter-note">Search and filters show real entries from your database.</p>
        </form>

        <div class="admin-confessions-panels">
            <section class="admin-confessions-directory" aria-labelledby="confessions-directory-title">
                <div class="admin-confessions-section-heading">
                    <div><p class="admin-eyebrow">CONFESSION DIRECTORY</p>
                        <h2 id="confessions-directory-title">Campus confessions</h2></div>
                    <span class="admin-confessions-count"><?= e($resultCount) ?> <?= $resultCount === 1 ? 'post' : 'posts' ?></span>
                </div>
                <?php if ($notice !== null): ?>
                    <p class="admin-confessions-empty" role="status"><?= e($notice) ?></p>
                <?php endif; ?>
                <?php if ($records === []): ?>
                    <div class="admin-confessions-empty">
                        <span class="admin-confessions-empty-icon" aria-hidden="true">✓</span>
                        <h3>No matching confessions</h3>
                        <p>Posts will appear here when they match your current search or filter.</p>
                    </div>
                <?php else: ?>
                    <div class="admin-confessions-list">
                        <?php foreach ($records as $post): ?>
                            <?php
                                $bodyText = (string) ($post['body'] ?? '');
                                $preview = function_exists('mb_substr') ? mb_substr($bodyText, 0, 220, 'UTF-8') : substr($bodyText, 0, 220);
                                $isFlagged = (int) $post['is_flagged'] === 1;
                            ?>
                            <article class="admin-confessions-post">
                                <div class="admin-confessions-post-meta">
                                    <strong>Post #<?= e($post['id']) ?></strong>
                                    <span class="admin-confessions-status<?= $isFlagged ? ' is-flagged' : '' ?>"><?= $isFlagged ? 'Flagged' : 'Published' ?></span>
                                </div>
                                <p class="admin-confessions-handle">@<?= e($post['anon_handle'] ?: 'anonymous') ?></p>
                                <p class="admin-confessions-post-body"><?= e($preview) ?><?= strlen($bodyText) > strlen($preview) ? '…' : '' ?></p>
                                <div class="admin-confessions-post-foot">
                                    <time datetime="<?= e($post['created_at']) ?>"><?= e($post['created_at']) ?></time>
                                    <a href="<?= e(admin_confessions_query(['view' => $post['id'], 'page' => $page])) ?>#confession-detail">View details →</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="admin-confessions-pagination" aria-label="Confession pages">
                            <?php if ($page > 1): ?>
                                <a href="<?= e(admin_confessions_query(['page' => $page - 1])) ?>">← Previous</a>
                            <?php endif; ?>
                            <span>Page <?= e($page) ?> of <?= e($totalPages) ?></span>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?= e(admin_confessions_query(['page' => $page + 1])) ?>">Next →</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <aside class="admin-confessions-guidance">
                <p class="admin-eyebrow">COMMUNITY SAFETY</p>
                <h2>Moderate thoughtfully</h2>
                <p>Protect student anonymity. Review the context, confirm decisions, and keep moderation actions auditable.</p>
                <a class="admin-confessions-action" href="moderation.php">Open moderation queue <span aria-hidden="true">→</span></a>
                <a class="admin-confessions-textlink" href="reports.php">Review private reports →</a>
            </aside>
        </div>

        <?php if ($selected !== null): ?>
            <section id="confession-detail" class="admin-confessions-detail" aria-labelledby="confession-detail-title" tabindex="-1">
                <div class="admin-confessions-section-heading">
                    <div><p class="admin-eyebrow">POST DETAILS · READ ONLY</p>
                        <h2 id="confession-detail-title">Post #<?= e($selected['id']) ?></h2></div>
                    <a href="<?= e(admin_confessions_query(['view' => null, 'page' => $page])) ?>">Close ×</a>
                </div>
                <p class="admin-confessions-handle">@<?= e($selected['anon_handle'] ?: 'anonymous') ?></p>
                <p class="admin-confessions-detail-body"><?= e($selected['body']) ?></p>
                <p class="admin-confessions-detail-meta"><?= (int) $selected['is_flagged'] === 1 ? 'Flagged for review' : 'Published' ?> · <?= e($selected['created_at']) ?></p>
                <a class="admin-confessions-action" href="moderation.php">Go to moderation tools →</a>
            </section>
        <?php endif; ?>
    </main>
    <nav class="admin-confessions-bottom-nav" aria-label="Mobile admin navigation">
        <?php foreach ($nav as $item): ?>
            <a href="<?= e($item['href']) ?>" class="<?= $item['title'] === 'Confessions' ? 'is-current' : '' ?>"
               <?= $item['title'] === 'Confessions' ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
</body>
</html>
