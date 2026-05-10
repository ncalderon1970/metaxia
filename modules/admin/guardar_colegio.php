<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::requireLogin();

header('Location: ' . APP_URL . '/modules/admin/index.php#colegio');
exit;