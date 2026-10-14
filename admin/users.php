<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/frontend.php';

require_login();

// Account data and exports are intentionally limited to administrators.
if (!is_admin()) {
    http_response_code(403);
    exit('Administrator access required.');
}

/** Fetch a scalar query-string value; never coerce array inputs. */
function users_input(string $key): string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
}

/** Keep identifying contact information private in every view and download. */
function users_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    return count($parts) === 2 ? '******@' . $parts[1] : 'Hidden';
}

/** Escape spreadsheet formula prefixes in an exported CSV cell. */
function users_csv_cell(string $value): string
{
    return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
}

/** Preserve current search filters in page and detail links. */
function users_url(array $filters, array $changes = []): string
{
    $params = array_merge($filters, $changes);
    $params = array_filter($params, static fn (mixed $v): bool => $v !== '' && $v !== null);
    return 'users.php' . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}

$emailColumn = operational_column_exists($pdo, 'users', 'student_email') ? 'student_email' : 'email';
$hasRole = operational_column_exists($pdo, 'users', 'role');
$hasSuspended = operational_column_exists($pdo, 'users', 'is_suspended');
$hasActive = operational_column_exists($pdo, 'users', 'is_active');
$hasVerified = operational_column_exists($pdo, 'users', 'is_verified');
$hasProfiles = operational_table_exists($pdo, 'profiles');

// Both supported Bushisa schemas are read without changing the database.
$emailExpression = 'u.' . $emailColumn;
$roleExpression = $hasRole ? "COALESCE(NULLIF(u.role, ''), 'student')" : "'student'";
$verifiedExpression = $hasVerified ? 'COALESCE(u.is_verified, 0)' : '0';
if ($hasSuspended && $hasActive) {
    $suspendedExpression = '(COALESCE(u.is_suspended, 0) = 1 OR COALESCE(u.is_active, 1) = 0)';
} elseif ($hasSuspended) {
    $suspendedExpression = '(COALESCE(u.is_suspended, 0) = 1)';
} elseif ($hasActive) {
    $suspendedExpression = '(COALESCE(u.is_active, 1) = 0)';
} else {
    $suspendedExpression = '(1 = 0)';
}
$nameExpression = $hasProfiles ? "COALESCE(NULLIF(p.display_name, ''), CONCAT('User #', u.id))" : "CONCAT('User #', u.id)";
$profileJoin = $hasProfiles ? ' LEFT JOIN profiles p ON p.user_id = u.id' : '';

$q = substr(users_input('q'), 0, 100);
$role = users_input('role');
$status = users_input('status');
$verification = users_input('verification');
if (!in_array($role, ['student', 'moderator', 'admin'], true)) {
    $role = '';
}
if (!in_array($status, ['active', 'suspended'], true)) {
    $status = '';
}
if (!in_array($verification, ['verified', 'pending'], true)) {
    $verification = '';
}
$filters = ['q' => $q, 'role' => $role, 'status' => $status, 'verification' => $verification];

$conditions = [];
$params = [];
if ($q !== '') {
    // Treat user-provided % and _ literally in SQL LIKE expressions.
    $escapedTerm = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $pattern = '%' . $escapedTerm . '%';
    $conditions[] = '(' . $nameExpression . " LIKE :name_search ESCAPE '!'"
        . ' OR ' . $emailExpression . " LIKE :email_search ESCAPE '!'"
        . " OR CAST(u.id AS CHAR) LIKE :id_search ESCAPE '!')";
    $params[':name_search'] = $pattern;
    $params[':email_search'] = $pattern;
    $params[':id_search'] = $pattern;
}
if ($role !== '') {
    $conditions[] = $roleExpression . ' = :role';
    $params[':role'] = $role;
}
if ($status !== '') {
    $conditions[] = $status === 'suspended' ? $suspendedExpression : 'NOT ' . $suspendedExpression;
}
if ($verification !== '') {
    $conditions[] = $verification === 'verified' ? $verifiedExpression . ' = 1' : $verifiedExpression . ' = 0';
}
$whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
$selectSql = 'SELECT u.id, ' . $emailExpression . ' AS email, ' . $nameExpression
    . ' AS display_name, ' . $roleExpression . ' AS role, '
    . $verifiedExpression . ' AS is_verified, CASE WHEN '
    . $suspendedExpression . ' THEN 1 ELSE 0 END AS is_suspended, u.created_at'
    . ' FROM users u' . $profileJoin;

