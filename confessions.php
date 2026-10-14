<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/rate_limit.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/operational.php';
require_once __DIR__ . '/php/confession_reports.php';

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$error = null;
$success = null;
$reportError = null;
$reportSuccess = isset($_SESSION['confession_report_notice']) && is_string($_SESSION['confession_report_notice'])
    ? $_SESSION['confession_report_notice'] : null;
unset($_SESSION['confession_report_notice']);
$reportPostId = null;
$csrfToken = null;
$confessions = [];
$page = clean_int($_GET['page'] ?? null) ?? 1;
if ($page < 1) {
    $page = 1;
}

$perPage = 20;
$sort = (($_GET['sort'] ?? 'latest') === 'trending') ? 'trending' : 'latest';
$sortOrder = $sort === 'trending'
    ? 'view_count DESC, c.created_at DESC, c.id DESC'
    : 'c.created_at DESC, c.id DESC';
$columnStatement = $pdo->query('SHOW COLUMNS FROM confessions');
$confessionColumns = $columnStatement !== false ? $columnStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$usesUserContentSchema = in_array('user_id', $confessionColumns, true) && in_array('content', $confessionColumns, true);
$usesAuthorBodySchema = in_array('author_id', $confessionColumns, true) && in_array('body', $confessionColumns, true);

$countStatement = $pdo->query('SELECT COUNT(*) FROM confessions WHERE is_flagged = 0');
$totalConfessions = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;
$total_pages = $totalConfessions > 0 ? (int) ceil($totalConfessions / $perPage) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $isReporting = isset($_POST['report_confession']);
    $reportPostId = $isReporting ? clean_int($_POST['confession_id'] ?? null) : null;

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        if ($isReporting) {
            $reportError = 'Invalid report form. Please try again.';
        } else {
            $error = 'Invalid form submission. Please try again.';
        }
    } elseif ($isReporting) {
        $reportReason = isset($_POST['reason']) && is_string($_POST['reason']) ? $_POST['reason'] : '';
        $reportDetails = isset($_POST['details']) && is_string($_POST['details']) ? $_POST['details'] : '';
        $result = bushisa_report_confession($pdo, $currentUserId, $reportPostId ?? 0, $reportReason, $reportDetails);
        if ($result['success']) {
            $_SESSION['confession_report_notice'] = 'Report submitted privately for moderator review. Thank you for helping keep Bushisa respectful.';
            redirect('confessions.php?sort=' . $sort . '&page=' . $page);
        }
        $reportError = $result['error'] ?? 'Unable to send this report.';
    } else {
        $rateLimitKey = (string) $currentUserId;
        if (!check_rate_limit($pdo, 'confession', $rateLimitKey, 5, 3600)) {
            $error = 'You have reached the confession limit for now.';
        } else {
            $rawBody = trim(str_replace("\0", '', (string) ($_POST['body'] ?? '')));
            if ($rawBody === '') {
                $error = 'Confession cannot be empty.';
            } else {
                $rawBody = function_exists('mb_substr') ? mb_substr($rawBody, 0, 500) : substr($rawBody, 0, 500);
                $sanitisedBody = clean($rawBody);

                $profileStatement = $pdo->prepare('SELECT anon_handle, display_name FROM profiles WHERE user_id = :user_id LIMIT 1');
                $profileStatement->execute([':user_id' => $currentUserId]);
                $identity = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];
                $anonHandle = trim((string) ($identity['anon_handle'] ?? ''));
                $publicName = trim((string) ($identity['display_name'] ?? ''));

                $sameIdentity = $publicName !== ''
                    && strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $anonHandle))
                       === strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $publicName));
                if ($anonHandle === '' || $sameIdentity) {
                    $error = 'Please choose a separate Confessions username in You before posting.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        record_attempt($pdo, 'confession', $rateLimitKey);

                        if ($usesUserContentSchema) {
                            // Support both the original and newer confession schemas.
                            if (in_array('anon_handle', $confessionColumns, true)) {
                                $insertConfession = $pdo->prepare(
                                    'INSERT INTO confessions (user_id, anon_handle, content, created_at, is_flagged)
                                     VALUES (:user_id, :anon_handle, :content, NOW(), 0)'
                                );
                                $insertConfession->execute([
                                    ':user_id' => $currentUserId,
                                    ':anon_handle' => $anonHandle,
                                    ':content' => $sanitisedBody,
                                ]);
                            } else {
                                $insertConfession = $pdo->prepare(
                                    'INSERT INTO confessions (user_id, content, created_at, is_flagged)
                                     VALUES (:user_id, :content, NOW(), 0)'
                                );
                                $insertConfession->execute([
                                    ':user_id' => $currentUserId,
                                    ':content' => $sanitisedBody,
                                ]);
                            }
                        } elseif ($usesAuthorBodySchema) {
                            $insertConfession = $pdo->prepare(
                                'INSERT INTO confessions (author_id, body, created_at, is_flagged)
                                 VALUES (:author_id, :body, NOW(), 0)'
                            );
                            $insertConfession->execute([
                                ':author_id' => $currentUserId,
                                ':body' => $sanitisedBody,
                            ]);
                        } else {
                            throw new RuntimeException('Unsupported confessions schema.');
                        }

                        $pdo->commit();
                        log_action($pdo, $currentUserId, 'confession_posted', $_SERVER['REMOTE_ADDR'] ?? null);
                        log_to_file('User ' . $currentUserId . ' posted a confession', 'INFO');

                        $success = 'Confession posted successfully.';
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        log_to_file('Confession post failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
                        $error = 'Unable to post your confession right now.';
                    }
                }
            }
        }
    }
}

