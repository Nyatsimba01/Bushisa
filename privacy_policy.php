<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/php/frontend.php';

$page_title = 'Privacy Policy | Bushisa';
$last_updated = '2026-10-04';

$privacy_content = [
    'Data Collected' => 'Student email, student ID, display name, anonymous handle, gender, date of birth, faculty, year of study, profile photos, preferences, swipe history, messages, confessions, IP addresses.',
    'Purpose' => 'Matchmaking, confessions feed, moderation, platform improvement.',
    'Storage' => 'Data stored in MySQL database on secured servers. Passwords hashed with bcrypt.',
    'Sharing' => 'Data is never sold. Shared only with moderators for safety review.',
    'Retention' => 'Active account data retained while account exists. Deleted account data anonymised within 30 days. Audit logs retained for 12 months.',
    'User Rights (Zimbabwe Data Protection Act)' => 'Access, rectification, erasure, portability.',
    'Contact' => 'bushisaservices@gmail.com',
];

render_auth_start('Privacy Policy');
?>
<section class="auth-card" aria-labelledby="privacy-title">
    <span class="brand-mark" aria-hidden="true">B</span>
    <h1 id="privacy-title">Privacy Policy</h1>
    <p class="auth-card__lead">Last updated <?= e($last_updated) ?>. Anonymous features must not reveal real student identities to other users.</p>
    <div class="form-grid">
        <?php foreach ($privacy_content as $heading => $content): ?>
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
