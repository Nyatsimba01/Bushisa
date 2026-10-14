<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/frontend.php';
require_once __DIR__ . '/../php/discovery_cards.php';

function check_discovery_card(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$base = [
    'user_id' => 42,
    'display_name' => 'Test <script>student</script>',
    'age' => 21,
    'faculty' => 'Applied Sciences',
    'year_of_study' => 'Part 2',
    'bio' => 'Reading & studying',
    'profile_photo_path' => '',
];

$basic = bushisa_render_discovery_card($base);
check_discovery_card(str_contains($basic, 'data-candidate-id="42"'), 'candidate ID');
check_discovery_card(str_contains($basic, 'data-swipe-direction="like"'), 'functional like');
check_discovery_card(str_contains($basic, 'data-swipe-direction="pass"'), 'functional pass');
check_discovery_card(str_contains($basic, 'Test &lt;script&gt;student&lt;/script&gt;'), 'HTML escaping');
check_discovery_card(!str_contains($basic, '<script>student</script>'), 'no script injection');
check_discovery_card(str_contains($basic, 'Reading &amp; studying'), 'bio is escaped');
check_discovery_card(str_contains($basic, 'ABOUT ME'), 'real bio label');
check_discovery_card(!str_contains($basic, 'CONVERSATION PROMPT'), 'no made-up prompt');
check_discovery_card(str_contains($basic, '🎓 Applied Sciences'), 'real faculty tag');
check_discovery_card(str_contains($basic, '📚 Part 2'), 'real year tag');
check_discovery_card(str_contains($basic, 'img') || str_contains($basic, 'figma-photo-fallback'), 'valid portrait fallback');
check_discovery_card(str_contains($basic, 'disabled title="Photo likes'), 'unimplemented photo control disabled');

$rich = $base + [];
$rich['conversation_prompt'] = 'My ideal campus activity?';
$rich['conversation_answer'] = 'Sketching after lectures.';
$rich['interests'] = ['🎸 Guitar', '<b>Unsafe</b>'];
$renderedRich = bushisa_render_discovery_card($rich);
check_discovery_card(str_contains($renderedRich, 'CONVERSATION PROMPT'), 'optional authentic prompt');
check_discovery_card(str_contains($renderedRich, 'Sketching after lectures.'), 'optional answer');
check_discovery_card(str_contains($renderedRich, '🎸 Guitar'), 'optional interest tag');
check_discovery_card(str_contains($renderedRich, '&lt;b&gt;Unsafe&lt;/b&gt;'), 'tags escaped');
check_discovery_card(!str_contains($renderedRich, '<b>Unsafe</b>'), 'no markup injection');
check_discovery_card(bushisa_render_discovery_cards([$base, $rich]) !== '', 'batch uses the same renderer');

echo "PASS: Figma discovery cards are consistent and safe for sample data.\n";
