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
require_once __DIR__ . '/php/frontend.php';
require_once __DIR__ . '/php/operational.php';

require_login();
ensure_operational_schema($pdo);

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
$confessionRuntimeColumns = operational_confession_columns($pdo);
$handleSelect = operational_column_exists($pdo, 'confessions', 'anon_handle')
    ? 'COALESCE(c.anon_handle, p.anon_handle, "anonymous") AS anon_handle'
    : 'COALESCE(p.anon_handle, "anonymous") AS anon_handle';

if ($usesUserContentSchema || $usesAuthorBodySchema) {
    $feedStatement = $pdo->prepare(
        sprintf(
            'SELECT
                c.id,
                c.%1$s AS body,
                c.created_at,
                c.is_flagged,
                %3$s,
                COUNT(cv.id) AS view_count
             FROM confessions c
             LEFT JOIN profiles p ON p.user_id = c.%2$s
             LEFT JOIN confession_views cv ON cv.confession_id = c.id
             WHERE c.is_flagged = 0
             GROUP BY c.id, c.%1$s, c.created_at, c.is_flagged, anon_handle
             ORDER BY view_count DESC, c.created_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset',
            $confessionRuntimeColumns['body'],
            $confessionRuntimeColumns['author'],
            $handleSelect
        )
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

render_page_shell_start('confessions', 'Confessions', $csrfToken, $currentUserId, false, 'Anonymous posts from the Bushisa community');
?>
<?php render_flash($error, $success); ?>

<section class="empty-state">
    <h2>Trending confessions</h2>
    <p>The feed is ordered by deduplicated view count, then newest confession. Anonymous handles remain noninteractive.</p>
</section>

<section class="confessions-feed" aria-labelledby="confessions-title">
    <h2 class="section-title" id="confessions-title">Anonymous feed</h2>
    <?php if ($confessions === []): ?>
        <article class="empty-state">
            <h2>No confessions yet</h2>
            <p>Be the first to share anonymously. Do not include details that identify you or someone else.</p>
        </article>
    <?php else: ?>
        <?php foreach ($confessions as $confession): ?>
            <?php
            $handle = (string) ($confession['anon_handle'] ?? 'anonymous');
            $body = (string) ($confession['body'] ?? '');
            ?>
            <article class="confession-card">
                <div class="confession-card__meta">
                    <strong>@<?= e($handle) ?></strong>
                    <span><?= e((int) ($confession['view_count'] ?? 0)) ?> views</span>
                    <time datetime="<?= e($confession['created_at'] ?? '') ?>"><?= e(format_time_ago((string) ($confession['created_at'] ?? ''))) ?></time>
                </div>
                <p class="confession-card__body"><?= e($body) ?></p>
                <div class="card-actions">
                    <button class="button-secondary" type="button" disabled>Reference unavailable</button>
                    <a class="button-secondary" href="community_guidelines.php">Report</a>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if ($total_pages > 1): ?>
    <nav class="pagination" aria-label="Confession pages">
        <?php if ($page > 1): ?>
            <a class="button-secondary" href="confessions.php?page=<?= e($page - 1) ?>">Previous</a>
        <?php endif; ?>
        <span class="muted">Page <?= e($page) ?> of <?= e($total_pages) ?></span>
        <?php if ($page < $total_pages): ?>
            <a class="button-secondary" href="confessions.php?page=<?= e($page + 1) ?>">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<button class="confession-fab" type="button" aria-label="Write confession" data-confession-open>+</button>

<section class="confession-modal" id="write-confession" role="dialog" aria-modal="true" aria-labelledby="write-confession-title" data-confession-modal hidden>
    <h2 id="write-confession-title">Write confession</h2>
    <p class="field-help">Your anonymous handle is shown, but your real profile must remain private. Avoid identifying details.</p>
    <form class="form-grid" method="post" action="confessions.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <label class="field">
            <span>Confession</span>
            <textarea name="body" maxlength="500" required placeholder="Share what is on your mind..."></textarea>
        </label>
        <div class="card-actions">
            <button class="confession-modal__submit" type="submit">Post anonymously</button>
            <button class="button-secondary" type="button" data-confession-close>Cancel</button>
        </div>
    </form>
</section>
<?php
render_page_shell_end();
