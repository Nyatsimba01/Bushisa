<?php

declare(strict_types=1);

/**
 * Find a user by email address.
 *
 * @param PDO $pdo
 * @param string $email
 * @return array<string, mixed>|null
 */
function find_user_by_email(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $statement->execute([':email' => $email]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Find a user by ID.
 *
 * @param PDO $pdo
 * @param int $id
 * @return array<string, mixed>|null
 */
function find_user_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Get a profile by user ID.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return array<string, mixed>|null
 */
function get_profile(PDO $pdo, int $user_id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM profiles WHERE user_id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $user_id]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Get preferences by user ID.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return array<string, mixed>|null
 */
function get_preferences(PDO $pdo, int $user_id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM preferences WHERE user_id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $user_id]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Update a user's profile fields from a whitelisted data set.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param array<string, mixed> $data
 * @return bool
 */
function update_profile(PDO $pdo, int $user_id, array $data): bool
{
    $allowedColumns = [
        'display_name',
        'bio',
        'faculty',
        'year_of_study',
        'profile_photo_path',
    ];

    $hasUpdate = false;
    $params = [
        ':user_id' => $user_id,
    ];

    $sql = <<<'SQL'
UPDATE profiles SET
    display_name = CASE WHEN :set_display_name = 1 THEN :display_name ELSE display_name END,
    bio = CASE WHEN :set_bio = 1 THEN :bio ELSE bio END,
    faculty = CASE WHEN :set_faculty = 1 THEN :faculty ELSE faculty END,
    year_of_study = CASE WHEN :set_year_of_study = 1 THEN :year_of_study ELSE year_of_study END,
    profile_photo_path = CASE WHEN :set_profile_photo_path = 1 THEN :profile_photo_path ELSE profile_photo_path END
WHERE user_id = :user_id
SQL;

    foreach ($allowedColumns as $column) {
        $flagKey = ':set_' . $column;
        $valueKey = ':' . $column;
        $present = array_key_exists($column, $data);
        $params[$flagKey] = $present ? 1 : 0;
        $params[$valueKey] = $present ? $data[$column] : null;
        $hasUpdate = $hasUpdate || $present;
    }

    if (!$hasUpdate) {
        return true;
    }

    $statement = $pdo->prepare($sql);
    return $statement->execute($params);
}

/**
 * Update a user's preferences from a whitelisted data set.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param array<string, mixed> $data
 * @return bool
 */
function update_preferences(PDO $pdo, int $user_id, array $data): bool
{
    $allowedColumns = [
        'preferred_gender',
        'age_range_min',
        'age_range_max',
        'preferred_faculty',
    ];

    $hasUpdate = false;
    $params = [
        ':user_id' => $user_id,
    ];

    $sql = <<<'SQL'
UPDATE preferences SET
    preferred_gender = CASE WHEN :set_preferred_gender = 1 THEN :preferred_gender ELSE preferred_gender END,
    age_range_min = CASE WHEN :set_age_range_min = 1 THEN :age_range_min ELSE age_range_min END,
    age_range_max = CASE WHEN :set_age_range_max = 1 THEN :age_range_max ELSE age_range_max END,
    preferred_faculty = CASE WHEN :set_preferred_faculty = 1 THEN :preferred_faculty ELSE preferred_faculty END
WHERE user_id = :user_id
SQL;

    foreach ($allowedColumns as $column) {
        $flagKey = ':set_' . $column;
        $valueKey = ':' . $column;
        $present = array_key_exists($column, $data);
        $params[$flagKey] = $present ? 1 : 0;
        $params[$valueKey] = $present ? $data[$column] : null;
        $hasUpdate = $hasUpdate || $present;
    }

    if (!$hasUpdate) {
        return true;
    }

    $statement = $pdo->prepare($sql);
    return $statement->execute($params);
}

/**
 * Determine whether a user is suspended.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return bool
 */
function is_user_suspended(PDO $pdo, int $user_id): bool
{
    $statement = $pdo->prepare('SELECT is_suspended FROM users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $user_id]);

    $value = $statement->fetchColumn();
    return $value !== false && (int) $value === 1;
}