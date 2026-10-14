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

/*
 * A complete set of the five core answer keys is the persisted onboarding
 * state. Old accounts that already completed the questionnaire work without
 * a database migration or a session-only completion flag.
 */
$requiredMatchingAnswers = ['intent', 'social_activity', 'communication_style', 'interests', 'availability'];
$hasCompletedMatching = true;
foreach ($requiredMatchingAnswers as $requiredMatchingKey) {
    if (trim((string) ($savedAnswers[$requiredMatchingKey] ?? '')) === '') {
        $hasCompletedMatching = false;
        break;
    }
}
$showMatchingMap = $hasCompletedMatching && (($_GET['edit'] ?? '') !== '1');

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
    if (in_array($key, ['interests', 'sports', 'music_styles', 'reading_styles', 'gaming_styles', 'creative_styles', 'outing_styles', 'food_styles', 'screen_styles', 'tech_styles', 'community_styles'], true)) {
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

<main class="fm-main" <?= $showMatchingMap ? '' : 'data-find-match' ?> id="main">
    <header class="fm-intro">
        <span class="fm-eyebrow">LESS GUESSING. MORE CONNECTION.</span>
        <?php if ($showMatchingMap): ?>
            <h1>Find your kind of connection</h1>
            <p>Your story is saved. Discover the campus, at your own pace.</p>
        <?php else: ?>
            <h1>Find someone on your wavelength</h1>
            <p>A few thoughtful answers to help you find common ground. Start with what you’re looking for.</p>
        <?php endif; ?>
    </header>


    <?php if ($showMatchingMap): ?>
        <div class="fm-map-layout">
            <section class="fm-map-panel" aria-labelledby="fm-map-title">
                <div class="fm-map-heading">
                    <div>
                        <h2 id="fm-map-title">Your campus, your connections.</h2>
                        <p>Explore broad campus areas. When verified profiles become available, this will be the place to discover them.</p>
                    </div>
                    <span class="fm-map-tag">Campus preview</span>
                </div>
                <div class="fm-map-stage" data-fm-map aria-label="Illustrative campus area map. No people or precise locations are shown.">
                    <div class="fm-map-grid" aria-hidden="true"></div>
                    <div class="fm-map-ring" aria-hidden="true"></div>
                    <div class="fm-map-road" aria-hidden="true"></div>
                    <button type="button" class="fm-map-zone" data-zone-name="Study spaces" style="--zone-x:24%;--zone-y:24%" aria-pressed="false">Study spaces</button>
                    <button type="button" class="fm-map-zone" data-zone-name="Campus commons" style="--zone-x:72%;--zone-y:20%" aria-pressed="false">Campus commons</button>
                    <button type="button" class="fm-map-zone" data-zone-name="Library area" style="--zone-x:67%;--zone-y:50%" aria-pressed="false">Library area</button>
                    <button type="button" class="fm-map-zone" data-zone-name="Teaching areas" style="--zone-x:28%;--zone-y:58%" aria-pressed="false">Teaching areas</button>
                    <div class="fm-map-status" role="status" aria-live="polite">
                        <strong data-zone-title>It's a little quiet around campus for now.</strong>
                        <p data-zone-description>Profile markers are not enabled in this development build. Explore the broad areas while discovery is being prepared.</p>
                    </div>
                </div>
                <p class="fm-map-caption">✧ This is a concept map, not a live location service. It does not request GPS access or show anyone's whereabouts.</p>
            </section>

            <aside class="fm-map-summary" aria-label="Your matching preferences">
                <span class="fm-map-eyebrow">THE DETAILS THAT MAKE YOU, YOU</span>
                <h2>You've found your starting point.</h2>
                <p>Your questionnaire is saved, and you can update it whenever you like.</p>
                <dl>
                    <div><dt>Looking for</dt><dd><?= e($savedAnswers['intent'] ?? 'Not set') ?></dd></div>
                    <div><dt>Your vibe</dt><dd><?= e($savedAnswers['social_activity'] ?? 'Not set') ?></dd></div>
                    <div><dt>Shared interests</dt><dd><?= e($savedAnswers['interests'] ?? 'Not set') ?></dd></div>
                </dl>
                <a class="fm-map-edit" href="matches.php?edit=1">Change my answers →</a>
                <p class="fm-map-safety">Real profile markers and profile cards are not enabled yet. Verified 18+ eligibility, consent and coarse-area sharing are required before activating discovery. Your exact location is never requested here.</p>
            </aside>
        </div>
    <?php else: ?>
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
                
<details class="fm-followups" data-conditional data-when-key="intent" data-when-values="Something serious|Casual dating|Friendship first|Still exploring" hidden>
 <summary>Your pace <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">What feels comfortable when getting to know someone?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="connection pace">
  <label class="fm-option"><input type="radio" name="connection_pace" data-answer-key="connection_pace" data-optional-answer value="Taking my time"<?= $isChecked('connection_pace','Taking my time') ?>><span class="fm-option-copy"><strong>Taking my time</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="connection_pace" data-answer-key="connection_pace" data-optional-answer value="Seeing how things unfold"<?= $isChecked('connection_pace','Seeing how things unfold') ?>><span class="fm-option-copy"><strong>Seeing how things unfold</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="connection_pace" data-answer-key="connection_pace" data-optional-answer value="Making plans when ready"<?= $isChecked('connection_pace','Making plans when ready') ?>><span class="fm-option-copy"><strong>Making plans when ready</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>
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
                
<details class="fm-followups" data-conditional data-when-key="social_activity" data-when-values="Quiet moments|Somewhere in the middle|Always up for an activity" hidden>
 <summary>Shared time <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">How do you most enjoy spending time with others?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="hangout style">
  <label class="fm-option"><input type="radio" name="hangout_style" data-answer-key="hangout_style" data-optional-answer value="One-on-one chats"<?= $isChecked('hangout_style','One-on-one chats') ?>><span class="fm-option-copy"><strong>One-on-one chats</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="hangout_style" data-answer-key="hangout_style" data-optional-answer value="Small groups"<?= $isChecked('hangout_style','Small groups') ?>><span class="fm-option-copy"><strong>Small groups</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="hangout_style" data-answer-key="hangout_style" data-optional-answer value="An activity together"<?= $isChecked('hangout_style','An activity together') ?>><span class="fm-option-copy"><strong>An activity together</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="hangout_style" data-answer-key="hangout_style" data-optional-answer value="A bit of everything"<?= $isChecked('hangout_style','A bit of everything') ?>><span class="fm-option-copy"><strong>A bit of everything</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

                <details class="fm-followups" data-conditional data-when-key="communication_style" data-when-values="Messages first|Face-to-face|A bit of both" hidden>
                    <summary>Breaking the ice <span class="fm-optional-tag">Optional</span></summary>
                    <div class="fm-followup-body">
                        <p class="fm-followup-question">What makes a first conversation easier?</p>
                        <fieldset class="fm-choices fm-extra-choices" aria-label="Conversation starters">
                            <?php foreach ([
                                'A shared hobby',
                                'A funny story',
                                'Campus life',
                                'A thoughtful question',
                                'Whatever comes naturally',
                            ] as $topic): ?>
                                <label class="fm-option">
                                    <input type="radio" name="conversation_opener" data-answer-key="conversation_opener" data-optional-answer value="<?= e($topic) ?>"<?= $isChecked('conversation_opener', $topic) ?>>
                                    <span class="fm-option-copy"><strong><?= e($topic) ?></strong></span>
                                    <span class="fm-select-indicator" aria-hidden="true"></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <button type="button" class="fm-clear-extra" data-clear-extra>Clear this optional answer</button>
                    </div>
                </details>
            </div>

            <div class="fm-step" data-step="2" hidden>
                <div class="fm-question">
                    <h2>What brings your world to life?</h2>
                    <p>Pick a few interests that you’d enjoy sharing or talking about.</p>
                </div>
                <fieldset class="fm-choices fm-interests" aria-label="Shared interests">
                    <?php foreach ([
                        'Music', 'Reading', 'Photography', 'Sport', 'Art', 'Gaming', 'Campus events', 'Travel',
                        'Food & cooking', 'Films & series', 'Technology', 'Community'
                    ] as $interest): ?>
                        <label class="fm-option">
                            <input type="checkbox" name="interests[]" data-answer-key="interests" value="<?= e($interest) ?>"<?= $isChecked('interests',$interest) ?>>
                            <span class="fm-icon" aria-hidden="true">✧</span>
                            <span class="fm-option-copy"><strong><?= e($interest) ?></strong></span>
                            <span class="fm-select-indicator" aria-hidden="true"></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="fm-optional-help">Want to go a little deeper? Expand any theme that interests you, or continue with the four steps. Every extra question is optional.</p>
                
<details class="fm-followups" data-conditional data-when-key="interests" data-when-values="Sport" hidden>
 <summary>Sports <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">Which sports do you enjoy?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="sports">
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Football"<?= $isChecked('sports','Football') ?>><span class="fm-option-copy"><strong>Football</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Basketball"<?= $isChecked('sports','Basketball') ?>><span class="fm-option-copy"><strong>Basketball</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Cricket"<?= $isChecked('sports','Cricket') ?>><span class="fm-option-copy"><strong>Cricket</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Rugby"<?= $isChecked('sports','Rugby') ?>><span class="fm-option-copy"><strong>Rugby</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Tennis"<?= $isChecked('sports','Tennis') ?>><span class="fm-option-copy"><strong>Tennis</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Athletics"<?= $isChecked('sports','Athletics') ?>><span class="fm-option-copy"><strong>Athletics</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="sports[]" data-answer-key="sports" data-optional-answer value="Other"<?= $isChecked('sports','Other') ?>><span class="fm-option-copy"><strong>Other</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
<div class="fm-mini-question"><p class="fm-followup-question">Do you prefer playing or watching?</p><fieldset class="fm-choices fm-extra-choices" aria-label="sport style">
  <label class="fm-option"><input type="radio" name="sport_style" data-answer-key="sport_style" data-optional-answer value="Playing"<?= $isChecked('sport_style','Playing') ?>><span class="fm-option-copy"><strong>Playing</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="sport_style" data-answer-key="sport_style" data-optional-answer value="Watching"<?= $isChecked('sport_style','Watching') ?>><span class="fm-option-copy"><strong>Watching</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="sport_style" data-answer-key="sport_style" data-optional-answer value="Both"<?= $isChecked('sport_style','Both') ?>><span class="fm-option-copy"><strong>Both</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset></div>
<div class="fm-mini-question" data-conditional data-when-key="sports" data-when-values="Football" hidden>
<label for="fm-football-team" class="fm-followup-question">Which football team do you support?</label>
<p class="fm-optional-help">Local or international. You can leave this blank.</p>
<input type="text" id="fm-football-team" class="fm-extra-input" data-answer-key="football_team" data-optional-answer maxlength="60" autocomplete="off" value="<?= e($savedAnswers['football_team'] ?? '') ?>" placeholder="Your team (optional)">
</div>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

<details class="fm-followups" data-conditional data-when-key="interests" data-when-values="Music" hidden>
 <summary>Music <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">What sounds do you enjoy?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="music styles">
  <label class="fm-option"><input type="checkbox" name="music_styles[]" data-answer-key="music_styles" data-optional-answer value="Amapiano"<?= $isChecked('music_styles','Amapiano') ?>><span class="fm-option-copy"><strong>Amapiano</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="music_styles[]" data-answer-key="music_styles" data-optional-answer value="Afrobeats"<?= $isChecked('music_styles','Afrobeats') ?>><span class="fm-option-copy"><strong>Afrobeats</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="music_styles[]" data-answer-key="music_styles" data-optional-answer value="Hip-hop"<?= $isChecked('music_styles','Hip-hop') ?>><span class="fm-option-copy"><strong>Hip-hop</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="music_styles[]" data-answer-key="music_styles" data-optional-answer value="Gospel"<?= $isChecked('music_styles','Gospel') ?>><span class="fm-option-copy"><strong>Gospel</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="music_styles[]" data-answer-key="music_styles" data-optional-answer value="Jazz"<?= $isChecked('music_styles','Jazz') ?>><span class="fm-option-copy"><strong>Jazz</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="music_styles[]" data-answer-key="music_styles" data-optional-answer value="Other"<?= $isChecked('music_styles','Other') ?>><span class="fm-option-copy"><strong>Other</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

<details class="fm-followups" data-conditional data-when-key="interests" data-when-values="Reading" hidden>
 <summary>Books and stories <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">What are you most likely to read?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="reading styles">
  <label class="fm-option"><input type="checkbox" name="reading_styles[]" data-answer-key="reading_styles" data-optional-answer value="Fiction"<?= $isChecked('reading_styles','Fiction') ?>><span class="fm-option-copy"><strong>Fiction</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="reading_styles[]" data-answer-key="reading_styles" data-optional-answer value="Non-fiction"<?= $isChecked('reading_styles','Non-fiction') ?>><span class="fm-option-copy"><strong>Non-fiction</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="reading_styles[]" data-answer-key="reading_styles" data-optional-answer value="Poetry"<?= $isChecked('reading_styles','Poetry') ?>><span class="fm-option-copy"><strong>Poetry</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="reading_styles[]" data-answer-key="reading_styles" data-optional-answer value="Comics"<?= $isChecked('reading_styles','Comics') ?>><span class="fm-option-copy"><strong>Comics</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="reading_styles[]" data-answer-key="reading_styles" data-optional-answer value="Other"<?= $isChecked('reading_styles','Other') ?>><span class="fm-option-copy"><strong>Other</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

<details class="fm-followups" data-conditional data-when-key="interests" data-when-values="Gaming" hidden>
 <summary>Gaming <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">What games do you enjoy?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="gaming styles">
  <label class="fm-option"><input type="checkbox" name="gaming_styles[]" data-answer-key="gaming_styles" data-optional-answer value="Video games"<?= $isChecked('gaming_styles','Video games') ?>><span class="fm-option-copy"><strong>Video games</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="gaming_styles[]" data-answer-key="gaming_styles" data-optional-answer value="Mobile games"<?= $isChecked('gaming_styles','Mobile games') ?>><span class="fm-option-copy"><strong>Mobile games</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="gaming_styles[]" data-answer-key="gaming_styles" data-optional-answer value="Board games"<?= $isChecked('gaming_styles','Board games') ?>><span class="fm-option-copy"><strong>Board games</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="gaming_styles[]" data-answer-key="gaming_styles" data-optional-answer value="Party games"<?= $isChecked('gaming_styles','Party games') ?>><span class="fm-option-copy"><strong>Party games</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="gaming_styles[]" data-answer-key="gaming_styles" data-optional-answer value="Other"<?= $isChecked('gaming_styles','Other') ?>><span class="fm-option-copy"><strong>Other</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

<details class="fm-followups" data-conditional data-when-key="interests" data-when-values="Art|Photography" hidden>
 <summary>Creative interests <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">Which creative activities interest you?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="creative styles">
  <label class="fm-option"><input type="checkbox" name="creative_styles[]" data-answer-key="creative_styles" data-optional-answer value="Drawing and design"<?= $isChecked('creative_styles','Drawing and design') ?>><span class="fm-option-copy"><strong>Drawing and design</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="creative_styles[]" data-answer-key="creative_styles" data-optional-answer value="Taking photos"<?= $isChecked('creative_styles','Taking photos') ?>><span class="fm-option-copy"><strong>Taking photos</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="creative_styles[]" data-answer-key="creative_styles" data-optional-answer value="Making things"<?= $isChecked('creative_styles','Making things') ?>><span class="fm-option-copy"><strong>Making things</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="creative_styles[]" data-answer-key="creative_styles" data-optional-answer value="Enjoying art"<?= $isChecked('creative_styles','Enjoying art') ?>><span class="fm-option-copy"><strong>Enjoying art</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

<details class="fm-followups" data-conditional data-when-key="interests" data-when-values="Campus events|Travel" hidden>
 <summary>Things to do <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">Which outings sound interesting?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="outing styles">
  <label class="fm-option"><input type="checkbox" name="outing_styles[]" data-answer-key="outing_styles" data-optional-answer value="Campus events"<?= $isChecked('outing_styles','Campus events') ?>><span class="fm-option-copy"><strong>Campus events</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="outing_styles[]" data-answer-key="outing_styles" data-optional-answer value="Day trips"<?= $isChecked('outing_styles','Day trips') ?>><span class="fm-option-copy"><strong>Day trips</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="outing_styles[]" data-answer-key="outing_styles" data-optional-answer value="Live music"<?= $isChecked('outing_styles','Live music') ?>><span class="fm-option-copy"><strong>Live music</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="checkbox" name="outing_styles[]" data-answer-key="outing_styles" data-optional-answer value="Quiet outings"<?= $isChecked('outing_styles','Quiet outings') ?>><span class="fm-option-copy"><strong>Quiet outings</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>

                <?php
                // Extra themes are optional and appear only after the matching interest is selected.
                // They use the existing answer table, without changing the four Figma steps.
                $extraInterestThemes = [
                    [
                        'interest' => 'Food & cooking',
                        'heading' => 'Food and cooking',
                        'question' => 'What do you enjoy around food?',
                        'key' => 'food_styles',
                        'options' => ['Trying new cafés', 'Home cooking', 'Local dishes', 'Baking', 'Street food', 'New recipes'],
                    ],
                    [
                        'interest' => 'Films & series',
                        'heading' => 'Films and series',
                        'question' => 'What would you enjoy watching?',
                        'key' => 'screen_styles',
                        'options' => ['Comedy', 'Drama', 'Documentaries', 'Animation', 'Science fiction', 'Mystery'],
                    ],
                    [
                        'interest' => 'Technology',
                        'heading' => 'Technology and ideas',
                        'question' => 'What catches your interest?',
                        'key' => 'tech_styles',
                        'options' => ['Building apps', 'Design', 'AI', 'Gadgets', 'Problem solving', 'Learning to code'],
                    ],
                    [
                        'interest' => 'Community',
                        'heading' => 'Community and campus',
                        'question' => 'How do you like getting involved?',
                        'key' => 'community_styles',
                        'options' => ['Volunteering', 'Student clubs', 'Mentoring', 'Campus projects', 'Community outreach'],
                    ],
                ];
                ?>
                <?php foreach ($extraInterestThemes as $theme): ?>
                    <details class="fm-followups" data-conditional data-when-key="interests" data-when-values="<?= e($theme['interest']) ?>" hidden>
                        <summary><?= e($theme['heading']) ?> <span class="fm-optional-tag">Optional</span></summary>
                        <div class="fm-followup-body">
                            <p class="fm-followup-question"><?= e($theme['question']) ?></p>
                            <fieldset class="fm-choices fm-extra-choices" aria-label="<?= e($theme['heading']) ?>">
                                <?php foreach ($theme['options'] as $option): ?>
                                    <label class="fm-option">
                                        <input type="checkbox" name="<?= e($theme['key']) ?>[]" data-answer-key="<?= e($theme['key']) ?>" data-optional-answer value="<?= e($option) ?>"<?= $isChecked($theme['key'], $option) ?>>
                                        <span class="fm-option-copy"><strong><?= e($option) ?></strong></span>
                                        <span class="fm-select-indicator" aria-hidden="true"></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
                        </div>
                    </details>
                <?php endforeach; ?>
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
                
<details class="fm-followups" data-conditional data-when-key="availability" data-when-values="Between lectures|Weekday evenings|Weekends|It varies" hidden>
 <summary>Making plans <span class="fm-optional-tag">Optional</span></summary>
 <div class="fm-followup-body"><p class="fm-followup-question">How do you like to arrange time with people?</p>
 <fieldset class="fm-choices fm-extra-choices" aria-label="planning style">
  <label class="fm-option"><input type="radio" name="planning_style" data-answer-key="planning_style" data-optional-answer value="Planning ahead"<?= $isChecked('planning_style','Planning ahead') ?>><span class="fm-option-copy"><strong>Planning ahead</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="planning_style" data-answer-key="planning_style" data-optional-answer value="Keeping plans flexible"<?= $isChecked('planning_style','Keeping plans flexible') ?>><span class="fm-option-copy"><strong>Keeping plans flexible</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
  <label class="fm-option"><input type="radio" name="planning_style" data-answer-key="planning_style" data-optional-answer value="A mix of both"<?= $isChecked('planning_style','A mix of both') ?>><span class="fm-option-copy"><strong>A mix of both</strong></span><span class="fm-select-indicator" aria-hidden="true"></span></label>
</fieldset>
 <button type="button" class="fm-clear-extra" data-clear-extra>Clear these optional answers</button>
 </div>
</details>
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
    <?php endif; ?>

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
    <?php if (!$showMatchingMap): ?><noscript><p class="fm-error">JavaScript is required to complete the step-by-step questionnaire. Your existing account information is unchanged.</p></noscript><?php endif; ?>
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
<?php if (!$showMatchingMap): ?>
<script src="<?= e(asset_path('js/find-match.js')) ?>"></script>
<script src="<?= e(asset_path('js/find-match-delight.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset_path('js/find-match-map.js')) ?>"></script>
<script src="<?= e(asset_path('js/find-match-full-profile.js')) ?>"></script>
</body>
</html>
