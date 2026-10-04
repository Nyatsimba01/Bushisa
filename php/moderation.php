<?php

declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/db.php';

/**
 * Suspend a user account.
 */
function suspend_user(PDO $pdo, int $user_id, int $admin_id): bool
{
    try {
        $statement = $pdo->prepare('UPDATE users SET is_suspended = 1 WHERE id = :user_id');
        $success = $statement->execute([':user_id' => $user_id]);

        if ($success) {
            log_action($pdo, $admin_id, 'suspend_user:' . $user_id, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $admin_id . ' suspended user ' . $user_id, 'INFO');
        }

        return $success;
    } catch (Throwable $throwable) {
        log_to_file('Failed to suspend user ' . $user_id . ': ' . $throwable->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Remove a user account suspension.
 */
function unsuspend_user(PDO $pdo, int $user_id, int $admin_id): bool
{
    try {
        $statement = $pdo->prepare('UPDATE users SET is_suspended = 0 WHERE id = :user_id');
        $success = $statement->execute([':user_id' => $user_id]);

        if ($success) {
            log_action($pdo, $admin_id, 'unsuspend_user:' . $user_id, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $admin_id . ' unsuspended user ' . $user_id, 'INFO');
        }

        return $success;
    } catch (Throwable $throwable) {
        log_to_file('Failed to unsuspend user ' . $user_id . ': ' . $throwable->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Flag a confession for moderation review.
 */
function flag_confession(PDO $pdo, int $confession_id): bool
{
    $statement = $pdo->prepare('UPDATE confessions SET is_flagged = 1 WHERE id = :confession_id');
    return $statement->execute([':confession_id' => $confession_id]);
}

/**
 * Remove the moderation flag from a confession.
 */
function unflag_confession(PDO $pdo, int $confession_id): bool
{
    $statement = $pdo->prepare('UPDATE confessions SET is_flagged = 0 WHERE id = :confession_id');
    return $statement->execute([':confession_id' => $confession_id]);
}

/**
 * Delete a confession and record the moderation action.
 */
function delete_confession(PDO $pdo, int $confession_id, int $admin_id): bool
{
    try {
        $statement = $pdo->prepare('DELETE FROM confessions WHERE id = :confession_id');
        $success = $statement->execute([':confession_id' => $confession_id]);

        if ($success) {
            log_action($pdo, $admin_id, 'delete_confession:' . $confession_id, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('Admin ' . $admin_id . ' deleted confession ' . $confession_id, 'INFO');
        }

        return $success;
    } catch (Throwable $throwable) {
        log_to_file('Failed to delete confession ' . $confession_id . ': ' . $throwable->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Get pending reports with reporter and reported user display names.
 *
 * @return array<int, array<string, mixed>>
 */
function get_pending_reports(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT
            reports.id,
            reports.reporter_id,
            reports.reported_user_id,
            reports.reason,
            reports.evidence_path,
            reports.status,
            reports.created_at,
            reporter_profiles.display_name AS reporter_display_name,
            reported_profiles.display_name AS reported_display_name
         FROM reports
         INNER JOIN profiles AS reporter_profiles
            ON reporter_profiles.user_id = reports.reporter_id
         INNER JOIN profiles AS reported_profiles
            ON reported_profiles.user_id = reports.reported_user_id
         WHERE reports.status = :status
         ORDER BY reports.created_at ASC, reports.id ASC'
    );
    $statement->execute([':status' => 'pending']);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get flagged confessions with the author's anonymous handle.
 *
 * @return array<int, array<string, mixed>>
 */
function get_flagged_confessions(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT
            confessions.id,
            confessions.author_id,
            confessions.body,
            confessions.is_flagged,
            confessions.created_at,
            profiles.anon_handle
         FROM confessions
         LEFT JOIN profiles
            ON profiles.user_id = confessions.author_id
         WHERE confessions.is_flagged = 1
         ORDER BY confessions.created_at DESC, confessions.id DESC'
    );
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
