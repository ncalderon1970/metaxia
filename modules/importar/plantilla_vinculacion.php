<?php
declare(strict_types=1);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_vinculacion_anual.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['run_alumno','run_apoderado','relacion','es_principal','contacto_emergencia','retiro_autorizado','vive_con_estudiante','autoriza_notificaciones'], ';');
fclose($out);
