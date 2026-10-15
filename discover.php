<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/block_handler.php';
require_once __DIR__ . '/php/discovery_feed.php';
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/discovery_cards.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$preferences = get_preferences($pdo, $currentUserId);
$discovery = bushisa_discovery_batch($pdo, $currentUserId, null, null, 'everyone');
$candidates = $discovery['profiles'];
$currentProfile = get_profile($pdo, $currentUserId);
$displayName = trim((string) ($currentProfile['display_name'] ?? ''));
$nameParts = preg_split('/[\\s_]+/u', $displayName);
$firstName = is_array($nameParts) ? trim((string) ($nameParts[0] ?? '')) : '';
$firstName = $firstName !== ''
    ? (function_exists('mb_convert_case')
        ? mb_convert_case($firstName, MB_CASE_TITLE, 'UTF-8')
        : ucfirst(strtolower($firstName)))
    : 'there';
$initial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($firstName, 0, 1))
    : strtoupper(substr($firstName, 0, 1));
$csrfToken = generate_csrf_token();
$ownPersonality = bushisa_personality_read($pdo, $currentUserId);
$hasProfileStory = trim((string) ($currentProfile['bio'] ?? '')) !== ''
    || trim($ownPersonality['conversation_prompt']) !== ''
    || $ownPersonality['interests'] !== [];
$needsDiscoverySetup = $preferences === null;
$showEmptyHome = $candidates === [];


$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';
$matchTimeColumn = in_array('matched_at', $matchColumns, true) ? 'matched_at' : 'created_at';

$matchesSql = sprintf(
    'SELECT m.id AS match_id,
         CASE WHEN m.%1$s = :select_user THEN m.%2$s ELSE m.%1$s END AS other_user_id,
         p.display_name, p.profile_photo_path, m.%3$s AS matched_at
     FROM matches m
     INNER JOIN profiles p
       ON p.user_id = CASE WHEN m.%1$s = :join_user THEN m.%2$s ELSE m.%1$s END
     WHERE (m.%1$s = :where_user_a OR m.%2$s = :where_user_b)
       AND NOT EXISTS (
           SELECT 1 FROM blocks b
           WHERE (b.blocker_id = :blocker_user AND b.blocked_id = p.user_id)
              OR (b.blocked_id = :blocked_user AND b.blocker_id = p.user_id)
       )
     ORDER BY m.%3$s DESC, m.id DESC LIMIT 12',
    $matchUserAColumn,
    $matchUserBColumn,
    $matchTimeColumn
);
$matchesStatement = $pdo->prepare($matchesSql);
$matchesStatement->execute([
    ':select_user' => $currentUserId,
    ':join_user' => $currentUserId,
    ':where_user_a' => $currentUserId,
    ':where_user_b' => $currentUserId,
    ':blocker_user' => $currentUserId,
    ':blocked_user' => $currentUserId,
]);
$establishedMatches = $matchesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pendingRequests = [];
$pendingCount = 0;
$unreadNotifications = 0;
try {
    if (operational_table_exists($pdo, 'message_requests')) {
        $pendingCountStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM message_requests
             WHERE recipient_id = :user_id AND status = "pending"'
        );
        $pendingCountStatement->execute([':user_id' => $currentUserId]);
        $pendingCount = (int) $pendingCountStatement->fetchColumn();

        $pendingSql = 'SELECT mr.match_id, p.display_name, p.profile_photo_path
                       FROM message_requests mr
                       INNER JOIN profiles p ON p.user_id = mr.requester_id
                       WHERE mr.recipient_id = :recipient_id
                         AND mr.status = "pending"
                       ORDER BY mr.requested_at DESC LIMIT 2';
        $pendingStatement = $pdo->prepare($pendingSql);
        $pendingStatement->execute([':recipient_id' => $currentUserId]);
        $pendingRequests = $pendingStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (operational_table_exists($pdo, 'notifications')) {
        $unreadStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE recipient_id = :user_id AND read_at IS NULL'
        );
        $unreadStatement->execute([':user_id' => $currentUserId]);
        $unreadNotifications = (int) $unreadStatement->fetchColumn();
    }
} catch (Throwable $error) {
    // Feed and navigation are still usable if an optional messaging table
    // belongs to an older development database revision.
    error_log('Home activity list: ' . $error->getMessage());
    $pendingRequests = [];
    $pendingCount = 0;
    $unreadNotifications = 0;
}

