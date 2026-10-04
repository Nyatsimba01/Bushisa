<?php

declare(strict_types=1);

/*
CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    identifier VARCHAR(100) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lookup (action, identifier, attempted_at)
) ENGINE=InnoDB;
*/

/**
 * Check whether an action is still within the allowed rate limit.
 *
 * @param PDO $pdo
 * @param string $action
 * @param string $identifier
 * @param int $max_attempts
 * @param int $window_seconds
 * @return bool
 */
function check_rate_limit(PDO $pdo, string $action, string $identifier, int $max_attempts, int $window_seconds): bool
{
    $sql = <<<'SQL'
SELECT COUNT(*) AS attempt_count
FROM rate_limits
WHERE action = :action
  AND identifier = :identifier
  AND attempted_at >= (NOW() - INTERVAL :window_seconds SECOND)
SQL;

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':action', $action, PDO::PARAM_STR);
    $statement->bindValue(':identifier', $identifier, PDO::PARAM_STR);
    $statement->bindValue(':window_seconds', $window_seconds, PDO::PARAM_INT);
    $statement->execute();

    $attemptCount = (int) $statement->fetchColumn();

    return $attemptCount < $max_attempts;
}

/**
 * Record a single rate-limit attempt.
 *
 * @param PDO $pdo
 * @param string $action
 * @param string $identifier
 * @return void
 */
function record_attempt(PDO $pdo, string $action, string $identifier): void
{
    $sql = <<<'SQL'
INSERT INTO rate_limits (action, identifier, attempted_at)
VALUES (:action, :identifier, NOW())
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':action' => $action,
        ':identifier' => $identifier,
    ]);
}

/**
 * Clear recorded attempts for a specific action and identifier.
 *
 * @param PDO $pdo
 * @param string $action
 * @param string $identifier
 * @return void
 */
function clear_attempts(PDO $pdo, string $action, string $identifier): void
{
    $sql = <<<'SQL'
DELETE FROM rate_limits
WHERE action = :action
  AND identifier = :identifier
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute([
        ':action' => $action,
        ':identifier' => $identifier,
    ]);
}
