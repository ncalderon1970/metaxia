<?php
declare(strict_types=1);
$tipo = $_GET['tipo'] ?? 'alumnos';
$headers = ['run','nombres','apellido_paterno','apellido_materno','fecha_nacimiento','sexo','genero','nombre_social'];
if ($tipo === 'apoderados') { $headers = array_merge($headers, ['telefono','email','direccion','relacion_general']); }
elseif ($tipo === 'docentes') { $headers = array_merge($headers, ['cargo','departamento','jefatura_curso','tipo_contrato']); }
elseif ($tipo === 'asistentes') { $headers = array_merge($headers, ['cargo','unidad','tipo_contrato']); }
else { $headers = array_merge($headers, ['curso','nivel','letra','jornada','estado_matricula']); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_' . preg_replace('/[^a-z_]/','', $tipo) . '_anual.csv"');
$out = fopen('php://output','w');
fputcsv($out, $headers, ';');
fclose($out);
