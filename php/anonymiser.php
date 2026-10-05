<?php

declare(strict_types=1);

require_once __DIR__ . '/logger.php';

/**
 * Anonymise a user while preserving rows needed for referential integrity.
 */
function anonymise_user(PDO $pdo, int $user_id, int $initiator_id): bool
{
    try {
        $pdo->beginTransaction();

        $profilePhotoPath = anonymiser_get_profile_photo_path($pdo, $user_id);
        anonymiser_delete_file($profilePhotoPath);

        if (anonymiser_table_exists($pdo, 'preferences')) {
            $deletePreferences = $pdo->prepare('DELETE FROM preferences WHERE user_id = :user_id');
            $deletePreferences->execute([':user_id' => $user_id]);
        }

        $anonHandle = anonymiser_unique_deleted_handle($pdo);
        $profileSets = [
            'display_name = :display_name',
            'bio = :bio',
            'anon_handle = :anon_handle',
        ];
        if (anonymiser_column_exists($pdo, 'profiles', 'profile_photo_path')) {
            $profileSets[] = 'profile_photo_path = NULL';
        }

        $updateProfile = $pdo->prepare(
            'UPDATE profiles SET ' . implode(', ', $profileSets) . ' WHERE user_id = :user_id'
        );
        $updateProfile->execute([
            ':display_name' => 'Deleted User',
            ':bio' => '',
            ':anon_handle' => $anonHandle,
            ':user_id' => $user_id,
        ]);

        if (anonymiser_table_exists($pdo, 'consent_records')) {
            $revokeConsents = $pdo->prepare(
                'UPDATE consent_records
                 SET revoked_at = NOW()
                 WHERE user_id = :user_id
                   AND revoked_at IS NULL'
            );
            $revokeConsents->execute([':user_id' => $user_id]);
        }

        anonymiser_detach_confessions($pdo, $user_id);
        anonymiser_soft_delete_user($pdo, $user_id);

        log_action($pdo, $initiator_id, 'anonymise_user:' . $user_id, $_SERVER['REMOTE_ADDR'] ?? null);
        log_to_file('Initiator ' . $initiator_id . ' anonymised user ' . $user_id, 'INFO');

        $pdo->commit();
        return true;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        log_to_file('Failed to anonymise user ' . $user_id . ': ' . $throwable->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Permanently delete a user and their upload directory. Use only when legally required.
 */
function hard_delete_user(PDO $pdo, int $user_id, int $initiator_id): bool
{
    try {
        $pdo->beginTransaction();

        log_action($pdo, $initiator_id, 'hard_delete_user:' . $user_id, $_SERVER['REMOTE_ADDR'] ?? null);

        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :user_id');
        $success = $deleteUser->execute([':user_id' => $user_id]);

        if (!$success) {
            $pdo->rollBack();
            return false;
        }

        $pdo->commit();
        anonymiser_delete_directory(anonymiser_upload_directory($user_id));
        log_to_file('Initiator ' . $initiator_id . ' hard deleted user ' . $user_id, 'INFO');

        return true;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        log_to_file('Failed to hard delete user ' . $user_id . ': ' . $throwable->getMessage(), 'ERROR');
        return false;
    }
}

function anonymiser_get_profile_photo_path(PDO $pdo, int $user_id): ?string
{
    if (!anonymiser_column_exists($pdo, 'profiles', 'profile_photo_path')) {
        return null;
    }

    $statement = $pdo->prepare('SELECT profile_photo_path FROM profiles WHERE user_id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $user_id]);

    $path = $statement->fetchColumn();
    return is_string($path) && $path !== '' ? $path : null;
}

function anonymiser_unique_deleted_handle(PDO $pdo): string
{
    do {
        $handle = 'deleted_' . bin2hex(random_bytes(4));
        $statement = $pdo->prepare('SELECT user_id FROM profiles WHERE anon_handle = :anon_handle LIMIT 1');
        $statement->execute([':anon_handle' => $handle]);
    } while ($statement->fetchColumn() !== false);

    return $handle;
}

function anonymiser_detach_confessions(PDO $pdo, int $user_id): void
{
    if (anonymiser_column_exists($pdo, 'confessions', 'author_id')) {
        $statement = $pdo->prepare('UPDATE confessions SET author_id = NULL WHERE author_id = :user_id');
        $statement->execute([':user_id' => $user_id]);
    }

    if (anonymiser_column_exists($pdo, 'confessions', 'user_id')) {
        $setClause = anonymiser_column_nullable($pdo, 'confessions', 'user_id')
            ? 'user_id = NULL, anon_handle = :anon_handle'
            : 'anon_handle = :anon_handle';

        $statement = $pdo->prepare('UPDATE confessions SET ' . $setClause . ' WHERE user_id = :user_id');
        $statement->execute([
            ':anon_handle' => 'deleted',
            ':user_id' => $user_id,
        ]);
    }
}

function anonymiser_soft_delete_user(PDO $pdo, int $user_id): void
{
    $sets = ['password_hash = :password_hash'];
    $params = [
        ':password_hash' => '',
        ':user_id' => $user_id,
    ];

    if (anonymiser_column_exists($pdo, 'users', 'is_active')) {
        $sets[] = 'is_active = 0';
    }

    if (anonymiser_column_exists($pdo, 'users', 'email')) {
        $sets[] = 'email = :email';
        $params[':email'] = anonymiser_deleted_email($user_id);
    }

    if (anonymiser_column_exists($pdo, 'users', 'student_email')) {
        $sets[] = 'student_email = :student_email';
        $params[':student_email'] = anonymiser_deleted_email($user_id);
    }

    if (anonymiser_column_exists($pdo, 'users', 'nust_student_id')) {
        $sets[] = 'nust_student_id = :nust_student_id';
        $params[':nust_student_id'] = 'deleted_' . bin2hex(random_bytes(4));
    }

    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :user_id';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
}

function anonymiser_column_exists(PDO $pdo, string $table, string $column): bool
{
    $allowedTables = ['users', 'profiles', 'confessions'];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $statement = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column_name');
    $statement->execute([':column_name' => $column]);

    return $statement->fetch(PDO::FETCH_ASSOC) !== false;
}

function anonymiser_table_exists(PDO $pdo, string $table): bool
{
    $allowedTables = ['preferences', 'consent_records'];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $statement = $pdo->prepare('SHOW TABLES LIKE :table_name');
    $statement->execute([':table_name' => $table]);

    return $statement->fetchColumn() !== false;
}

function anonymiser_column_nullable(PDO $pdo, string $table, string $column): bool
{
    $allowedTables = ['users', 'profiles', 'confessions'];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $statement = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column_name');
    $statement->execute([':column_name' => $column]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && isset($row['Null']) && strtoupper((string) $row['Null']) === 'YES';
}

function anonymiser_deleted_email(int $user_id): string
{
    return hash('sha256', 'deleted_user_' . $user_id . '_' . bin2hex(random_bytes(8))) . '@deleted.local';
}

function anonymiser_delete_file(?string $path): void
{
    if ($path === null) {
        return;
    }

    $absolutePath = anonymiser_resolve_project_path($path);
    if ($absolutePath !== null && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function anonymiser_upload_directory(int $user_id): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . (string) $user_id;
}

function anonymiser_delete_directory(string $directory): void
{
    $baseUploads = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
    $target = realpath($directory);

    if ($baseUploads === false || $target === false || !is_dir($target)) {
        return;
    }

    if (strpos($target, $baseUploads . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($target);
}

function anonymiser_resolve_project_path(string $path): ?string
{
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) {
        return null;
    }

    $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $candidate = $path;
    if (!preg_match('/^[A-Za-z]:\\\\/', $path) && strpos($path, DIRECTORY_SEPARATOR) !== 0) {
        $candidate = $projectRoot . DIRECTORY_SEPARATOR . ltrim($normalizedPath, DIRECTORY_SEPARATOR);
    }

    $resolved = realpath($candidate);
    if ($resolved === false || strpos($resolved, $projectRoot . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }

    return $resolved;
}
