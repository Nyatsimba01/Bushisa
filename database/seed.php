<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/password_policy.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This seed script must be run from the command line.' . PHP_EOL);
}

echo 'Starting Bushisa database seed...' . PHP_EOL;

$users = [
    ['student_id' => 'N02410001', 'email' => 'n02410001@students.nust.ac.zw', 'display_name' => 'Tapiwa Moyo', 'anon_handle' => 'campus_simba', 'gender' => 'male', 'faculty' => 'Engineering', 'year' => 'Part 2', 'age' => 20],
    ['student_id' => 'N02410002', 'email' => 'n02410002@students.nust.ac.zw', 'display_name' => 'Farai Ncube', 'anon_handle' => 'skyline_farai', 'gender' => 'male', 'faculty' => 'Applied Sciences', 'year' => 'Part 3', 'age' => 21],
    ['student_id' => 'N02410003', 'email' => 'n02410003@students.nust.ac.zw', 'display_name' => 'Kudakwashe Dube', 'anon_handle' => 'kuda_codes', 'gender' => 'male', 'faculty' => 'Commerce', 'year' => 'Part 1', 'age' => 19],
    ['student_id' => 'N02410004', 'email' => 'n02410004@students.nust.ac.zw', 'display_name' => 'Tendai Sibanda', 'anon_handle' => 'tendai_trails', 'gender' => 'male', 'faculty' => 'Built Environment', 'year' => 'Part 4', 'age' => 23],
    ['student_id' => 'N02410005', 'email' => 'n02410005@students.nust.ac.zw', 'display_name' => 'Blessing Mpofu', 'anon_handle' => 'bless_the_brave', 'gender' => 'male', 'faculty' => 'Communication & Info Science', 'year' => 'Postgrad', 'age' => 25],
    ['student_id' => 'N02410006', 'email' => 'n02410006@students.nust.ac.zw', 'display_name' => 'Nyasha Gumbo', 'anon_handle' => 'nyasha_notes', 'gender' => 'female', 'faculty' => 'Medicine', 'year' => 'Part 2', 'age' => 20],
    ['student_id' => 'N02410007', 'email' => 'n02410007@students.nust.ac.zw', 'display_name' => 'Chipo Ndlovu', 'anon_handle' => 'chipo_chats', 'gender' => 'female', 'faculty' => 'Commerce', 'year' => 'Part 3', 'age' => 22],
    ['student_id' => 'N02410008', 'email' => 'n02410008@students.nust.ac.zw', 'display_name' => 'Ruvimbo Hove', 'anon_handle' => 'ruvi_rhythm', 'gender' => 'female', 'faculty' => 'Applied Sciences', 'year' => 'Part 1', 'age' => 19],
    ['student_id' => 'N02410009', 'email' => 'n02410009@students.nust.ac.zw', 'display_name' => 'Tariro Mlambo', 'anon_handle' => 'tariro_sunrise', 'gender' => 'female', 'faculty' => 'Engineering', 'year' => 'Part 4', 'age' => 24],
    ['student_id' => 'N02410010', 'email' => 'n02410010@students.nust.ac.zw', 'display_name' => 'Memory Zhou', 'anon_handle' => 'memory_lane', 'gender' => 'female', 'faculty' => 'Communication & Info Science', 'year' => 'Part 2', 'age' => 21],
];

