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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Bushisa</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #0b0f19;
            color: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            background-color: #161f30;
            border: 1px solid #243048;
            border-radius: 12px;
            width: 100%;
            max-width: 400px;
            padding: 2rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
        }
        h1 {
            color: #f43f5e;
            font-size: 1.75rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        p.subtitle {
            color: #94a3b8;
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .alert {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
        }
        .field {
            margin-bottom: 1.2rem;
        }
        label {
            display: block;
            font-size: 0.85rem;
            color: #cbd5e1;
            margin-bottom: 0.35rem;
        }
        input {
            width: 100%;
            padding: 0.75rem;
            background: #0b0f19;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #fff;
            font-size: 0.95rem;
        }
        input:focus {
            outline: none;
            border-color: #f43f5e;
        }
        button {
            width: 100%;
            padding: 0.8rem;
            background: #f43f5e;
            border: none;
            border-radius: 6px;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.5rem;
        }
        button:hover {
            background: #e11d48;
        }
        .footer {
            margin-top: 1.25rem;
            text-align: center;
            font-size: 0.85rem;
            color: #94a3b8;
        }
        .footer a {
            color: #f43f5e;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Bushisa</h1>
        <p class="subtitle">Sign in to your account</p>

        <?php if (!empty($error)): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

            <div class="field">
                <label for="email">NUST Student Email</label>
                <input type="email" id="email" name="email" placeholder="e.g. s0123456x@students.nust.ac.zw" required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Sign In</button>
        </form>

        <div class="footer">
            Don't have an account? <a href="public/register.php">Register here</a>
        </div>
    </div>
</body>
</html>