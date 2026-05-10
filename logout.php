<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/DB.php';
require_once __DIR__ . '/core/Auth.php';

Auth::logout();

header('Location: ' . APP_URL . '/public/login.php?closed=1');
exit;