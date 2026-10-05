<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/password_policy.php';
require_once __DIR__ . '/php/rate_limit.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/auth.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';

ensure_operational_schema($pdo);

$error = null;
$successRedirect = null;
$csrfToken = null;

if (isset($_SESSION['user_id'])) {
    redirect('discover.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = clean_email((string) ($_POST['email'] ?? ''));
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $result = attempt_login($pdo, $email, $password, $identifier);

        if (!empty($result['success'])) {
            $successRedirect = is_string($result['redirect'] ?? null) ? $result['redirect'] : 'discover.php';
            redirect($successRedirect);
        }

        $error = is_string($result['error'] ?? null) ? $result['error'] : 'Unable to log in.';
    }
}

$csrfToken = generate_csrf_token();

$emailValue = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';

render_auth_start('Sign in', $csrfToken);
?>
<section class="auth-card" aria-labelledby="login-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="login-title">Welcome back to Bushisa</h1>
    <p class="auth-card__lead">Sign in with your NUST student email to continue discovery, confessions and approved conversations.</p>

    <?php render_flash($error, null); ?>

    <form class="form-grid" method="post" action="login.php" data-server-form>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <label class="field">
            <span>Student email</span>
            <input type="email" name="email" value="<?= e($emailValue) ?>" autocomplete="email" inputmode="email" required placeholder="name@students.nust.ac.zw">
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button class="button" type="submit">Sign in</button>
    </form>

    <p class="auth-switch">New to Bushisa? <a href="register.php">Create your student account</a></p>
    <p class="field-help">Verification depends on the active backend. Bushisa will not display identity as verified unless the server proves it.</p>
</section>
<?php
render_auth_end();
