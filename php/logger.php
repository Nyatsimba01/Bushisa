<?php

declare(strict_types=1);

require_once __DIR__ . '/operational.php';

/**
 * Log an action to the audit_log table.
 *
 * @param PDO $pdo
 * @param int|null $user_id
 * @param string $action
 * @param string|null $ip
 * @return void
 */
function log_action(PDO $pdo, ?int $user_id, string $action, ?string $ip = null): void
{
    ensure_operational_schema($pdo);
    $resolvedIp = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null);

    $sql = <<<'SQL'
INSERT INTO audit_log (user_id, action, ip_address, timestamp)
VALUES (:user_id, :action, :ip_address, NOW())
SQL;

    $statement = $pdo->prepare($sql);
    $statement->bindValue(':user_id', $user_id, $user_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue(':action', $action, PDO::PARAM_STR);
    $statement->bindValue(':ip_address', $resolvedIp, $resolvedIp === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $statement->execute();
}

/**
 * Append a message to the flat-file application log.
 *
 * @param string $message
 * @param string $level
 * @return void
 */
function log_to_file(string $message, string $level = 'INFO'): void
{
    $logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0775, true);
    }

    $logFile = $logDirectory . DIRECTORY_SEPARATOR . 'app.log';
    $line = sprintf('[%s] [%s] %s%s', date('Y-m-d H:i:s'), strtoupper($level), $message, PHP_EOL);
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
