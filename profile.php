<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/upload_validator.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/profile_personality.php';

require_login();

$userId = (int) $_SESSION['user_id'];
$error = null;
$success = null;
$csrfToken = null;
$profile = get_profile($pdo, $userId);
$preferences = get_preferences($pdo, $userId);
$user = find_user_by_id($pdo, $userId);
$personalityEnabled = bushisa_personality_available($pdo);
$personalityDetails = bushisa_personality_read($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_personality'])) {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif (!$personalityEnabled) {
        $error = 'The optional profile details table has not been installed yet.';
    } else {
        try {
            $details = bushisa_personality_validate($_POST);
            $pdo->beginTransaction();
            bushisa_personality_save($pdo, $userId, $details);
            $pdo->commit();
            $personalityDetails = $details;
            $success = 'Your conversation prompt and interests have been saved.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_to_file('Profile details update failed for user ' . $userId . ': ' . $exception->getMessage(), 'ERROR');
            $error = 'Unable to save conversation details right now.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $existingPreferences = $preferences ?? [];

        $displayName = trim(str_replace("\0", '', (string) ($_POST['display_name'] ?? '')));
        $bio = trim(str_replace("\0", '', (string) ($_POST['bio'] ?? '')));
        $faculty = trim(str_replace("\0", '', (string) ($_POST['faculty'] ?? '')));
        $yearOfStudy = trim(str_replace("\0", '', (string) ($_POST['year_of_study'] ?? '')));
        $preferredGenderRaw = trim(str_replace("\0", '', (string) ($_POST['preferred_gender'] ?? '')));
        $preferredFaculty = trim(str_replace("\0", '', (string) ($_POST['preferred_faculty'] ?? '')));
        $ageRangeMin = clean_int($_POST['age_range_min'] ?? null);
        $ageRangeMax = clean_int($_POST['age_range_max'] ?? null);

        $profileData = [];
        if ($displayName !== '') {
            $profileData['display_name'] = $displayName;
        }

        $profileData['bio'] = $bio;

        if ($faculty !== '') {
            $profileData['faculty'] = $faculty;
        }

        if ($yearOfStudy !== '') {
            $profileData['year_of_study'] = $yearOfStudy;
        }

        $photoFile = $_FILES['profile_photo'] ?? $_FILES['photo'] ?? null;
        if (is_array($photoFile) && isset($photoFile['error']) && (int) $photoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            $validation = validate_upload($photoFile);
            if (!$validation['valid']) {
                $error = $validation['error'] ?? 'Invalid upload.';
            } else {
                $savedPath = save_upload($photoFile, $userId);
                if ($savedPath === false) {
                    $error = 'Unable to save uploaded photo.';
                } else {
                    strip_exif(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $savedPath));
                    $profileData['profile_photo_path'] = $savedPath;
                }
            }
        }

        $preferencesData = [];
        if ($preferredGenderRaw !== '') {
            $preferredGender = clean_enum($preferredGenderRaw, ['male', 'female']);
            if ($preferredGender === null) {
                $error = 'Please choose a valid preferred gender.';
            } else {
                $preferencesData['preferred_gender'] = $preferredGender;
            }
        }

        if ($error === null && $ageRangeMin !== null) {
            $preferencesData['age_range_min'] = $ageRangeMin;
        }

        if ($error === null && $ageRangeMax !== null) {
            $preferencesData['age_range_max'] = $ageRangeMax;
        }

        if ($error === null && $preferredFaculty !== '') {
            $preferencesData['preferred_faculty'] = $preferredFaculty;
        }

        if ($error === null) {
            $effectiveMin = $preferencesData['age_range_min'] ?? ($existingPreferences['age_range_min'] ?? null);
            $effectiveMax = $preferencesData['age_range_max'] ?? ($existingPreferences['age_range_max'] ?? null);

            if ($effectiveMin !== null && $effectiveMax !== null && $effectiveMin > $effectiveMax) {
                $error = 'Minimum age cannot be greater than maximum age.';
            }
        }

        if ($error === null) {
            try {
                $pdo->beginTransaction();

                if (!update_profile($pdo, $userId, $profileData)) {
                    throw new RuntimeException('Unable to update profile.');
                }

                if (!update_preferences($pdo, $userId, $preferencesData)) {
                    throw new RuntimeException('Unable to update preferences.');
                }

                $pdo->commit();

                log_action($pdo, $userId, 'profile_update', $_SERVER['REMOTE_ADDR'] ?? null);
                log_to_file('Profile updated for user ' . $userId, 'INFO');

                $success = 'Profile updated successfully.';
                $profile = get_profile($pdo, $userId);
                $preferences = get_preferences($pdo, $userId);
            } catch (Throwable $throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                log_to_file('Profile update failed for user ' . $userId . ': ' . $throwable->getMessage(), 'ERROR');
                $error = 'Unable to update profile right now.';
            }
        }
    }
}

