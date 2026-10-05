<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/operational.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$allowedQuestions = ['intent', 'interests', 'social_activity', 'communication_style', 'availability'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody !== false ? $rawBody : '', true);
    $payload = is_array($payload) ? $payload : $_POST;

    $submittedToken = '';
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($payload['csrf_token']) && is_string($payload['csrf_token'])) {
        $submittedToken = $payload['csrf_token'];
    }

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }

    $answers = isset($payload['answers']) && is_array($payload['answers']) ? $payload['answers'] : $payload;
    foreach ($allowedQuestions as $question) {
        if (!isset($answers[$question]) || !is_string($answers[$question]) || trim($answers[$question]) === '') {
            continue;
        }

        $answer = function_exists('mb_substr')
            ? mb_substr(trim(str_replace("\0", '', $answers[$question])), 0, 120)
            : substr(trim(str_replace("\0", '', $answers[$question])), 0, 120);

        $statement = $pdo->prepare(
            'INSERT INTO find_match_answers (user_id, question_key, answer_value)
             VALUES (:user_id, :question_key, :answer_value)
             ON DUPLICATE KEY UPDATE answer_value = VALUES(answer_value)'
        );
        $statement->execute([
            ':user_id' => $currentUserId,
            ':question_key' => $question,
            ':answer_value' => $answer,
        ]);
    }

    json_response(['success' => true, 'data' => null]);
}

$limit = clean_int($_GET['limit'] ?? null) ?? 20;
$limit = max(1, min(50, $limit));
$offset = clean_int($_GET['offset'] ?? null) ?? 0;
$offset = max(0, $offset);

$profile = get_profile($pdo, $currentUserId);
$preferences = get_preferences($pdo, $currentUserId);
if ($profile === null) {
    json_response(['error' => 'Profile is required before matching'], 400);
}

$blockedIds = [];
$blockedStatement = $pdo->prepare('SELECT blocker_id, blocked_id FROM blocks WHERE blocker_id = :user_id OR blocked_id = :user_id');
$blockedStatement->execute([':user_id' => $currentUserId]);
while ($row = $blockedStatement->fetch(PDO::FETCH_ASSOC)) {
    $otherId = (int) $row['blocker_id'] === $currentUserId ? (int) $row['blocked_id'] : (int) $row['blocker_id'];
    if ($otherId > 0) {
        $blockedIds[$otherId] = $otherId;
    }
}

$params = [':current_user_id' => $currentUserId];
$blockedSql = '';
if ($blockedIds !== []) {
    $placeholders = [];
    foreach (array_values($blockedIds) as $index => $blockedId) {
        $placeholder = ':blocked_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $blockedId;
    }
    $blockedSql = ' AND p.user_id NOT IN (' . implode(', ', $placeholders) . ')';
}

$matchColumns = operational_match_columns($pdo);
$swipeColumns = operational_swipe_columns($pdo);
$profilePhotoSelect = operational_column_exists($pdo, 'profiles', 'profile_photo_path')
    ? 'p.profile_photo_path AS profile_photo_path'
    : (operational_column_exists($pdo, 'profiles', 'photo_1_url') ? 'p.photo_1_url AS profile_photo_path' : 'NULL AS profile_photo_path');
$facultySelect = operational_column_exists($pdo, 'profiles', 'faculty') ? 'p.faculty AS faculty' : 'NULL AS faculty';
$yearSelect = operational_column_exists($pdo, 'profiles', 'year_of_study') ? 'p.year_of_study AS year_of_study' : 'NULL AS year_of_study';
$ageSelect = operational_column_exists($pdo, 'profiles', 'date_of_birth')
    ? 'TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age'
    : 'NULL AS age';
$userAvailabilitySql = operational_column_exists($pdo, 'users', 'is_suspended')
    ? 'COALESCE(u.is_suspended, 0) = 0'
    : (operational_column_exists($pdo, 'users', 'is_active') ? 'COALESCE(u.is_active, 1) = 1' : '1 = 1');
$statement = $pdo->prepare(
    sprintf(
        'SELECT
            p.user_id,
            p.display_name,
            p.bio,
            p.gender,
            %8$s,
            %9$s,
            %10$s,
            %6$s,
            s.%2$s AS swipe_state,
            m.id AS match_id,
            mr.status AS message_status
         FROM profiles p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN swipes s ON s.swiper_id = :current_user_id AND s.%1$s = p.user_id
         LEFT JOIN matches m
            ON (m.%3$s = :current_user_id AND m.%4$s = p.user_id)
            OR (m.%4$s = :current_user_id AND m.%3$s = p.user_id)
         LEFT JOIN message_requests mr ON mr.match_id = m.id
         WHERE p.user_id <> :current_user_id
           AND %7$s
           %5$s
         ORDER BY p.user_id ASC',
        $swipeColumns['target'],
        $swipeColumns['direction'],
        $matchColumns['user_a'],
        $matchColumns['user_b'],
        $blockedSql,
        $ageSelect,
        $userAvailabilitySql,
        $facultySelect,
        $yearSelect,
        $profilePhotoSelect
    )
);
$statement->execute($params);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$results = [];
foreach ($rows as $row) {
    $score = 40;
    if (($preferences['preferred_gender'] ?? null) === ($row['gender'] ?? null)) {
        $score += 20;
    }
    if (($preferences['preferred_faculty'] ?? 'Any') === 'Any' || ($preferences['preferred_faculty'] ?? '') === ($row['faculty'] ?? '')) {
        $score += 15;
    }
    if (($profile['faculty'] ?? '') === ($row['faculty'] ?? '')) {
        $score += 10;
    }
    if (($profile['year_of_study'] ?? '') === ($row['year_of_study'] ?? '')) {
        $score += 5;
    }

    $score = min(100, $score);
    $action = 'like';
    if (!empty($row['match_id'])) {
        $messageStatus = (string) ($row['message_status'] ?? '');
        $action = match ($messageStatus) {
            'pending' => 'pending',
            'declined' => 'declined',
            'approved' => 'approved_open_chat',
            'revoked' => 'revoked',
            default => 'request_message',
        };
    } elseif (($row['swipe_state'] ?? '') === 'like') {
        $action = 'liked';
    }

    $results[] = [
        'user_id' => (int) $row['user_id'],
        'display_name' => (string) $row['display_name'],
        'bio' => (string) ($row['bio'] ?? ''),
        'faculty' => (string) ($row['faculty'] ?? ''),
        'year_of_study' => (string) ($row['year_of_study'] ?? ''),
        'profile_photo_path' => (string) ($row['profile_photo_path'] ?? ''),
        'age' => isset($row['age']) ? (int) $row['age'] : null,
        'score' => $score,
        'score_label' => 'Questionnaire compatibility',
        'match_id' => isset($row['match_id']) ? (int) $row['match_id'] : null,
        'action' => $action,
    ];
}

usort($results, static function (array $left, array $right): int {
    return ($right['score'] <=> $left['score']) ?: ($left['user_id'] <=> $right['user_id']);
});

$paged = array_slice($results, $offset, $limit);

json_response([
    'success' => true,
    'data' => [
        'results' => $paged,
        'has_more' => $offset + count($paged) < count($results),
        'next_offset' => $offset + count($paged),
        'scoring_version' => 'v1_profile_preference_baseline',
    ],
]);