$page = min($page, $total_pages);
$offset = ($page - 1) * $perPage;
$confessionRuntimeColumns = operational_confession_columns($pdo);
// Always use the separate pseudonym from profiles; never a stale snapshot.
// The public name is read only to suppress old duplicate handles.
$handleSelect = 'p.anon_handle AS anon_handle, p.display_name AS account_display_name';

if ($usesUserContentSchema || $usesAuthorBodySchema) {
    $feedStatement = $pdo->prepare(
        sprintf(
            'SELECT
                c.id,
                c.%1$s AS body,
                c.created_at,
                c.is_flagged,
                %3$s,
                COUNT(cv.id) AS view_count
             FROM confessions c
             LEFT JOIN profiles p ON p.user_id = c.%2$s
             LEFT JOIN confession_views cv ON cv.confession_id = c.id
             WHERE c.is_flagged = 0
             GROUP BY c.id, c.%1$s, c.created_at, c.is_flagged, p.anon_handle, p.display_name
             ORDER BY %4$s
             LIMIT :limit OFFSET :offset',
            $confessionRuntimeColumns['body'],
            $confessionRuntimeColumns['author'],
            $handleSelect,
            $sortOrder
        )
    );
} else {
    $feedStatement = false;
}

if ($feedStatement !== false) {
    $feedStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $feedStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $feedStatement->execute();
    $confessions = $feedStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$csrfToken = generate_csrf_token();

$profile = get_profile($pdo, $currentUserId);
$profileName = trim((string) ($profile['display_name'] ?? ''));
$initial = $profileName !== '' ? strtoupper(substr($profileName, 0, 1)) : 'B';
$navItems = [
    ['href' => 'discover.php', 'label' => 'Home', 'icon' => 'home', 'active' => false],
    ['href' => 'matches.php', 'label' => 'Find Match', 'icon' => 'nav-heart', 'active' => false],
    ['href' => 'confessions.php', 'label' => 'Confessions', 'icon' => 'confessions', 'active' => true],
    ['href' => 'profile.php', 'label' => 'You', 'icon' => 'you', 'active' => false],
];
$unreadCount = 0;
try {
    if (operational_table_exists($pdo, 'notifications')) {
        $notificationStatement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = :id AND read_at IS NULL');
        $notificationStatement->execute([':id' => $currentUserId]);
        $unreadCount = (int) $notificationStatement->fetchColumn();
    }
} catch (Throwable $exception) {
    error_log('Confessions notification count unavailable: ' . $exception->getMessage());
}
render_document_head('Confessions', $csrfToken, $currentUserId);
?>
<body class="page-confessions-figma bushisa-app" id="top">
<header class="site-header">
    <div class="header-inner">
        <a href="discover.php" class="brand" aria-label="Bushisa Home">
            <img src="<?= e(asset_path('preview/assets/icons/brand-heart.svg')) ?>" alt="" aria-hidden="true">
            <span class="brand-title">Bushisa</span>
            <span class="brand-subtitle">NUST CONNECTIONS</span>
        </a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= e($item['href']) ?>" <?= $item['active'] ? 'class="active" aria-current="page"' : '' ?>>
                    <img src="<?= e(asset_path('preview/assets/icons/' . $item['icon'] . '.svg')) ?>" alt="" aria-hidden="true">
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="header-actions">
            <button class="round-button notification-bell" type="button" data-notification-bell
                aria-controls="notificationPanel" aria-expanded="false" aria-label="Open notifications">
                <img src="<?= e(asset_path('preview/assets/icons/bell.svg')) ?>" alt="" aria-hidden="true">
                <?php if ($unreadCount > 0): ?>
                    <span class="unread-count"><?= e($unreadCount > 9 ? '9+' : (string) $unreadCount) ?></span>
                <?php endif; ?>
            </button>
            <a class="round-button account-letter" href="profile.php" aria-label="Your profile"><?= e($initial) ?></a>
        </div>
    </div>
</header>
<?php render_notification_panel(); ?>
<main id="main" class="cf-main">
    <header class="cf-intro">
        <div class="cf-intro__copy">
            <p class="cf-eyebrow">THE OTHER SIDE OF CAMPUS</p>
            <h1>Confessions</h1>
            <p>A small space for campus thoughts. Keep it kind, keep it anonymous.</p>
        </div>
        <button type="button" class="cf-post-button" data-confession-open aria-label="Post a confession">
            <span class="cf-post-button__label">Post a confession</span>
            <span class="cf-post-button__plus" aria-hidden="true">+</span>
        </button>
    </header>
    <?php render_flash(null, $success); ?>
    <?php if ($reportSuccess !== null): ?>
        <div class="cf-report-success" role="status"><?= e($reportSuccess) ?></div>
    <?php endif; ?>
    <div class="cf-layout">
        <section class="cf-feed" aria-label="Anonymous confession feed">
            <div class="cf-toolbar">
                <nav class="cf-tabs" aria-label="Sort confessions">
                    <a class="cf-tab<?= $sort === 'latest' ? ' is-current' : '' ?>" href="confessions.php?sort=latest" <?= $sort === 'latest' ? 'aria-current="page"' : '' ?>>Latest</a>
                    <a class="cf-tab<?= $sort === 'trending' ? ' is-current' : '' ?>" href="confessions.php?sort=trending" <?= $sort === 'trending' ? 'aria-current="page"' : '' ?>>Trending</a>
                </nav>
                <p class="cf-post-count"><?= e((string) $totalConfessions) ?> <?= $totalConfessions === 1 ? 'confession' : 'confessions' ?></p>
            </div>
            <aside class="cf-guidance">
                <div class="cf-guidance__desktop">
                    <h2>Keep it kind. Respect mention settings.</h2>
                    <p>Mention only opted-in students. No private details, contact information or harassment.</p>
                </div>
                <div class="cf-guidance__mobile">
                    <h2>Respectful mentions only</h2>
                    <p>Mention someone only if they've opted in. No private details, contact info, or harassment.</p>
                </div>
            </aside>
            <?php if ($confessions === []): ?>
                <div class="cf-empty">
                    <span class="cf-empty__symbol" aria-hidden="true">✧</span>
                    <h2>Every campus has a story.</h2>
                    <p>It's a little quiet here for now. When someone shares a thought, it'll appear here. Posts should be anonymous and respectful.</p>
                </div>
            <?php else: ?>
                <div class="cf-list">
                    <?php foreach ($confessions as $confession): ?>
                        <?php
                            $handle = ltrim(trim((string) ($confession['anon_handle'] ?? '')), '@');
                            $accountDisplayName = trim((string) ($confession['account_display_name'] ?? ''));
                            // Do not reveal the matching public display name from an old account.
                            $matchesPublicName = $accountDisplayName !== ''
                                && strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $handle))
                                   === strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $accountDisplayName));
                            if ($handle === '' || $matchesPublicName) {
                                $handle = 'anonymous';
                            }
                            $views = max(0, (int) ($confession['view_count'] ?? 0));
                        ?>
                        <article class="cf-card">
                            <div class="cf-card__byline">
                                <span class="cf-card__avatar" aria-hidden="true"><img src="<?= e(asset_path('preview/assets/icons/confessions.svg')) ?>" alt=""></span>
                                <div class="cf-card__author">
                                    <strong>@<?= e($handle) ?></strong>
                                    <time datetime="<?= e((string) ($confession['created_at'] ?? '')) ?>"><?= e(format_time_ago((string) ($confession['created_at'] ?? ''))) ?></time>
                                </div>
                                <span class="cf-card__quote" aria-hidden="true">“</span>
                            </div>
                            <div class="cf-bubble">
                                <p class="cf-bubble__body"><?= e((string) ($confession['body'] ?? '')) ?></p>
                                <div class="cf-bubble__actions">
                                    <span class="cf-bubble__views"><?= e((string) $views) ?> <?= $views === 1 ? 'view' : 'views' ?></span>
                                    <div class="cf-bubble__links">
                                        <button type="button" class="cf-report-trigger" data-report-confession="<?= e((string) $confession['id']) ?>" aria-label="Report confession #<?= e((string) $confession['id']) ?>">Report</button>
                                        <button class="cf-bubble__soon" type="button" disabled title="Reactions are being tested">♡ Like soon</button>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($total_pages > 1): ?>
                <nav class="cf-pagination" aria-label="Confession pages">
                    <?php if ($page > 1): ?>
                        <a href="confessions.php?sort=<?= e($sort) ?>&amp;page=<?= e((string) ($page - 1)) ?>">← Previous</a>
                    <?php endif; ?>
                    <span>Page <?= e((string) $page) ?> of <?= e((string) $total_pages) ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="confessions.php?sort=<?= e($sort) ?>&amp;page=<?= e((string) ($page + 1)) ?>">Next →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
            <div class="cf-feed-end">
                <h2>You're all caught up.</h2>
                <p>That's everything for now. The next thought can take its time.</p>
            </div>
        </section>
        <aside class="cf-aside" aria-label="Community guidance">
            <h2>See something harmful?</h2>
            <p>Use Report on a post to privately send it to the moderator queue. Reporting does not hide a post automatically. Review and removal are not immediate.</p>
            <a href="community_guidelines.php">Read community rules →</a>
        </aside>
    </div>
    <div class="cf-beta">
        <strong>BETA</strong>
        <span>This is a development build. Reporting sends posts to a private moderator queue, but reviews are not monitored in real time. Use only fictional test content until verification and moderation are ready.</span>
    </div>