$navigation = [
    ['href' => 'discover.php', 'label' => 'Home', 'icon' => 'home', 'active' => true],
    ['href' => 'matches.php', 'label' => 'Find Match', 'icon' => 'nav-heart', 'active' => false],
    ['href' => 'confessions.php', 'label' => 'Confessions', 'icon' => 'confessions', 'active' => false],
    ['href' => 'profile.php', 'label' => 'You', 'icon' => 'you', 'active' => false],
];

render_document_head('Home', $csrfToken, $currentUserId);
?>
<body id="top" class="page-home home-figma-app" data-current-user-id="<?= e($currentUserId) ?>">
<header class="site-header">
    <div class="header-inner">
        <a href="discover.php" class="brand" aria-label="Bushisa Home">
            <img src="<?= e(asset_path('preview/assets/icons/brand-heart.svg')) ?>" alt="" aria-hidden="true">
            <span class="brand-title">Bushisa</span>
            <span class="brand-subtitle">NUST CONNECTIONS</span>
        </a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <?php foreach ($navigation as $link): ?>
                <a href="<?= e($link['href']) ?>" <?= $link['active'] ? 'class="active" aria-current="page"' : '' ?>>
                    <img src="<?= e(asset_path('preview/assets/icons/' . $link['icon'] . '.svg')) ?>" alt="" aria-hidden="true">
                    <span><?= e($link['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="header-actions">
            <button type="button" class="round-button notification-bell" data-notification-bell
                    aria-label="Open notifications" aria-expanded="false" aria-controls="notificationPanel">
                <img src="<?= e(asset_path('preview/assets/icons/bell.svg')) ?>" alt="" aria-hidden="true">
                <?php if ($unreadNotifications > 0): ?>
                    <span class="unread-count"><?= e($unreadNotifications > 9 ? '9+' : (string) $unreadNotifications) ?></span>
                <?php endif; ?>
            </button>
            <a class="round-button account-letter" href="profile.php" aria-label="Your profile"><?= e($initial) ?></a>
        </div>
    </div>
</header>
<?php render_notification_panel(); ?>
<div class="preview-banner">
    <div class="inner"><span class="preview-eye" aria-hidden="true">◉</span>
        <span>DEVELOPMENT PREVIEW · Live data from the local test database · Figma Home layout</span>
    </div>
</div>

<main class="main">
    <div class="welcome">
        <p>Hey, <?= e($firstName) ?>. Make room for a little possibility.</p>
        <p>Your campus. Your kind of connection.</p>
    </div>

    <section class="chats" aria-labelledby="chats-title">
        <div class="section-head">
            <div class="head-with-pill">
                <h2 class="section-title" id="chats-title">Your chats</h2>
                <?php if ($pendingCount > 0): ?>
                    <a class="pill" href="matches.php"><?= e($pendingCount) ?> <?= $pendingCount === 1 ? 'request' : 'requests' ?></a>
                <?php endif; ?>
            </div>
            <a class="peach-link" href="matches.php">See all →</a>
        </div>
        <?php if ($establishedMatches === [] && $pendingCount === 0): ?>
            <div class="home-quiet-chats">
                <span class="home-quiet-chats__icon" aria-hidden="true">
                    <img src="<?= e(asset_path('preview/assets/icons/new-chat.svg')) ?>" alt="">
                </span>
                <p>Conversations will appear here when available.</p>
                <a href="matches.php">View Find Match →</a>
            </div>
        <?php else: ?>
        <div class="chats-strip" aria-label="Your actual matches and pending requests">
            <a class="chat-circle" href="matches.php">
                <span class="new-circle"><img src="<?= e(asset_path('preview/assets/icons/new-chat.svg')) ?>" alt="" aria-hidden="true"></span>
                <span>New</span>
            </a>
            <?php foreach ($establishedMatches as $match): ?>
                <?php $matchName = trim((string) ($match['display_name'] ?? 'Student')); ?>
                <a class="chat-circle" href="chat.php?match_id=<?= e($match['match_id']) ?>" aria-label="<?= e($matchName) ?> chat or invitation">
                    <?= render_avatar($match['profile_photo_path'] ?? null, $matchName) ?>
                    <span><?= e($matchName) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($pendingRequests !== []): ?>
                <span class="chat-divider" role="separator"></span>
                <?php foreach ($pendingRequests as $request): ?>
                    <?php $requestName = trim((string) ($request['display_name'] ?? 'Student')); ?>
                    <a class="chat-circle pending-request" href="chat.php?match_id=<?= e($request['match_id']) ?>" aria-label="Pending request from <?= e($requestName) ?>">
                        <?= render_avatar($request['profile_photo_path'] ?? null, $requestName) ?>
                        <span class="pending-icon">?</span>
                        <span>Pending</span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($establishedMatches === []): ?>
                <div class="chat-note home-empty-chat">
                    <strong>No matches yet</strong>
                    When eligible matches and accepted invitations are available, your conversations will appear here.
                </div>
            <?php else: ?>
                <div class="chat-note">
                    <strong>Mutual match required</strong>
                    Chat opens only after an invitation is accepted. Passing stays private.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($establishedMatches !== [] || $pendingCount > 0): ?>
            <div class="chats-bottom"><span class="private-pass">Passing stays private. Conversations require consent.</span></div>
        <?php endif; ?>
    </section>

    <section class="suggestions" aria-labelledby="suggested-title">
        <div class="section-head">
            <div>
                <h2 id="suggested-title" class="section-title">Explore students on Bushisa</h2>
                <p class="section-head-sub">Browse eligible members and express interest. Shared interests are explored in Find Match.</p>
            </div>
            <div class="suggestion-tools">
                <a class="soft-button" href="profile.php#preferences">
                    <img src="<?= e(asset_path('preview/assets/icons/filter.svg')) ?>" alt="" aria-hidden="true">
                    Filter
                </a>
            </div>
        </div>

            <?php if ($showEmptyHome): ?>
            <section class="home-firstlook" aria-labelledby="home-firstlook-title">
                <div class="home-firstlook__intro">
                    <span class="home-firstlook__eyebrow">
                        <img src="<?= e(asset_path('preview/assets/icons/sprout.svg')) ?>" alt="" aria-hidden="true">
                        YOUR NEXT CHAPTER
                    </span>
                    <div class="home-firstlook__art" aria-hidden="true">
                        <span class="home-firstlook__halo home-firstlook__halo--outer"></span>
                        <span class="home-firstlook__halo home-firstlook__halo--inner"></span>
                        <img src="<?= e(asset_path('preview/assets/icons/brand-heart.svg')) ?>" alt="">
                    </div>
                    <h3 id="home-firstlook-title">
                        No new profiles available just yet.
                    </h3>
                    <p class="home-firstlook__lead">
                        Browse again when more eligible students join, or explore shared interests in Find Match.
                    </p>
                    <span class="home-firstlook__status">
                        <span class="home-firstlook__status-dot" aria-hidden="true"></span>
                        Explore at your own pace
                    </span>
                </div>
                <div class="home-firstlook__explore" aria-label="Explore Bushisa">
                    <a class="home-path home-path--featured" href="profile.php#conversation-and-interests">
                        <span class="home-path__top">
                            <span class="home-path__icon"><img src="<?= e(asset_path('preview/assets/icons/you.svg')) ?>" alt="" aria-hidden="true"></span>
                            <span class="home-path__number">01</span>
                        </span>
                        <strong><?= $hasProfileStory ? 'Make your story even more you.' : 'Your story starts with you.' ?></strong>
                        <span><?= $hasProfileStory ? 'Update the details that make your profile personal.' : 'Add a bio, interests and a conversation starter.' ?></span>
                        <span class="home-path__link">Go to You <span aria-hidden="true">↗</span></span>
                    </a>
                    <a class="home-path" href="matches.php">
                        <span class="home-path__top">
                            <span class="home-path__icon"><img src="<?= e(asset_path('preview/assets/icons/nav-heart.svg')) ?>" alt="" aria-hidden="true"></span>
                            <span class="home-path__number">02</span>
                        </span>
                        <strong>Find Match</strong>
                        <span>Explore how shared answers and compatibility work.</span>
                        <span class="home-path__link">Explore <span aria-hidden="true">↗</span></span>
                    </a>
                    <a class="home-path" href="confessions.php">
                        <span class="home-path__top">
                            <span class="home-path__icon"><img src="<?= e(asset_path('preview/assets/icons/confessions.svg')) ?>" alt="" aria-hidden="true"></span>
                            <span class="home-path__number">03</span>
                        </span>
                        <strong>Confessions</strong>
                        <span>Discover the campus stories section.</span>
                        <span class="home-path__link">Take a look <span aria-hidden="true">↗</span></span>
                    </a>
                </div>
            </section>
            <?php else: ?>
            <div class="suggestions-grid" data-discovery-grid>
                <?= bushisa_render_discovery_cards(array_slice($candidates, 0, 2)) ?>
                <?php if (isset($candidates[2])): ?>
                    <div class="mobile-third-card"><?= bushisa_render_discovery_card($candidates[2]) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$showEmptyHome): ?>
        <div class="feature-row<?= !isset($candidates[2]) ? ' feature-row--no-card' : '' ?>">
            <section class="story-panel" aria-labelledby="story-title">
                <img class="story-icon" src="<?= e(asset_path('preview/assets/icons/sprout.svg')) ?>" alt="" aria-hidden="true">
                <h3 id="story-title">Your story starts here.</h3>
                <p>Make your profile feel like you. Add a short bio, interests and a conversation starter.</p>
                <a class="peach-link" href="profile.php#conversation-and-interests">Add a conversation prompt →</a>
                <a class="peach-link" href="matches.php">Explore Find Match →</a>
            </section>
            <?php if (isset($candidates[2])): ?>
                <?= bushisa_render_discovery_card($candidates[2]) ?>
            <?php endif; ?>
            <section class="consent-panel">
                <p class="kicker">A MATCH IS JUST THE START</p>
                <h3>A mutual yes. Then a hello.</h3>
                <p>Liking each other creates a mutual match, not permission to message. Chat starts only when an invitation is accepted.</p>
            </section>
        </div>

        <?php endif; ?>

        <?php if ($candidates !== []): ?>
            <div class="suggestions-grid home-continuation-grid" data-discovery-continuation>
                <?= bushisa_render_discovery_cards(array_slice($candidates, 3)) ?>
            </div>
            <div class="home-discovery-pagination" data-discovery-pagination
                 data-after="<?= e($discovery['next_cursor'] ?? '') ?>"
                 data-has-more="<?= $discovery['has_more'] ? 'true' : 'false' ?>">
                <p class="home-discovery-loading scroll-demo-state" data-discovery-loading role="status" hidden>Finding more profiles…</p>
                <p class="home-discovery-error scroll-demo-state" data-discovery-error role="alert" hidden>
                    Couldn't load more profiles. <button type="button" data-discovery-retry>Retry</button>
                </p>
                <span class="home-discovery-sentinel" data-discovery-sentinel aria-hidden="true"></span>
                <section class="caught-up" data-discovery-end <?= $discovery['has_more'] ? 'hidden' : '' ?>>
                    <h3><img src="<?= e(asset_path('preview/assets/icons/caught-up.svg')) ?>" alt="" aria-hidden="true"> You're caught up.</h3>
                    <p>You've seen all eligible profiles for now. You can revisit your matches or adjust preferences.</p>
                    <div class="caught-up-actions">
                        <a class="soft-button" href="matches.php">Check your matches</a>
                        <a class="soft-button" href="profile.php#preferences">Edit preferences</a>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </section>

    <section class="process-section" aria-labelledby="process-title">
        <h2 id="process-title">A connection, at your pace.</h2>
        <div class="process-steps">
            <?php
            $steps = [
                ['Your profile', 'Share a little about you.'],
                ['Discover', 'Find shared answers.'],
                ['Like', 'Show your interest.'],
                ['Mutual match', 'You both choose each other.'],
                ['Chat consent', 'An invitation must be accepted.'],
            ];
            foreach ($steps as $index => $step):
            ?>
                <div class="process-step">
                    <span class="number"><?= e($index + 1) ?></span>
                    <strong><?= e($step[0]) ?></strong>
                    <p><?= e($step[1]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="beta-bar"><span class="beta-chip">BETA</span> Matching, chat and media features are being tested. Some features are not available yet.</div>
</main>

<footer class="site-footer">
    <span>For NUST students · 18+ only · Independent student project</span>
    <span class="links">
        <a href="community_guidelines.php">Community rules</a> /
        <a href="privacy_policy.php">Safety &amp; privacy</a>
    </span>
</footer>
<nav class="bottom-nav" aria-label="Mobile navigation">
    <?php foreach ($navigation as $link): ?>
        <a href="<?= e($link['href']) ?>" <?= $link['active'] ? 'class="active" aria-current="page"' : '' ?>>
            <img src="<?= e(asset_path('preview/assets/icons/' . $link['icon'] . '.svg')) ?>" alt="" aria-hidden="true">
            <span><?= e($link['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<script src="<?= e(asset_path('js/app.js')) ?>"></script>
<script src="<?= e(asset_path('js/discovery_feed.js')) ?>"></script>
<script src="<?= e(asset_path('js/home-real.js')) ?>"></script>
</body>
</html>
