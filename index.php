<?php

declare(strict_types=1);

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/php/function.php';

if (isset($_SESSION['user_id'])) {
    redirect('discover.php');
}

redirect('login.php');