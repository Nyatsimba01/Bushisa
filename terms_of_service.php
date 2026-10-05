<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/php/frontend.php';

$page_title = 'Terms of Service | Bushisa';
$last_updated = '2026-10-04';

$tos_content = [
    'Eligibility' => 'Users must be NUST students aged 18+, using valid @students.nust.ac.zw email.',
    'Account Responsibility' => 'Users are responsible for their account security and activity.',
    'Prohibited Conduct' => 'Harassment, hate speech, catfishing, solicitation, sharing explicit content without consent.',
    'Content Ownership' => 'Users retain ownership of content they post but grant Bushisa a licence to display it.',
    'Moderation' => 'Bushisa reserves the right to suspend or ban accounts that violate these terms.',
    'Limitation of Liability' => 'Bushisa is not liable for user interactions that occur off-platform.',
    'Termination' => 'Users may delete their account at any time; Bushisa may terminate accounts for violations.',
];

render_auth_start('Terms of Service');
?>
<section class="auth-card" aria-labelledby="terms-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="terms-title">Terms of Service</h1>
    <p class="auth-card__lead">Last updated <?= e($last_updated) ?>. Use Bushisa only if you can follow the consent and safety rules.</p>
    <div class="form-grid">
        <?php foreach ($tos_content as $heading => $content): ?>
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
