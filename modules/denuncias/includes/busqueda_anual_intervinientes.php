<?php
/**
 * METIS SGCE · Fase 23E
 * Búsqueda anual de intervinientes desde comunidad educativa histórica.
 *
 * Requiere tablas anuales creadas en Fase 23A-23B.
 * No usa INFORMATION_SCHEMA ni SHOW COLUMNS/SHOW TABLES.
 */

declare(strict_types=1);

if (!function_exists('metis_vp_run_limpio')) {
    function metis_vp_run_limpio(string $run): string
    {
        return preg_replace('/[^0-9kK]/', '', $run) ?? '';
    }
}

if (!function_exists('metis_vp_nombre_preferente')) {
    function metis_vp_nombre_preferente(array $r): string
    {
        $nombreSocial = trim((string)($r['nombre_social'] ?? ''));
        if ($nombreSocial !== '') {
            return $nombreSocial;
        }

        $partes = array_filter([
            trim((string)($r['apellido_paterno'] ?? '')),
            trim((string)($r['apellido_materno'] ?? '')),
            trim((string)($r['nombres'] ?? '')),
        ], static fn($v): bool => $v !== '');

        $nombre = trim(implode(' ', $partes));
        if ($nombre === '') {
            $nombre = trim((string)($r['nombre'] ?? ''));
        }

        return $nombre !== '' ? $nombre : 'N/N';
    }
}

