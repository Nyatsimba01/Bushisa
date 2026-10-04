<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';

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

// --- Frontend HTML will be added later ---
