<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/csrf.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$matches = [];

$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';
$matchTimeColumn = in_array('matched_at', $matchColumns, true) ? 'matched_at' : 'created_at';

$messageColumnsStatement = $pdo->query('SHOW COLUMNS FROM messages');
$messageColumns = $messageColumnsStatement !== false ? $messageColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$messageBodyColumn = in_array('body', $messageColumns, true) ? 'body' : 'message_text';
$messageTimeColumn = in_array('sent_at', $messageColumns, true) ? 'sent_at' : 'created_at';

$sql = sprintf(
    'SELECT
        m.id AS match_id,
        CASE WHEN m.%1$s = :select_user THEN m.%2$s ELSE m.%1$s END AS other_user_id,
        p.display_name,
        p.profile_photo_path,
        p.faculty,
        lm.body AS last_message,
        lm.sent_at AS last_message_time,
        m.%3$s AS matched_at
    FROM matches m
    INNER JOIN profiles p
        ON p.user_id = CASE WHEN m.%1$s = :join_user THEN m.%2$s ELSE m.%1$s END
    LEFT JOIN (
        SELECT msg.match_id, msg.%4$s AS body, msg.%5$s AS sent_at
        FROM messages msg
        INNER JOIN (
            SELECT match_id, MAX(id) AS last_message_id
            FROM messages
            GROUP BY match_id
        ) latest ON latest.last_message_id = msg.id
    ) lm ON lm.match_id = m.id
    WHERE m.%1$s = :where_user_a OR m.%2$s = :where_user_b
    ORDER BY COALESCE(lm.sent_at, m.%3$s) DESC, m.id DESC',
    $matchUserAColumn,
    $matchUserBColumn,
    $matchTimeColumn,
    $messageBodyColumn,
    $messageTimeColumn
);

$statement = $pdo->prepare($sql);
$statement->execute([
    ':select_user' => $currentUserId,
    ':join_user' => $currentUserId,
    ':where_user_a' => $currentUserId,
    ':where_user_b' => $currentUserId,
]);
$matches = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Keep the established-match query intact; the questionnaire is an additive UI.
$csrfToken = generate_csrf_token();
$savedAnswers = [];
try {
    if (operational_table_exists($pdo, 'find_match_answers')) {
        $answerQuery = $pdo->prepare('SELECT question_key, answer_value FROM find_match_answers WHERE user_id = :user_id');
        $answerQuery->execute([':user_id' => $currentUserId]);
        foreach ($answerQuery->fetchAll(PDO::FETCH_ASSOC) ?: [] as $answerRow) {
            $savedAnswers[(string) $answerRow['question_key']] = (string) $answerRow['answer_value'];
        }
    }
} catch (Throwable $exception) {
    error_log('Find Match questionnaire prefilling unavailable: ' . $exception->getMessage());
}

$profile = get_profile($pdo, $currentUserId);
$profileName = trim((string) ($profile['display_name'] ?? ''));
$profileInitial = $profileName !== '' ? strtoupper(substr($profileName, 0, 1)) : 'B';
$navItems = [
    ['href' => 'discover.php', 'label' => 'Home', 'icon' => 'home', 'active' => false],
    ['href' => 'matches.php', 'label' => 'Find Match', 'icon' => 'nav-heart', 'active' => true],
    ['href' => 'confessions.php', 'label' => 'Confessions', 'icon' => 'confessions', 'active' => false],
    ['href' => 'profile.php', 'label' => 'You', 'icon' => 'you', 'active' => false],
];
$unreadCount = 0;
try {
    if (operational_table_exists($pdo, 'notifications')) {
        $notificationQuery = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = :id AND read_at IS NULL');
        $notificationQuery->execute([':id' => $currentUserId]);
        $unreadCount = (int) $notificationQuery->fetchColumn();
    }
} catch (Throwable $exception) {
    error_log('Find Match notification count unavailable: ' . $exception->getMessage());
}
$isChecked = static function (string $key, string $value) use ($savedAnswers): string {
    $stored = (string) ($savedAnswers[$key] ?? '');
    if ($key === 'interests') {
        return in_array($value, array_map('trim', explode(',', $stored)), true) ? ' checked' : '';
    }
    return $stored === $value ? ' checked' : '';
};

render_document_head('Find Match', $csrfToken, $currentUserId);
?>
<body class="page-match-figma bushisa-app" id="top">
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="discover.php" aria-label="Bushisa Home">
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
            <button type="button" class="round-button notification-bell" data-notification-bell
                aria-controls="notificationPanel" aria-expanded="false" aria-label="Open notifications">
                <img src="<?= e(asset_path('preview/assets/icons/bell.svg')) ?>" alt="" aria-hidden="true">
                <?php if ($unreadCount > 0): ?>
                    <span class="unread-count"><?= e($unreadCount > 9 ? '9+' : (string) $unreadCount) ?></span>
                <?php endif; ?>
            </button>
            <a class="round-button account-letter" href="profile.php" aria-label="Your profile"><?= e($profileInitial) ?></a>
        </div>
    </div>