$statsStatement = $pdo->query(
    'SELECT COUNT(*) AS total_accounts,'
    . ' COALESCE(SUM(CASE WHEN ' . $verifiedExpression . ' = 1 THEN 1 ELSE 0 END), 0) AS verified_users,'
    . ' COALESCE(SUM(CASE WHEN ' . $suspendedExpression . ' THEN 1 ELSE 0 END), 0) AS suspended_users,'
    . ' COALESCE(SUM(CASE WHEN ' . $roleExpression . " = 'admin' THEN 1 ELSE 0 END), 0) AS administrators"
    . ' FROM users u'
);
$stats = $statsStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$summary = [
    ['label' => 'Total accounts', 'value' => (int) ($stats['total_accounts'] ?? 0)],
    ['label' => 'Verified users', 'value' => (int) ($stats['verified_users'] ?? 0)],
    ['label' => 'Suspended', 'value' => (int) ($stats['suspended_users'] ?? 0)],
    ['label' => 'Administrators', 'value' => (int) ($stats['administrators'] ?? 0)],
];

// Admin-only, read-only export. The downloaded email field remains masked.
if (users_input('export') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bushisa-accounts-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store, max-age=0');
    $stream = fopen('php://output', 'wb');
    if ($stream !== false) {
        fputcsv($stream, ['Account ID', 'Display name', 'Masked email', 'Role', 'Verification', 'Status', 'Joined']);
        $exportStatement = $pdo->prepare($selectSql . $whereSql . ' ORDER BY u.created_at DESC, u.id DESC');
        $exportStatement->execute($params);
        while (($row = $exportStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            fputcsv($stream, [
                (int) $row['id'],
                users_csv_cell((string) $row['display_name']),
                users_mask_email((string) $row['email']),
                users_csv_cell(ucfirst((string) $row['role'])),
                (int) $row['is_verified'] === 1 ? 'Verified' : 'Pending',
                (int) $row['is_suspended'] === 1 ? 'Suspended' : 'Active',
                (string) $row['created_at'],
            ]);
        }
        fclose($stream);
    }
    exit;
}

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM users u' . $profileJoin . $whereSql);
$countStatement->execute($params);
$matching = (int) $countStatement->fetchColumn();
$perPage = 10;
$pages = max(1, (int) ceil($matching / $perPage));
$page = filter_var(users_input('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = min($pages, $page === false ? 1 : $page);
$offset = ($page - 1) * $perPage;

$statement = $pdo->prepare($selectSql . $whereSql . ' ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) {
    $statement->bindValue($key, $value, PDO::PARAM_STR);
}
$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$users = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selected = null;
$viewId = filter_var(users_input('view'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($viewId !== false) {
    $detailStatement = $pdo->prepare($selectSql . ' WHERE u.id = :id LIMIT 1');
    $detailStatement->execute([':id' => $viewId]);
    $selected = $detailStatement->fetch(PDO::FETCH_ASSOC) ?: null;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <title>User management | Bushisa</title>
    <link rel="stylesheet" href="<?= e(asset_path('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_path('css/admin-users.css')) ?>">
</head>
<body class="admin-users-body">
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Administration">
        <div class="admin-brand"><span class="admin-brand__heart" aria-hidden="true">♡</span><span><strong>Bushisa</strong><small>ADMIN WORKSPACE</small></span></div>
        <div class="admin-sidebar__line"></div>
        <p class="admin-kicker admin-sidebar__label">Workspace</p>
        <nav class="admin-side-links" aria-label="Admin pages">
            <a href="dashboard.php"><span aria-hidden="true">◫</span>Overview</a>
            <a class="is-active" href="users.php" aria-current="page"><span aria-hidden="true">♧</span>Users</a>
            <a href="../confessions.php"><span aria-hidden="true">◌</span>Confessions</a>
            <a href="reports.php"><span aria-hidden="true">⚑</span>Reports</a>
            <a href="moderation.php"><span aria-hidden="true">◇</span>Moderation</a>
        </nav>
        <div class="admin-sidebar__bottom">
            <div class="admin-sidebar__line"></div>
            <p class="admin-kicker">Account</p>
            <a class="admin-side-small admin-side-small--accent" href="../discover.php">← &nbsp;Back to Bushisa</a>
            <a class="admin-side-small" href="../logout.php">↪ &nbsp;Log out</a>
            <p class="admin-sidebar__motto">A little more care,<br>a safer campus.</p>
        </div>
    </aside>

    <header class="admin-mobile-header">
        <div class="admin-mobile-brand"><span aria-hidden="true">♡</span> Bushisa</div>
        <span class="admin-mobile-tag">ADMIN</span>
    </header>

    <main class="admin-main" id="main">
        <div class="admin-main__inner">
            <header class="admin-heading">
                <div>
                    <p class="admin-kicker admin-heading__crumb">Administration / Users</p>
                    <h1>User management</h1>
                    <p class="admin-heading__subtitle">Review accounts, verification and access.</p>
                </div>
                <span class="admin-development"><span class="admin-development__dot"></span> ADMINISTRATION</span>
            </header>

            <section class="admin-glass admin-safety" aria-label="Account safety">
                <div><p class="admin-kicker">Account safety</p>
                    <p>Sensitive student information stays behind administrator access.</p>
                </div>
                <span class="admin-safety__chip">LIVE DATABASE</span>
            </section>

            <section class="admin-stats" aria-label="Account statistics">
                <?php foreach ($summary as $metric): ?>
                    <article class="admin-stat">
                        <h2><?= e($metric['label']) ?></h2>
                        <p class="admin-stat__value"><?= e($metric['value']) ?></p>
                        <p class="admin-stat__note">Live database count</p>
                    </article>
                <?php endforeach; ?>
            </section>

            <form class="admin-glass admin-filters" method="get" action="users.php" role="search">
                <p class="admin-kicker">Find an account</p>
                <div class="admin-filters__fields">
                    <label class="admin-search-wrap">
                        <span class="sr-only">Search by name, account ID or email</span>
                        <span aria-hidden="true" class="admin-search-icon">⌕</span>
                        <input type="search" name="q" maxlength="100" value="<?= e($q) ?>" placeholder="Search by name, account ID or email">
                    </label>
                    <label><span class="sr-only">Role filter</span><select name="role">
                        <option value="">All roles</option>
                        <?php foreach (['student' => 'Student', 'moderator' => 'Moderator', 'admin' => 'Admin'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $role === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><span class="sr-only">Status filter</span><select name="status">
                        <option value="">All statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    </select></label>
                    <label class="admin-verification-filter"><span class="sr-only">Verification filter</span><select name="verification">
                        <option value="">Any verification</option>
                        <option value="verified" <?= $verification === 'verified' ? 'selected' : '' ?>>Verified</option>
                        <option value="pending" <?= $verification === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select></label>
                    <button class="admin-submit" type="submit">Search <span aria-hidden="true">↗</span></button>
                    <a class="admin-export" href="<?= e(users_url($filters, ['export' => 'csv'])) ?>">Export CSV <span aria-hidden="true">↗</span></a>
                </div>
                <p class="admin-filters__help">Verification and export are available on desktop. Filters apply to live records.</p>
            </form>

            <?php if ($selected !== null): ?>
                <section class="admin-glass admin-detail" id="account-detail" aria-labelledby="account-detail-title">
                    <div class="admin-detail__heading">
                        <div><p class="admin-kicker">Read-only account details</p><h2 id="account-detail-title"><?= e($selected['display_name']) ?> <span>#<?= e($selected['id']) ?></span></h2></div>
                        <a href="<?= e(users_url($filters, ['page' => $page])) ?>" aria-label="Close account details">✕</a>
                    </div>
                    <dl class="admin-detail__list">
                        <div><dt>Account ID</dt><dd>#<?= e($selected['id']) ?></dd></div>
                        <div><dt>Masked email</dt><dd><?= e(users_mask_email((string) $selected['email'])) ?></dd></div>
                        <div><dt>Role</dt><dd><?= e(ucfirst((string) $selected['role'])) ?></dd></div>
                        <div><dt>Verification</dt><dd><?= (int) $selected['is_verified'] === 1 ? 'Verified' : 'Pending' ?></dd></div>
                        <div><dt>Status</dt><dd><?= (int) $selected['is_suspended'] === 1 ? 'Suspended' : 'Active' ?></dd></div>
                        <div><dt>Joined</dt><dd><?= e($selected['created_at']) ?></dd></div>
                    </dl>
                    <p class="admin-detail__foot">No account changes can be made from this page.</p>
                </section>
            <?php endif; ?>

            <section class="admin-directory" aria-labelledby="admin-users-title">
                <div class="admin-directory__heading">
                    <div><p class="admin-kicker">Account directory</p><h2 id="admin-users-title">All user accounts</h2></div>
                    <span class="admin-directory__chip"><?= e($matching) ?> matching account<?= $matching === 1 ? '' : 's' ?></span>
                </div>

                <div class="admin-glass admin-table-shell">
                    <div class="admin-table-wrap">
                        <table class="admin-user-table">
                            <thead><tr><th scope="col">Account</th><th scope="col">Account ID</th><th scope="col">Role</th><th scope="col">Verification</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $id = (int) $user['id'];
                                $name = (string) $user['display_name'];
                                $initial = strtoupper(substr(trim($name), 0, 1)) ?: 'B';
                                $verified = (int) $user['is_verified'] === 1;
                                $suspended = (int) $user['is_suspended'] === 1;
                                $detailUrl = users_url($filters, ['page' => $page, 'view' => $id]) . '#account-detail';
                                ?>
                                <tr>
                                    <td><div class="admin-user-id"><span class="admin-avatar"><?= e($initial) ?></span><div><strong><?= e($name) ?></strong><small><?= e(users_mask_email((string) $user['email'])) ?></small></div></div></td>
                                    <td class="admin-account-number">#<?= e(str_pad((string) $id, 3, '0', STR_PAD_LEFT)) ?></td>
                                    <td><span class="admin-pill admin-pill--role"><?= e(ucfirst((string) $user['role'])) ?></span></td>
                                    <td><span class="admin-pill <?= $verified ? 'admin-pill--good' : 'admin-pill--pending' ?>"><?= $verified ? 'Verified' : 'Pending' ?></span></td>
                                    <td><span class="admin-pill <?= $suspended ? 'admin-pill--suspended' : 'admin-pill--good' ?>"><?= $suspended ? 'Suspended' : 'Active' ?></span></td>
                                    <td><a class="admin-view" href="<?= e($detailUrl) ?>">View <span aria-hidden="true">→</span></a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="admin-mobile-cards">
                        <?php foreach ($users as $user): ?>
                            <?php
                            $id = (int) $user['id'];
                            $name = (string) $user['display_name'];
                            $initial = strtoupper(substr(trim($name), 0, 1)) ?: 'B';
                            $verified = (int) $user['is_verified'] === 1;
                            $suspended = (int) $user['is_suspended'] === 1;
                            ?>
                            <article class="admin-user-card">
                                <div class="admin-user-card__top">
                                    <div class="admin-user-id"><span class="admin-avatar"><?= e($initial) ?></span><div><strong><?= e($name) ?></strong><small><?= e(users_mask_email((string) $user['email'])) ?></small></div></div>
                                    <span class="admin-pill admin-pill--role"><?= e(ucfirst((string) $user['role'])) ?></span>
                                </div>
                                <div class="admin-user-card__bottom">
                                    <span class="admin-pill <?= $verified ? 'admin-pill--good' : 'admin-pill--pending' ?>"><?= $verified ? 'Verified' : 'Pending' ?></span>
                                    <span class="admin-pill <?= $suspended ? 'admin-pill--suspended' : 'admin-pill--good' ?>"><?= $suspended ? 'Suspended' : 'Active' ?></span>
                                    <a class="admin-view" href="<?= e(users_url($filters, ['page' => $page, 'view' => $id])) ?>#account-detail">View details <span aria-hidden="true">→</span></a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($users === []): ?>
                        <div class="admin-no-results"><h3>No accounts found</h3><p>Try adjusting your search or filters.</p><a href="users.php">Clear filters</a></div>
                    <?php endif; ?>

                    <div class="admin-directory__footer">
                        <span>Showing <?= $matching > 0 ? e($offset + 1) : '0' ?>–<?= e(min($matching, $offset + count($users))) ?> of <?= e($matching) ?> accounts</span>
                        <nav class="admin-pagination" aria-label="Account pages">
                            <?php if ($page > 1): ?><a href="<?= e(users_url($filters, ['page' => $page - 1])) ?>" aria-label="Previous page">‹</a><?php else: ?><span aria-hidden="true">‹</span><?php endif; ?>
                            <span><?= e($page) ?> / <?= e($pages) ?></span>
                            <?php if ($page < $pages): ?><a href="<?= e(users_url($filters, ['page' => $page + 1])) ?>" aria-label="Next page">›</a><?php else: ?><span aria-hidden="true">›</span><?php endif; ?>
                        </nav>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
<nav class="admin-mobile-nav" aria-label="Mobile administration">
    <a href="dashboard.php">Overview</a>
    <a class="is-active" href="users.php" aria-current="page">Users</a>
    <a href="reports.php">Reports</a>
    <a href="moderation.php">Moderation</a>
</nav>
</body>
</html>
