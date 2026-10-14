<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/confessions_identity.php';

$cases = [
    'quiet_spark' => true,
    'quiet_spark🌸' => true,
    'moonlight💜' => true,
    'star_👩🏽‍💻' => true,
    'blue🇿🇼' => true,
    '🌸abc' => true,
    '✨✨abc' => true,
    'abc❤️' => true,
    'quiet💜🌙🌸' => false,
    'quiet spark' => false,
    '💜💜' => false,
    'a___🌸' => false,
    'ñame🌙' => false,
    'abc' . str_repeat('x', 48) => false,
];

$failed = 0;
foreach ($cases as $handle => $expected) {
    $valid = bushisa_validate_confessions_handle($handle) === null;
    if ($valid !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $handle . PHP_EOL);
        ++$failed;
    }
}

if (!bushisa_confessions_handle_matches_public('nyatsimba_mutotesi🌸', 'Nyatsimba Mutotesi')) {
    fwrite(STDERR, 'FAIL: alias exposes public name' . PHP_EOL);
    ++$failed;
}
if (bushisa_confessions_handle_matches_public('quiet_spark🌸', 'Nyatsimba Mutotesi')) {
    fwrite(STDERR, 'FAIL: independent alias rejected' . PHP_EOL);
    ++$failed;
}
if (bushisa_clean_confessions_handle(' @quiet_spark🌸 ') !== 'quiet_spark🌸') {
    fwrite(STDERR, 'FAIL: username prefix normalization' . PHP_EOL);
    ++$failed;
}

if ($failed > 0) {
    exit(1);
}
echo "Confessions handle tests passed (" . count($cases) . " example usernames).\n";
