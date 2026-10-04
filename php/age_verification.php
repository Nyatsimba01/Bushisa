<?php

declare(strict_types=1);

/**
 * Verify that a date of birth belongs to a user aged 18 to 100.
 *
 * @return array{is_valid: bool, age: int|null, error: string|null}
 */
function verify_age(string $date_of_birth): array
{
    $dateOfBirth = DateTimeImmutable::createFromFormat('!Y-m-d', $date_of_birth);
    $dateErrors = DateTimeImmutable::getLastErrors();

    if (
        $dateOfBirth === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $dateOfBirth->format('Y-m-d') !== $date_of_birth
    ) {
        return [
            'is_valid' => false,
            'age' => null,
            'error' => 'Invalid date of birth.',
        ];
    }

    $today = new DateTimeImmutable('today');
    if ($dateOfBirth > $today) {
        return [
            'is_valid' => false,
            'age' => null,
            'error' => 'Invalid date of birth.',
        ];
    }

    $age = $dateOfBirth->diff($today)->y;

    if ($age < 18) {
        return [
            'is_valid' => false,
            'age' => $age,
            'error' => 'You must be at least 18 years old to use Bushisa.',
        ];
    }

    if ($age > 100) {
        return [
            'is_valid' => false,
            'age' => $age,
            'error' => 'Invalid date of birth.',
        ];
    }

    return [
        'is_valid' => true,
        'age' => $age,
        'error' => null,
    ];
}

/**
 * Stop request handling when the supplied date of birth is not eligible.
 */
function enforce_age_check(string $date_of_birth): void
{
    $result = verify_age($date_of_birth);
    if ($result['is_valid']) {
        return;
    }

    http_response_code(403);
    exit($result['error'] ?? 'Age verification failed.');
}
