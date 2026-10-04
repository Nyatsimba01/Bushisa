<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';

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

// --- Frontend HTML will be added later ---
