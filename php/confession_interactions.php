<?php

declare(strict_types=1);

require_once __DIR__ . '/operational.php';
require_once __DIR__ . '/logger.php';

/**
 * Every like belongs to one logged-in account and a visible confession.
 * The composite primary key prevents duplicate likes.
 *
 * @return array{success:bool, liked:bool, error:?string}
 */
function bushisa_toggle_confession_like(PDO $pdo, int $userId, int $confessionId): array
{
    if ($userId < 1 || $confessionId < 1) {
        return ['success' => false, 'liked' => false, 'error' => 'Invalid confession.'];
    }

    try {
        ensure_operational_schema($pdo);
        $pdo->beginTransaction();

        $visible = $pdo->prepare(
            'SELECT id FROM confessions WHERE id = :id AND is_flagged = 0 FOR UPDATE'
        );
        $visible->execute([':id' => $confessionId]);
        if ($visible->fetchColumn() === false) {
            $pdo->rollBack();
            return ['success' => false, 'liked' => false, 'error' => 'This confession is not available.'];
        }

        $existing = $pdo->prepare(
            'SELECT 1 FROM confession_likes
             WHERE confession_id = :confession_id AND user_id = :user_id LIMIT 1'
        );
        $existing->execute([':confession_id' => $confessionId, ':user_id' => $userId]);
        $previouslyLiked = $existing->fetchColumn() !== false;

        if ($previouslyLiked) {
            $remove = $pdo->prepare(
                'DELETE FROM confession_likes WHERE confession_id = :confession_id AND user_id = :user_id'
            );
            $remove->execute([':confession_id' => $confessionId, ':user_id' => $userId]);
        } else {
            $add = $pdo->prepare(
                'INSERT INTO confession_likes (confession_id, user_id, created_at)
                 VALUES (:confession_id, :user_id, NOW())'
            );
            $add->execute([':confession_id' => $confessionId, ':user_id' => $userId]);
        }

        $pdo->commit();
        return ['success' => true, 'liked' => !$previouslyLiked, 'error' => null];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_to_file('Confession like toggle failed: ' . $exception->getMessage(), 'ERROR');
        return ['success' => false, 'liked' => false, 'error' => 'Unable to update your like. Please try again.'];
    }
}

/**
 * Delete a confession only when the signed-in user owns the database row.
 * Preserve open moderation evidence by rejecting deletion while a report is
 * pending (or the confession is flagged). Existing reports/audit records stay.
 *
 * @return array{success:bool, error:?string}
 */
function bushisa_delete_own_confession(PDO $pdo, int $userId, int $confessionId): array
{
    if ($userId < 1 || $confessionId < 1) {
        return ['success' => false, 'error' => 'Invalid confession.'];
    }

    try {
        ensure_operational_schema($pdo);
        $authorColumn = operational_confession_columns($pdo)['author'];
        $pdo->beginTransaction();

        // Never trust a posted username/author ID; ownership is from the DB.
        $owner = $pdo->prepare(
            'SELECT ' . $authorColumn . ' AS owner_id, is_flagged
             FROM confessions WHERE id = :id FOR UPDATE'
        );
        $owner->execute([':id' => $confessionId]);
        $row = $owner->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['owner_id'] ?? 0) !== $userId) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'You can only delete your own confession.'];
        }
        if ((int) ($row['is_flagged'] ?? 0) === 1) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'This confession is under moderation review and cannot be deleted yet.'];
        }

        if (operational_table_exists($pdo, 'reports')) {
            $pending = $pdo->prepare(
                "SELECT 1 FROM reports
                 WHERE status = 'pending'
                   AND (confession_id = :confession_id
                        OR (target_type = 'confession' AND target_id = :target_id))
                 LIMIT 1"
            );
            $pending->execute([
                ':confession_id' => $confessionId,
                ':target_id' => $confessionId,
            ]);
            if ($pending->fetchColumn() !== false) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'This confession has a pending report. It cannot be deleted until moderation review is complete.'];
            }
        }

        foreach (['confession_likes', 'confession_views'] as $table) {
            $deleteRelations = $pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE confession_id = :confession_id'
            );
            $deleteRelations->execute([':confession_id' => $confessionId]);
        }
        $deleteReferences = $pdo->prepare(
            'DELETE FROM confession_references
             WHERE confession_id = :confession_id OR referenced_confession_id = :referenced_confession_id'
        );
        $deleteReferences->execute([
            ':confession_id' => $confessionId,
            ':referenced_confession_id' => $confessionId,
        ]);
        $delete = $pdo->prepare(
            'DELETE FROM confessions WHERE id = :confession_id AND ' . $authorColumn . ' = :owner_id'
        );
        $delete->execute([':confession_id' => $confessionId, ':owner_id' => $userId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Confession delete did not affect exactly one owned row.');
        }

        $pdo->commit();

        try {
            log_action($pdo, $userId, 'self_delete_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
            log_to_file('User ' . $userId . ' removed own confession ' . $confessionId, 'INFO');
        } catch (Throwable $logException) {
            error_log('Confession deletion audit failed: ' . $logException->getMessage());
        }

        return ['success' => true, 'error' => null];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_to_file('Own confession deletion failed: ' . $exception->getMessage(), 'ERROR');
        return ['success' => false, 'error' => 'Unable to delete your confession right now. Please try again.'];
    }
}
