<?php

declare(strict_types=1);

require_once __DIR__ . '/profile_personality.php';

/**
 * Shared, stable discovery pagination for the Home page and its API.
 *
 * The Home 'everyone' scope lists eligible adult accounts without questionnaire filters.
 * Find Match performs additional common-ground ranking separately.
 * Candidates are ordered by immutable user IDs instead of RAND(). Keyset
 * pagination avoids repeats/skips caused by changing offsets after swipes.
 * This is server-authoritative: clients cannot choose eligibility filters.
 */

const BUSHISA_DISCOVERY_BATCH_SIZE = 6;

/**
 * @param array<string,mixed>|null $preferences
 * @return array{profiles:array<int,array<string,mixed>>,has_more:bool,next_cursor:?int}
 */
function bushisa_discovery_batch(PDO $pdo, int $viewerId, ?array $preferences, ?int $afterId = null, string $scope = 'preferences'): array
{
    $empty = ['profiles' => [], 'has_more' => false, 'next_cursor' => null];
    if ($viewerId <= 0 || ($scope !== 'everyone' && ($preferences === null || !isset($preferences['preferred_gender'])))) {
        return $empty;
    }

    $allEligibleUsers = $scope === 'everyone';
    $gender = $allEligibleUsers ? '' : (string) $preferences['preferred_gender'];
    $ageMin = $allEligibleUsers ? 18 : max(18, (int) ($preferences['age_range_min'] ?? 18));
    $ageMax = $allEligibleUsers ? 99 : min(99, (int) ($preferences['age_range_max'] ?? 30));
    if ($ageMax < $ageMin) {
        return $empty;
    }

    $matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
    $columns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
    $matchA = in_array('user_a_id', $columns, true) ? 'user_a_id' : 'user_one_id';
    $matchB = in_array('user_b_id', $columns, true) ? 'user_b_id' : 'user_two_id';

    // Existing block helper checks both directions and initializes its table.
    $blocked = get_blocked_user_ids($pdo, $viewerId);

    // Existing installations work before this optional migration is applied.
    $withPersonality = bushisa_personality_available($pdo);
    $detailsSelect = $withPersonality
        ? 'pp.prompt_text AS conversation_prompt, pp.answer_text AS conversation_answer, pp.interests_json'
        : "'' AS conversation_prompt, '' AS conversation_answer, '[]' AS interests_json";
    $detailsJoin = $withPersonality
        ? 'LEFT JOIN bushisa_profile_personality pp ON pp.user_id = p.user_id'
        : '';

    $sql = <<<SQL
SELECT p.user_id, p.display_name, p.bio, p.faculty, p.year_of_study,
       p.profile_photo_path,
       TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
       {$detailsSelect}
FROM profiles p
INNER JOIN users u ON u.id = p.user_id
{$detailsJoin}
WHERE p.user_id <> :viewer_id
  AND u.is_suspended = 0
  AND p.date_of_birth IS NOT NULL
  AND TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN :age_min AND :age_max
  AND NOT EXISTS (
      SELECT 1 FROM swipes s
      WHERE s.swiper_id = :swipe_viewer AND s.swiped_id = p.user_id
  )
  AND NOT EXISTS (
      SELECT 1 FROM matches m
      WHERE (m.{$matchA} = :match_viewer_a AND m.{$matchB} = p.user_id)
         OR (m.{$matchB} = :match_viewer_b AND m.{$matchA} = p.user_id)
  )
SQL;

    $params = [
        ':viewer_id' => $viewerId,
        ':age_min' => $ageMin,
        ':age_max' => $ageMax,
        ':swipe_viewer' => $viewerId,
        ':match_viewer_a' => $viewerId,
        ':match_viewer_b' => $viewerId,
    ];

    if (!$allEligibleUsers) {
        $sql .= "\n AND p.gender = :gender";
        $params[':gender'] = $gender;
    }

    $faculty = $allEligibleUsers ? 'Any' : (string) ($preferences['preferred_faculty'] ?? 'Any');
    if ($faculty !== 'Any' && $faculty !== '') {
        $sql .= "\n AND p.faculty = :faculty";
        $params[':faculty'] = $faculty;
    }

    if ($afterId !== null && $afterId > 0) {
        $sql .= "\n AND p.user_id > :after_id";
        $params[':after_id'] = $afterId;
    }

    foreach (array_values($blocked) as $index => $blockedId) {
        $placeholder = ':blocked_' . $index;
        $sql .= "\n AND p.user_id <> " . $placeholder;
        $params[$placeholder] = (int) $blockedId;
    }

    $sql .= "\n ORDER BY p.user_id ASC LIMIT :batch_limit";
    $statement = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':batch_limit', BUSHISA_DISCOVERY_BATCH_SIZE + 1, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $hasMore = count($rows) > BUSHISA_DISCOVERY_BATCH_SIZE;
    $profiles = array_slice($rows, 0, BUSHISA_DISCOVERY_BATCH_SIZE);
    $last = $profiles === [] ? null : $profiles[count($profiles) - 1];
    $nextCursor = $hasMore && $last !== null ? (int) $last['user_id'] : null;

    return ['profiles' => bushisa_personality_decode_candidate_rows($profiles), 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
}
