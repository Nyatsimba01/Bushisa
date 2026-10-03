<?php
// public/register.php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $interestedIn = $_POST['interested_in'] ?? '';

    // 1. Validate NUST student email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with(strtolower($email), '@students.nust.ac.zw')) {
        $error = 'You must use a valid NUST student email (@students.nust.ac.zw).';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (empty($displayName) || empty($gender) || empty($interestedIn)) {
        $error = 'All fields are required.';
    } else {
        // 2. Check if student email is already registered
        $stmt = $pdo->prepare('SELECT id FROM users WHERE student_email = ?');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            // 3. Hash password and generate random anonymous handle
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $anonHandle = 'NUST_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 6);

            $pdo->beginTransaction();
            try {
                // Insert into users table
                $insertUser = $pdo->prepare('INSERT INTO users (student_email, password_hash, is_verified) VALUES (?, ?, 1)');
                $insertUser->execute([$email, $passwordHash]);
                $userId = $pdo->lastInsertId();

                // Insert into profiles table
                $insertProfile = $pdo->prepare('INSERT INTO profiles (user_id, display_name, anon_handle, gender, interested_in) VALUES (?, ?, ?, ?, ?)');
                $insertProfile->execute([$userId, $displayName, $anonHandle, $gender, $interestedIn]);

                $pdo->commit();
                $success = 'Account created successfully! You can now log in.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Bushisa</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2rem; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; color: #ec4899; }
        label { display: block; margin: 0.75rem 0 0.25rem; font-size: 0.875rem; color: #94a3b8; }
        input, select { width: 100%; padding: 0.6rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 0.75rem; margin-top: 1.25rem; border: none; border-radius: 6px; background: #ec4899; color: #fff; font-weight: bold; cursor: pointer; }
        button:hover { background: #db2777; }
        .alert { padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.875rem; }
        .alert-error { background: #ef4444; color: #fff; }
        .alert-success { background: #10b981; color: #fff; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Join Bushisa</h1>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <label for="display_name">First Name / Display Name</label>
            <input type="text" id="display_name" name="display_name" required placeholder="e.g. Tinashe">

            <label for="email">NUST Student Email</label>
            <input type="email" id="email" name="email" required placeholder="studentID@students.nust.ac.zw">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="8" required>

            <label for="gender">Your Gender</label>
            <select id="gender" name="gender" required>
                <option value="">Select...</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
                <option value="other">Other</option>
            </select>

            <label for="interested_in">Interested In</label>
            <select id="interested_in" name="interested_in" required>
                <option value="">Select...</option>
                <option value="male">Men</option>
                <option value="female">Women</option>
                <option value="everyone">Everyone</option>
            </select>

            <button type="submit">Create Account</button>
        </form>
    </div>
</body>
</html>