</main>
<footer class="site-footer cf-footer">
    <span>For NUST students · 18+ only · Independent student project</span>
    <span class="links"><a href="community_guidelines.php">Community rules</a> / <a href="privacy_policy.php">Safety &amp; privacy</a></span>
</footer>
<nav class="bottom-nav" aria-label="Mobile navigation">
    <?php foreach ($navItems as $item): ?>
        <a href="<?= e($item['href']) ?>" <?= $item['active'] ? 'class="active" aria-current="page"' : '' ?>>
            <img src="<?= e(asset_path('preview/assets/icons/' . $item['icon'] . '.svg')) ?>" alt="" aria-hidden="true">
            <span><?= e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<section class="cf-modal" id="write-confession" role="dialog" aria-modal="true"
         aria-labelledby="write-confession-title" data-confession-modal <?= $error === null ? 'hidden' : '' ?>>
    <div class="cf-composer">
        <div class="cf-composer__top">
            <h2 id="write-confession-title">A thought worth sharing?</h2>
            <button type="button" class="cf-composer__close" data-confession-close aria-label="Close composer">×</button>
        </div>
        <p>Other students see your separate Confessions username, not your public profile name. Choose or update it in <a href="profile.php#confessions-identity">You → Confessions identity</a>. Avoid personal details. Moderators can still attribute posts for safety.</p>
        <?php render_flash($error, null); ?>
        <form method="post" action="confessions.php?sort=<?= e($sort) ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <label for="cf-body">Your confession</label>
            <textarea id="cf-body" name="body" maxlength="500" required placeholder="What's on your mind?"><?= e((string) ($_POST['body'] ?? '')) ?></textarea>
            <p class="cf-composer__help">Up to 500 characters · Only share what is safe to make public.</p>
            <div class="cf-composer__actions">
                <button type="button" class="cf-composer__cancel" data-confession-close>Cancel</button>
                <button type="submit" class="cf-composer__submit">Post anonymously →</button>
            </div>
        </form>
    </div>
