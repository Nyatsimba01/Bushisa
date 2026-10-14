<?php

declare(strict_types=1);

/**
 * Check moderator access against the current database row rather than only a
 * role cached in the login session. This fixes stale roles after a local
 * database edit AND honours permission revocation immediately.
 *
 * Call require_login() before calling this helper.
 */
function bushisa_require_moderator(PDO $pdo): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $role = null;
    $configurationError = false;

    try {
        if ($userId > 0) {
            $statement = $pdo->prepare(
                'SELECT role, is_suspended FROM users WHERE id = :user_id LIMIT 1'
            );
            $statement->execute([':user_id' => $userId]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);

            if (is_array($user)) {
                $role = isset($user['role']) && is_string($user['role']) ? $user['role'] : null;
                if ((int) ($user['is_suspended'] ?? 0) !== 1
                    && in_array($role, ['admin', 'moderator'], true)) {
                    $_SESSION['role'] = $role;
                    return;
                }
            }
        }
    } catch (PDOException $exception) {
        error_log('Admin permission lookup failed: ' . $exception->getMessage());
        $configurationError = true;
    }

    // Fail closed: never grant access because a session claims to be an admin
    // when the database does not agree or its role column is unavailable.
    $_SESSION['role'] = 'student';
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');

    $message = $configurationError
        ? 'Bushisa could not read the permissions from your local database. Check that the users table contains a role column.'
        : 'This signed-in account does not currently have administrator or moderator access in the database.';
    $roleLabel = $configurationError
        ? 'Not available'
        : (in_array($role, ['student', 'admin', 'moderator'], true) ? $role : 'Not assigned');

    $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedRole = htmlspecialchars($roleLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin access check · Bushisa</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #21151a; color: #f8ede2; padding: 20px;
            font: 15px/1.65 Inter, Arial, sans-serif;
        }
        main {
            width: min(100%, 600px); background: #302128;
            border: 1px solid #57404a; border-radius: 20px;
            padding: clamp(24px, 5vw, 42px);
        }
        .eyebrow { color: #f4b598; font-size: 12px; font-weight: 700; letter-spacing: .07em; }
        h1 { font: 400 clamp(30px, 5vw, 42px)/1.2 Georgia, serif; margin: 8px 0 15px; }
        p { color: #c8b4b9; margin: 12px 0; }
        strong { color: #f8ede2; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
        a { color: #21151a; background: #f4b598; border-radius: 10px;
            text-decoration: none; padding: 10px 15px; font-weight: 700; }
        a:last-child { background: #4a2434; color: #f8ede2; }
        a:focus-visible { outline: 3px solid #f8ede2; outline-offset: 3px; }
    </style>
</head>
<body>
<main>
    <span class="eyebrow">BUSHISA · ACCOUNT PERMISSIONS</span>
    <h1>Administrator access not available</h1>
    <p><?= $escapedMessage ?></p>
    <p><strong>Current database role:</strong> <?= $escapedRole ?></p>
    <p>If you recently edited an account in phpMyAdmin, make sure the change was saved and that you're signed into <strong>that same test account</strong>.</p>
    <div class="actions">
        <a href="../discover.php">Back to Home</a>
        <a href="../profile.php#account">Your account</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}
