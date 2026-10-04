<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/block_handler.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$preferences = get_preferences($pdo, $currentUserId);
$candidates = [];

if ($preferences !== null && isset($preferences['preferred_gender'])) {
    $preferredGender = (string) $preferences['preferred_gender'];
    $ageRangeMin = isset($preferences['age_range_min']) ? (int) $preferences['age_range_min'] : 18;
    $ageRangeMax = isset($preferences['age_range_max']) ? (int) $preferences['age_range_max'] : 30;
    $preferredFaculty = isset($preferences['preferred_faculty']) ? (string) $preferences['preferred_faculty'] : 'Any';
    $blockedUserIds = get_blocked_user_ids($pdo, $currentUserId);

    $sql = <<<SQL
SELECT
    p.user_id,
    p.display_name,
    p.bio,
    p.gender,
    p.date_of_birth,
    p.faculty,
    p.year_of_study,
    p.profile_photo_path,
    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
FROM profiles p
INNER JOIN users u ON u.id = p.user_id
WHERE p.user_id <> :current_user_id
  AND u.is_suspended = 0
  AND p.gender = :preferred_gender
  AND p.date_of_birth IS NOT NULL
  AND TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN :age_min AND :age_max
  AND NOT EXISTS (
      SELECT 1
      FROM swipes s
      WHERE s.swiper_id = :current_user_id
        AND s.swiped_id = p.user_id
  )
SQL;

    $params = [
        ':current_user_id' => $currentUserId,
        ':preferred_gender' => $preferredGender,
        ':age_min' => $ageRangeMin,
        ':age_max' => $ageRangeMax,
    ];

    if ($preferredFaculty !== 'Any' && $preferredFaculty !== '') {
        $sql .= "\n  AND p.faculty = :preferred_faculty";
        $params[':preferred_faculty'] = $preferredFaculty;
    }

    if ($blockedUserIds !== []) {
        $blockedPlaceholders = [];
        foreach (array_values($blockedUserIds) as $index => $blockedUserId) {
            $placeholder = ':blocked_' . $index;
            $blockedPlaceholders[] = $placeholder;
            $params[$placeholder] = (int) $blockedUserId;
        }

        $sql .= "\n  AND p.user_id NOT IN (" . implode(', ', $blockedPlaceholders) . ')';
    }

    $sql .= "\nORDER BY RAND()\nLIMIT 10";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $candidates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// --- Frontend HTML will be added later ---