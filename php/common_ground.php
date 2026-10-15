<?php

declare(strict_types=1);

/**
 * Find Match: discover common ground from consenting adults who completed
 * the same saved questionnaire. Does not use a map, GPS, or precise location.
 *
 * Results reuse the Home server's block, suspension, age-18+, prior-interest,
 * mutual-match and eligibility filtering. Only COMMON answer labels are
 * presented; other students' full questionnaire records stay private.
 *
 * @param array<string,string> $myAnswers
 * @return array<int,array{candidate:array<string,mixed>,shared:array<int,string>,weight:int}>
 */
function bushisa_common_ground(PDO $pdo, int $viewerId, array $myAnswers): array
{
    if ($viewerId < 1 || !operational_table_exists($pdo, 'find_match_answers')) {
        return [];
    }

    $required = ['intent','social_activity','communication_style','interests','availability'];
    foreach ($required as $key) {
        if (trim((string) ($myAnswers[$key] ?? '')) === '') {
            return [];
        }
    }

    $normalise = static function (string $value): string {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $parseInterests = static function (string $value) use ($normalise): array {
        $unique = [];
        foreach (explode(',', $value) as $item) {
            $name = trim($item);
            if ($name !== '') {
                $unique[$normalise($name)] = $name;
            }
        }
        return $unique;
    };
    $mine = $parseInterests((string) $myAnswers['interests']);
    $rows = [];
    $cursor = null;

    // Bound the scan to 90 eligible profiles to keep first paint predictable.
    // Ordering remains deterministic and does not reveal absent accounts.
    for ($batch = 0; $batch < 15; ++$batch) {
        $result = bushisa_discovery_batch($pdo, $viewerId, null, $cursor, 'everyone');
        foreach ($result['profiles'] as $profile) {
            $id = (int) ($profile['user_id'] ?? 0);
            if ($id > 0) {
                $rows[$id] = $profile;
            }
        }
        if (!$result['has_more'] || !is_int($result['next_cursor'])) {
            break;
        }
        $cursor = $result['next_cursor'];
    }

    if ($rows === []) {
        return [];
    }

    // The questionnaire is only compared with accounts that completed it.
    // No one else's answer values are returned to the client.
    $ids = array_keys($rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare(
        'SELECT user_id, question_key, answer_value
         FROM find_match_answers
         WHERE user_id IN (' . $placeholders . ')
           AND question_key IN ("intent","social_activity","communication_style","interests","availability")'
    );
    $query->execute($ids);
    $answers = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $answer) {
        $answers[(int) $answer['user_id']][(string) $answer['question_key']] =
            (string) $answer['answer_value'];
    }

    $matched = [];
    foreach ($rows as $id => $profile) {
        $theirs = $answers[$id] ?? [];
        $complete = true;
        foreach ($required as $key) {
            if (trim((string) ($theirs[$key] ?? '')) === '') {
                $complete = false;
                break;
            }
        }
        if (!$complete) {
            continue;
        }

        $shared = [];
        $score = 0;
        $interests = $parseInterests($theirs['interests']);
        foreach ($mine as $key => $display) {
            if (array_key_exists($key, $interests)) {
                $shared[] = $display;
                $score += 3;
            }
        }
        foreach ([
            'intent' => 'Similar connection goals',
            'social_activity' => 'Similar social style',
            'communication_style' => 'Similar communication',
            'availability' => 'Similar availability',
        ] as $key => $label) {
            if ($normalise((string) $myAnswers[$key]) === $normalise($theirs[$key])) {
                $shared[] = $label;
                $score++;
            }
        }
        if ($score > 0) {
            $matched[] = [
                'candidate' => $profile,
                'shared' => array_slice($shared, 0, 6),
                'weight' => $score,
            ];
        }
    }

    // Explain actual common ground rather than displaying a fake percentage.
    usort($matched, static function (array $a, array $b): int {
        $difference = $b['weight'] <=> $a['weight'];
        return $difference !== 0 ? $difference :
            ((int) $a['candidate']['user_id'] <=> (int) $b['candidate']['user_id']);
    });

    return array_slice($matched, 0, 24);
}
