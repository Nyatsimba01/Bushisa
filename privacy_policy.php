<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';

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

// --- Frontend HTML will be added later ---
