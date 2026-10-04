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

require_login();

$userId = (int) $_SESSION['user_id'];
$error = null;
$success = null;
$csrfToken = null;
$profile = get_profile($pdo, $userId);
$preferences = get_preferences($pdo, $userId);

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

        if ($bio !== '') {
            $profileData['bio'] = $bio;
        }

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

// --- Frontend HTML will be added later ---