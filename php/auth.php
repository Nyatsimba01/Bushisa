<?php

declare(strict_types=1);

/**
 * Attempt to authenticate a user by email and password.
 *
 * @param PDO $pdo
 * @param string $email
 * @param string $password
 * @param string|null $identifier
 * @return array{success: bool, error: string|null, redirect: string|null}
 */
function attempt_login(PDO $pdo, string $email, string $password, ?string $identifier = null): array
{
    $rateLimitKey = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (!check_rate_limit($pdo, 'login', $rateLimitKey, 5, 900)) {
        log_to_file('Login rate limit exceeded for ' . $rateLimitKey, 'WARN');

        return [
            'success' => false,
            'error' => 'Too many login attempts. Please try again later.',
            'redirect' => null,
        ];
    }

    if ($email === '') {
        record_attempt($pdo, 'login', $rateLimitKey);

        return [
            'success' => false,
            'error' => 'Please enter a valid email address.',
            'redirect' => null,
        ];
    }

    $user = find_user_by_email($pdo, $email);
    if ($user === null) {
        record_attempt($pdo, 'login', $rateLimitKey);

        return [
            'success' => false,
            'error' => 'Invalid email or password.',
            'redirect' => null,
        ];
    }

    if (is_user_suspended($pdo, (int) $user['id'])) {
        log_to_file('Suspended user login denied for user_id ' . (int) $user['id'], 'WARN');

        return [
            'success' => false,
            'error' => 'Your account is suspended.',
            'redirect' => null,
        ];
    }

    $passwordHash = isset($user['password_hash']) && is_string($user['password_hash']) ? $user['password_hash'] : '';
    if ($passwordHash === '' || !verify_password($password, $passwordHash)) {
        record_attempt($pdo, 'login', $rateLimitKey);

        return [
            'success' => false,
            'error' => 'Invalid email or password.',
            'redirect' => null,
        ];
    }

    clear_attempts($pdo, 'login', $rateLimitKey);
    regenerate_session();
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = isset($user['role']) && is_string($user['role']) && $user['role'] !== '' ? $user['role'] : 'user';
    $_SESSION['email'] = $user['email'] ?? $email;

    log_action($pdo, (int) $user['id'], 'login', $rateLimitKey);
    log_to_file('User ' . (int) $user['id'] . ' logged in', 'INFO');

    return [
        'success' => true,
        'error' => null,
        'redirect' => 'discover.php',
    ];
}

/**
 * Register a new Bushisa user.
 *
 * @param PDO $pdo
 * @param array<string, mixed> $data
 * @return array{success: bool, error: string|null, message: string|null, redirect: string|null}
 */
