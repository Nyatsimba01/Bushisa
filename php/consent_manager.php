<?php

declare(strict_types=1);

/**
 * Record explicit consent unless the same consent is already active.
 */
function grant_consent(PDO $pdo, int $user_id, string $consent_type): bool
{
    if (has_active_consent($pdo, $user_id, $consent_type)) {
        return true;
    }

    $statement = $pdo->prepare(
        'INSERT INTO consent_records (user_id, consent_type, granted_at, revoked_at)
         VALUES (:user_id, :consent_type, NOW(), NULL)'
    );

    return $statement->execute([
        ':user_id' => $user_id,
        ':consent_type' => $consent_type,
    ]);
}

/**
 * Revoke all active records for a specific consent type.
 */
function revoke_consent(PDO $pdo, int $user_id, string $consent_type): bool
{
    $statement = $pdo->prepare(
        'UPDATE consent_records
         SET revoked_at = NOW()
         WHERE user_id = :user_id
           AND consent_type = :consent_type
           AND revoked_at IS NULL'
    );

    return $statement->execute([
        ':user_id' => $user_id,
        ':consent_type' => $consent_type,
    ]);
}

/**
 * Check whether a user has an active consent record.
 */
function has_active_consent(PDO $pdo, int $user_id, string $consent_type): bool
{
    $statement = $pdo->prepare(
        'SELECT id
         FROM consent_records
         WHERE user_id = :user_id
           AND consent_type = :consent_type
           AND granted_at IS NOT NULL
           AND revoked_at IS NULL
         LIMIT 1'
    );
    $statement->execute([
        ':user_id' => $user_id,
        ':consent_type' => $consent_type,
    ]);

    return $statement->fetchColumn() !== false;
}

/**
 * Return all consent records for a user.
 *
 * @return array<int, array<string, mixed>>
 */
function get_user_consents(PDO $pdo, int $user_id): array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, consent_type, granted_at, revoked_at
         FROM consent_records
         WHERE user_id = :user_id
         ORDER BY granted_at DESC, id DESC'
    );
    $statement->execute([':user_id' => $user_id]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
