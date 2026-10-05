<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/password_policy.php';
require_once __DIR__ . '/php/anonymiser.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/operational.php';

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$error = null;
$csrfToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $user = find_user_by_id($pdo, $currentUserId);
        if ($user === null || !isset($user['password_hash']) || !is_string($user['password_hash']) || !verify_password($password, $user['password_hash'])) {
            $error = 'Password confirmation failed.';
        } elseif (anonymise_user($pdo, $currentUserId, $currentUserId)) {
            destroy_session();
            redirect('login.php');
        } else {
            $error = 'Unable to delete account right now.';
        }
    }
}

$csrfToken = generate_csrf_token();

render_page_shell_start('you', 'Delete Account', $csrfToken, $currentUserId, false, 'Permanent account removal');
?>
<?php render_flash($error, null); ?>

<section class="section-card">
    <h2>Delete your Bushisa account</h2>
    <p class="muted">This removes you from discovery, revokes your session and anonymises profile data using the current retention policy. Some restricted moderation and audit records may remain where required for safety.</p>
    <form class="form-grid" method="post" action="account_delete.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <label class="field">
            <span>Confirm password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <div class="card-actions">
            <button class="button-danger" type="submit">Delete account</button>
            <a class="button-secondary" href="profile.php">Cancel</a>
        </div>
    </form>
</section>
<?php
render_page_shell_end();