</header>
<?php render_notification_panel(); ?>

<main class="fm-main" data-find-match id="main">
    <header class="fm-intro">
        <span class="fm-eyebrow">LESS GUESSING. MORE CONNECTION.</span>
        <h1>Find someone on your wavelength</h1>
        <p>A few thoughtful answers to help you find common ground. Start with what you’re looking for.</p>
    </header>

    <div class="fm-layout">
        <section class="fm-questionnaire" aria-labelledby="fm-question-title" data-match-flow-title>
            <div class="fm-progress" aria-label="Questionnaire progress">
                <p class="fm-progress-label" data-progress-label>Step 1 of 4 · Your intentions</p>
                <div class="fm-progress-track">
                    <?php foreach (['Your intentions', 'Your vibe', 'Shared interests', 'Preferences'] as $index => $label): ?>
                        <div class="fm-progress-step <?= $index === 0 ? 'is-current' : '' ?>" data-progress
                            <?= $index === 0 ? 'aria-current="step"' : '' ?>>
                            <span class="fm-progress-segment" aria-hidden="true"></span>
                            <span><?= e($label) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="fm-step" data-step="0">
                <div class="fm-question">
                    <h2 id="fm-question-title">What kind of connection feels right?</h2>
                    <p>Choose one. You can change your mind later.</p>
                </div>
                <fieldset class="fm-choices" aria-label="Your intentions">
                    <label class="fm-option">
                        <input type="radio" name="intent" data-answer-key="intent" value="Something serious"<?= $isChecked('intent','Something serious') ?>>
                        <span class="fm-icon" aria-hidden="true"><img src="<?= e(asset_path('preview/assets/icons/brand-heart.svg')) ?>" alt=""></span>
                        <span class="fm-option-copy"><strong>Something serious</strong><small>Date with the hope of building a lasting relationship.</small></span>
                        <span class="fm-select-indicator" aria-hidden="true"></span>
                    </label>
                    <label class="fm-option">
                        <input type="radio" name="intent" data-answer-key="intent" value="Casual dating"<?= $isChecked('intent','Casual dating') ?>>
                        <span class="fm-icon" aria-hidden="true">☕</span>
                        <span class="fm-option-copy"><strong>Casual dating</strong><small>Meet and date without expecting a long-term commitment.</small></span>
                        <span class="fm-select-indicator" aria-hidden="true"></span>
                    </label>
                    <label class="fm-option">
                        <input type="radio" name="intent" data-answer-key="intent" value="Friendship first"<?= $isChecked('intent','Friendship first') ?>>
                        <span class="fm-icon" aria-hidden="true">♧</span>
                        <span class="fm-option-copy"><strong>Friendship first</strong><small>Start as friends and leave room for a connection to grow.</small></span>
                        <span class="fm-select-indicator" aria-hidden="true"></span>
                    </label>
                    <label class="fm-option">
                        <input type="radio" name="intent" data-answer-key="intent" value="Still exploring"<?= $isChecked('intent','Still exploring') ?>>
                        <span class="fm-icon" aria-hidden="true">◎</span>
                        <span class="fm-option-copy"><strong>Still exploring</strong><small>Not sure yet. Meet people and figure out what feels right.</small></span>
                        <span class="fm-select-indicator" aria-hidden="true"></span>
                    </label>
                </fieldset>
            </div>

            <div class="fm-step" data-step="1" hidden>
                <div class="fm-question">
                    <h2>What feels most like you?</h2>
                    <p>A little about your social style and how you like to connect.</p>
                </div>
                <fieldset class="fm-choices" aria-label="Your social style">
                    <?php foreach ([
                        ['Quiet moments', 'Smaller groups and thoughtful conversations.'],
                        ['Somewhere in the middle', 'A balance of relaxed days and social plans.'],
                        ['Always up for an activity', 'Outings, events and shared experiences.'],
                    ] as $option): ?>
                        <label class="fm-option">
                            <input type="radio" name="social_activity" data-answer-key="social_activity" value="<?= e($option[0]) ?>"<?= $isChecked('social_activity',$option[0]) ?>>
                            <span class="fm-icon" aria-hidden="true">✧</span>
                            <span class="fm-option-copy"><strong><?= e($option[0]) ?></strong><small><?= e($option[1]) ?></small></span>
                            <span class="fm-select-indicator" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="fm-question">
                    <h2>How do you prefer to communicate?</h2>
                    <p>Choose the style that feels natural.</p>
                </div>
                <fieldset class="fm-choices" aria-label="Your communication style">
                    <?php foreach ([
                        ['Messages first', 'Start a conversation through messages.'],
                        ['Face-to-face', 'Talking in person feels easier.'],
                        ['A bit of both', 'Whatever works for both people.'],
                    ] as $option): ?>
                        <label class="fm-option">
                            <input type="radio" name="communication_style" data-answer-key="communication_style" value="<?= e($option[0]) ?>"<?= $isChecked('communication_style',$option[0]) ?>>
                            <span class="fm-icon" aria-hidden="true">◌</span>
                            <span class="fm-option-copy"><strong><?= e($option[0]) ?></strong><small><?= e($option[1]) ?></small></span>
                            <span class="fm-select-indicator" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>

            <div class="fm-step" data-step="2" hidden>
                <div class="fm-question">
                    <h2>What brings your world to life?</h2>
                    <p>Pick a few interests that you’d enjoy sharing or talking about.</p>
                </div>
                <fieldset class="fm-choices fm-interests" aria-label="Shared interests">
                    <?php foreach ([
                        'Music', 'Reading', 'Photography', 'Sport', 'Art', 'Gaming', 'Campus events', 'Travel'
                    ] as $interest): ?>
                        <label class="fm-option">
                            <input type="checkbox" name="interests[]" data-answer-key="interests" value="<?= e($interest) ?>"<?= $isChecked('interests',$interest) ?>>
                            <span class="fm-icon" aria-hidden="true">✧</span>
                            <span class="fm-option-copy"><strong><?= e($interest) ?></strong></span>
                            <span class="fm-select-indicator" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>

            <div class="fm-step" data-step="3" hidden>
                <div class="fm-question">
                    <h2>When do you usually have time?</h2>
                    <p>Your answer helps describe your availability. You can also update your discovery preferences on the You page.</p>
                </div>
                <fieldset class="fm-choices" aria-label="Availability">
                    <?php foreach ([
                        ['Between lectures', 'A coffee or a short break on campus.'],
                        ['Weekday evenings', 'After the day’s classes and work.'],
                        ['Weekends', 'A little more breathing room.'],
                        ['It varies', 'No set schedule — make plans when it feels right.'],
                    ] as $option): ?>
                        <label class="fm-option">
                            <input type="radio" name="availability" data-answer-key="availability" value="<?= e($option[0]) ?>"<?= $isChecked('availability',$option[0]) ?>>
                            <span class="fm-icon" aria-hidden="true">◷</span>
                            <span class="fm-option-copy"><strong><?= e($option[0]) ?></strong><small><?= e($option[1]) ?></small></span>
                            <span class="fm-select-indicator" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </div>

            <p class="fm-error" data-match-error role="alert"></p>
            <div class="fm-step-actions" data-match-actions>
                <p class="fm-selected" data-selected aria-live="polite"></p>
                <div class="fm-actions-right">
                    <button type="button" class="fm-back" data-match-back hidden>← Back</button>
                    <button type="button" class="fm-continue" data-match-next>Continue →</button>
                </div>
            </div>
            <section class="fm-complete" data-match-complete hidden aria-live="polite">
                <h2>A little more of your story, saved.</h2>
                <p>Your answers have been saved to your profile’s questionnaire. Compatibility scoring from these answers has not yet been launched, and student and age verification still need to be enforced before real discovery.</p>
                <p><a href="profile.php">Review your profile →</a></p>
                <button type="button" class="fm-back" data-match-edit>Review my answers</button>
            </section>
        </section>

        <aside class="fm-side" aria-label="How matching works">
            <div class="fm-guidance">
                <span class="fm-sparkle" aria-hidden="true">✧</span>
                <h2>Common ground,<br>not a score.</h2>
                <p>When discovery is ready, compatibility will be explained through shared answers, interests and intentions — not a mysterious percentage.</p>
            </div>
            <div class="fm-consent">
                <h2>You both get a say.</h2>
                <p>Like → mutual match → chat invitation. A mutual match does not open a chat. An invitation must be accepted first.</p>
                <hr>
                <p class="fm-consent-small">Before discovering people: complete your profile, confirm you’re 18+ and verify your student status.</p>
                <a href="profile.php">Go to your profile →</a>
            </div>
        </aside>
    </div>

    <div class="fm-beta">
        <strong>BETA</strong>
        <span>Matching and chat are still in testing. This questionnaire saves your answers; verified compatibility results are not available yet.</span>
    </div>

    <?php if ($matches !== []): ?>
        <details class="fm-existing">
            <summary>Established matches (<?= e((string) count($matches)) ?>)</summary>
            <p>These are existing match records. Conversations require a separate accepted invitation.</p>
            <div class="match-list-page">
                <?php foreach ($matches as $match): ?>
                    <article class="match-row">
                        <div class="profile-summary">
                            <?= render_avatar($match['profile_photo_path'] ?? null, (string) ($match['display_name'] ?? 'Match')) ?>
                            <div>
                                <h3><?= e($match['display_name'] ?? 'Match') ?></h3>
                                <p class="muted"><?= e($match['faculty'] ?? 'NUST student') ?></p>
                            </div>
                        </div>
                        <div class="card-actions">
                            <a class="button-secondary" href="chat.php?match_id=<?= e((string) ($match['match_id'] ?? '')) ?>">Conversation details</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
    <noscript><p class="fm-error">JavaScript is required to complete the step-by-step questionnaire. Your existing account information is unchanged.</p></noscript>
</main>

<footer class="site-footer fm-footer">
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
<script src="<?= e(asset_path('js/app.js')) ?>"></script>
<script src="<?= e(asset_path('js/find-match.js')) ?>"></script>
</body>
</html>
