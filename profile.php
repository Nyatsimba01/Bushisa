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

require_login();

$userId = (int) $_SESSION['user_id'];
$error = null;
$success = null;
$csrfToken = null;
$profile = get_profile($pdo, $userId);
$preferences = get_preferences($pdo, $userId);
$user = find_user_by_id($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

render_page_shell_start('you', 'You', $csrfToken, $userId, false, 'Profile, privacy and account settings');
?>
<?php render_flash($error, $success); ?>

<section class="settings-grid">
    <article class="section-card">
        <h2>Profile summary</h2>
        <div class="profile-summary">
            <?= render_avatar($photoPath, (string) ($profile['display_name'] ?? 'You')) ?>
            <div>
                <h3><?= e($profile['display_name'] ?? 'Your profile') ?></h3>
                <p class="muted"><?= e($profile['faculty'] ?? 'Faculty not set') ?> · <?= e($profile['year_of_study'] ?? 'Year not set') ?></p>
            </div>
        </div>
    </article>

    <article class="section-card">
        <h2>Shared image quota</h2>
        <div class="quota-meter" style="--quota: <?= e($quotaPercent) ?>%">
            <div class="quota-meter__bar"><span class="quota-meter__fill"></span></div>
            <p class="quota-meter__label"><?= e(number_format((float) $photoBytes / 1048576, 2)) ?> MB used of 5 MB. Backend currently enforces one upload, not the required three-slot aggregate quota.</p>
        </div>
    </article>
</section>

<section class="section-card" aria-labelledby="media-title">
    <h2 id="media-title">Images</h2>
    <div class="card-grid">
        <div class="image-slot">
            <div>
                <strong>Profile photo</strong>
                <p class="muted">Active backend slot</p>
            </div>
            <?= render_avatar($photoPath, (string) ($profile['display_name'] ?? 'Profile photo')) ?>
        </div>
        <div class="image-slot">
            <div>
                <strong>Discovery photo 1</strong>
                <p class="muted">Needs schema support</p>
            </div>
            <button class="button-secondary" type="button" disabled>Add</button>
        </div>
        <div class="image-slot">
            <div>
                <strong>Discovery photo 2</strong>
                <p class="muted">Needs schema support</p>
            </div>
            <button class="button-secondary" type="button" disabled>Add</button>
        </div>
    </div>
</section>

<section class="section-card" aria-labelledby="edit-profile-title">
    <h2 id="edit-profile-title">Edit profile</h2>
    <form class="form-grid" method="post" action="profile.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <label class="field">
            <span>Verified student email</span>
            <input type="email" value="<?= e($user['email'] ?? $user['student_email'] ?? '') ?>" readonly>
            <p class="field-help">This is read-only in the UI and must remain server-enforced.</p>
        </label>

        <label class="field">
            <span>Display name</span>
            <input type="text" name="display_name" value="<?= e($profile['display_name'] ?? '') ?>" maxlength="30" required>
        </label>

        <label class="field">
            <span>Bio</span>
            <textarea name="bio" maxlength="500" placeholder="Share a little context for discovery."><?= e($profile['bio'] ?? '') ?></textarea>
        </label>

        <label class="field">
            <span>Faculty</span>
            <select name="faculty">
                <option value="">Keep current</option>
                <?php foreach ($faculties as $faculty): ?>
                    <option value="<?= e($faculty) ?>" <?= ($profile['faculty'] ?? '') === $faculty ? 'selected' : '' ?>><?= e($faculty) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Year of study</span>
            <select name="year_of_study">
                <option value="">Keep current</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?= e($year) ?>" <?= ($profile['year_of_study'] ?? '') === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Replace profile photo</span>
            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
            <p class="field-help">The existing image remains until the server accepts the replacement.</p>
        </label>

        <button class="button" type="submit">Save profile</button>
    </form>
</section>

<section class="section-card" id="preferences" aria-labelledby="preferences-title">
    <h2 id="preferences-title">Discovery preferences</h2>
    <form class="form-grid" method="post" action="profile.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="display_name" value="<?= e($profile['display_name'] ?? '') ?>">
        <input type="hidden" name="bio" value="<?= e($profile['bio'] ?? '') ?>">
        <input type="hidden" name="faculty" value="<?= e($profile['faculty'] ?? '') ?>">
        <input type="hidden" name="year_of_study" value="<?= e($profile['year_of_study'] ?? '') ?>">

        <label class="field">
            <span>Preferred gender</span>
            <select name="preferred_gender">
                <option value="">Keep current</option>
                <option value="female" <?= ($preferences['preferred_gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="male" <?= ($preferences['preferred_gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            </select>
        </label>

        <div class="form-row">
            <label class="field">
                <span>Minimum age</span>
                <input type="number" name="age_range_min" min="18" max="99" value="<?= e($preferences['age_range_min'] ?? 18) ?>">
            </label>
            <label class="field">
                <span>Maximum age</span>
                <input type="number" name="age_range_max" min="18" max="99" value="<?= e($preferences['age_range_max'] ?? 30) ?>">
            </label>
        </div>

        <label class="field">
            <span>Preferred faculty</span>
            <select name="preferred_faculty">
                <option value="Any">Any</option>
                <?php foreach ($faculties as $faculty): ?>
                    <option value="<?= e($faculty) ?>" <?= ($preferences['preferred_faculty'] ?? '') === $faculty ? 'selected' : '' ?>><?= e($faculty) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button class="button" type="submit">Save preferences</button>
    </form>
</section>

<section class="settings-grid">
    <article class="section-card" id="notifications">
        <h2>Notification preferences</h2>
        <div class="preferences-panel">
            <label class="switch-row"><span>Matches and milestones</span><input type="checkbox" checked disabled></label>
            <label class="switch-row"><span>Confession references</span><input type="checkbox" disabled></label>
            <label class="switch-row"><span>Message requests</span><input type="checkbox" disabled></label>
        </div>
        <p class="field-help">Backend preferences and browser push permission are not yet separate contracts.</p>
    </article>

    <article class="section-card">
        <h2>Privacy and safety</h2>
        <p class="muted">Screenshot alerts are not supported in standard web browsers. Reporting, blocking, unmatching and account deletion need final server contracts before production UI can claim success.</p>
        <div class="card-actions">
            <a class="button-secondary" href="privacy_policy.php">Privacy policy</a>
            <a class="button-secondary" href="community_guidelines.php">Guidelines</a>
        </div>
    </article>

    <article class="section-card">
        <h2>Account</h2>
        <div class="card-actions">
            <a class="button-secondary" href="logout.php">Log out</a>
            <a class="button-danger" href="account_delete.php">Delete account</a>
        </div>
        <p class="field-help">Deletion requires password confirmation and then anonymises the account using the retention policy.</p>
    </article>
</section>
<?php
render_page_shell_end();
