<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/logger.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/operational.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody !== false ? $rawBody : '', true);
$payload = is_array($payload) ? $payload : $_POST;

$submittedToken = '';
if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (isset($payload['csrf_token']) && is_string($payload['csrf_token'])) {
    $submittedToken = $payload['csrf_token'];
}

if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
    json_response(['error' => 'Invalid CSRF token'], 403);
}

$action = isset($payload['action']) && is_string($payload['action'])
    ? clean_enum($payload['action'], ['block', 'unblock', 'report'])
    : null;

if ($action === 'block' || $action === 'unblock') {
    $targetUserId = clean_int($payload['user_id'] ?? null);
    if ($targetUserId === null || $targetUserId <= 0 || $targetUserId === $currentUserId) {
        json_response(['error' => 'Invalid user'], 400);
    }

    if ($action === 'block') {
        $statement = $pdo->prepare(
            'INSERT IGNORE INTO blocks (blocker_id, blocked_id, created_at)
             VALUES (:blocker_id, :blocked_id, NOW())'
        );
        $statement->execute([
            ':blocker_id' => $currentUserId,
            ':blocked_id' => $targetUserId,
        ]);

        $matchColumns = operational_match_columns($pdo);
        $deleteMatch = $pdo->prepare(
            sprintf(
                'DELETE FROM matches
                 WHERE (%1$s = :current_user_id AND %2$s = :target_user_id)
                    OR (%1$s = :target_user_id AND %2$s = :current_user_id)',
                $matchColumns['user_a'],
                $matchColumns['user_b']
            )
        );
        $deleteMatch->execute([
            ':current_user_id' => $currentUserId,
            ':target_user_id' => $targetUserId,
        ]);

        log_action($pdo, $currentUserId, 'block_user:' . $targetUserId, $_SERVER['REMOTE_ADDR'] ?? null);
    } else {
        $statement = $pdo->prepare(
            'DELETE FROM blocks
             WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id'
        );
        $statement->execute([
            ':blocker_id' => $currentUserId,
            ':blocked_id' => $targetUserId,
        ]);

        log_action($pdo, $currentUserId, 'unblock_user:' . $targetUserId, $_SERVER['REMOTE_ADDR'] ?? null);
    }

    json_response(['success' => true, 'data' => null]);
}

if ($action === 'report') {
    $targetType = isset($payload['target_type']) && is_string($payload['target_type'])
        ? clean_enum($payload['target_type'], ['user', 'photo', 'confession', 'conversation'])
        : null;
    $targetId = clean_int($payload['target_id'] ?? null);
    $reportedUserId = clean_int($payload['reported_user_id'] ?? null);
    $reason = isset($payload['reason']) && is_string($payload['reason']) ? clean($payload['reason']) : '';
    $allowedReasons = ['harassment', 'hate_speech', 'explicit_content', 'spam', 'catfish_impersonation', 'other'];

    if ($targetType === null || $targetId === null || $targetId <= 0 || $reason === '') {
        json_response(['error' => 'Invalid report'], 400);
    }

    if (!in_array($reason, $allowedReasons, true)) {
        json_response(['error' => 'Invalid report reason'], 400);
    }

    if ($targetType === 'user') {
        $reportedUserId = $targetId;
    }

    if ($reportedUserId === $currentUserId) {
        json_response(['error' => 'You cannot report yourself'], 400);
    }

    $reportsColumns = $pdo->query('SHOW COLUMNS FROM reports')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $reportedUserNullable = false;
    foreach ($reportsColumns as $column) {
        if (($column['Field'] ?? '') === 'reported_user_id') {
            $reportedUserNullable = strtoupper((string) ($column['Null'] ?? 'NO')) === 'YES';
            break;
        }
    }

    if ($reportedUserId === null && !$reportedUserNullable) {
        json_response(['error' => 'This report target needs a user-based report contract first'], 501);
    }

    $statement = $pdo->prepare(
        'INSERT INTO reports (reporter_id, reported_user_id, confession_id, target_type, target_id, reason, status, created_at)
         VALUES (:reporter_id, :reported_user_id, :confession_id, :target_type, :target_id, :reason, "pending", NOW())'
    );
    $statement->execute([
        ':reporter_id' => $currentUserId,
        ':reported_user_id' => $reportedUserId,
        ':confession_id' => $targetType === 'confession' ? $targetId : null,
        ':target_type' => $targetType,
        ':target_id' => $targetId,
        ':reason' => $reason,
    ]);

    log_action($pdo, $currentUserId, 'report_' . $targetType . ':' . $targetId, $_SERVER['REMOTE_ADDR'] ?? null);
    json_response(['success' => true, 'data' => ['status' => 'pending']]);
}

json_response(['error' => 'Invalid safety action'], 400);