try {
    $pdo->beginTransaction();

    $passwordHash = hash_password('TestPass123');
    $userIds = [];

    echo 'Creating test users, profiles, preferences, and consents...' . PHP_EOL;

    foreach ($users as $index => $user) {
        $insertUser = $pdo->prepare(
            'INSERT INTO users (nust_student_id, email, password_hash, is_verified, is_suspended)
             VALUES (:student_id, :email, :password_hash, 1, 0)
             ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                password_hash = VALUES(password_hash),
                is_verified = 1,
                is_suspended = 0'
        );
        $insertUser->execute([
            ':student_id' => $user['student_id'],
            ':email' => $user['email'],
            ':password_hash' => $passwordHash,
        ]);

        $userId = fetch_user_id_by_student_id($pdo, $user['student_id']);
        $userIds[$index + 1] = $userId;

        $insertProfile = $pdo->prepare(
            'INSERT INTO profiles (user_id, display_name, anon_handle, bio, gender, date_of_birth, faculty, year_of_study, profile_photo_path)
             VALUES (:user_id, :display_name, :anon_handle, :bio, :gender, :date_of_birth, :faculty, :year_of_study, NULL)
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                anon_handle = VALUES(anon_handle),
                bio = VALUES(bio),
                gender = VALUES(gender),
                date_of_birth = VALUES(date_of_birth),
                faculty = VALUES(faculty),
                year_of_study = VALUES(year_of_study),
                profile_photo_path = NULL'
        );
        $insertProfile->execute([
            ':user_id' => $userId,
            ':display_name' => $user['display_name'],
            ':anon_handle' => $user['anon_handle'],
            ':bio' => sample_bio((string) $user['faculty'], (string) $user['year']),
            ':gender' => $user['gender'],
            ':date_of_birth' => date_of_birth_for_age((int) $user['age']),
            ':faculty' => $user['faculty'],
            ':year_of_study' => $user['year'],
        ]);

        $insertPreferences = $pdo->prepare(
            'INSERT INTO preferences (user_id, preferred_gender, age_range_min, age_range_max, preferred_faculty)
             VALUES (:user_id, :preferred_gender, 18, 30, :preferred_faculty)
             ON DUPLICATE KEY UPDATE
                preferred_gender = VALUES(preferred_gender),
                age_range_min = 18,
                age_range_max = 30,
                preferred_faculty = VALUES(preferred_faculty)'
        );
        $insertPreferences->execute([
            ':user_id' => $userId,
            ':preferred_gender' => $user['gender'] === 'male' ? 'female' : 'male',
            ':preferred_faculty' => 'Any',
        ]);

        grant_seed_consent($pdo, $userId, 'terms_of_service');
        grant_seed_consent($pdo, $userId, 'privacy_policy');
    }

    echo 'Creating swipe scenarios...' . PHP_EOL;

    $swipes = [
        [1, 6, 'like'],
        [6, 1, 'like'],
        [2, 7, 'like'],
        [7, 2, 'like'],
        [3, 8, 'like'],
        [4, 9, 'pass'],
        [10, 5, 'like'],
    ];

    foreach ($swipes as [$swiperKey, $swipedKey, $direction]) {
        insert_swipe($pdo, $userIds[$swiperKey], $userIds[$swipedKey], $direction);
    }

    echo 'Creating matches...' . PHP_EOL;

    $matchOneId = insert_match($pdo, $userIds[1], $userIds[6]);
    $matchTwoId = insert_match($pdo, $userIds[2], $userIds[7]);

    echo 'Creating messages...' . PHP_EOL;

    $messages = [
        [$matchOneId, $userIds[1], 'Hi Nyasha, your Medicine stories sound intense.'],
        [$matchOneId, $userIds[6], 'They are, but Engineering sounds just as hectic.'],
        [$matchOneId, $userIds[1], 'Coffee after lectures this week?'],
        [$matchTwoId, $userIds[2], 'Hey Chipo, I saw you are in Commerce too.'],
        [$matchTwoId, $userIds[7], 'Yes, final project season is keeping me busy.'],
    ];

    foreach ($messages as [$matchId, $senderId, $body]) {
        insert_message($pdo, $matchId, $senderId, $body);
    }

    echo 'Creating confessions...' . PHP_EOL;

    $confessions = [
        [$userIds[3], 'I still get lost around campus and pretend I know where I am going.'],
        [$userIds[8], 'The library is peaceful until group assignment week starts.'],
        [$userIds[10], 'Someone smiled at me near the canteen and it made my whole day.'],
    ];

    foreach ($confessions as [$authorId, $body]) {
        insert_confession($pdo, $authorId, $body);
    }

    $pdo->commit();

    echo 'Seed completed successfully.' . PHP_EOL;
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Seed failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

function fetch_user_id_by_student_id(PDO $pdo, string $studentId): int
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE nust_student_id = :student_id LIMIT 1');
    $statement->execute([':student_id' => $studentId]);

    return (int) $statement->fetchColumn();
}

