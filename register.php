<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/password_policy.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/auth.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/frontend.php';

ensure_operational_schema($pdo);

$error = null;
$success = null;
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
        $formData = [
            'nust_student_id' => strtolower(trim(str_replace("\0", '', (string) ($_POST['nust_student_id'] ?? '')))),
            'email' => clean_email((string) ($_POST['email'] ?? '')),
            'password' => isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '',
            'display_name' => trim(str_replace("\0", '', (string) ($_POST['display_name'] ?? ''))),
            'anon_handle' => ltrim(trim(str_replace("\0", '', (string) ($_POST['anon_handle'] ?? ''))), '@'),
            'gender' => strtolower(trim(str_replace("\0", '', (string) ($_POST['gender'] ?? '')))),
            'faculty' => trim(str_replace("\0", '', (string) ($_POST['faculty'] ?? ''))),
            'year_of_study' => trim(str_replace("\0", '', (string) ($_POST['year_of_study'] ?? ''))),
        ];

        $result = register_user($pdo, $formData);

        if (!empty($result['success'])) {
            $success = is_string($result['message'] ?? null) ? $result['message'] : 'Account created successfully.';
            $successRedirect = is_string($result['redirect'] ?? null) ? $result['redirect'] : null;
        } else {
            $error = is_string($result['error'] ?? null) ? $result['error'] : 'Registration failed.';
        }
    }
}

$csrfToken = generate_csrf_token();

$posted = static fn(string $key): string => isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
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

render_auth_start('Create account', $csrfToken);
?>
<section class="auth-card" aria-labelledby="register-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="register-title">Join Bushisa</h1>
    <p class="auth-card__lead">Set up a NUST-only profile for discovery and anonymous confessions. Keep your student identity private in public cards.</p>

    <?php render_flash($error, $success); ?>

    <?php if ($successRedirect !== null): ?>
        <p><a class="button" href="<?= e($successRedirect) ?>">Continue to sign in</a></p>
    <?php else: ?>
        <form class="form-grid" method="post" action="register.php" data-server-form>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <label class="field">
                <span>NUST student number</span>
                <input type="text" name="nust_student_id" value="<?= e(strtolower($posted('nust_student_id'))) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required placeholder="n02531234f">
                <p class="field-help">Use lowercase letters. The example format is inferred from the product brief and still needs backend confirmation.</p>
            </label>

            <label class="field">
                <span>Student email</span>
                <input type="email" name="email" value="<?= e($posted('email')) ?>" autocomplete="email" required placeholder="name@students.nust.ac.zw">
            </label>

            <label class="field">
                <span>Password</span>
                <input type="password" name="password" autocomplete="new-password" required>
                <p class="field-help">Use at least 8 characters with uppercase, lowercase, a digit and a special character.</p>
            </label>

            <label class="field">
                <span>Display name</span>
                <input type="text" name="display_name" value="<?= e($posted('display_name')) ?>" maxlength="30" required>
            </label>

            <label class="field">
                <span>Anonymous handle</span>
                <input type="text" name="anon_handle" value="<?= e($posted('anon_handle')) ?>" maxlength="50" autocapitalize="none" spellcheck="false" required placeholder="quiet_spark">
            </label>

            <label class="field">
                <span>Gender</span>
                <select name="gender" required>
                    <option value="">Choose one</option>
                    <option value="female" <?= strtolower($posted('gender')) === 'female' ? 'selected' : '' ?>>Female</option>
                    <option value="male" <?= strtolower($posted('gender')) === 'male' ? 'selected' : '' ?>>Male</option>
                </select>
            </label>

            <label class="field">
                <span>Faculty</span>
                <select name="faculty" required>
                    <option value="">Choose faculty</option>
                    <?php foreach ($faculties as $faculty): ?>
                        <option value="<?= e($faculty) ?>" <?= $posted('faculty') === $faculty ? 'selected' : '' ?>><?= e($faculty) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Year of study</span>
                <select name="year_of_study" required>
                    <option value="">Choose year</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e($year) ?>" <?= $posted('year_of_study') === $year ? 'selected' : '' ?>><?= e($year) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="button" type="submit">Create account</button>
        </form>
    <?php endif; ?>

    <p class="auth-switch">Already have an account? <a href="login.php">Sign in</a></p>
</section>
<?php
render_auth_end();
