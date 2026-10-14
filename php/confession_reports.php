<?php

declare(strict_types=1);

require_once __DIR__ . '/operational.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/logger.php';

/** These reason codes are never user-supplied SQL or HTML. */
function bushisa_confession_report_reasons(): array
{
    return [
        'harassment' => 'Harassment or bullying',
        'hate_speech' => 'Hate speech or discrimination',
        'private_information' => 'Private or identifying information',
        'explicit_content' => 'Inappropriate content',
        'spam' => 'Spam or misleading content',
        'other' => 'Something else',
    ];
}

/** Pure validation for the reporting form, shared with regression tests. */
function bushisa_validate_confession_report(int $confessionId, string $reason, string $details): ?string
{
    if ($confessionId < 1) {
        return 'Please select a valid confession.';
    }
    if (!array_key_exists($reason, bushisa_confession_report_reasons())) {
        return 'Please choose a reason for the report.';
    }
    if (preg_match('//u', $details) !== 1 || strlen($details) > 1800) {
        return 'Please keep your optional explanation brief.';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($details, 'UTF-8') : strlen($details);
    if ($length > 140) {
        return 'Please keep your explanation within 140 characters.';
    }
    return null;
}

/**
 * Report a visible confession; the author is always resolved by the server.
 * Never trust an author ID or profile handle supplied by the browser.
 *
 * @return array{success:bool, error:?string}
 */
function bushisa_report_confession(PDO $pdo, int $reporterId, int $confessionId, string $reason, string $details): array
{
    $details = trim(str_replace("\0", '', $details));
    $error = bushisa_validate_confession_report($confessionId, $reason, $details);
    if ($error !== null) {
        return ['success' => false, 'error' => $error];
    }

    try {
        ensure_operational_schema($pdo);
        $columns = operational_confession_columns($pdo);
        $authorColumn = $columns['author'];
        $getConfession = $pdo->prepare(
            'SELECT id, ' . $authorColumn . ' AS author_id
             FROM confessions WHERE id = :id AND is_flagged = 0 LIMIT 1'
        );
        $getConfession->execute([':id' => $confessionId]);
        $confession = $getConfession->fetch(PDO::FETCH_ASSOC);
        if (!is_array($confession)) {
            return ['success' => false, 'error' => 'This confession is no longer available to report.'];
        }

        $authorId = isset($confession['author_id']) && $confession['author_id'] !== null
            ? (int) $confession['author_id'] : null;
        if ($reporterId === $authorId) {
            return ['success' => false, 'error' => 'You cannot report your own confession.'];
        }

        $checkDuplicate = $pdo->prepare(
            'SELECT id FROM reports
             WHERE reporter_id = :reporter_id AND confession_id = :confession_id
               AND status = "pending"
             LIMIT 1'
        );
        $checkDuplicate->execute([':reporter_id' => $reporterId, ':confession_id' => $confessionId]);
        if ($checkDuplicate->fetchColumn() !== false) {
            return ['success' => false, 'error' => 'You already have a pending report for this confession.'];
        }

        if (!check_rate_limit($pdo, 'report_confession', (string) $reporterId, 5, 3600)) {
            return ['success' => false, 'error' => 'You have reached the reporting limit. Please try again later.'];
        }

        $description = bushisa_confession_report_reasons()[$reason];
        $recordedReason = $description . ($details !== '' ? ' — ' . $details : '');

        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO reports
                (reporter_id, reported_user_id, confession_id, target_type, target_id, reason, status, created_at)
             VALUES
                (:reporter_id, :reported_user_id, :confession_id, "confession", :target_id, :reason, "pending", NOW())'
        );
        $insert->execute([
            ':reporter_id' => $reporterId,
            ':reported_user_id' => $authorId,
            ':confession_id' => $confessionId,
            ':target_id' => $confessionId,
            ':reason' => $recordedReason,
        ]);
        record_attempt($pdo, 'report_confession', (string) $reporterId);
        $pdo->commit();

        log_action($pdo, $reporterId, 'report_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);
        log_to_file('Confession ' . $confessionId . ' received a report for moderator review', 'INFO');
        return ['success' => true, 'error' => null];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_to_file('Confession reporting failed: ' . $exception->getMessage(), 'ERROR');
        return ['success' => false, 'error' => 'We could not submit the report right now. Please try again.'];
    }
}