$csrfToken = generate_csrf_token();

$faculties = [
    'Applied Sciences',
    'Built Environment',
    'Communication & Information Science',
    'Commerce & Law',
    'Industrial Technology',
    'Medicine',
    'Science & Technology Education',
];
$years = ['Part 1', 'Part 2', 'Part 3', 'Part 4', 'Postgrad'];
$photoPath = isset($profile['profile_photo_path']) && is_string($profile['profile_photo_path']) ? $profile['profile_photo_path'] : '';
$photoFile = $photoPath !== '' ? __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $photoPath) : '';
$photoBytes = $photoFile !== '' && is_file($photoFile) ? filesize($photoFile) : 0;
$quotaBytes = 5 * 1024 * 1024;
$quotaPercent = $photoBytes > 0 ? min(100, (int) round(($photoBytes / $quotaBytes) * 100)) : 0;

$profileName = trim((string) ($profile['display_name'] ?? ''));
$profileInitial = $profileName !== ''
    ? (function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($profileName, 0, 1)) : strtoupper(substr($profileName, 0, 1)))
    : 'B';
$hasBio = trim((string) ($profile['bio'] ?? '')) !== '';
$hasPrompt = trim((string) ($personalityDetails['conversation_prompt'] ?? '')) !== '';
$hasInterests = $personalityDetails['interests'] !== [];
$isPhotoSet = $photoFile !== '' && is_file($photoFile);
$displayPhotoUrl = $isPhotoSet ? profile_photo_url($photoPath) : null;
$hasPreferenceSetup = $preferences !== null;
$promptChoices = [
    'What helps you unwind after lectures?',
    'Which campus food spot deserves the hype?',
    'What’s your exam-season survival trick?',
];
$navItems = [
    ['href' => 'discover.php', 'label' => 'Home', 'icon' => 'home', 'active' => false],
    ['href' => 'matches.php', 'label' => 'Find Match', 'icon' => 'nav-heart', 'active' => false],
    ['href' => 'confessions.php', 'label' => 'Confessions', 'icon' => 'confessions', 'active' => false],
    ['href' => 'profile.php', 'label' => 'You', 'icon' => 'you', 'active' => true],
];
$unreadCount = 0;
try {
    if (operational_table_exists($pdo, 'notifications')) {
        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = :user AND read_at IS NULL');
        $countStatement->execute([':user' => $userId]);
        $unreadCount = (int) $countStatement->fetchColumn();
    }
} catch (Throwable $exception) {
    error_log('Profile notification count unavailable: ' . $exception->getMessage());
}

render_document_head('You', $csrfToken, $userId);
?>
<body class="page-you-figma bushisa-app" id="top">
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
            <a class="round-button account-letter" href="#profile-editor" aria-label="Your profile"><?= e($profileInitial) ?></a>
        </div>
    </div>
</header>
<?php render_notification_panel(); ?>

