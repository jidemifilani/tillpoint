<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
csrf_require();

$n = q('UPDATE notifications SET read_at = NOW()
         WHERE read_at IS NULL AND (user_id = ? OR role_code = ?)',
       [(int) $_SESSION['uid'], (string) ($_SESSION['role_code'] ?? '')])->rowCount();
flash_ok($n === 1 ? 'Notification marked read.' : "$n notifications marked read.");
redirect($_SERVER['HTTP_REFERER'] ?? '');
