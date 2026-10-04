<?php
// public/register.php
session_start();

// Check which database path is available
if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
} elseif (file_exists(__DIR__ . '/../php/db.php')) {
    require_once __DIR__ . '/../php/db.php';
} else {
    die('Database configuration file not found.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $studentId = strtoupper(trim($_POST['nust_student_id'] ?? ''));
    $displayName = trim($_POST['display_name'] ?? '');
    $anonHandle = trim($_POST['anon_handle'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $faculty = $_POST['faculty'] ?? '';
    $yearOfStudy = $_POST['year_of_study'] ?? '';

    // Strip leading '@' if student included it
    $anonHandle = ltrim($anonHandle, '@');

    // 1. Basic Form Validations
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@students.nust.ac.zw')) {
        $error = 'You must enter a valid NUST student email address (@students.nust.ac.zw).';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (empty($studentId) || empty($displayName) || empty($anonHandle) || empty($gender) || empty($faculty) || empty($yearOfStudy)) {
        $error = 'All fields are required.';
    } else {
        // 2. Check if student ID or email is already registered
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR nust_student_id = ?');
        $stmt->execute([$email, $studentId]);

        if ($stmt->fetch()) {
            $error = 'An account with that student ID or email already exists.';
        } else {
            // 3. Check if anonymous handle is taken
            $stmt = $pdo->prepare('SELECT user_id FROM profiles WHERE anon_handle = ?');
            $stmt->execute([$anonHandle]);

            if ($stmt->fetch()) {
                $error = 'That anonymous confession handle is already taken. Please choose another.';
            } else {
                // 4. Hash password and derive opposite-gender preference
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $preferredGender = ($gender === 'male') ? 'female' : 'male';

                $pdo->beginTransaction();
                try {
                    // Insert into users table
                    $insertUser = $pdo->prepare('INSERT INTO users (nust_student_id, email, password_hash, is_verified) VALUES (?, ?, ?, 1)');
                    $insertUser->execute([$studentId, $email, $passwordHash]);
                    $userId = $pdo->lastInsertId();

                    // Insert into profiles table
                    $insertProfile = $pdo->prepare('INSERT INTO profiles (user_id, display_name, anon_handle, gender, faculty, year_of_study) VALUES (?, ?, ?, ?, ?, ?)');
                    $insertProfile->execute([$userId, $displayName, $anonHandle, $gender, $faculty, $yearOfStudy]);

                    // Insert into preferences table
                    $insertPref = $pdo->prepare('INSERT INTO preferences (user_id, preferred_gender) VALUES (?, ?)');
                    $insertPref->execute([$userId, $preferredGender]);

                    $pdo->commit();
                    $success = 'Account created successfully! You can now log in.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Registration failed: ' . $e->getMessage();
                }
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
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 2rem 0; box-sizing: border-box; }
        .card { background: #1e293b; padding: 2rem; border-radius: 12px; width: 100%; max-width: 440px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; color: #ec4899; }
        label { display: block; margin: 0.75rem 0 0.25rem; font-size: 0.85rem; color: #94a3b8; }
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
            <label for="nust_student_id">NUST Student ID</label>
            <input type="text" id="nust_student_id" name="nust_student_id" required placeholder="e.g. N0241012A">

            <label for="email">NUST Student Email</label>
            <input type="email" id="email" name="email" required placeholder="n0241012a@students.nust.ac.zw">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="8" required>

            <label for="display_name">Display Name (Visible on Dating)</label>
            <input type="text" id="display_name" name="display_name" required placeholder="e.g. Tinashe">

            <label for="anon_handle">Anonymous Handle (Visible on Confessions)</label>
            <input type="text" id="anon_handle" name="anon_handle" required placeholder="e.g. GhostRider">

            <label for="gender">Gender</label>
            <select id="gender" name="gender" required>
                <option value="">Select Gender...</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
            </select>

            <label for="faculty">Faculty</label>
            <select id="faculty" name="faculty" required>
                <option value="">Select Faculty...</option>
                <option value="Applied Sciences">Applied Sciences</option>
                <option value="Engineering">Engineering</option>
                <option value="Commerce">Commerce</option>
                <option value="Medicine">Medicine</option>
                <option value="Built Environment">Built Environment</option>
                <option value="Communication & Info Science">Communication & Information Science</option>
            </select>

            <label for="year_of_study">Year of Study</label>
            <select id="year_of_study" name="year_of_study" required>
                <option value="">Select Year...</option>
                <option value="Part 1">Part 1</option>
                <option value="Part 2">Part 2</option>
                <option value="Part 3">Part 3</option>
                <option value="Part 4">Part 4</option>
                <option value="Postgrad">Postgraduate</option>
            </select>

            <button type="submit">Create Account</button>
        </form>
    </div>
</body>
</html>