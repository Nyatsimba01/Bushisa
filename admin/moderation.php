<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/moderation.php';
require_once __DIR__ . '/../php/logger.php';

require_login();

if (!is_moderator()) {
    redirect('/discover.php');
}

$adminId = get_current_user_id();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $action = clean_enum((string) ($_POST['action'] ?? ''), [
        'unflag_confession',
        'delete_confession',
        'unsuspend_user',
    ]);

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($adminId === null) {
        $error = 'Unable to identify the current admin.';
    } elseif ($action === null) {
        $error = 'Invalid moderation action.';
    } elseif ($action === 'unflag_confession') {
        $confessionId = clean_int($_POST['confession_id'] ?? null);

        if ($confessionId === null || $confessionId < 1) {
            $error = 'Invalid confession.';
        } elseif (unflag_confession($pdo, $confessionId)) {
            log_action($pdo, $adminId, 'admin_unflag_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $adminId . ' unflagged confession ' . $confessionId, 'INFO');
            $success = 'Confession unflagged.';
        } else {
            $error = 'Unable to unflag confession.';
        }
    } elseif ($action === 'delete_confession') {
        $confessionId = clean_int($_POST['confession_id'] ?? null);

        if ($confessionId === null || $confessionId < 1) {
            $error = 'Invalid confession.';
        } elseif (delete_confession($pdo, $confessionId, $adminId)) {
            log_action($pdo, $adminId, 'admin_delete_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $adminId . ' deleted confession ' . $confessionId . ' from moderation page', 'INFO');
            $success = 'Confession deleted.';
        } else {
            $error = 'Unable to delete confession.';
        }
    } elseif ($action === 'unsuspend_user') {
        $userId = clean_int($_POST['user_id'] ?? null);

        if ($userId === null || $userId < 1) {
            $error = 'Invalid user.';
        } elseif (unsuspend_user($pdo, $userId, $adminId)) {
            log_action($pdo, $adminId, 'admin_unsuspend_user:' . $userId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $adminId . ' unsuspended user ' . $userId . ' from moderation page', 'INFO');
            $success = 'User unsuspended.';
        } else {
            $error = 'Unable to unsuspend user.';
        }
    }
}

$flagged = get_flagged_confessions($pdo);
$suspended_users = moderation_get_suspended_users($pdo);
$csrfToken = generate_csrf_token();

/**
 * Fetch suspended users with profile display names.
 *
 * @return array<int, array<string, mixed>>
 */
function moderation_get_suspended_users(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT
            users.id,
            users.email,
            users.created_at,
            users.is_suspended,
            profiles.display_name
         FROM users
         LEFT JOIN profiles
            ON profiles.user_id = users.id
         WHERE users.is_suspended = 1
         ORDER BY users.created_at DESC, users.id DESC'
    );
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// --- Frontend HTML will be added later ---
