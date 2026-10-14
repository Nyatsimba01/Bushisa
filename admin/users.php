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
 * Users Management mirrors Bushisa Figma nodes 83:18 (desktop) and 85:34
 * (mobile, with the user's bottom-navigation adjustment).
 * Account actions are deliberately read-only in this first release.
 */
function admin_user_label(array $row): string
{
    $name = trim((string) ($row['display_name'] ?? ''));
    return $name !== '' ? $name : 'Account #' . (int) ($row['id'] ?? 0);
}

function admin_user_initial(string $name): string
{
    return strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1));
}

function admin_masked_email(string $email): string
{
    $parts = explode('@', strtolower($email), 2);
    if (count($parts) !== 2) {
        return 'Email unavailable';
    }
    // Never show the local part of a student email in the directory/CSV.
    return '••••••@' . $parts[1];
}

function admin_user_badge(string $category, string $value): string
{
    return 'admin-users-badge admin-users-badge--' . $category . '-' . $value;
}

function admin_user_params(array $filters, array $additional = []): string
{
    return http_build_query(array_merge($filters, $additional), '', '&', PHP_QUERY_RFC3986);
}

function admin_users_csv_safe(string $value): string
{
    $value = trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    // Prevent formula execution when a CSV is opened in a spreadsheet.
    if ($value !== '' && preg_match('/^[=+\-@\t]/u', $value) === 1) {
        return "'" . $value;
    }
    return $value;
}

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 80, 'UTF-8') : substr($search, 0, 80);

$roles = ['all' => 'All roles', 'student' => 'Student', 'moderator' => 'Moderator', 'admin' => 'Admin'];
$statuses = ['all' => 'All statuses', 'active' => 'Active', 'suspended' => 'Suspended'];
$verificationOptions = ['all' => 'Any', 'verified' => 'Verified', 'pending' => 'Pending'];

$role = isset($_GET['role']) && is_string($_GET['role']) && array_key_exists($_GET['role'], $roles) ? $_GET['role'] : 'all';
$status = isset($_GET['status']) && is_string($_GET['status']) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : 'all';
$verification = isset($_GET['verification']) && is_string($_GET['verification'])
    && array_key_exists($_GET['verification'], $verificationOptions) ? $_GET['verification'] : 'all';

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = is_int($page) && $page > 0 ? min($page, 100000) : 1;
$perPage = 25;
$filters = ['q' => $search, 'role' => $role, 'status' => $status, 'verification' => $verification];

$where = [];
$bindings = [];
if ($search !== '') {
    $where[] = '(p.display_name LIKE :search_name OR u.email LIKE :search_email
                 OR u.nust_student_id LIKE :search_student OR CAST(u.id AS CHAR) LIKE :search_id)';
    $like = '%' . $search . '%';
    $bindings[':search_name'] = $like;
    $bindings[':search_email'] = $like;
    $bindings[':search_student'] = $like;
    $bindings[':search_id'] = $like;
}
if ($role !== 'all') {
    $where[] = 'u.role = :role';
    $bindings[':role'] = $role;
}
if ($status === 'active') {
    $where[] = 'COALESCE(u.is_suspended, 0) = 0';
} elseif ($status === 'suspended') {
    $where[] = 'u.is_suspended = 1';
}
if ($verification === 'verified') {
    $where[] = 'u.is_verified = 1';
} elseif ($verification === 'pending') {
    $where[] = 'COALESCE(u.is_verified, 0) = 0';
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$fromSql = ' FROM users u LEFT JOIN profiles p ON p.user_id = u.id';
$columns = 'u.id, u.email, u.role, u.is_verified, u.is_suspended, u.created_at, p.display_name';
$summarySql = 'SELECT COUNT(*) AS total,
                 COALESCE(SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END),0) AS verified,
                 COALESCE(SUM(CASE WHEN is_suspended = 1 THEN 1 ELSE 0 END),0) AS suspended,
                 COALESCE(SUM(CASE WHEN role = "admin" THEN 1 ELSE 0 END),0) AS admins
               FROM users';
$summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];
$summary = [
    'total' => (int) ($summary['total'] ?? 0),
    'verified' => (int) ($summary['verified'] ?? 0),
    'suspended' => (int) ($summary['suspended'] ?? 0),
    'admins' => (int) ($summary['admins'] ?? 0),
];

$prepareFilters = static function (PDOStatement $statement) use ($bindings): void {
    foreach ($bindings as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_STR);
    }
};

