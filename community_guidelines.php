<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/php/frontend.php';

$page_title = 'Community Guidelines | Bushisa';

$guidelines = [
    'Be Respectful' => 'Treat all users with dignity. No harassment, bullying, or intimidation.',
    'Be Honest' => 'Use your real details for your dating profile. Catfishing results in permanent bans.',
    'Consent Matters' => 'Do not share private conversations or photos without explicit consent.',
    'Report Abuse' => 'Use the report feature to flag any user who violates these guidelines.',
    'Confessions Etiquette' => 'Confessions are anonymous but not lawless. Hate speech, threats, and defamation will be removed.',
    'One Account Per Student' => 'Multiple accounts are not allowed and will be merged or banned.',
    'Consequences' => 'Warnings -> Temporary suspension -> Permanent ban.',
];

render_auth_start('Community Guidelines');
?>
<section class="auth-card" aria-labelledby="guidelines-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="guidelines-title">Community Guidelines</h1>
    <p class="auth-card__lead">Bushisa is built around privacy, consent and respectful student discovery.</p>
    <div class="form-grid">
        <?php foreach ($guidelines as $heading => $content): ?>
            <article class="section-card">
                <h2><?= e($heading) ?></h2>
                <p class="muted"><?= e($content) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="auth-switch"><a href="profile.php">Back to Bushisa</a></p>
</section>
<?php
render_auth_end();
