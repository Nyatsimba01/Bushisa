<?php
declare(strict_types=1);
require_once __DIR__ . '/logger.php';

function admin_content_input(array $source, string $key, string $default = ''): string {
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default;
}
function admin_content_id(array $source, string $key): int {
    $value = filter_var(admin_content_input($source, $key), FILTER_VALIDATE_INT);
    return is_int($value) && $value > 0 ? $value : 0;
}
function admin_content_url(string $route, array $filters = [], array $changes = [], string $anchor = 'content-filters'): string {
    return $route . '?' . http_build_query(array_merge($filters, $changes)) . '#' . $anchor;
}
function admin_content_like(string $search): string {
    return '%' . strtr($search, ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
}
function admin_content_search_id(string $search): int {
    return preg_match('/^#?[0-9]+$/D', $search) ? (int)ltrim($search, '#') : 0;
}
/** Bind confirmation to the actual content and state the reviewer saw. */
function admin_content_version(array $row): string {
    return hash_hmac('sha256', json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), generate_csrf_token());
}
function admin_content_post(PDO $pdo, int $id, bool $lock = false): ?array {
    $body = operational_confession_columns($pdo)['body'];
    $query = $pdo->prepare("SELECT id, `$body` AS body, is_flagged, created_at FROM confessions WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}
function admin_content_account(PDO $pdo, int $id, bool $lock = false): ?array {
    // Account records intentionally contain no link to anonymous post handles.
    $query = $pdo->prepare('SELECT id, role, is_suspended, created_at FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}
function admin_content_related_reports(PDO $pdo, int $id, bool $account = false): array {
    $where = $account ? 'reported_user_id = ?' : "COALESCE(NULLIF(confession_id,0), CASE WHEN target_type='confession' THEN target_id ELSE NULL END) = ?";
    $query = $pdo->prepare('SELECT id, reason, status, created_at FROM reports WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 10');
    $query->execute([$id]);
    return $query->fetchAll();
}
/** Atomic, reversible actions only. Failed audit writes roll back the change. */
function admin_content_action(PDO $pdo, int $actorId, array $post): string {
    if (!validate_csrf_token(admin_content_input($post,'csrf_token'))) throw new DomainException('The form expired. Please reload and try again.');
    if (admin_content_input($post,'confirm') !== 'yes') throw new RuntimeException('Confirm that you reviewed the case before taking action.');
    $action = admin_content_input($post,'action');
    if (!in_array($action, ['hide_confession','unflag_confession','unsuspend_user'], true)) throw new RuntimeException('Invalid moderation action.');
    $id = admin_content_id($post,'record_id');
    if (!$id) throw new RuntimeException('Invalid record.');
    try {
        $pdo->beginTransaction();
        $actor = $pdo->prepare('SELECT role, is_suspended FROM users WHERE id = ? FOR UPDATE');
        $actor->execute([$actorId]); $permission = $actor->fetch();
        if (!$permission || (int)$permission['is_suspended'] === 1 || !in_array($permission['role'],['admin','moderator'],true)) throw new RuntimeException('Your moderation permissions have changed.');
        if ($action === 'unsuspend_user') {
            if ($permission['role'] !== 'admin') throw new RuntimeException('Only administrators can restore account access.');
            $row = admin_content_account($pdo,$id,true);
            if (!$row || $id === $actorId || !in_array($row['role'],['student','user'],true)) throw new RuntimeException('This account cannot be restored from this page.');
            if ((int)$row['is_suspended'] !== 1) throw new RuntimeException('This account is no longer suspended.');
        } else {
            $row = admin_content_post($pdo,$id,true);
            if (!$row) throw new RuntimeException('This confession is no longer available.');
            $expected = $action === 'hide_confession' ? 0 : 1;
            if ((int)$row['is_flagged'] !== $expected) throw new RuntimeException('This confession has changed. Review its current state first.');
        }
        if (!hash_equals(admin_content_version($row),admin_content_input($post,'version'))) throw new RuntimeException('This record has changed since you opened it. Reload and review it again.');
        if ($action === 'unsuspend_user') {
            $pdo->prepare('UPDATE users SET is_suspended=0 WHERE id=?')->execute([$id]);
            $message = 'Account #' . $id . ' access restored.';
        } else {
            $pdo->prepare('UPDATE confessions SET is_flagged=? WHERE id=?')->execute([$action === 'hide_confession' ? 1 : 0,$id]);
            $message = 'Confession #' . $id . ($action === 'hide_confession' ? ' hidden from the public feed.' : ' restored to the public feed.');
        }
        log_action($pdo,$actorId,'admin_' . $action . ':' . $id);
        $pdo->commit();
        return $message;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
function admin_content_form(array $row, string $action, string $label, string $url): void { ?>
<form method="post" action="<?= e($url) ?>" class="sensitive-action">
<input type="hidden" name="csrf_token" value="<?= e(generate_csrf_token()) ?>"><input type="hidden" name="record_id" value="<?= e($row['id']) ?>"><input type="hidden" name="version" value="<?= e(admin_content_version($row)) ?>"><input type="hidden" name="action" value="<?= e($action) ?>">
<label class="confirmation"><input type="checkbox" name="confirm" value="yes" required> I have reviewed the context and confirm: <?= e($label) ?>.</label><div class="buttons"><button type="submit"><?= e($label) ?></button></div>
</form>
<?php }
function admin_content_reports(array $reports): void { ?>
<h3>Related reports · latest 10</h3>
<?php if (!$reports): ?><p class="hint">No reports are linked to this record. A moderation flag can also be set directly.</p><?php endif; ?>
<?php foreach ($reports as $report): ?><article class="linked-report"><a href="reports.php?report=<?= e($report['id']) ?>#report-detail">Report #<?= e($report['id']) ?> →</a><span class="status"><?= e(ucwords(str_replace('_',' ',$report['status']))) ?></span><p><?= e($report['reason']) ?></p></article><?php endforeach; ?>
<?php }