<main class="you-main">
    <div class="you-intro">
        <span class="you-eyebrow">START WITH YOU</span>
        <h1>A little more you.</h1>
        <p>Build a profile that feels natural, not perfect. A few details can make the first hello easier.</p>
    </div>
    <?php render_flash($error, $success); ?>

    <div class="you-workspace">
        <div class="you-editor-column">
            <section class="you-editor-card" id="profile-editor" aria-labelledby="you-preview-title">
                <div class="you-card-heading">
                    <span id="you-preview-title">YOUR PROFILE PREVIEW</span>
                    <span class="you-draft">Your draft</span>
                </div>
                <div class="you-identity">
                    <?php if ($displayPhotoUrl !== null): ?>
                        <img class="you-avatar" src="<?= e($displayPhotoUrl) ?>" alt="Current profile photo">
                    <?php else: ?>
                        <span class="you-avatar you-avatar--initial" aria-hidden="true"><?= e($profileInitial) ?></span>
                    <?php endif; ?>
                    <div>
                        <h2><?= str_replace(['_', '-'], ['_<wbr>', '-<wbr>'], e($profileName !== '' ? $profileName : 'Your profile')) ?></h2>
                        <p><?= e($profile['faculty'] ?? 'Faculty not set') ?> · <?= e($profile['year_of_study'] ?? 'Year not set') ?></p>
                    </div>
                </div>

                <div class="you-divider"></div>

                <form method="post" action="profile.php#profile-editor" enctype="multipart/form-data" class="you-main-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <section class="you-form-section" aria-labelledby="you-photos-title">
                        <h3 id="you-photos-title">Your photos</h3>
                        <div class="you-photo-slots">
                            <label class="you-photo-slot you-photo-slot--main">
                                <?php if ($displayPhotoUrl !== null): ?>
                                    <img class="you-photo-slot__preview" src="<?= e($displayPhotoUrl) ?>" alt="" aria-hidden="true">
                                <?php else: ?>
                                    <img class="you-photo-slot__icon" src="<?= e(asset_path('preview/assets/icons/camera.svg')) ?>" alt="" aria-hidden="true">
                                <?php endif; ?>
                                <span>Main photo</span>
                                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-label="Choose a main profile photo">
                            </label>
                            <div class="you-photo-slot you-photo-slot--unavailable" aria-label="Second photo slot, coming soon">
                                <span class="you-photo-plus" aria-hidden="true">—</span><span>Coming soon</span>
                            </div>
                            <div class="you-photo-slot you-photo-slot--unavailable" aria-label="Third photo slot, coming soon">
                                <span class="you-photo-plus" aria-hidden="true">—</span><span>Coming soon</span>
                            </div>
                        </div>
                        <p class="you-photo-meta"><?= e(number_format((float) $photoBytes / 1048576, 2)) ?> MB used of 5 MB. Main photo <?= $isPhotoSet ? 'added' : 'not added' ?>.</p>
                        <p class="you-inline-note"><span aria-hidden="true">ⓘ</span> Only one profile photo is supported by the current backend; extra slots are in testing.</p>
                    </section>

                    <label class="you-field" for="you-bio">
                        <span>Short bio</span>
                        <textarea id="you-bio" name="bio" maxlength="500" rows="4" placeholder="What should someone know about you?"><?= e($profile['bio'] ?? '') ?></textarea>
                        <small>A few honest lines are enough.</small>
                    </label>
                    <div class="you-profile-details">
                        <label class="you-field" for="you-display-name">
                            <span>Display name</span>
                            <input id="you-display-name" type="text" name="display_name" value="<?= e($profile['display_name'] ?? '') ?>" maxlength="30" required>
                        </label>
                        <label class="you-field" for="you-faculty">
                            <span>Faculty</span>
                            <select id="you-faculty" name="faculty">
                                <option value="">Keep current</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= e($faculty) ?>" <?= ($profile['faculty'] ?? '') === $faculty ? 'selected' : '' ?>><?= e($faculty) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="you-field" for="you-year">
                            <span>Year of study</span>
                            <select id="you-year" name="year_of_study">
                                <option value="">Keep current</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?= e($year) ?>" <?= ($profile['year_of_study'] ?? '') === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <button class="you-save-button" type="submit">Save profile <span aria-hidden="true">✓</span></button>
                </form>

                <form id="conversation-and-interests" method="post" action="profile.php#conversation-and-interests" class="you-personality-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="save_personality" value="1">
                    <label class="you-field" for="you-interests">
                        <span>Interests</span>
                        <input id="you-interests" name="interests" type="text" maxlength="350"
                               placeholder="+ Choose your interests"
                               value="<?= e(implode(', ', $personalityDetails['interests'])) ?>" <?= !$personalityEnabled ? 'disabled' : '' ?>>
                        <small>Choose up to five interests, separated by commas.</small>
                    </label>

                    <div class="you-form-section">
                        <h3>Conversation prompts</h3>
                        <p class="you-muted">Choose an optional conversation starter. One question and answer can currently be saved.</p>
                        <div class="you-prompt-choices" aria-label="Choose a conversation starter">
                            <?php foreach ($promptChoices as $choice): ?>
                                <button class="you-prompt-option" type="button" data-prompt-choice="<?= e($choice) ?>" <?= !$personalityEnabled ? 'disabled' : '' ?>><?= e($choice) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <label class="you-field" for="you-prompt">
                        <span>Your conversation question</span>
                        <textarea id="you-prompt" name="conversation_prompt" maxlength="160" rows="2"
                                  placeholder="Choose a prompt above or write your own." <?= !$personalityEnabled ? 'disabled' : '' ?>><?= e($personalityDetails['conversation_prompt']) ?></textarea>
                    </label>
                    <label class="you-field" for="you-answer">
                        <span>Short answer</span>
                        <textarea id="you-answer" name="conversation_answer" maxlength="240" rows="3"
                                  placeholder="Add a short answer…" <?= !$personalityEnabled ? 'disabled' : '' ?>><?= e($personalityDetails['conversation_answer']) ?></textarea>
                        <small>Your answer needs a question. Keep it short and personal.</small>
                    </label>
                    <?php if (!$personalityEnabled): ?>
                        <p class="you-inline-note">Conversation details will become editable after the optional database migration.</p>
                    <?php endif; ?>
                    <button class="you-save-button" type="submit" <?= !$personalityEnabled ? 'disabled' : '' ?>>Save conversation details <span aria-hidden="true">✓</span></button>
                </form>
            </section>

            <details class="you-settings-expander" id="preferences">
                <summary>Discovery preferences <span>Review or update your filters</span></summary>
                <div class="you-settings-inner">
                    <p>These filters decide which eligible profiles appear in your Home feed.</p>
                    <form method="post" action="profile.php#preferences" class="you-preferences-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="display_name" value="<?= e($profile['display_name'] ?? '') ?>">
                        <input type="hidden" name="bio" value="<?= e($profile['bio'] ?? '') ?>">
                        <input type="hidden" name="faculty" value="<?= e($profile['faculty'] ?? '') ?>">
                        <input type="hidden" name="year_of_study" value="<?= e($profile['year_of_study'] ?? '') ?>">
                        <label class="you-field"><span>Preferred gender</span>
                            <select name="preferred_gender">
                                <option value="">Keep current</option>
                                <option value="female" <?= ($preferences['preferred_gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                <option value="male" <?= ($preferences['preferred_gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                            </select>
                        </label>
                        <div class="you-form-row">
                            <label class="you-field"><span>Minimum age (18+)</span>
                                <input type="number" name="age_range_min" min="18" max="99" value="<?= e($preferences['age_range_min'] ?? 18) ?>">
                            </label>
                            <label class="you-field"><span>Maximum age</span>
                                <input type="number" name="age_range_max" min="18" max="99" value="<?= e($preferences['age_range_max'] ?? 30) ?>">
                            </label>
                        </div>
                        <label class="you-field"><span>Preferred faculty</span>
                            <select name="preferred_faculty">
                                <option value="Any">Any</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= e($faculty) ?>" <?= ($preferences['preferred_faculty'] ?? '') === $faculty ? 'selected' : '' ?>><?= e($faculty) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="you-save-button" type="submit">Save discovery preferences</button>
                    </form>
                </div>
            </details>
        </div>

        <aside class="you-sidebar" aria-label="Profile setup and safety">
            <section class="you-checklist" aria-labelledby="you-checklist-title">
                <h2 id="you-checklist-title">Ready for your first hello?</h2>
                <p>Your next steps before discovery. Student verification and age eligibility still need proper enforcement.</p>
                <div class="you-task">
                    <span class="you-task__check <?= $hasBio ? 'is-done' : '' ?>" aria-hidden="true"><?= $hasBio ? '✓' : '' ?></span>
                    <div><strong>Write a short bio</strong><p>Let someone get a sense of you.</p><a href="#you-bio"><?= $hasBio ? 'Review bio' : 'Add bio' ?> →</a></div>
                </div>
                <div class="you-task">
                    <span class="you-task__check <?= $hasPrompt && $hasInterests ? 'is-done' : '' ?>" aria-hidden="true"><?= $hasPrompt && $hasInterests ? '✓' : '' ?></span>
                    <div><strong>Add interests &amp; a prompt</strong><p>Give a conversation somewhere to start.</p><a href="#conversation-and-interests"><?= $hasPrompt && $hasInterests ? 'Review answers' : 'Edit profile' ?> →</a></div>
                </div>
                <div class="you-task">
                    <span class="you-task__check" aria-hidden="true"></span>
                    <div><strong>Verify student status</strong><p>Verification is not confirmed by this development build.</p><span class="you-task__unavailable">Not yet supported</span></div>
                </div>
                <div class="you-task">
                    <span class="you-task__check" aria-hidden="true"></span>
                    <div><strong>Confirm you're 18+</strong><p>Required before any real access to adult matching features.</p><span class="you-task__unavailable">Age verification pending</span></div>
                </div>
                <div class="you-task">
                    <span class="you-task__check <?= $isPhotoSet ? 'is-done' : '' ?>" aria-hidden="true"><?= $isPhotoSet ? '✓' : '' ?></span>
                    <div><strong>Add a photo</strong><p>Only the main photo upload currently works.</p><a href="#you-photos-title"><?= $isPhotoSet ? 'View photo' : 'Choose photo' ?> →</a></div>
                </div>
            </section>

            <section class="you-privacy" aria-labelledby="you-privacy-title">
                <h2 id="you-privacy-title">Privacy &amp; account</h2>
                <div class="you-privacy-row you-privacy-row--unavailable"><span class="you-privacy-icon" aria-hidden="true">◉</span>
                    <div><strong>Profile visibility</strong><p>Visibility controls are not available yet.</p></div><span class="you-feature-status">Coming soon</span>
                </div>
                <div class="you-privacy-row you-privacy-row--unavailable"><span class="you-privacy-icon" aria-hidden="true">Ⅱ</span>
                    <div><strong>Pause profile</strong><p>Temporarily hiding your profile is not available yet.</p></div><span class="you-feature-status">Coming soon</span>
                </div>
                <a class="you-privacy-row" href="community_guidelines.php"><span class="you-privacy-icon" aria-hidden="true">♧</span>
                    <div><strong>Blocking &amp; reporting</strong><p>Read safety and community guidance.</p></div><span aria-hidden="true">›</span>
                </a>
                <a class="you-privacy-row" href="#account"><span class="you-privacy-icon" aria-hidden="true">⚙</span>
                    <div><strong>Account settings</strong><p>Account details and deletion options.</p></div><span aria-hidden="true">›</span>
                </a>
                <p class="you-privacy-note">Some privacy and safety controls are still being integrated.</p>
            </section>
            <details class="you-settings-expander" id="account">
                <summary>Account &amp; notifications <span>See available controls</span></summary>
                <div class="you-settings-inner">
                    <label class="you-field"><span>Student email (read-only)</span><input type="email" value="<?= e($user['email'] ?? $user['student_email'] ?? '') ?>" readonly></label>
                    <p class="you-muted">Notification preferences are still in testing. No browser push permissions are requested here.</p>
                    <div class="you-account-actions"><a href="privacy_policy.php">Privacy policy</a><a href="logout.php">Log out</a><a href="account_delete.php">Delete account</a></div>
                </div>
            </details>
        </aside>
    </div>

    <div class="you-beta"><span>BETA</span><p>Profile saving, media and account controls are still being tested. Don't add sensitive personal information during development.</p></div>
</main>

<footer class="site-footer">
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
<script src="<?= e(asset_path('js/you-profile.js')) ?>"></script>
</body>
</html>
