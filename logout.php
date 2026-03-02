<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_SESSION['user']['id'])) {
    $userId = (int) $_SESSION['user']['id'];
    $companyId = (int) ($_SESSION['user']['company_id'] ?? 0);
    $stmt = db()->prepare('UPDATE users SET remember_token = NULL WHERE id = ? AND company_id = ?');
    $stmt->bind_param('ii', $userId, $companyId);
    $stmt->execute();
}

$_SESSION = [];
session_destroy();

// Clear remember me cookie
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 3600, '/');
}

session_start();
set_flash('success', __('You have been logged out.'));
redirect('login.php');
