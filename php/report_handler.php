<?php

declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/sanitize.php';

/**
 * Submit a report against another user.
 *
 * @param PDO $pdo
 * @param int $reporter_id
 * @param int $reported_user_id
 * @param string $reason
 * @param string|null $evidence_path
 * @return array{success: bool, error: string|null}
 */
function submit_report(PDO $pdo, int $reporter_id, int $reported_user_id, string $reason, ?string $evidence_path = null): array
{
    if ($reporter_id === $reported_user_id) {
        return [
            'success' => false,
            'error' => 'You cannot report yourself.',
        ];
    }

    $reason = clean($reason);
    $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 255) : substr($reason, 0, 255);
    if ($reason === '') {
        return [
            'success' => false,
            'error' => 'Please provide a report reason.',
        ];
    }

    $userStatement = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
    $userStatement->execute([':id' => $reported_user_id]);
    if ($userStatement->fetchColumn() === false) {
        return [
            'success' => false,
            'error' => 'The reported user does not exist.',
        ];
    }

    $duplicateStatement = $pdo->prepare(
        'SELECT id FROM reports
         WHERE reporter_id = :reporter_id
           AND reported_user_id = :reported_user_id
           AND status = :status
         LIMIT 1'
    );
    $duplicateStatement->execute([
        ':reporter_id' => $reporter_id,
        ':reported_user_id' => $reported_user_id,
        ':status' => 'pending',
    ]);

    if ($duplicateStatement->fetchColumn() !== false) {
        return [
            'success' => false,
            'error' => 'You have already submitted a pending report against this user.',
        ];
    }

    try {
        $insertStatement = $pdo->prepare(
            'INSERT INTO reports (reporter_id, reported_user_id, reason, evidence_path, status, created_at)
             VALUES (:reporter_id, :reported_user_id, :reason, :evidence_path, :status, NOW())'
        );
        $insertStatement->execute([
            ':reporter_id' => $reporter_id,
            ':reported_user_id' => $reported_user_id,
            ':reason' => $reason,
            ':evidence_path' => $evidence_path,
            ':status' => 'pending',
        ]);

        log_action($pdo, $reporter_id, 'report_user:' . $reported_user_id, $_SERVER['REMOTE_ADDR'] ?? null);
        log_to_file('User ' . $reporter_id . ' reported user ' . $reported_user_id, 'INFO');

        return [
            'success' => true,
            'error' => null,
        ];
    } catch (Throwable $throwable) {
        log_to_file('Report submission failed for user ' . $reporter_id . ': ' . $throwable->getMessage(), 'ERROR');

        return [
            'success' => false,
            'error' => 'Unable to submit report right now.',
        ];
    }
}

/**
 * Get reports filed by a given user.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return array<int, array<string, mixed>>
 */
function get_reports_by_user(PDO $pdo, int $user_id): array
{
    $statement = $pdo->prepare(
        'SELECT id, reporter_id, reported_user_id, reason, evidence_path, status, created_at
         FROM reports
         WHERE reporter_id = :user_id
         ORDER BY created_at DESC, id DESC'
    );
    $statement->execute([':user_id' => $user_id]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get reports filed against a given user.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return array<int, array<string, mixed>>
 */
function get_reports_against_user(PDO $pdo, int $user_id): array
{
    $statement = $pdo->prepare(
        'SELECT id, reporter_id, reported_user_id, reason, evidence_path, status, created_at
         FROM reports
         WHERE reported_user_id = :user_id
         ORDER BY created_at DESC, id DESC'
    );
    $statement->execute([':user_id' => $user_id]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Update a report status.
 *
 * @param PDO $pdo
 * @param int $report_id
 * @param string $new_status
 * @return bool
 */
function update_report_status(PDO $pdo, int $report_id, string $new_status): bool
{
    $allowedStatuses = ['reviewed', 'dismissed', 'action_taken'];
    if (!in_array($new_status, $allowedStatuses, true)) {
        return false;
    }

    $statement = $pdo->prepare('UPDATE reports SET status = :status WHERE id = :id');
    return $statement->execute([
        ':status' => $new_status,
        ':id' => $report_id,
    ]);
}
