<?php

declare(strict_types=1);

/*
CREATE TABLE IF NOT EXISTS blocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blocker_id INT UNSIGNED NOT NULL,
    blocked_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_block_pair (blocker_id, blocked_id),
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
*/

/**
 * Block another user and remove any mutual match.
 *
 * @param PDO $pdo
 * @param int $blocker_id
 * @param int $blocked_id
 * @return bool
 */
function block_user(PDO $pdo, int $blocker_id, int $blocked_id): bool
{
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare('INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (:blocker_id, :blocked_id)');
        $statement->execute([
            ':blocker_id' => $blocker_id,
            ':blocked_id' => $blocked_id,
        ]);

        $matchColumnsStatement = $pdo->query('SHOW COLUMNS FROM matches');
        $matchColumns = $matchColumnsStatement !== false ? $matchColumnsStatement->fetchAll(PDO::FETCH_COLUMN) : [];
        $matchUserAColumn = in_array('user_a_id', $matchColumns, true) ? 'user_a_id' : 'user_one_id';
        $matchUserBColumn = in_array('user_b_id', $matchColumns, true) ? 'user_b_id' : 'user_two_id';

        $deleteMatch = $pdo->prepare(
            sprintf(
                'DELETE FROM matches
                 WHERE (%1$s = :blocker_id AND %2$s = :blocked_id)
                    OR (%1$s = :blocked_id AND %2$s = :blocker_id)',
                $matchUserAColumn,
                $matchUserBColumn
            )
        );
        $deleteMatch->execute([
            ':blocker_id' => $blocker_id,
            ':blocked_id' => $blocked_id,
        ]);

        $pdo->commit();

        return true;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

/**
 * Remove a block relationship.
 *
 * @param PDO $pdo
 * @param int $blocker_id
 * @param int $blocked_id
 * @return bool
 */
function unblock_user(PDO $pdo, int $blocker_id, int $blocked_id): bool
{
    $statement = $pdo->prepare('DELETE FROM blocks WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id');
    return $statement->execute([
        ':blocker_id' => $blocker_id,
        ':blocked_id' => $blocked_id,
    ]);
}

/**
 * Determine whether either user has blocked the other.
 *
 * @param PDO $pdo
 * @param int $user_a
 * @param int $user_b
 * @return bool
 */
function is_blocked(PDO $pdo, int $user_a, int $user_b): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM blocks WHERE (blocker_id = :user_a AND blocked_id = :user_b) OR (blocker_id = :user_b AND blocked_id = :user_a) LIMIT 1'
    );
    $statement->execute([
        ':user_a' => $user_a,
        ':user_b' => $user_b,
    ]);

    return (bool) $statement->fetchColumn();
}

/**
 * Get all user IDs that the given user has blocked or that have blocked the user.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return array<int, int>
 */
function get_blocked_user_ids(PDO $pdo, int $user_id): array
{
    $statement = $pdo->prepare(
        'SELECT blocker_id, blocked_id FROM blocks WHERE blocker_id = :user_id OR blocked_id = :user_id'
    );
    $statement->execute([':user_id' => $user_id]);

    $blockedIds = [];
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }

        $blockerId = isset($row['blocker_id']) ? (int) $row['blocker_id'] : 0;
        $blockedId = isset($row['blocked_id']) ? (int) $row['blocked_id'] : 0;

        if ($blockerId > 0 && $blockerId !== $user_id) {
            $blockedIds[$blockerId] = $blockerId;
        }

        if ($blockedId > 0 && $blockedId !== $user_id) {
            $blockedIds[$blockedId] = $blockedId;
        }
    }

    return array_values($blockedIds);
}
