<?php

declare(strict_types=1);

/**
 * Operational contract helpers for feature tables that are required by the
 * product spec but absent from the original project schema.
 */

function operational_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?
         LIMIT 1'
    );

    $stmt->execute([$table]);

    return $stmt->fetchColumn() !== false;
}

function operational_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() !== false;
}

function operational_match_columns(PDO $pdo): array
{
    return [
        'user_a' => operational_column_exists($pdo, 'matches', 'user_a_id') ? 'user_a_id' : 'user_one_id',
        'user_b' => operational_column_exists($pdo, 'matches', 'user_b_id') ? 'user_b_id' : 'user_two_id',
        'created_at' => operational_column_exists($pdo, 'matches', 'matched_at') ? 'matched_at' : 'created_at',
    ];
}

function operational_message_columns(PDO $pdo): array
{
    return [
        'body' => operational_column_exists($pdo, 'messages', 'body') ? 'body' : 'message_text',
        'created_at' => operational_column_exists($pdo, 'messages', 'sent_at') ? 'sent_at' : 'created_at',
    ];
}

function operational_swipe_columns(PDO $pdo): array
{
    return [
        'target' => operational_column_exists($pdo, 'swipes', 'swiped_id') ? 'swiped_id' : 'target_id',
        'direction' => operational_column_exists($pdo, 'swipes', 'direction') ? 'direction' : 'action',
    ];
}

function operational_confession_columns(PDO $pdo): array
{
    return [
        'author' => operational_column_exists($pdo, 'confessions', 'author_id') ? 'author_id' : 'user_id',
        'body' => operational_column_exists($pdo, 'confessions', 'body') ? 'body' : 'content',
    ];
}

