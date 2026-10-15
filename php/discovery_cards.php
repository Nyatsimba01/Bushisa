<?php

declare(strict_types=1);

/**
 * A single Figma Home card renderer for the first results and AJAX batches.
 * User data is always escaped. No fictional interests/prompts are inserted.
 *
 * Requires php/frontend.php (e() and profile_photo_url()).
 */

function bushisa_discovery_card_icon(string $name): string
{
    $icons = ['camera', 'nav-heart', 'pass', 'like', 'bookmark', 'confess'];
    if (!in_array($name, $icons, true)) {
        return '';
    }

    return '<img src="preview/assets/icons/' . e($name) . '.svg" alt="" aria-hidden="true">';
}

/**
 * @param array<string,mixed> $candidate
 */
function bushisa_render_discovery_card(array $candidate): string
{
    $id = (int) ($candidate['user_id'] ?? 0);
    if ($id <= 0) {
        return '';
    }

    $name = trim((string) ($candidate['display_name'] ?? ''));
    $name = $name !== '' ? $name : 'Student';
    $age = isset($candidate['age']) ? (int) $candidate['age'] : null;
    $faculty = trim((string) ($candidate['faculty'] ?? ''));
    $year = trim((string) ($candidate['year_of_study'] ?? ''));
    $bio = trim((string) ($candidate['bio'] ?? ''));
    $photo = profile_photo_url(isset($candidate['profile_photo_path']) ? (string) $candidate['profile_photo_path'] : null);

    // Future integration point: these are OPTIONAL actual database values, not
    // a guess based on the bio or the matching questionnaire.
    $question = trim((string) ($candidate['conversation_prompt'] ?? ''));
    $answer = trim((string) ($candidate['conversation_answer'] ?? ''));
    $interests = isset($candidate['interests']) && is_array($candidate['interests'])
        ? array_values(array_filter($candidate['interests'], 'is_string'))
        : [];

    $portraitLabel = $name . ($age !== null && $age >= 18 ? ', ' . $age : '');
    $profileDescription = $faculty !== '' ? $faculty . ' · NUST' : 'NUST student';
    $fallbackInitial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($name, 0, 1))
        : strtoupper(substr($name, 0, 1));

    ob_start();
    ?>
    <article class="profile-card figma-profile-card" data-candidate-id="<?= e($id) ?>" aria-label="<?= e($name) ?> profile">
        <div class="profile-picture">
            <?php if ($photo !== null): ?>
                <img src="<?= e($photo) ?>" alt="<?= e($name) ?> profile photo" loading="lazy" decoding="async">
            <?php else: ?>
                <div class="figma-photo-fallback" role="img" aria-label="No profile photo provided"><?= e($fallbackInitial) ?></div>
            <?php endif; ?>

            <button class="overlay-action" type="button" disabled title="Photo likes are coming later" aria-label="Photo likes are not available yet">
                <?= bushisa_discovery_card_icon('camera') ?> Like photo
            </button>

            <div class="photo-overlay">
                <h3><?= e($portraitLabel) ?></h3>
                <p><?= e($profileDescription) ?></p>
            </div>
        </div>

        <div class="profile-content">
            <div class="prompt-box">
                <?php if ($question !== ''): ?>
                    <p class="prompt-label">CONVERSATION PROMPT</p>
                    <p class="prompt-value"><?= e($question) ?></p>
                    <?php if ($answer !== ''): ?>
                        <p class="figma-prompt-answer"><?= e($answer) ?></p>
                    <?php endif; ?>
                    <div class="answer-line">
                        <button class="small-action" type="button" disabled title="Answer likes are coming later">
                            <?= bushisa_discovery_card_icon('nav-heart') ?> Like answer
                        </button>
                    </div>
                <?php else: ?>
                    <p class="prompt-label">ABOUT ME</p>
                    <p class="prompt-value"><?= e($bio !== '' ? $bio : 'This student has not added a bio yet.') ?></p>
                <?php endif; ?>
            </div>

            <div class="tags" aria-label="Profile details">
                <?php foreach (array_slice($interests, 0, 5) as $interest): ?>
                    <?php $value = trim((string) $interest); ?>
                    <?php if ($value !== ''): ?>
                        <span class="tag"><?= e($value) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($faculty !== ''): ?>
                    <span class="tag">🎓 <?= e($faculty) ?></span>
                <?php endif; ?>
                <?php if ($year !== ''): ?>
                    <span class="tag">📚 <?= e($year) ?></span>
                <?php endif; ?>
            </div>

            <div class="photo-feedback">
                <button class="small-action" type="button" disabled title="Photo likes are coming later">
                    <?= bushisa_discovery_card_icon('nav-heart') ?> Like photo
                </button>
                <span>Photo interactions coming soon</span>
            </div>

            <div class="profile-actions">
                <button class="circle-action" type="button" data-swipe-target="<?= e($id) ?>" data-swipe-direction="pass" aria-label="Pass on <?= e($name) ?>" title="Pass">
                    <?= bushisa_discovery_card_icon('pass') ?>
                </button>
                <button class="circle-action like" type="button" data-swipe-target="<?= e($id) ?>" data-swipe-direction="like" aria-label="Like <?= e($name) ?>" title="Like">
                    <?= bushisa_discovery_card_icon('like') ?>
                </button>
                <button class="circle-action" type="button" disabled aria-label="Bookmarks are coming later" title="Bookmarks are coming later">
                    <?= bushisa_discovery_card_icon('bookmark') ?>
                </button>
            </div>

            <button class="confess-button home-connect-action" type="button"
                data-swipe-target="<?= e($id) ?>" data-swipe-direction="like"
                aria-label="Express interest in connecting with <?= e($name) ?>"
                title="Express interest. Chat requires mutual interest and an accepted invitation.">
                <?= bushisa_discovery_card_icon('nav-heart') ?>
                <span class="figma-desktop-confess">Express interest →</span>
                <span class="figma-mobile-confess">Connect →</span>
            </button>
        </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

/**
 * @param array<int,array<string,mixed>> $profiles
 */
function bushisa_render_discovery_cards(array $profiles): string
{
    return implode("\n", array_map('bushisa_render_discovery_card', $profiles));
}