if (!function_exists('metis_vp_calcular_edad')) {
    function metis_vp_calcular_edad(?string $fechaNacimiento, ?string $fechaReferencia): ?int
    {
        if (!$fechaNacimiento || !$fechaReferencia) {
            return null;
        }

        try {
            $nacimiento = new DateTimeImmutable($fechaNacimiento);
            $referencia = new DateTimeImmutable($fechaReferencia);
            return (int)$nacimiento->diff($referencia)->y;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('metis_vp_fecha_referencia_caso')) {
    function metis_vp_fecha_referencia_caso(PDO $pdo, int $casoId, int $colegioId): string
    {
        if ($casoId <= 0 || $colegioId <= 0) {
            return date('Y-m-d');
        }

        try {
            $stmt = $pdo->prepare("\n                SELECT DATE(COALESCE(fecha_hechos, fecha_hora_incidente, fecha_ingreso, created_at, NOW())) AS fecha_ref\n                FROM casos\n                WHERE id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
            $stmt->execute([$casoId, $colegioId]);
            $fecha = (string)($stmt->fetchColumn() ?: '');
            return $fecha !== '' ? $fecha : date('Y-m-d');
        } catch (Throwable $e) {
            return date('Y-m-d');
        }
    }
}

if (!function_exists('metis_vp_anio_caso')) {
    function metis_vp_anio_caso(PDO $pdo, int $casoId, int $colegioId): int
    {
        $anioGet = (int)($_GET['anio_escolar'] ?? 0);
        if ($anioGet >= 2020 && $anioGet <= 2100) {
            return $anioGet;
        }

        $fecha = metis_vp_fecha_referencia_caso($pdo, $casoId, $colegioId);
        $anio = (int)date('Y', strtotime($fecha));
        return $anio > 0 ? $anio : (int)date('Y');
    }
}

if (!function_exists('metis_vp_row_to_item')) {
    function metis_vp_row_to_item(array $r, string $tipo, string $tipoLabel, string $fechaReferencia): array
    {
        $nombrePreferente = metis_vp_nombre_preferente($r);
        $edad = metis_vp_calcular_edad($r['fecha_nacimiento'] ?? null, $fechaReferencia);

        $extra = '';
        if ($tipo === 'alumno') {
            $extra = trim((string)($r['curso'] ?? ''));
        } else {
            $extra = trim((string)($r['cargo'] ?? ''));
        }

        $legacyKey = match ($tipo) {
            'alumno' => 'alumno_legacy_id',
            'apoderado' => 'apoderado_legacy_id',
            'docente' => 'docente_legacy_id',
            'asistente' => 'asistente_legacy_id',
            default => null,
        };

        return [
            'id' => (int)$r['id'],
            'persona_id' => $legacyKey ? (int)($r[$legacyKey] ?? 0) : 0,
            'persona_anual_id' => (int)$r['id'],
            'persona_anual_tipo' => $tipo,
            'nombre' => $nombrePreferente,
            'run' => (string)($r['run'] ?: '0-0'),
            'curso' => $extra,
            'tipo' => $tipo,
            'tipo_label' => $tipoLabel,
            'anio_escolar' => (int)($r['anio_escolar'] ?? 0),
            'sexo' => (string)($r['sexo'] ?? ''),
            'genero' => (string)($r['genero'] ?? ''),
            'nombre_social' => (string)($r['nombre_social'] ?? ''),
            'fecha_nacimiento' => (string)($r['fecha_nacimiento'] ?? ''),
            'edad' => $edad,
            'snapshot_run' => (string)($r['run'] ?: '0-0'),
            'snapshot_nombres' => (string)($r['nombres'] ?? ''),
            'snapshot_apellido_paterno' => (string)($r['apellido_paterno'] ?? ''),
            'snapshot_apellido_materno' => (string)($r['apellido_materno'] ?? ''),
            'snapshot_nombre_social' => (string)($r['nombre_social'] ?? ''),
            'snapshot_sexo' => (string)($r['sexo'] ?? ''),
            'snapshot_genero' => (string)($r['genero'] ?? ''),
            'snapshot_fecha_nacimiento' => (string)($r['fecha_nacimiento'] ?? ''),
            'snapshot_edad' => $edad,
            'snapshot_curso' => $extra,
            'snapshot_anio_escolar' => (int)($r['anio_escolar'] ?? 0),
            'snapshot_fecha_referencia' => $fechaReferencia,
        ];
    }
}

if (!function_exists('metis_vp_buscar_anual')) {
    function metis_vp_buscar_anual(PDO $pdo, string $tabla, string $tipo, string $tipoLabel, string $q, int $colegioId, int $anioEscolar, string $fechaReferencia): array
    {
        $tablasPermitidas = [
            'alumnos_anual',
            'apoderados_anual',
            'docentes_anual',
            'asistentes_anual',
        ];

        if (!in_array($tabla, $tablasPermitidas, true)) {
            return [];
        }

        $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
        $qRun = '%' . metis_vp_run_limpio($q) . '%';

        $selectCargo = in_array($tabla, ['docentes_anual', 'asistentes_anual'], true)
            ? 'COALESCE(cargo, \'\') AS cargo,'
            : "'' AS cargo,";

        $selectCurso = $tabla === 'alumnos_anual'
            ? 'COALESCE(curso, \'\') AS curso,'
            : "'' AS curso,";

        $legacyColumn = match ($tabla) {
            'alumnos_anual' => 'alumno_legacy_id',
            'apoderados_anual' => 'apoderado_legacy_id',
            'docentes_anual' => 'docente_legacy_id',
            'asistentes_anual' => 'asistente_legacy_id',
            default => 'NULL',
        };

        $nombreFallback = $tabla === 'alumnos_anual'
            ? "CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres)"
            : "COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, '')";

        try {
            $stmt = $pdo->prepare("\n                SELECT\n                    id,\n                    {$legacyColumn},\n                    run,\n                    nombres,\n                    apellido_paterno,\n                    apellido_materno,\n                    " . ($tabla === 'alumnos_anual' ? "'' AS nombre," : "COALESCE(nombre, '') AS nombre,") . "\n                    fecha_nacimiento,\n                    sexo,\n                    genero,\n                    nombre_social,\n                    anio_escolar,\n                    {$selectCurso}\n                    {$selectCargo}\n                    vigente\n                FROM {$tabla}\n                WHERE colegio_id = ?\n                  AND anio_escolar = ?\n                  AND vigente = 1\n                  AND (\n                        UPPER(CONVERT({$nombreFallback} USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                     OR UPPER(CONVERT(COALESCE(nombre_social, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                     OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                     OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n                  )\n                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC\n                LIMIT 10\n            ");
            $stmt->execute([$colegioId, $anioEscolar, $qTexto, $qTexto, '%' . $q . '%', $qRun]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[Metis][23E] Error búsqueda anual ' . $tabla . ': ' . $e->getMessage());
            return [];
        }

        return array_map(static fn(array $r): array => metis_vp_row_to_item($r, $tipo, $tipoLabel, $fechaReferencia), $rows);
    }
}

if (!function_exists('metis_vp_buscar_por_tipo_anual')) {
    function metis_vp_buscar_por_tipo_anual(PDO $pdo, string $tipo, string $q, int $colegioId, int $anioEscolar, string $fechaReferencia): array
    {
        return match ($tipo) {
            'alumno' => metis_vp_buscar_anual($pdo, 'alumnos_anual', 'alumno', 'Alumno/a', $q, $colegioId, $anioEscolar, $fechaReferencia),
            'apoderado' => metis_vp_buscar_anual($pdo, 'apoderados_anual', 'apoderado', 'Apoderado/a', $q, $colegioId, $anioEscolar, $fechaReferencia),
            'funcionario' => array_merge(
                metis_vp_buscar_anual($pdo, 'docentes_anual', 'docente', 'Docente', $q, $colegioId, $anioEscolar, $fechaReferencia),
                metis_vp_buscar_anual($pdo, 'asistentes_anual', 'asistente', 'Asistente', $q, $colegioId, $anioEscolar, $fechaReferencia)
            ),
            'todos' => array_merge(
                metis_vp_buscar_anual($pdo, 'alumnos_anual', 'alumno', 'Alumno/a', $q, $colegioId, $anioEscolar, $fechaReferencia),
                metis_vp_buscar_anual($pdo, 'apoderados_anual', 'apoderado', 'Apoderado/a', $q, $colegioId, $anioEscolar, $fechaReferencia),
                metis_vp_buscar_anual($pdo, 'docentes_anual', 'docente', 'Docente', $q, $colegioId, $anioEscolar, $fechaReferencia),
                metis_vp_buscar_anual($pdo, 'asistentes_anual', 'asistente', 'Asistente', $q, $colegioId, $anioEscolar, $fechaReferencia)
            ),
            default => [],
        };
    }
}

$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$casoIdAjax = (int)($_GET['id'] ?? 0);
$tipo = trim((string)($_GET['tipo'] ?? 'alumno'));
$q = trim((string)($_GET['q'] ?? ''));

if (!in_array($tipo, ['alumno', 'apoderado', 'funcionario', 'todos', 'externo'], true)) {
    $tipo = 'alumno';
}

$fechaReferencia = metis_vp_fecha_referencia_caso($pdo, $casoIdAjax, $colegioId);
$anioEscolar = metis_vp_anio_caso($pdo, $casoIdAjax, $colegioId);

if ($tipo === 'externo' || mb_strlen($q, 'UTF-8') < 2 || $colegioId <= 0) {
    echo json_encode([
        'ok' => true,
        'items' => [],
        'message' => '',
        'anio_escolar' => $anioEscolar,
        'fecha_referencia' => $fechaReferencia,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = metis_vp_buscar_por_tipo_anual($pdo, $tipo, $q, $colegioId, $anioEscolar, $fechaReferencia);

$message = '';
if (empty($items) && $tipo !== 'todos' && $q !== '') {
    $items = metis_vp_buscar_por_tipo_anual($pdo, 'todos', $q, $colegioId, $anioEscolar, $fechaReferencia);
    $message = $items
        ? 'Se muestran coincidencias de otros estamentos del año escolar ' . $anioEscolar . '.'
        : 'Sin coincidencias para el año escolar ' . $anioEscolar . '. Usa "Externo" para ingresar manualmente.';
}

echo json_encode([
    'ok' => true,
    'items' => array_slice($items, 0, 20),
    'message' => $message,
    'anio_escolar' => $anioEscolar,
    'fecha_referencia' => $fechaReferencia,
], JSON_UNESCAPED_UNICODE);
exit;
