<?php

// Send HSTS only over HTTPS on deployed domains, not local XAMPP.
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost = preg_match('/^(localhost|127\\.0\\.0\\.1)(:[0-9]+)?$/', $host) === 1;
$isHttpsRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$isLocalHost && $isHttpsRequest) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none';");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
