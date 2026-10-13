<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
logout();
session_start();
flash_info('You have been signed out.');
redirect('login');