// Only administrators may export; the file intentionally contains masked email
// addresses and no student IDs, credentials, profile secrets or anonymous handles.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!is_admin()) {
        http_response_code(403);
        exit('Only administrators can export an account directory.');
    }
    $csv = $pdo->prepare('SELECT ' . $columns . $fromSql . $whereSql . ' ORDER BY u.id DESC LIMIT 1000');
    $prepareFilters($csv);
    $csv->execute();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bushisa-users-directory.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output !== false) {
        fputcsv($output, ['Account ID', 'Display name', 'Masked email', 'Role', 'Verification', 'Status', 'Joined']);
        while (($entry = $csv->fetch(PDO::FETCH_ASSOC)) !== false) {
            fputcsv($output, [
                (int) $entry['id'],
                admin_users_csv_safe(admin_user_label($entry)),
                admin_users_csv_safe(admin_masked_email((string) $entry['email'])),
                admin_users_csv_safe(ucfirst((string) $entry['role'])),
                (int) $entry['is_verified'] === 1 ? 'Verified' : 'Pending',
                (int) $entry['is_suspended'] === 1 ? 'Suspended' : 'Active',
                admin_users_csv_safe(substr((string) $entry['created_at'], 0, 10)),
            ]);
        }
        fclose($output);
    }
    exit;
}

