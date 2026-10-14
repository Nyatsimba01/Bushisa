<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/profile_personality.php';

function assert_personality(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function should_reject(array $input, string $case): void
{
    try {
        bushisa_personality_validate($input);
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "FAIL: expected rejection of {$case}\n");
    exit(1);
}

$details = bushisa_personality_validate([
    'conversation_prompt' => 'What helps you enjoy campus?',
    'conversation_answer' => 'Reading & music.',
    'interests' => " Reading , Guitar, Coffee, reading \nPhotography ",
]);
assert_personality($details['conversation_prompt'] === 'What helps you enjoy campus?', 'question');
assert_personality($details['conversation_answer'] === 'Reading & music.', 'answer');
assert_personality($details['interests'] === ['Reading', 'Guitar', 'Coffee', 'Photography'], 'trim/dedupe tags');

$empty = bushisa_personality_validate([]);
assert_personality($empty['conversation_prompt'] === '' && $empty['conversation_answer'] === '' && $empty['interests'] === [], 'empty data');

should_reject(['conversation_answer' => 'Answer alone'], 'answer without question');
should_reject(['conversation_prompt' => str_repeat('x', 161)], 'long question');
should_reject(['conversation_prompt' => 'Prompt', 'conversation_answer' => str_repeat('x', 241)], 'long answer');
should_reject(['interests' => 'One, Two, Three, Four, Five, Six'], 'more than five tags');
should_reject(['interests' => str_repeat('x', 41)], 'long interest');
should_reject(['interests' => ['array should not be accepted']], 'nonstring interest');

$rows = bushisa_personality_decode_candidate_rows([
    ['user_id' => 1, 'interests_json' => '["Music","Coffee"]'],
    ['user_id' => 2, 'interests_json' => 'invalid'],
]);
assert_personality($rows[0]['interests'] === ['Music', 'Coffee'], 'JSON interests decode');
assert_personality($rows[1]['interests'] === [], 'invalid interest JSON is harmless');
assert_personality(!isset($rows[0]['interests_json']), 'JSON internal column removed');

echo "PASS: profile personality validation and decoding.\n";