function ensure_operational_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(50) NOT NULL,
            identifier VARCHAR(100) NOT NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lookup (action, identifier, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_user_time (user_id, timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS blocks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT UNSIGNED NOT NULL,
            blocked_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_block_pair (blocker_id, blocked_id),
            INDEX idx_blocks_blocker (blocker_id),
            INDEX idx_blocks_blocked (blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            match_id INT UNSIGNED NOT NULL,
            requester_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            status ENUM("pending", "approved", "declined", "revoked") NOT NULL DEFAULT "pending",
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            UNIQUE KEY uq_message_request_pair (match_id, requester_id, recipient_id),
            INDEX idx_message_request_match_status (match_id, status),
            INDEX idx_message_request_recipient_status (recipient_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipient_id INT UNSIGNED NOT NULL,
            category VARCHAR(50) NOT NULL,
            title VARCHAR(160) NOT NULL,
            body VARCHAR(500) NOT NULL,
            deep_link VARCHAR(255) NULL,
            dedupe_key VARCHAR(190) NULL,
            payload_json JSON NULL,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notification_dedupe (recipient_id, dedupe_key),
            INDEX idx_notifications_recipient (recipient_id, read_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notification_preferences (
            user_id INT UNSIGNED NOT NULL,
            category VARCHAR(50) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS confession_views (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            confession_id INT UNSIGNED NOT NULL,
            viewer_hash CHAR(64) NOT NULL,
            viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_confession_view (confession_id, viewer_hash),
            INDEX idx_confession_views_count (confession_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS confession_references (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            confession_id INT UNSIGNED NOT NULL,
            referenced_confession_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_confession_reference (confession_id, referenced_confession_id),
            INDEX idx_confession_reference_target (referenced_confession_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS profile_images (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            slot ENUM("profile", "discovery_1", "discovery_2") NOT NULL,
            path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            byte_size INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_profile_image_slot (user_id, slot),
            INDEX idx_profile_images_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS find_match_answers (
            user_id INT UNSIGNED NOT NULL,
            question_key VARCHAR(60) NOT NULL,
            answer_value VARCHAR(120) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, question_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (operational_table_exists($pdo, 'messages')) {
        if (!operational_column_exists($pdo, 'messages', 'unsent_at')) {
            $pdo->exec('ALTER TABLE messages ADD COLUMN unsent_at TIMESTAMP NULL');
        }
        if (!operational_column_exists($pdo, 'messages', 'unsent_by')) {
            $pdo->exec('ALTER TABLE messages ADD COLUMN unsent_by INT UNSIGNED NULL');
        }
        if (!operational_column_exists($pdo, 'messages', 'moderation_body')) {
            $bodyColumn = operational_message_columns($pdo)['body'];
            $pdo->exec('ALTER TABLE messages ADD COLUMN moderation_body TEXT NULL AFTER ' . $bodyColumn);
        }
    }

    if (operational_table_exists($pdo, 'reports')) {
        if (!operational_column_exists($pdo, 'reports', 'target_type')) {
            $pdo->exec('ALTER TABLE reports ADD COLUMN target_type VARCHAR(40) NOT NULL DEFAULT "user"');
        }
        if (!operational_column_exists($pdo, 'reports', 'target_id')) {
            $pdo->exec('ALTER TABLE reports ADD COLUMN target_id INT UNSIGNED NULL');
        }
        if (!operational_column_exists($pdo, 'reports', 'confession_id')) {
            $pdo->exec('ALTER TABLE reports ADD COLUMN confession_id INT UNSIGNED NULL');
        }
    }

    $ensured = true;
}

function operational_is_blocked(PDO $pdo, int $userA, int $userB): bool
{
    ensure_operational_schema($pdo);

    $statement = $pdo->prepare(
        'SELECT 1
         FROM blocks
         WHERE (blocker_id = :user_a AND blocked_id = :user_b)
            OR (blocker_id = :reverse_user_b AND blocked_id = :reverse_user_a)
         LIMIT 1'
    );
    $statement->execute([
        ':user_a' => $userA,
        ':user_b' => $userB,
        ':reverse_user_b' => $userB,
        ':reverse_user_a' => $userA,
    ]);

    return $statement->fetchColumn() !== false;
}

function operational_get_match(PDO $pdo, int $matchId, int $currentUserId): ?array
{
    $columns = operational_match_columns($pdo);
    $statement = $pdo->prepare(
        sprintf(
            'SELECT id, %1$s AS user_a_id, %2$s AS user_b_id
             FROM matches
             WHERE id = :match_id
               AND (%1$s = :current_user_id OR %2$s = :other_current_user_id)
             LIMIT 1',
            $columns['user_a'],
            $columns['user_b']
        )
    );
    $statement->execute([
        ':match_id' => $matchId,
        ':current_user_id' => $currentUserId,
        ':other_current_user_id' => $currentUserId,
    ]);

    $match = $statement->fetch(PDO::FETCH_ASSOC);
    return $match === false ? null : $match;
}

function operational_other_user_id(array $match, int $currentUserId): int
{
    return (int) ((int) $match['user_a_id'] === $currentUserId ? $match['user_b_id'] : $match['user_a_id']);
}

function operational_has_message_approval(PDO $pdo, int $matchId): bool
{
    ensure_operational_schema($pdo);

    $statement = $pdo->prepare(
        'SELECT 1
         FROM message_requests
         WHERE match_id = :match_id
           AND status = "approved"
           AND revoked_at IS NULL
         LIMIT 1'
    );
    $statement->execute([':match_id' => $matchId]);

    return $statement->fetchColumn() !== false;
}

function operational_message_request_status(PDO $pdo, int $matchId, int $currentUserId, int $otherUserId): array
{
    ensure_operational_schema($pdo);

    $statement = $pdo->prepare(
        'SELECT *
         FROM message_requests
         WHERE match_id = :match_id
           AND ((requester_id = :current_user_id AND recipient_id = :other_user_id)
             OR (requester_id = :reverse_other_user_id AND recipient_id = :reverse_current_user_id))
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute([
        ':match_id' => $matchId,
        ':current_user_id' => $currentUserId,
        ':other_user_id' => $otherUserId,
        ':reverse_other_user_id' => $otherUserId,
        ':reverse_current_user_id' => $currentUserId,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'status' => 'none',
            'is_requester' => false,
            'is_recipient' => false,
            'row' => null,
        ];
    }

    return [
        'status' => (string) $row['status'],
        'is_requester' => (int) $row['requester_id'] === $currentUserId,
        'is_recipient' => (int) $row['recipient_id'] === $currentUserId,
        'row' => $row,
    ];
}

function operational_write_notification(PDO $pdo, int $recipientId, string $category, string $title, string $body, ?string $deepLink = null, ?string $dedupeKey = null, array $payload = []): void
{
    ensure_operational_schema($pdo);

    $preference = $pdo->prepare(
        'SELECT is_enabled
         FROM notification_preferences
         WHERE user_id = :user_id AND category = :category
         LIMIT 1'
    );
    $preference->execute([
        ':user_id' => $recipientId,
        ':category' => $category,
    ]);
    $enabled = $preference->fetchColumn();
    if ($enabled !== false && (int) $enabled !== 1) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO notifications (recipient_id, category, title, body, deep_link, dedupe_key, payload_json, created_at)
         VALUES (:recipient_id, :category, :title, :body, :deep_link, :dedupe_key, :payload_json, NOW())
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            body = VALUES(body),
            deep_link = VALUES(deep_link),
            payload_json = VALUES(payload_json),
            created_at = NOW(),
            read_at = NULL'
    );
    $statement->execute([
        ':recipient_id' => $recipientId,
        ':category' => $category,
        ':title' => $title,
        ':body' => $body,
        ':deep_link' => $deepLink,
        ':dedupe_key' => $dedupeKey,
        ':payload_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
}

function operational_viewer_hash(int $viewerId): string
{
    return hash('sha256', 'user:' . $viewerId);
}