$countStmt = $pdo->prepare('SELECT COUNT(DISTINCT u.id)' . $fromSql . $whereSql);
$prepareFilters($countStmt);
$countStmt->execute();
$filteredCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($filteredCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rowsStmt = $pdo->prepare('SELECT ' . $columns . $fromSql . $whereSql
    . ' ORDER BY u.created_at DESC, u.id DESC LIMIT :rows_limit OFFSET :rows_offset');
$prepareFilters($rowsStmt);
$rowsStmt->bindValue(':rows_limit', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':rows_offset', $offset, PDO::PARAM_INT);
$rowsStmt->execute();
$users = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedUser = null;
$selectedId = filter_var($_GET['account'] ?? null, FILTER_VALIDATE_INT);
if (is_int($selectedId) && $selectedId > 0) {
    $selectedStmt = $pdo->prepare('SELECT ' . $columns . $fromSql . ' WHERE u.id = :account_id LIMIT 1');
    $selectedStmt->bindValue(':account_id', $selectedId, PDO::PARAM_INT);
    $selectedStmt->execute();
    $selectedUser = $selectedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$baseQuery = admin_user_params($filters, ['page' => $page]);
$nav = [
    ['href' => 'dashboard.php', 'title' => 'Overview', 'icon' => 'overview'],
    ['href' => 'users.php', 'title' => 'Users', 'icon' => 'users'],
    ['href' => '../confessions.php', 'title' => 'Confessions', 'icon' => 'confessions'],
    ['href' => 'reports.php', 'title' => 'Reports', 'icon' => 'reports'],
    ['href' => 'moderation.php', 'title' => 'Moderation', 'icon' => 'moderation'],
];
$verificationLabel = static fn (array $user): string => (int) ($user['is_verified'] ?? 0) === 1 ? 'Verified' : 'Pending';
$statusLabel = static fn (array $user): string => (int) ($user['is_suspended'] ?? 0) === 1 ? 'Suspended' : 'Active';
$roleLabel = static fn (array $user): string => ucfirst((string) ($user['role'] ?? 'student'));

render_document_head('Users management');
?>
<body class="bushisa-admin-dashboard bushisa-admin-users">
<a href="#users-main" class="admin-skip-link">Skip to users directory</a>
<div class="admin-layout admin-users-layout">
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
                    <a class="admin-nav__link<?= $item['title'] === 'Users' ? ' is-current' : '' ?>"
                       href="<?= e($item['href']) ?>" <?= $item['title'] === 'Users' ? 'aria-current="page"' : '' ?>>
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

    <div class="admin-users-mobile-top">
        <a class="admin-users-mobile-brand" href="dashboard.php"><span aria-hidden="true">♡</span> Bushisa</a>
        <span class="admin-users-mobile-caption">ADMIN</span>
    </div>

    <main class="admin-main admin-users-main" id="users-main">
        <header class="admin-page-header admin-users-header">
            <div class="admin-page-header__copy">
                <p class="admin-eyebrow">ADMINISTRATION / USERS</p>
                <h1>User management</h1>
                <p class="admin-page-header__description">Review accounts, verification and access.</p>
            </div>
            <div class="admin-preview-tag"><span class="admin-preview-tag__dot" aria-hidden="true"></span> DEVELOPMENT PREVIEW</div>
        </header>

        <div class="admin-users-notice">
            <div class="admin-users-notice__copy">
                <span class="admin-eyebrow">ACCOUNT SAFETY</span>
                <p>Sensitive student information belongs behind authorised admin access.</p>
            </div>
            <span class="admin-users-note-chip">LIVE / LOCAL DATA</span>
        </div>

        <section class="admin-metrics admin-users-metrics" aria-label="User statistics">
            <?php foreach ([
                ['Total accounts', $summary['total'], 'Accounts in your database'],
                ['Verified users', $summary['verified'], 'Verified account flags'],
                ['Suspended users', $summary['suspended'], 'Accounts currently suspended'],
                ['Administrator roles', $summary['admins'], 'Accounts assigned admin'],
            ] as $stat): ?>
                <article class="admin-metric">
                    <h2><?= e($stat[0]) ?></h2>
                    <p class="admin-metric__value"><?= e($stat[1]) ?></p>
                    <p class="admin-metric__note"><?= e($stat[2]) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <form class="admin-users-filters" action="users.php" method="get" role="search" id="admin-users-filter-form">
            <label class="admin-eyebrow admin-users-filters__title" for="admin-user-search">FIND AN ACCOUNT</label>
            <div class="admin-users-filters__fields">
                <div class="admin-users-search-wrap">
                    <button class="admin-users-search-button" type="submit" aria-label="Search accounts"><span aria-hidden="true">⌕</span></button>
                    <input name="q" type="search" id="admin-user-search" value="<?= e($search) ?>"
                           placeholder="Search by name, account ID or email" maxlength="80" autocomplete="off">
                </div>
                <label class="admin-users-select">
                    <span class="admin-users-sr-only">Role</span>
                    <select name="role" aria-label="Filter by role">
                        <?php foreach ($roles as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $role === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="admin-users-select">
                    <span class="admin-users-sr-only">Status</span>
                    <select name="status" aria-label="Filter by status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="admin-users-select admin-users-select--verification">
                    <span class="admin-users-sr-only">Verification</span>
                    <select name="verification" aria-label="Filter by verification">
                        <?php foreach ($verificationOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $verification === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (is_admin()): ?>
                    <a class="admin-users-export" href="users.php?<?= e(admin_user_params($filters, ['export' => 'csv'])) ?>">
                        Export CSV <span aria-hidden="true">↗</span>
                    </a>
                <?php endif; ?>
            </div>
            <p class="admin-users-filter-help">Verification and export are available on desktop.</p>
        </form>

        <section class="admin-users-directory" aria-labelledby="users-directory-heading">
            <header class="admin-users-directory__heading">
                <div>
                    <span class="admin-eyebrow">ACCOUNT DIRECTORY</span>
                    <h2 id="users-directory-heading">All user accounts</h2>
                </div>
                <span class="admin-users-directory__count"><?= e($filteredCount) ?> <?= $filteredCount === 1 ? 'ACCOUNT' : 'ACCOUNTS' ?> FOUND</span>
            </header>

            <div class="admin-users-table-wrap">
                <table class="admin-users-table">
                    <thead>
                        <tr>
                            <th scope="col">ACCOUNT</th>
                            <th scope="col">ACCOUNT ID</th>
                            <th scope="col">ROLE</th>
                            <th scope="col">VERIFICATION</th>
                            <th scope="col">STATUS</th>
                            <th scope="col">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $name = admin_user_label($user);
                                $roleValue = strtolower((string) ($user['role'] ?? 'student'));
                                $verValue = strtolower($verificationLabel($user));
                                $stateValue = strtolower($statusLabel($user));
                                $detailsUrl = 'users.php?' . admin_user_params($filters, ['page' => $page, 'account' => (int) $user['id']]);
                            ?>
                            <tr>
                                <td>
                                    <div class="admin-users-identity">
                                        <span class="admin-users-avatar" aria-hidden="true"><?= e(admin_user_initial($name)) ?></span>
                                        <div>
                                            <strong><?= e($name) ?></strong>
                                            <small><?= e(admin_masked_email((string) ($user['email'] ?? ''))) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="admin-users-id">#<?= e(str_pad((string) (int) $user['id'], 3, '0', STR_PAD_LEFT)) ?></span></td>
                                <td><span class="<?= e(admin_user_badge('role', $roleValue)) ?>"><?= e($roleLabel($user)) ?></span></td>
                                <td><span class="<?= e(admin_user_badge('verified', $verValue)) ?>"><?= e($verificationLabel($user)) ?></span></td>
                                <td><span class="<?= e(admin_user_badge('status', $stateValue)) ?>"><?= e($statusLabel($user)) ?></span></td>
                                <td><a class="admin-users-view" href="<?= e($detailsUrl) ?>">View <span aria-hidden="true">→</span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($users === []): ?>
                    <div class="admin-users-empty">No accounts match these filters. <a href="users.php">Clear filters</a>.</div>
                <?php endif; ?>
            </div>

            <div class="admin-users-mobile-cards">
                <?php foreach ($users as $user): ?>
                    <?php
                        $name = admin_user_label($user);
                        $roleValue = strtolower((string) ($user['role'] ?? 'student'));
                        $verValue = strtolower($verificationLabel($user));
                        $stateValue = strtolower($statusLabel($user));
                        $detailsUrl = 'users.php?' . admin_user_params($filters, ['page' => $page, 'account' => (int) $user['id']]);
                    ?>
                    <article class="admin-users-mobile-card">
                        <div class="admin-users-mobile-card__top">
                            <span class="admin-users-avatar" aria-hidden="true"><?= e(admin_user_initial($name)) ?></span>
                            <div class="admin-users-mobile-card__identity">
                                <strong><?= e($name) ?></strong>
                                <small><?= e(admin_masked_email((string) ($user['email'] ?? ''))) ?></small>
                            </div>
                            <span class="<?= e(admin_user_badge('role', $roleValue)) ?>"><?= e($roleLabel($user)) ?></span>
                        </div>
                        <div class="admin-users-mobile-card__bottom">
                            <span class="<?= e(admin_user_badge('verified', $verValue)) ?>"><?= e($verificationLabel($user)) ?></span>
                            <span class="<?= e(admin_user_badge('status', $stateValue)) ?>"><?= e($statusLabel($user)) ?></span>
                            <a class="admin-users-view" href="<?= e($detailsUrl) ?>">View details <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ($users === []): ?>
                    <p class="admin-users-empty">No accounts match your filters. <a href="users.php">Clear filters</a>.</p>
                <?php endif; ?>
            </div>
            <footer class="admin-users-directory__footer">
                <p>Showing <?= $filteredCount === 0 ? '0' : e($offset + 1) ?>–<?= e(min($offset + count($users), $filteredCount)) ?> of <?= e($filteredCount) ?> accounts · Email addresses masked</p>
                <nav class="admin-users-pagination" aria-label="Directory pages">
                    <?php if ($page > 1): ?>
                        <a href="users.php?<?= e(admin_user_params($filters, ['page' => $page - 1])) ?>" aria-label="Previous page">‹</a>
                    <?php endif; ?>
                    <span><?= e($page) ?> / <?= e($totalPages) ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="users.php?<?= e(admin_user_params($filters, ['page' => $page + 1])) ?>" aria-label="Next page">›</a>
                    <?php endif; ?>
                </nav>
            </footer>
        </section>
    </main>

    <nav class="admin-users-bottom-nav" aria-label="Mobile admin navigation">
        <?php foreach ($nav as $item): ?>
            <?php if ($item['title'] === 'Confessions') continue; ?>
            <a href="<?= e($item['href']) ?>" class="<?= $item['title'] === 'Users' ? 'is-current' : '' ?>"
                <?= $item['title'] === 'Users' ? 'aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if (is_array($selectedUser)): ?>
    <?php $closeUrl = 'users.php?' . $baseQuery; ?>
    <div class="admin-users-detail-overlay" role="presentation">
        <section class="admin-users-detail" role="dialog" aria-modal="true" aria-labelledby="admin-user-detail-title">
            <a class="admin-users-detail__close" href="<?= e($closeUrl) ?>" aria-label="Close account details">×</a>
            <span class="admin-eyebrow">ACCOUNT DETAILS · READ ONLY</span>
            <h2 id="admin-user-detail-title"><?= e(admin_user_label($selectedUser)) ?></h2>
            <p class="admin-users-detail__subtitle">Account #<?= e((int) $selectedUser['id']) ?> · <?= e(admin_masked_email((string) $selectedUser['email'])) ?></p>
            <dl>
                <div><dt>Role</dt><dd><?= e($roleLabel($selectedUser)) ?></dd></div>
                <div><dt>Verification</dt><dd><?= e($verificationLabel($selectedUser)) ?></dd></div>
                <div><dt>Account status</dt><dd><?= e($statusLabel($selectedUser)) ?></dd></div>
                <div><dt>Joined</dt><dd><?= e(substr((string) $selectedUser['created_at'], 0, 10)) ?></dd></div>
            </dl>
            <p class="admin-users-detail__privacy">Account management actions are not enabled here. Viewing a record does not change the account.</p>
            <a class="admin-users-detail__back" href="<?= e($closeUrl) ?>">← Back to directory</a>
        </section>
    </div>
<?php endif; ?>

<script src="<?= e(asset_path('js/admin-users.js')) ?>"></script>
</body>
</html>
