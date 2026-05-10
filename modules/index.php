<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Auth.php';

Auth::requireLogin();

header('Location: ' . APP_URL . '/modules/dashboard/index.php');
exit;