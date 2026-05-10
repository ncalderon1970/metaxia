<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="plantilla_vinculacion_apoderados_metis.csv"');
header('Pragma: no-cache'); header('Expires: 0');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fwrite($out, "sep=;\r\n");

fputcsv($out, ['run_alumno','run_apoderado','tipo_relacion','es_titular','puede_retirar','recibe_notificaciones','vive_con_estudiante','observacion'], ';');
fputcsv($out, ['# RUN sin puntos con guion','# RUN sin puntos con guion','# madre|padre|abuelo|tio|hermano|tutor|otro','# 1=SI 0=NO - apoderado principal','# 1=SI 0=NO','# 1=SI 0=NO','# 1=SI 0=NO','# Opcional'], ';');
fputcsv($out, ['12345678-9','98765432-1','madre','1','1','1','1',''], ';');
fputcsv($out, ['12345678-9','11111111-1','padre','0','1','0','0','Padre separado'], ';');
fputcsv($out, ['87654321-0','98765432-1','madre','1','1','1','1',''], ';');
fputcsv($out, ['98765432-1','44444444-4','abuelo','1','1','1','0','Vive con abuelo'], ';');
fclose($out);
exit;