function register_user(PDO $pdo, array $data): array
{
    $studentId = strtoupper(trim(str_replace("\0", '', (string) ($data['nust_student_id'] ?? ''))));
    $email = clean_email((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $displayName = trim(str_replace("\0", '', (string) ($data['display_name'] ?? '')));
    $anonHandle = ltrim(trim(str_replace("\0", '', (string) ($data['anon_handle'] ?? ''))), '@');
    $gender = strtolower(trim(str_replace("\0", '', (string) ($data['gender'] ?? ''))));
    $faculty = trim(str_replace("\0", '', (string) ($data['faculty'] ?? '')));
    $yearOfStudy = trim(str_replace("\0", '', (string) ($data['year_of_study'] ?? '')));

    if ($studentId === '' || !preg_match('/^[A-Z0-9]{4,20}$/', $studentId)) {
        return [
            'success' => false,
            'error' => 'Please enter a valid NUST student ID.',
            'message' => null,
            'redirect' => null,
        ];
    }

    if ($email === '' || !validate_nust_email($email)) {
        return [
            'success' => false,
            'error' => 'You must use a valid @students.nust.ac.zw email address.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $passwordErrors = validate_password($password);
    if ($password !== '' && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $passwordErrors[] = 'Password must contain at least one special character.';
    }

    if ($passwordErrors !== []) {
        return [
            'success' => false,
            'error' => implode(' ', $passwordErrors),
            'message' => null,
            'redirect' => null,
        ];
    }

    if ($displayName === '' || !preg_match('/^[A-Za-z0-9 ]{2,30}$/', $displayName)) {
        return [
            'success' => false,
            'error' => 'Display name must be 2 to 30 characters and use only letters, numbers, and spaces.',
            'message' => null,
            'redirect' => null,
        ];
    }

    if ($anonHandle === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $anonHandle)) {
        return [
            'success' => false,
            'error' => 'Please choose a valid anonymous handle.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $gender = clean_enum($gender, ['male', 'female']) ?? '';
    if ($gender === '') {
        return [
            'success' => false,
            'error' => 'Please select a valid gender.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $faculty = clean_enum($faculty, [
        'Applied Sciences',
        'Built Environment',
        'Communication & Information Science',
        'Commerce & Law',
        'Industrial Technology',
        'Medicine',
        'Science & Technology Education',
    ]) ?? '';
    if ($faculty === '') {
        return [
            'success' => false,
            'error' => 'Please select a valid faculty.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $yearOfStudy = clean_enum($yearOfStudy, ['Part 1', 'Part 2', 'Part 3', 'Part 4', 'Postgrad']) ?? '';
    if ($yearOfStudy === '') {
        return [
            'success' => false,
            'error' => 'Please select a valid year of study.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $existingUser = $pdo->prepare('SELECT id FROM users WHERE email = :email OR nust_student_id = :student_id LIMIT 1');
    $existingUser->execute([
        ':email' => $email,
        ':student_id' => $studentId,
    ]);

    if ($existingUser->fetchColumn() !== false) {
        return [
            'success' => false,
            'error' => 'An account with that email or student ID already exists.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $existingHandle = $pdo->prepare('SELECT user_id FROM profiles WHERE anon_handle = :anon_handle LIMIT 1');
    $existingHandle->execute([':anon_handle' => $anonHandle]);

    if ($existingHandle->fetchColumn() !== false) {
        return [
            'success' => false,
            'error' => 'That anonymous handle is already taken.',
            'message' => null,
            'redirect' => null,
        ];
    }

    $preferredGender = $gender === 'male' ? 'female' : 'male';
    $passwordHash = hash_password($password);

    try {
        $pdo->beginTransaction();

        $userStatement = $pdo->prepare(
            'INSERT INTO users (nust_student_id, email, password_hash, is_verified, is_suspended) VALUES (:student_id, :email, :password_hash, 1, 0)'
        );
        $userStatement->execute([
            ':student_id' => $studentId,
            ':email' => $email,
            ':password_hash' => $passwordHash,
        ]);

        $userId = (int) $pdo->lastInsertId();

        $profileStatement = $pdo->prepare(
            'INSERT INTO profiles (user_id, display_name, anon_handle, bio, gender, date_of_birth, faculty, year_of_study, profile_photo_path) VALUES (:user_id, :display_name, :anon_handle, :bio, :gender, NULL, :faculty, :year_of_study, NULL)'
        );
        $profileStatement->execute([
            ':user_id' => $userId,
            ':display_name' => $displayName,
            ':anon_handle' => $anonHandle,
            ':bio' => '',
            ':gender' => $gender,
            ':faculty' => $faculty,
            ':year_of_study' => $yearOfStudy,
        ]);

        $preferencesStatement = $pdo->prepare(
            'INSERT INTO preferences (user_id, preferred_gender, age_range_min, age_range_max, preferred_faculty) VALUES (:user_id, :preferred_gender, 18, 30, :preferred_faculty)'
        );
        $preferencesStatement->execute([
            ':user_id' => $userId,
            ':preferred_gender' => $preferredGender,
            ':preferred_faculty' => 'Any',
        ]);

        $pdo->commit();

        log_action($pdo, $userId, 'register', $_SERVER['REMOTE_ADDR'] ?? null);
        log_to_file('User ' . $userId . ' registered', 'INFO');

        return [
            'success' => true,
            'error' => null,
            'message' => 'Account created successfully. You can now log in.',
            'redirect' => 'login.php',
        ];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        log_to_file('Registration failed: ' . $exception->getMessage(), 'ERROR');

        return [
            'success' => false,
            'error' => 'Registration failed. Please try again.',
            'message' => null,
            'redirect' => null,
        ];
    }
}