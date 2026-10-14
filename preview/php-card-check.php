<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/frontend.php';
require_once __DIR__ . '/../php/discovery_cards.php';

// Fictional visual fixtures only. No account, login, or database is accessed.
$samples = [
    [
        'user_id' => 101,
        'display_name' => 'Naledi (sample)',
        'age' => 20,
        'faculty' => 'Applied Sciences',
        'year_of_study' => 'Part 2',
        'profile_photo_path' => 'preview/assets/naledi.jpg',
        'bio' => 'Reading, jazz and quiet campus walks.',
        'conversation_prompt' => 'Favourite way to spend an afternoon on campus?',
        'conversation_answer' => 'Coffee and a quiet walk.',
        'interests' => ['📚 Reading', '🎵 Jazz', '☕ Coffee'],
    ],
    [
        'user_id' => 102,
        'display_name' => 'Tafadzwa (sample)',
        'age' => 21,
        'faculty' => 'Industrial Technology',
        'year_of_study' => 'Part 3',
        'profile_photo_path' => 'preview/assets/tafadzwa.jpg',
        'bio' => 'Engineering, music and weekend travel.',
        // No prompt/interests yet: this tests honest fallback behaviour.
    ],
    [
        'user_id' => 103,
        'display_name' => 'Example student',
        'age' => 22,
        'faculty' => 'Commerce & Law',
        'year_of_study' => 'Part 4',
        'profile_photo_path' => null,
        'bio' => '',
    ],
];

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <base href="../">
    <title>Bushisa · PHP Figma card test</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/home-discovery-cards.css">
    <style>
      body { min-height:100vh; background:#21151a; color:#f8ede2; }
      .component-check { max-width:1280px; margin:0 auto; padding:48px 80px; }
      .component-check h1 { font:400 28px Georgia,serif; margin:0 0 12px; }
      .component-check p.note { margin:0 0 24px; color:#c8b4b9; }
      .component-check .profile-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:24px; }
      .component-check .notice { padding:14px 18px; margin:0 0 24px; border-radius:12px; background:#3b2931; color:#f4b598; font-size:12px; }
      .component-check .status { color:#f4b598; padding:12px 0; min-height:32px; }
      @media(max-width:700px) {
        .component-check { padding:24px; }
        .component-check .profile-grid { grid-template-columns:1fr; gap:16px; }
      }
    </style>
</head>
<body class="bushisa-app page-home">
<main class="component-check">
    <h1>Figma card component check</h1>
    <p class="note">This page uses the exact PHP renderer shared by Home and its automatic-scrolling endpoint.</p>
    <p class="notice">DESIGN TEST ONLY · All names and profile details are fictional samples. No accounts, database, likes, matches, or messages are involved.</p>
    <div class="profile-grid">
        <?= bushisa_render_discovery_cards($samples) ?>
    </div>
    <p class="status" id="demo-status" role="status" aria-live="polite"></p>
</main>
<script>
document.addEventListener('click', (event) => {
    const target = event.target instanceof Element
        ? event.target.closest('[data-swipe-target]') : null;
    if (target) {
        event.preventDefault();
        document.getElementById('demo-status').textContent =
            'Visual test only. No action was recorded.';
    }
});
</script>
</body>
</html>
