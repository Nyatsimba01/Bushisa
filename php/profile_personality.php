<?php

declare(strict_types=1);

require_once __DIR__ . '/operational.php';

const BUSHISA_PERSONALITY_TABLE = 'bushisa_profile_personality';

/**
 * The migration is deliberately explicit: page views must not modify the DB.
 */
function bushisa_personality_available(PDO $pdo): bool
{
    return operational_table_exists($pdo, BUSHISA_PERSONALITY_TABLE);
}

function bushisa_personality_character_count(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

/**
 * @param array<string,mixed> $input
 * @return array{conversation_prompt:string,conversation_answer:string,interests:array<int,string>}
 * @throws InvalidArgumentException
 */
function bushisa_personality_validate(array $input): array
{
    $names = ['conversation_prompt', 'conversation_answer', 'interests'];
    foreach ($names as $name) {
        if (isset($input[$name]) && !is_string($input[$name])) {
            throw new InvalidArgumentException('Invalid profile detail submitted.');
        }
    }

    $prompt = trim(str_replace("\0", '', (string) ($input['conversation_prompt'] ?? '')));
    $answer = trim(str_replace("\0", '', (string) ($input['conversation_answer'] ?? '')));
    $rawTags = trim(str_replace("\0", '', (string) ($input['interests'] ?? '')));

    if (bushisa_personality_character_count($prompt) > 160) {
        throw new InvalidArgumentException('The conversation prompt must be 160 characters or fewer.');
    }
    if (bushisa_personality_character_count($answer) > 240) {
        throw new InvalidArgumentException('The conversation answer must be 240 characters or fewer.');
    }
    if ($prompt === '' && $answer !== '') {
        throw new InvalidArgumentException('Please add a question before its answer.');
    }
    if (bushisa_personality_character_count($rawTags) > 350) {
        throw new InvalidArgumentException('Your interest list is too long.');
    }

    $items = $rawTags === '' ? [] : preg_split('/[,，\r\n]+/u', $rawTags);
    if (!is_array($items)) {
        throw new InvalidArgumentException('Invalid interest list.');
    }

    $interests = [];
    $seen = [];
    foreach ($items as $item) {
        $value = trim(preg_replace('/\s+/u', ' ', $item) ?? $item);
        if ($value === '') {
            continue;
        }
        if (bushisa_personality_character_count($value) > 40) {
            throw new InvalidArgumentException('Each interest must be 40 characters or fewer.');
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $interests[] = $value;
        if (count($interests) > 5) {
            throw new InvalidArgumentException('Please choose no more than five interests.');
        }
    }

    return [
        'conversation_prompt' => $prompt,
        'conversation_answer' => $answer,
        'interests' => $interests,
    ];
}

/**
 * @return array{conversation_prompt:string,conversation_answer:string,interests:array<int,string>}
 */
function bushisa_personality_read(PDO $pdo, int $userId): array
{
    $empty = ['conversation_prompt' => '', 'conversation_answer' => '', 'interests' => []];

    if ($userId <= 0 || !bushisa_personality_available($pdo)) {
        return $empty;
    }

    $statement = $pdo->prepare(
        'SELECT prompt_text, answer_text, interests_json
         FROM bushisa_profile_personality WHERE user_id = :user_id LIMIT 1'
    );
    $statement->execute([':user_id' => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $empty;
    }

    $tags = json_decode((string) ($row['interests_json'] ?? '[]'), true);
    return [
        'conversation_prompt' => (string) ($row['prompt_text'] ?? ''),
        'conversation_answer' => (string) ($row['answer_text'] ?? ''),
        'interests' => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
    ];
}

/**
 * @param array{conversation_prompt:string,conversation_answer:string,interests:array<int,string>} $details
 */
function bushisa_personality_save(PDO $pdo, int $userId, array $details): void
{
    if ($userId <= 0 || !bushisa_personality_available($pdo)) {
        throw new RuntimeException('The profile details migration has not been applied.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO bushisa_profile_personality
            (user_id, prompt_text, answer_text, interests_json)
         VALUES (:user_id, :prompt_text, :answer_text, :interests_json)
         ON DUPLICATE KEY UPDATE
            prompt_text = VALUES(prompt_text),
            answer_text = VALUES(answer_text),
            interests_json = VALUES(interests_json)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':prompt_text' => $details['conversation_prompt'],
        ':answer_text' => $details['conversation_answer'],
        ':interests_json' => json_encode($details['interests'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function bushisa_personality_decode_candidate_rows(array $rows): array
{
    foreach ($rows as &$row) {
        $items = json_decode((string) ($row['interests_json'] ?? '[]'), true);
        $row['interests'] = is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        unset($row['interests_json']);
    }
    unset($row);
    return $rows;
}
