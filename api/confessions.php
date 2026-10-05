<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/security_headers.php';
require_once __DIR__ . '/../php/session_manager.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../php/csrf.php';
require_once __DIR__ . '/../php/sanitize.php';
require_once __DIR__ . '/../php/db.php';
require_once __DIR__ . '/../php/rate_limit.php';
require_once __DIR__ . '/../php/logger.php';
require_once __DIR__ . '/../php/function.php';
require_once __DIR__ . '/../php/operational.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

require_login();
ensure_operational_schema($pdo);

$currentUserId = (int) $_SESSION['user_id'];
$confessionColumns = operational_confession_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = clean_int($_GET['limit'] ?? null) ?? 20;
    $limit = max(1, min(50, $limit));
    $offset = clean_int($_GET['offset'] ?? null) ?? 0;
    $offset = max(0, $offset);
    $confessionId = clean_int($_GET['confession_id'] ?? null);

    if ($confessionId !== null && $confessionId > 0) {
        $viewerHash = operational_viewer_hash($currentUserId);
        $viewStatement = $pdo->prepare(
            'INSERT IGNORE INTO confession_views (confession_id, viewer_hash, viewed_at)
             VALUES (:confession_id, :viewer_hash, NOW())'
        );
        $viewStatement->execute([
            ':confession_id' => $confessionId,
            ':viewer_hash' => $viewerHash,
        ]);
    }

    $statement = $pdo->prepare(
        sprintf(
            'SELECT
                c.id,
                c.%1$s AS body,
                c.created_at,
                c.is_flagged,
                p.anon_handle,
                COUNT(cv.id) AS view_count,
                rc.referenced_confession_id,
                ref.%1$s AS reference_body,
                rp.anon_handle AS reference_handle
             FROM confessions c
             LEFT JOIN profiles p ON p.user_id = c.%2$s
             LEFT JOIN confession_views cv ON cv.confession_id = c.id
             LEFT JOIN confession_references rc ON rc.confession_id = c.id
             LEFT JOIN confessions ref ON ref.id = rc.referenced_confession_id
             LEFT JOIN profiles rp ON rp.user_id = ref.%2$s
             WHERE c.is_flagged = 0
             GROUP BY c.id, c.%1$s, c.created_at, c.is_flagged, p.anon_handle, rc.referenced_confession_id, ref.%1$s, rp.anon_handle
             ORDER BY view_count DESC, c.created_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset',
            $confessionColumns['body'],
            $confessionColumns['author']
        )
    );
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_map(static function (array $row): array {
        $referenceBody = isset($row['reference_body']) && is_string($row['reference_body'])
            ? trim(strip_tags(html_entity_decode($row['reference_body'], ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            : '';

        return [
            'id' => (int) $row['id'],
            'body' => (string) ($row['body'] ?? ''),
            'anon_handle' => (string) ($row['anon_handle'] ?? 'anonymous'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'view_count' => (int) ($row['view_count'] ?? 0),
            'reference' => !empty($row['referenced_confession_id']) ? [
                'id' => (int) $row['referenced_confession_id'],
                'anon_handle' => (string) ($row['reference_handle'] ?? 'anonymous'),
                'excerpt' => $referenceBody === ''
                    ? 'Referenced confession unavailable.'
                    : (function_exists('mb_substr') ? mb_substr($referenceBody, 0, 140) : substr($referenceBody, 0, 140)),
            ] : null,
        ];
    }, $rows);

    json_response([
        'success' => true,
        'data' => [
            'confessions' => $items,
            'has_more' => count($items) === $limit,
            'next_offset' => $offset + count($items),
        ],
    ]);
}

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

$action = isset($payload['action']) && is_string($payload['action']) ? clean_enum($payload['action'], ['create', 'flag']) : 'create';

if ($action === 'flag') {
    $confessionId = clean_int($payload['confession_id'] ?? null);
    if ($confessionId === null || $confessionId <= 0) {
        json_response(['error' => 'Invalid confession'], 400);
    }

    $statement = $pdo->prepare('UPDATE confessions SET is_flagged = 1 WHERE id = :id');
    $statement->execute([':id' => $confessionId]);
    log_action($pdo, $currentUserId, 'flag_confession:' . $confessionId, $_SERVER['REMOTE_ADDR'] ?? null);

    json_response(['success' => true, 'data' => null]);
}

if (!check_rate_limit($pdo, 'confession', (string) $currentUserId, 5, 3600)) {
    json_response(['error' => 'You have reached the confession limit for now.'], 429);
}

$body = isset($payload['body']) && is_string($payload['body']) ? trim(str_replace("\0", '', $payload['body'])) : '';
if ($body === '') {
    json_response(['error' => 'Confession cannot be empty'], 400);
}

$body = function_exists('mb_substr') ? mb_substr($body, 0, 500) : substr($body, 0, 500);
$body = clean($body);
$referencedConfessionId = clean_int($payload['referenced_confession_id'] ?? null);

$profile = get_profile($pdo, $currentUserId);
$anonHandle = is_array($profile) && isset($profile['anon_handle']) ? (string) $profile['anon_handle'] : '';
if ($anonHandle === '') {
    json_response(['error' => 'Unable to load anonymous handle'], 400);
}

try {
    $pdo->beginTransaction();
    record_attempt($pdo, 'confession', (string) $currentUserId);

    $insertColumns = [$confessionColumns['author'], $confessionColumns['body'], 'created_at', 'is_flagged'];
    $insertValues = [':author_id', ':body', 'NOW()', '0'];
    $insertParams = [
        ':author_id' => $currentUserId,
        ':body' => $body,
    ];

    if (operational_column_exists($pdo, 'confessions', 'anon_handle')) {
        $insertColumns[] = 'anon_handle';
        $insertValues[] = ':anon_handle';
        $insertParams[':anon_handle'] = $anonHandle;
    }

    $statement = $pdo->prepare(
        'INSERT INTO confessions (' . implode(', ', $insertColumns) . ')
         VALUES (' . implode(', ', $insertValues) . ')'
    );
    $statement->execute($insertParams);

    $confessionId = (int) $pdo->lastInsertId();

    if ($referencedConfessionId !== null && $referencedConfessionId > 0 && $referencedConfessionId !== $confessionId) {
        $referenceStatement = $pdo->prepare(
            'INSERT IGNORE INTO confession_references (confession_id, referenced_confession_id, created_at)
             VALUES (:confession_id, :referenced_confession_id, NOW())'
        );
        $referenceStatement->execute([
            ':confession_id' => $confessionId,
            ':referenced_confession_id' => $referencedConfessionId,
        ]);

        $ownerStatement = $pdo->prepare(
            sprintf(
                'SELECT %1$s AS author_id FROM confessions WHERE id = :id LIMIT 1',
                $confessionColumns['author']
            )
        );
        $ownerStatement->execute([':id' => $referencedConfessionId]);
        $ownerId = $ownerStatement->fetchColumn();
        if ($ownerId !== false && (int) $ownerId > 0 && (int) $ownerId !== $currentUserId) {
            operational_write_notification(
                $pdo,
                (int) $ownerId,
                'confession_reference',
                'Confession referenced',
                'Someone referenced one of your anonymous confessions.',
                'confessions.php',
                'confession_reference:' . $referencedConfessionId . ':' . (int) $ownerId
            );
        }
    }

    $pdo->commit();

    log_action($pdo, $currentUserId, 'confession_posted', $_SERVER['REMOTE_ADDR'] ?? null);
    json_response([
        'success' => true,
        'data' => [
            'confessions' => [[
                'id' => $confessionId,
                'body' => $body,
                'anon_handle' => $anonHandle,
                'created_at' => date('c'),
                'view_count' => 0,
                'reference' => null,
            ]],
        ],
    ]);
} catch (Throwable $throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    log_to_file('Confession API post failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
    json_response(['error' => 'Unable to post confession right now'], 500);
}
