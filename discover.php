<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/function.php';
require_once __DIR__ . '/php/block_handler.php';
require_once __DIR__ . '/php/frontend.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$preferences = get_preferences($pdo, $currentUserId);
$candidates = [];
$establishedMatches = [];

$matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
$matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
$matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';
$matchTimeColumn = in_array('matched_at', $matchColumns, true) ? 'matched_at' : 'created_at';

$matchesSql = sprintf(
    'SELECT
        m.id AS match_id,
        CASE WHEN m.%1$s = :select_user THEN m.%2$s ELSE m.%1$s END AS other_user_id,
        p.display_name,
        p.profile_photo_path,
        p.faculty,
        m.%3$s AS matched_at
     FROM matches m
     INNER JOIN profiles p
        ON p.user_id = CASE WHEN m.%1$s = :join_user THEN m.%2$s ELSE m.%1$s END
     WHERE m.%1$s = :where_user_a OR m.%2$s = :where_user_b
     ORDER BY m.%3$s DESC, m.id DESC
     LIMIT 12',
    $matchUserAColumn,
    $matchUserBColumn,
    $matchTimeColumn
);
$matchesStatement = $pdo->prepare($matchesSql);
$matchesStatement->execute([
    ':select_user' => $currentUserId,
    ':join_user' => $currentUserId,
    ':where_user_a' => $currentUserId,
    ':where_user_b' => $currentUserId,
]);
$establishedMatches = $matchesStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
WHERE p.user_id <> :exclude_user
  AND u.is_suspended = 0
  AND p.gender = :preferred_gender
  AND p.date_of_birth IS NOT NULL
  AND TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN :age_min AND :age_max
  AND NOT EXISTS (
      SELECT 1
      FROM swipes s
      WHERE s.swiper_id = :swiper_user
        AND s.swiped_id = p.user_id
  )
  AND NOT EXISTS (
      SELECT 1
      FROM matches m
      WHERE (m.{$matchUserAColumn} = :match_user_a AND m.{$matchUserBColumn} = p.user_id)
         OR (m.{$matchUserBColumn} = :match_user_b AND m.{$matchUserAColumn} = p.user_id)
  )
SQL;

    $params = [
        ':exclude_user' => $currentUserId,
        ':swiper_user' => $currentUserId,
        ':match_user_a' => $currentUserId,
        ':match_user_b' => $currentUserId,
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

render_page_shell_start('home', 'Bushisa', null, $currentUserId, true);
?>
<section class="matches-strip" aria-labelledby="matches-title">
    <h2 class="sr-only" id="matches-title">Established matches</h2>
    <?php if ($establishedMatches === []): ?>
        <div class="empty-state">
            <h2>No established matches yet</h2>
            <p>Find compatible students, send likes, and mutual likes will appear here. A match still is not chat permission.</p>
            <a class="button-secondary" href="matches.php">Go to Find Match</a>
        </div>
    <?php else: ?>
        <ul class="matches-strip__list" aria-label="Established matches">
            <?php foreach ($establishedMatches as $match): ?>
                <li>
                    <a class="match-avatar-link" href="chat.php?match_id=<?= e($match['match_id'] ?? '') ?>" aria-label="Open consent-aware chat with <?= e($match['display_name'] ?? 'match') ?>">
                        <?= render_avatar($match['profile_photo_path'] ?? null, (string) ($match['display_name'] ?? 'Match')) ?>
                        <span><?= e($match['display_name'] ?? 'Match') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section aria-labelledby="home-heading">
    <h2 class="hero-statement" id="home-heading">A little spark. A real connection.</h2>

    <?php if ($preferences === null): ?>
        <div class="empty-state">
            <h2>Set your discovery preferences</h2>
            <p>Bushisa needs basic preferences before it can show eligible nonmatches.</p>
            <a class="button" href="profile.php#preferences">Set preferences</a>
        </div>
    <?php elseif ($candidates === []): ?>
        <div class="empty-state">
            <h2>No discovery profiles right now</h2>
            <p>There are no eligible nonmatches for your current preferences. You can broaden your preferences or check back later.</p>
            <a class="button-secondary" href="profile.php#preferences">Edit preferences</a>
        </div>
    <?php else: ?>
        <div class="profile-grid" data-static-discovery>
            <?php foreach ($candidates as $candidate): ?>
                <?php
                $name = (string) ($candidate['display_name'] ?? 'Student');
                $age = isset($candidate['age']) ? (int) $candidate['age'] : null;
                $meta = array_filter([
                    $age !== null ? $age . ' years old' : null,
                    $candidate['faculty'] ?? null,
                    $candidate['year_of_study'] ?? null,
                ]);
                ?>
                <article class="profile-card" data-candidate-id="<?= e($candidate['user_id'] ?? '') ?>">
                    <div class="profile-card__image">
                        <?= render_avatar($candidate['profile_photo_path'] ?? null, $name) ?>
                    </div>
                    <div class="profile-card__body">
                        <h3 class="profile-card__name"><?= e($name) ?></h3>
                        <?php if ($meta !== []): ?>
                            <p class="profile-card__meta"><?= e(implode(' · ', $meta)) ?></p>
                        <?php endif; ?>
                        <p class="profile-card__bio"><?= e($candidate['bio'] !== '' ? $candidate['bio'] : 'No bio added yet.') ?></p>
                        <div class="card-actions">
                            <button class="discover-card__action discover-card__action--like" type="button" data-swipe-target="<?= e($candidate['user_id'] ?? '') ?>" data-swipe-direction="like">Like</button>
                            <button class="discover-card__action" type="button" data-swipe-target="<?= e($candidate['user_id'] ?? '') ?>" data-swipe-direction="pass">Pass</button>
                            <a class="button-secondary" href="community_guidelines.php">Report</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
render_page_shell_end();
