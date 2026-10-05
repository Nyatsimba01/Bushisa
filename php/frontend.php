<?php

declare(strict_types=1);

require_once __DIR__ . '/operational.php';

/**
 * Shared frontend helpers for server-rendered Bushisa pages.
 */

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_page_name(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

function is_active_page(string $page): bool
{
    return current_page_name() === $page;
}

function asset_path(string $path): string
{
    $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $version = is_file($absolute) ? (string) filemtime($absolute) : '1';
    $scriptDirectory = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/.');
    $prefix = in_array($scriptDirectory, ['admin', 'public'], true) ? '../' : '';

    return $prefix . $path . '?v=' . rawurlencode($version);
}

function profile_photo_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $path) === 1) {
        return $path;
    }

    return ltrim($path, '/');
}

function render_avatar(?string $photoPath, string $name, string $class = 'avatar'): string
{
    $photoUrl = profile_photo_url($photoPath);
    $initial = strtoupper(substr(trim($name) !== '' ? trim($name) : 'B', 0, 1));

    if ($photoUrl === null) {
        return '<span class="' . e($class) . ' avatar--fallback" aria-hidden="true">' . e($initial) . '</span>';
    }

    return '<img class="' . e($class) . '" src="' . e($photoUrl) . '" alt="' . e($name) . ' profile photo" loading="lazy" decoding="async">';
}

function body_classes(string $pageType, bool $isAuthenticated = true): string
{
    $classes = ['bushisa-app', 'page-' . $pageType];
    if (!$isAuthenticated) {
        $classes[] = 'auth-page';
    }

    return implode(' ', $classes);
}

function render_document_head(string $title, ?string $csrfToken = null, ?int $currentUserId = null): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <?php if ($csrfToken !== null): ?>
        <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <?php endif; ?>
    <?php if ($currentUserId !== null): ?>
        <meta name="current-user-id" content="<?= e($currentUserId) ?>">
    <?php endif; ?>
    <title><?= e($title) ?> | Bushisa</title>
    <link rel="stylesheet" href="<?= e(asset_path('css/style.css')) ?>">
</head>
<?php
}

/**
 * @param array<int, array{href: string, label: string, icon: string, page: string}>|null $items
 */
function render_primary_navigation(?array $items = null): void
{
    $items ??= [
        ['href' => 'discover.php', 'label' => 'Home', 'icon' => 'home', 'page' => 'discover.php'],
        ['href' => 'confessions.php', 'label' => 'Confessions', 'icon' => 'confessions', 'page' => 'confessions.php'],
        ['href' => 'matches.php', 'label' => 'Find Match', 'icon' => 'search', 'page' => 'matches.php'],
        ['href' => 'profile.php', 'label' => 'You', 'icon' => 'you', 'page' => 'profile.php'],
    ];
    ?>
    <nav class="primary-nav" aria-label="Primary">
        <?php foreach ($items as $item): ?>
            <?php $active = is_active_page($item['page']); ?>
            <a class="primary-nav__item<?= $active ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                <span class="primary-nav__icon primary-nav__icon--<?= e($item['icon']) ?>" aria-hidden="true"></span>
                <span class="primary-nav__label"><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php
}

function render_app_header(string $title, bool $showBell = false, ?string $subtitle = null): void
{
    ?>
    <header class="topbar">
        <div>
            <p class="topbar__kicker">NUST student connections</p>
            <h1><?= e($title) ?></h1>
            <?php if ($subtitle !== null && $subtitle !== ''): ?>
                <p class="topbar__subtitle"><?= e($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($showBell): ?>
            <button class="icon-button notification-bell" type="button" aria-label="Open notifications" data-notification-bell aria-expanded="false" aria-controls="notificationPanel">
                <span class="ui-icon ui-icon--bell" aria-hidden="true"></span>
                <span class="notification-bell__dot" aria-hidden="true"></span>
            </button>
        <?php endif; ?>
    </header>
<?php
}

function render_notification_panel(): void
{
    global $pdo;

    $notifications = [];
    if ($pdo instanceof PDO && isset($_SESSION['user_id'])) {
        try {
            ensure_operational_schema($pdo);
            $statement = $pdo->prepare(
                'SELECT id, category, title, body, deep_link, read_at, created_at
                 FROM notifications
                 WHERE recipient_id = :recipient_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT 10'
            );
            $statement->execute([':recipient_id' => (int) $_SESSION['user_id']]);
            $notifications = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $throwable) {
            $notifications = [];
        }
    }
    ?>
    <aside class="notification-panel" id="notificationPanel" data-notification-panel hidden>
        <div class="notification-panel__header">
            <h2>Notifications</h2>
            <button class="icon-button" type="button" aria-label="Close notifications" data-notification-close>
                <span class="ui-icon ui-icon--close" aria-hidden="true"></span>
            </button>
        </div>
        <div class="notification-list">
            <?php if ($notifications === []): ?>
                <article class="notification-item">
                    <span class="notification-item__icon ui-icon ui-icon--bell" aria-hidden="true"></span>
                    <div>
                        <h3>Caught up</h3>
                        <p>New matches, message requests and confession references will appear here.</p>
                        <time>No unread activity</time>
                    </div>
                </article>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <article class="notification-item<?= empty($notification['read_at']) ? ' is-unread' : '' ?>">
                        <span class="notification-item__icon ui-icon ui-icon--bell" aria-hidden="true"></span>
                        <div>
                            <h3><?= e($notification['title'] ?? 'Notification') ?></h3>
                            <p><?= e($notification['body'] ?? '') ?></p>
                            <time datetime="<?= e($notification['created_at'] ?? '') ?>"><?= e(isset($notification['created_at']) ? format_time_ago((string) $notification['created_at']) : '') ?></time>
                            <?php if (!empty($notification['deep_link'])): ?>
                                <a class="inline-link" href="<?= e($notification['deep_link']) ?>">Open</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a class="notification-panel__prefs" href="profile.php#notifications">Notification preferences</a>
    </aside>
<?php
}

function render_page_shell_start(string $pageType, string $title, ?string $csrfToken = null, ?int $currentUserId = null, bool $showBell = false, ?string $subtitle = null): void
{
    render_document_head($title, $csrfToken, $currentUserId);
    ?>
<body class="<?= e(body_classes($pageType)) ?>" data-current-user-id="<?= $currentUserId !== null ? e($currentUserId) : '' ?>">
<div class="app-shell">
    <?php render_primary_navigation(); ?>
    <div class="app-main">
        <?php render_app_header($title, $showBell, $subtitle); ?>
        <?php if ($showBell) {
            render_notification_panel();
        } ?>
        <main class="page-content" id="main">
<?php
}

function render_page_shell_end(array $scripts = []): void
{
    ?>
        </main>
    </div>
</div>
<script src="<?= e(asset_path('js/app.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e(asset_path($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function render_auth_start(string $title, ?string $csrfToken = null): void
{
    render_document_head($title, $csrfToken);
    ?>
<body class="<?= e(body_classes('auth', false)) ?>">
<main class="auth-shell">
<?php
}

function render_auth_end(array $scripts = []): void
{
    ?>
</main>
<script src="<?= e(asset_path('js/app.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e(asset_path($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function render_flash(?string $error, ?string $success): void
{
    if ($error !== null && $error !== '') {
        echo '<div class="status-banner status-banner--error" role="alert">' . e($error) . '</div>';
    }

    if ($success !== null && $success !== '') {
        echo '<div class="status-banner status-banner--success" role="status">' . e($success) . '</div>';
    }
}