function date_of_birth_for_age(int $age): string
{
    return (new DateTimeImmutable('today'))
        ->modify('-' . $age . ' years')
        ->modify('-' . ($age % 9 + 1) . ' days')
        ->format('Y-m-d');
}

function sample_bio(string $faculty, string $year): string
{
    return $year . ' student in ' . $faculty . '. Here for respectful matches and good campus conversation.';
}

function grant_seed_consent(PDO $pdo, int $userId, string $consentType): void
{
    $activeConsent = $pdo->prepare(
        'SELECT id
         FROM consent_records
         WHERE user_id = :user_id
           AND consent_type = :consent_type
           AND revoked_at IS NULL
         LIMIT 1'
    );
    $activeConsent->execute([
        ':user_id' => $userId,
        ':consent_type' => $consentType,
    ]);

    if ($activeConsent->fetchColumn() !== false) {
        return;
    }

    $insertConsent = $pdo->prepare(
        'INSERT INTO consent_records (user_id, consent_type, granted_at, revoked_at)
         VALUES (:user_id, :consent_type, NOW(), NULL)'
    );
    $insertConsent->execute([
        ':user_id' => $userId,
        ':consent_type' => $consentType,
    ]);
}

function insert_swipe(PDO $pdo, int $swiperId, int $swipedId, string $direction): void
{
    $statement = $pdo->prepare(
        'INSERT INTO swipes (swiper_id, swiped_id, direction, created_at)
         VALUES (:swiper_id, :swiped_id, :direction, NOW())
         ON DUPLICATE KEY UPDATE
            direction = VALUES(direction),
            created_at = VALUES(created_at)'
    );
    $statement->execute([
        ':swiper_id' => $swiperId,
        ':swiped_id' => $swipedId,
        ':direction' => $direction,
    ]);
}

function insert_match(PDO $pdo, int $userAId, int $userBId): int
{
    $firstUserId = min($userAId, $userBId);
    $secondUserId = max($userAId, $userBId);

    $statement = $pdo->prepare(
        'INSERT INTO matches (user_a_id, user_b_id, matched_at)
         VALUES (:user_a_id, :user_b_id, NOW())
         ON DUPLICATE KEY UPDATE matched_at = VALUES(matched_at)'
    );
    $statement->execute([
        ':user_a_id' => $firstUserId,
        ':user_b_id' => $secondUserId,
    ]);

    $selectMatch = $pdo->prepare(
        'SELECT id FROM matches WHERE user_a_id = :user_a_id AND user_b_id = :user_b_id LIMIT 1'
    );
    $selectMatch->execute([
        ':user_a_id' => $firstUserId,
        ':user_b_id' => $secondUserId,
    ]);

    return (int) $selectMatch->fetchColumn();
}

function insert_message(PDO $pdo, int $matchId, int $senderId, string $body): void
{
    $statement = $pdo->prepare(
        'INSERT INTO messages (match_id, sender_id, body, sent_at, is_read)
         VALUES (:match_id, :sender_id, :body, NOW(), 0)'
    );
    $statement->execute([
        ':match_id' => $matchId,
        ':sender_id' => $senderId,
        ':body' => $body,
    ]);
}

function insert_confession(PDO $pdo, int $authorId, string $body): void
{
    $statement = $pdo->prepare(
        'INSERT INTO confessions (author_id, body, is_flagged, created_at)
         VALUES (:author_id, :body, 0, NOW())'
    );
    $statement->execute([
        ':author_id' => $authorId,
        ':body' => $body,
    ]);
}
