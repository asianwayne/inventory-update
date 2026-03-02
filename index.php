<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (auth_user()) {
    redirect('dashboard.php');
}

redirect('login.php');
