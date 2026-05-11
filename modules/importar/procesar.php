<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/_importar_anual_helpers.php';

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

CSRF::requireValid();

$pdo = DB::conn();
$user = Auth::user();
$colegioId = (int)Auth::colegioId();
$userId = (int)($user['id'] ?? 0);
$anioEscolar = importar_anual_validar_anio($_POST['anio_escolar'] ?? null);
$tipo = (string)($_POST['tipo'] ?? '');

$mapa = [
    'alumnos' => 'alumnos_anual',
    'apoderados' => 'apoderados_anual',
    'docentes' => 'docentes_anual',
    'asistentes' => 'asistentes_anual',
];

if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
    header('Location: index.php?error=archivo');
    exit;
}

$tmp = $_FILES['archivo']['tmp_name'];
$handle = fopen($tmp, 'r');
if (!$handle) {
    header('Location: index.php?error=lectura');
    exit;
}

$insertados = 0;
$actualizados = 0;
$errores = 0;

try {
    $pdo->beginTransaction();

    $headers = fgetcsv($handle, 0, ';');
    if (!$headers) {
        throw new RuntimeException('CSV sin encabezados.');
    }

    $headers = array_map(fn($h) => mb_strtolower(trim((string)$h), 'UTF-8'), $headers);

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $data = [];
        foreach ($headers as $i => $h) {
            $data[$h] = $row[$i] ?? '';
        }

        if ($tipo === 'vinculacion') {
            $runAlumno = importar_anual_normalizar_run($data['run_alumno'] ?? '');
            $runApoderado = importar_anual_normalizar_run($data['run_apoderado'] ?? '');
            if ($runAlumno === '' || $runApoderado === '') { $errores++; continue; }

            $alumno = importar_anual_buscar_por_run($pdo, 'alumnos_anual', $colegioId, $anioEscolar, $runAlumno);
            $apoderado = importar_anual_buscar_por_run($pdo, 'apoderados_anual', $colegioId, $anioEscolar, $runApoderado);
            if (!$alumno || !$apoderado) { $errores++; continue; }

            $stmt = $pdo->prepare("\n                SELECT id FROM alumno_apoderado_anual\n                WHERE colegio_id = ? AND anio_escolar = ? AND alumno_anual_id = ? AND apoderado_anual_id = ?\n                LIMIT 1\n            ");
            $stmt->execute([$colegioId, $anioEscolar, (int)$alumno['id'], (int)$apoderado['id']]);
            $relId = $stmt->fetchColumn();

            $vals = [
                importar_anual_upper($data['relacion'] ?? ''),
                (int)($data['es_principal'] ?? 0),
                (int)($data['contacto_emergencia'] ?? 0),
                (int)($data['retiro_autorizado'] ?? 0),
                (int)($data['vive_con_estudiante'] ?? 0),
                (int)($data['autoriza_notificaciones'] ?? 0),
            ];

            if ($relId) {
                $stmt = $pdo->prepare("\n                    UPDATE alumno_apoderado_anual\n                    SET relacion=?, es_principal=?, contacto_emergencia=?, retiro_autorizado=?, vive_con_estudiante=?, autoriza_notificaciones=?, vigente=1, updated_at=NOW()\n                    WHERE id=? AND colegio_id=?\n                ");
                $stmt->execute([...$vals, (int)$relId, $colegioId]);
                $actualizados++;
            } else {
                $stmt = $pdo->prepare("\n                    INSERT INTO alumno_apoderado_anual\n                    (colegio_id, anio_escolar, alumno_anual_id, apoderado_anual_id, relacion, es_principal, contacto_emergencia, retiro_autorizado, vive_con_estudiante, autoriza_notificaciones, vigente, created_at, updated_at)\n                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())\n                ");
                $stmt->execute([$colegioId, $anioEscolar, (int)$alumno['id'], (int)$apoderado['id'], ...$vals]);
                $insertados++;
            }
            continue;
        }

        $tabla = $mapa[$tipo] ?? null;
        if (!$tabla) { throw new RuntimeException('Tipo no válido.'); }

        $run = importar_anual_normalizar_run($data['run'] ?? '');
        if ($run === '') { $errores++; continue; }

        $base = [
            'run' => $run,
            'nombres' => importar_anual_upper($data['nombres'] ?? ''),
            'apellido_paterno' => importar_anual_upper($data['apellido_paterno'] ?? ''),
            'apellido_materno' => importar_anual_upper($data['apellido_materno'] ?? ''),
            'fecha_nacimiento' => trim((string)($data['fecha_nacimiento'] ?? '')) ?: null,
            'sexo' => importar_anual_upper($data['sexo'] ?? ''),
            'genero' => importar_anual_upper($data['genero'] ?? ''),
            'nombre_social' => importar_anual_nombre_social($data['nombre_social'] ?? ''),
        ];

        $existente = importar_anual_buscar_por_run($pdo, $tabla, $colegioId, $anioEscolar, $run);

        if ($tabla === 'alumnos_anual') {
            $extraCols = ['curso','nivel','letra','jornada','estado_matricula'];
        } elseif ($tabla === 'apoderados_anual') {
            $extraCols = ['telefono','email','direccion','relacion_general'];
        } elseif ($tabla === 'docentes_anual') {
            $extraCols = ['cargo','departamento','jefatura_curso','tipo_contrato'];
        } else {
            $extraCols = ['cargo','unidad','tipo_contrato'];
        }

        $extra = [];
        foreach ($extraCols as $col) {
            $extra[$col] = ($col === 'email') ? importar_anual_lower($data[$col] ?? '') : importar_anual_upper($data[$col] ?? '');
        }

        if ($existente) {
            $sets = [];
            $values = [];
            foreach (array_merge($base, $extra) as $col => $val) {
                $sets[] = "{$col} = ?";
                $values[] = $val;
            }
            $sets[] = 'vigente = 1';
            $sets[] = 'updated_at = NOW()';
            $values[] = (int)$existente['id'];
            $values[] = $colegioId;
            $stmt = $pdo->prepare("UPDATE {$tabla} SET " . implode(', ', $sets) . " WHERE id = ? AND colegio_id = ?");
            $stmt->execute($values);
            $actualizados++;
        } else {
            $cols = array_keys(array_merge($base, $extra));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $pdo->prepare("\n                INSERT INTO {$tabla}\n                (colegio_id, anio_escolar, " . implode(', ', $cols) . ", vigente, created_at, updated_at)\n                VALUES (?, ?, {$placeholders}, 1, NOW(), NOW())\n            ");
            $stmt->execute([$colegioId, $anioEscolar, ...array_values(array_merge($base, $extra))]);
            $insertados++;
        }
    }

    if (function_exists('registrar_bitacora')) {
        registrar_bitacora($pdo, $colegioId, $userId, 'importar', 'importacion_anual', $tipo, 0, "Importación anual {$tipo} año {$anioEscolar}");
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('[Metis] Error importación anual: ' . $e->getMessage());
    fclose($handle);
    header('Location: index.php?error=bd');
    exit;
}

fclose($handle);
header('Location: index.php?ok=1&insertados=' . $insertados . '&actualizados=' . $actualizados . '&errores=' . $errores);
exit;
