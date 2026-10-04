<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/php/csrf.php';
require_once __DIR__ . '/php/sanitize.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/rate_limit.php';
require_once __DIR__ . '/php/logger.php';
require_once __DIR__ . '/php/function.php';

require_login();

$currentUserId = (int) $_SESSION['user_id'];
$error = null;
$success = null;
$csrfToken = null;
$confessions = [];
$page = clean_int($_GET['page'] ?? null) ?? 1;
if ($page < 1) {
    $page = 1;
}

$perPage = 20;
$columnStatement = $pdo->query('SHOW COLUMNS FROM confessions');
$confessionColumns = $columnStatement !== false ? $columnStatement->fetchAll(PDO::FETCH_COLUMN) : [];
$usesUserContentSchema = in_array('user_id', $confessionColumns, true) && in_array('content', $confessionColumns, true);
$usesAuthorBodySchema = in_array('author_id', $confessionColumns, true) && in_array('body', $confessionColumns, true);

$countStatement = $pdo->query('SELECT COUNT(*) FROM confessions');
$totalConfessions = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;
$total_pages = $totalConfessions > 0 ? (int) ceil($totalConfessions / $perPage) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if ($submittedToken === '' || !validate_csrf_token($submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $rateLimitKey = (string) $currentUserId;
        if (!check_rate_limit($pdo, 'confession', $rateLimitKey, 5, 3600)) {
            $error = 'You have reached the confession limit for now.';
        } else {
            $rawBody = trim(str_replace("\0", '', (string) ($_POST['body'] ?? '')));
            if ($rawBody === '') {
                $error = 'Confession cannot be empty.';
            } else {
                $rawBody = function_exists('mb_substr') ? mb_substr($rawBody, 0, 500) : substr($rawBody, 0, 500);
                $sanitisedBody = clean($rawBody);

                $profileStatement = $pdo->prepare('SELECT anon_handle FROM profiles WHERE user_id = :user_id LIMIT 1');
                $profileStatement->execute([':user_id' => $currentUserId]);
                $anonHandle = $profileStatement->fetchColumn();

                if (!is_string($anonHandle) || $anonHandle === '') {
                    $error = 'Unable to load your anonymous handle.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        record_attempt($pdo, 'confession', $rateLimitKey);

                        if ($usesUserContentSchema) {
                            $insertConfession = $pdo->prepare(
                                'INSERT INTO confessions (user_id, anon_handle, content, created_at, is_flagged)
                                 VALUES (:user_id, :anon_handle, :content, NOW(), 0)'
                            );
                            $insertConfession->execute([
                                ':user_id' => $currentUserId,
                                ':anon_handle' => $anonHandle,
                                ':content' => $sanitisedBody,
                            ]);
                        } elseif ($usesAuthorBodySchema) {
                            $insertConfession = $pdo->prepare(
                                'INSERT INTO confessions (author_id, body, created_at, is_flagged)
                                 VALUES (:author_id, :body, NOW(), 0)'
                            );
                            $insertConfession->execute([
                                ':author_id' => $currentUserId,
                                ':body' => $sanitisedBody,
                            ]);
                        } else {
                            throw new RuntimeException('Unsupported confessions schema.');
                        }

                        $pdo->commit();
                        log_action($pdo, $currentUserId, 'confession_posted', $_SERVER['REMOTE_ADDR'] ?? null);
                        log_to_file('User ' . $currentUserId . ' posted a confession', 'INFO');

                        $success = 'Confession posted successfully.';
                    } catch (Throwable $throwable) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        log_to_file('Confession post failed for user ' . $currentUserId . ': ' . $throwable->getMessage(), 'ERROR');
                        $error = 'Unable to post your confession right now.';
                    }
                }
            }
        }
    }
}

$offset = ($page - 1) * $perPage;
if ($usesUserContentSchema) {
    $feedStatement = $pdo->prepare(
        'SELECT c.id, c.content AS body, c.created_at, c.is_flagged, p.anon_handle
         FROM confessions c
         LEFT JOIN profiles p ON p.user_id = c.user_id
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT :limit OFFSET :offset'
    );
} elseif ($usesAuthorBodySchema) {
    $feedStatement = $pdo->prepare(
        'SELECT c.id, c.body, c.created_at, c.is_flagged, p.anon_handle
         FROM confessions c
         LEFT JOIN profiles p ON p.user_id = c.author_id
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT :limit OFFSET :offset'
    );
} else {
    $feedStatement = false;
}

if ($feedStatement !== false) {
    $feedStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $feedStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $feedStatement->execute();
    $confessions = $feedStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$csrfToken = generate_csrf_token();

// --- Frontend HTML will be added later ---