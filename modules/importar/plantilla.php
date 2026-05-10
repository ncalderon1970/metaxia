<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::requireLogin();

$tipo = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : 'apoderados';

$headersByType = [
    'alumnos' => ['run', 'nombres', 'apellido_paterno', 'apellido_materno', 'fecha_nacimiento', 'curso', 'email', 'telefono', 'direccion'],
    'apoderados' => ['run', 'nombres', 'apellido_paterno', 'apellido_materno', 'parentesco', 'email', 'telefono', 'direccion'],
    'docentes' => ['run', 'nombres', 'apellido_paterno', 'apellido_materno', 'email', 'telefono', 'cargo', 'especialidad'],
    'asistentes' => ['run', 'nombres', 'apellido_paterno', 'apellido_materno', 'email', 'telefono', 'cargo', 'especialidad'],
];

if (!isset($headersByType[$tipo])) {
    $tipo = 'apoderados';
}

$filename = 'plantilla_' . $tipo . '_metis.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'wb');

// BOM para Excel.
fwrite($out, "\xEF\xBB\xBF");
fwrite($out, "sep=;\r\n");
fputcsv($out, $headersByType[$tipo], ';');

if ($tipo === 'apoderados') {
    fputcsv($out, ['19954340-8', 'CRISTOBAL JESUS', 'OLIVARES', 'GONZALEZ', 'PADRE', 'cristobal@example.cl', '56912345678', 'CALLE 123'], ';');
} elseif ($tipo === 'alumnos') {
    fputcsv($out, ['19954340-8', 'CRISTOBAL JESUS', 'OLIVARES', 'GONZALEZ', '2012-05-10', '8 BASICO A', 'alumno@example.cl', '56912345678', 'CALLE 123'], ';');
} else {
    fputcsv($out, ['19954340-8', 'CRISTOBAL JESUS', 'OLIVARES', 'GONZALEZ', 'persona@example.cl', '56912345678', 'INSPECTOR', 'CONVIVENCIA'], ';');
}

fclose($out);
exit;
