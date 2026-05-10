<?php
declare(strict_types=1);
// Compatibilidad: el flujo consolidado de apoderados se gestiona en vincular_apoderado.php.
require_once dirname(__DIR__, 2) . '/config/app.php';
$alumnoId = (int)($_GET['alumno_id'] ?? $_POST['alumno_id'] ?? 0);
$url = APP_URL . '/modules/comunidad/vincular_apoderado.php';
if ($alumnoId > 0) {
    $url .= '?alumno_id=' . $alumnoId;
}
header('Location: ' . $url);
exit;
