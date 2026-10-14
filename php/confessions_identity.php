<?php

declare(strict_types=1);

/**
 * One shared validation policy for signup and You / Confessions identity.
 * A username must stay recognisable: at least three ASCII letters/digits,
 * the usual ._- separators, plus up to two emoji grapheme clusters.
 *
 * Storage columns use VARCHAR(50) utf8mb4, so count Unicode code points
 * (rather than bytes) to avoid truncating joined emoji.
 */
function bushisa_clean_confessions_handle(string $raw): string
{
    $trimmed = trim(str_replace("\0", '', $raw));
    return ltrim($trimmed, '@');
}

/**
 * @return string|null A friendly validation error, or null when valid.
 */
function bushisa_validate_confessions_handle(string $handle): ?string
{
    if ($handle === '' || preg_match('//u', $handle) !== 1) {
        return 'Enter a username with at least 3 letters or numbers. You can add up to 2 emojis.';
    }

    $codePointCount = preg_match_all('/./us', $handle);
    if ($codePointCount === false || $codePointCount > 50) {
        return 'Keep your username within 50 Unicode characters, including emoji parts.';
    }

    $graphemes = [];
    if (preg_match_all('/\X/u', $handle, $matches) === false) {
        return 'Please use valid Unicode characters in your username.';
    }
    $graphemes = $matches[0] ?? [];

    $lettersAndNumbers = 0;
    $emojiCount = 0;
    foreach ($graphemes as $cluster) {
        if (preg_match('/\A[A-Za-z0-9_.-]\z/D', $cluster) === 1) {
            if (preg_match('/[A-Za-z0-9]/', $cluster) === 1) {
                ++$lettersAndNumbers;
            }
            continue;
        }

        // Support ordinary emoji, skin-tone modifiers, joined family/people
        // emojis, flags, and keycap symbols, without admitting arbitrary
        // invisible Unicode characters or text that spoofs another handle.
        $pictograph = preg_match('/\p{Extended_Pictographic}/u', $cluster) === 1;
        $allowedPictograph = preg_match(
            '/\A[\p{Extended_Pictographic}\p{Emoji_Modifier}\x{200D}\x{FE0E}\x{FE0F}]+\z/u',
            $cluster
        ) === 1;
        $flag = preg_match('/\A\p{Regional_Indicator}{2}\z/u', $cluster) === 1;
        $keycap = preg_match('/\A[0-9#*]\x{FE0F}?\x{20E3}\z/u', $cluster) === 1;
        if (($pictograph && $allowedPictograph) || $flag || $keycap) {
            ++$emojiCount;
            continue;
        }

        return 'Use letters, numbers, dots, underscores, hyphens and standard emojis only (no spaces).';
    }

    if ($lettersAndNumbers < 3) {
        return 'Your username needs at least 3 letters or numbers, plus optional emojis.';
    }

    if ($emojiCount > 2) {
        return 'Use no more than 2 emojis in your Confessions username.';
    }

    return null;
}

/**
 * Compare public and Confessions names by their text identities, ignoring
 * punctuation, emoji, and case. This intentionally rejects a public name
 * merely decorated with hearts or flowers.
 */
function bushisa_confessions_handle_matches_public(string $handle, string $publicName): bool
{
    $publicKey = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $publicName));
    $handleKey = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $handle));
    return $publicKey !== '' && $publicKey === $handleKey;
}
