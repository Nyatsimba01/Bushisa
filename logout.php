<?php

declare(strict_types=1);

require_once __DIR__ . '/php/session_manager.php';
require_once __DIR__ . '/php/function.php';

destroy_session();
redirect('login.php');