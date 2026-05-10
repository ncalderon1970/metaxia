<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . APP_URL . '/modules/comunidad/index.php?tipo=alumnos');
    exit;
}

// Redirigir al editor real en comunidad
header('Location: ' . APP_URL . '/modules/comunidad/editar.php?tipo=alumnos&id=' . $id);
exit;