</section>
<section class="cf-modal cf-report-modal" id="report-confession-dialog" role="dialog" aria-modal="true"
         aria-labelledby="cf-report-title" data-report-modal <?= $reportError === null ? 'hidden' : '' ?>>
    <div class="cf-composer cf-report-composer">
        <div class="cf-composer__top">
            <div>
                <span class="cf-report-eyebrow">CONFIDENTIAL MODERATOR REPORT</span>
                <h2 id="cf-report-title">Report this confession</h2>
            </div>
            <button type="button" class="cf-composer__close" data-report-close aria-label="Close reporting form">×</button>
        </div>
        <p>Tell the moderators what concerns you. This report is private and will not appear beside the confession. Do not include anyone's personal details.</p>
        <?php if ($reportError !== null): ?>
            <div class="cf-report-error" role="alert"><?= e($reportError) ?></div>
        <?php endif; ?>
        <form method="post" action="confessions.php?sort=<?= e($sort) ?>&amp;page=<?= e((string) $page) ?>" data-report-form>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="report_confession" value="1">
            <input type="hidden" name="confession_id" value="<?= e((string) ($reportPostId ?? '')) ?>" data-report-id>
            <p class="cf-report-target" data-report-target>Choose the confession to report.</p>
            <label for="cf-report-reason">Why are you reporting this?</label>
            <select id="cf-report-reason" name="reason" required>
                <option value="">Choose a reason</option>
                <?php foreach (bushisa_confession_report_reasons() as $reasonCode => $reasonLabel): ?>
                    <option value="<?= e($reasonCode) ?>" <?= (($_POST['reason'] ?? '') === $reasonCode && $reportError !== null) ? 'selected' : '' ?>><?= e($reasonLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="cf-report-details">More context (optional)</label>
            <textarea id="cf-report-details" name="details" maxlength="140" rows="3" placeholder="A short explanation for moderators..."><?= e($reportError !== null && isset($_POST['details']) && is_string($_POST['details']) ? $_POST['details'] : '') ?></textarea>
            <p class="cf-composer__help">Up to 140 characters. Reports are reviewed by moderators, not shared publicly.</p>
            <div class="cf-composer__actions">
                <button class="cf-composer__cancel" type="button" data-report-close>Cancel</button>
                <button class="cf-composer__submit" type="submit">Submit report →</button>
            </div>
        </form>
    </div>
</section>
<script src="<?= e(asset_path('js/app.js')) ?>"></script>
<script src="<?= e(asset_path('js/confessions-report.js')) ?>"></script>
</body>
</html>
