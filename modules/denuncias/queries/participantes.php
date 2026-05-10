<?php
declare(strict_types=1);
/**
 * Metis · Denuncias › Queries — participantes y contexto familiar
 *
 * Este archivo reemplaza la lógica defensiva de introspección dinámica del esquema.
 * La estructura de datos se asume estable; las consultas se protegen con
 * try/catch para no romper la vista del expediente ante errores transitorios.
 */

function ver_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $default;
}

function ver_nombre_persona(array $row): string
{
    $nombres = ver_pick($row, ['nombres', 'nombre', 'nombre_completo', 'nombre_referencial'], '');
    $paterno = ver_pick($row, ['apellido_paterno', 'paterno', 'ap_paterno', 'primer_apellido'], '');
    $materno = ver_pick($row, ['apellido_materno', 'materno', 'ap_materno', 'segundo_apellido'], '');

    $nombre = trim($nombres . ' ' . $paterno . ' ' . $materno);

    return $nombre !== '' ? $nombre : 'Sin nombre';
}

function ver_cargar_alumno_por_id(PDO $pdo, int $alumnoId, int $colegioId): ?array
{
    if ($alumnoId <= 0 || $colegioId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM alumnos
            WHERE id = ?
              AND colegio_id = ?
            LIMIT 1
        ");
        $stmt->execute([$alumnoId, $colegioId]);

        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[Metis] ver_cargar_alumno_por_id: ' . $e->getMessage());
        return null;
    }
}

function ver_cargar_alumno_por_run(PDO $pdo, string $run, int $colegioId): ?array
{
    $run = trim($run);

    if ($run === '' || $colegioId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM alumnos
            WHERE run = ?
              AND colegio_id = ?
            LIMIT 1
        ");
        $stmt->execute([$run, $colegioId]);

        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[Metis] ver_cargar_alumno_por_run: ' . $e->getMessage());
        return null;
    }
}

function ver_cargar_apoderados_de_alumno(PDO $pdo, int $alumnoId, int $colegioId): array
{
    if ($alumnoId <= 0 || $colegioId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.*,
                aa.id AS relacion_id,
                aa.parentesco AS relacion_parentesco,
                aa.es_titular AS es_principal,
                aa.recibe_notificaciones AS contacto_emergencia,
                aa.puede_retirar AS autorizado_retirar,
                aa.vive_con_estudiante AS vive_con_estudiante,
                aa.observacion AS relacion_observacion,
                aa.activo AS relacion_activo,
                aa.created_at AS relacion_created_at,
                aa.updated_at AS relacion_updated_at
            FROM alumno_apoderado aa
            INNER JOIN apoderados a ON a.id = aa.apoderado_id
            WHERE aa.alumno_id = ?
              AND a.colegio_id = ?
            ORDER BY
                aa.activo DESC,
                aa.es_titular DESC,
                aa.recibe_notificaciones DESC,
                aa.puede_retirar DESC,
                aa.id DESC
        ");
        $stmt->execute([$alumnoId, $colegioId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[Metis] ver_cargar_apoderados_de_alumno: ' . $e->getMessage());
        return [];
    }
}

function ver_cargar_contexto_familiar(PDO $pdo, array $participantes, int $colegioId): array
{
    $contexto = [];
    $vistos = [];

    if ($colegioId <= 0) {
        return [];
    }

    foreach ($participantes as $participante) {
        $tipoPersona = mb_strtolower((string)($participante['tipo_persona'] ?? ''), 'UTF-8');

        if (!in_array($tipoPersona, ['alumno', 'estudiante'], true)) {
            continue;
        }

        $alumno = null;
        $personaId = (int)($participante['persona_id'] ?? 0);

        if ($personaId > 0) {
            $alumno = ver_cargar_alumno_por_id($pdo, $personaId, $colegioId);
        }

        if (!$alumno && !empty($participante['run'])) {
            $alumno = ver_cargar_alumno_por_run($pdo, (string)$participante['run'], $colegioId);
        }

        if (!$alumno) {
            continue;
        }

        $alumnoId = (int)($alumno['id'] ?? 0);

        if ($alumnoId <= 0 || isset($vistos[$alumnoId])) {
            continue;
        }

        $vistos[$alumnoId] = true;
        $apoderados = ver_cargar_apoderados_de_alumno($pdo, $alumnoId, $colegioId);

        $principales = [];
        $emergencia = [];
        $retiro = [];
        $activos = [];

        foreach ($apoderados as $apoderado) {
            if ((int)($apoderado['relacion_activo'] ?? 1) === 1) {
                $activos[] = $apoderado;
            }

            if ((int)($apoderado['es_principal'] ?? 0) === 1) {
                $principales[] = $apoderado;
            }

            if ((int)($apoderado['contacto_emergencia'] ?? 0) === 1) {
                $emergencia[] = $apoderado;
            }

            if ((int)($apoderado['autorizado_retirar'] ?? 0) === 1) {
                $retiro[] = $apoderado;
            }
        }

        $contexto[] = [
            'participante' => $participante,
            'alumno' => $alumno,
            'apoderados' => $apoderados,
            'apoderados_activos' => $activos,
            'principales' => $principales,
            'emergencia' => $emergencia,
            'retiro' => $retiro,
        ];
    }

    return $contexto;
}

function ver_resumen_contexto_familiar(array $contextoFamiliar): array
{
    $alumnosTotal = count($contextoFamiliar);
    $alumnosConApoderados = 0;
    $alumnosSinApoderados = 0;
    $principalesTotal = 0;
    $emergenciaTotal = 0;
    $retiroTotal = 0;
    $telefonoDisponible = false;
    $emailDisponible = false;
    $primerAlumno = null;
    $primerPrincipal = null;
    $observaciones = [];

    foreach ($contextoFamiliar as $bloque) {
        $alumno = $bloque['alumno'] ?? [];
        $activos = $bloque['apoderados_activos'] ?? [];
        $apoderados = $bloque['apoderados'] ?? [];
        $principales = $bloque['principales'] ?? [];
        $emergencia = $bloque['emergencia'] ?? [];
        $retiro = $bloque['retiro'] ?? [];

        if ($primerAlumno === null && is_array($alumno) && $alumno) {
            $primerAlumno = $alumno;
        }

        if (count($apoderados) > 0) {
            $alumnosConApoderados++;
        } else {
            $alumnosSinApoderados++;
        }

        $principalesTotal += count($principales);
        $emergenciaTotal += count($emergencia);
        $retiroTotal += count($retiro);

        if ($primerPrincipal === null && !empty($principales[0])) {
            $primerPrincipal = $principales[0];
        }

        foreach (array_merge($principales, $emergencia, $activos) as $apoderado) {
            if (!$telefonoDisponible && ver_pick($apoderado, ['telefono', 'fono', 'celular'], '') !== '') {
                $telefonoDisponible = true;
            }

            if (!$emailDisponible && ver_pick($apoderado, ['email', 'correo', 'correo_electronico'], '') !== '') {
                $emailDisponible = true;
            }

            $obs = trim((string)($apoderado['relacion_observacion'] ?? ''));
            if ($obs !== '' && !in_array($obs, $observaciones, true)) {
                $observaciones[] = $obs;
            }
        }
    }

    if ($primerPrincipal === null) {
        foreach ($contextoFamiliar as $bloque) {
            $activos = $bloque['apoderados_activos'] ?? [];
            if (!empty($activos[0])) {
                $primerPrincipal = $activos[0];
                break;
            }
        }
    }

    $principalNombre = $primerPrincipal ? ver_nombre_persona($primerPrincipal) : 'No informado';
    $principalTelefono = $primerPrincipal ? ver_pick($primerPrincipal, ['telefono', 'fono', 'celular'], '-') : '-';
    $principalEmail = $primerPrincipal ? ver_pick($primerPrincipal, ['email', 'correo', 'correo_electronico'], '-') : '-';
    $principalParentesco = $primerPrincipal ? ver_pick($primerPrincipal, ['relacion_parentesco', 'parentesco'], '-') : '-';

    $alumnoNombre = $primerAlumno ? ver_nombre_persona($primerAlumno) : 'Sin alumno vinculado';
    $alumnoId = $primerAlumno ? (int)($primerAlumno['id'] ?? 0) : 0;

    if ($alumnosTotal === 0) {
        $estadoTexto = 'Sin alumno vinculado';
        $estadoClase = 'warn';
        $accion = 'Vincular alumno desde comunidad educativa.';
    } elseif ($alumnosSinApoderados > 0) {
        $estadoTexto = 'Apoderado pendiente';
        $estadoClase = 'danger';
        $accion = 'Registrar o vincular apoderado del estudiante.';
    } elseif ($principalesTotal === 0) {
        $estadoTexto = 'Sin apoderado principal';
        $estadoClase = 'warn';
        $accion = 'Definir apoderado principal.';
    } elseif (!$telefonoDisponible) {
        $estadoTexto = 'Sin teléfono de contacto';
        $estadoClase = 'warn';
        $accion = 'Completar teléfono de contacto familiar.';
    } else {
        $estadoTexto = 'Contexto familiar disponible';
        $estadoClase = 'ok';
        $accion = 'Mantener datos familiares actualizados.';
    }

    return [
        'alumnos_total' => $alumnosTotal,
        'alumnos_con_apoderados' => $alumnosConApoderados,
        'alumnos_sin_apoderados' => $alumnosSinApoderados,
        'apoderados_principales' => $principalesTotal,
        'contactos_emergencia' => $emergenciaTotal,
        'autorizados_retiro' => $retiroTotal,
        'telefono_disponible' => $telefonoDisponible,
        'email_disponible' => $emailDisponible,
        'principal_nombre' => $principalNombre,
        'principal_telefono' => $principalTelefono,
        'principal_email' => $principalEmail,
        'principal_parentesco' => $principalParentesco,
        'alumno_nombre' => $alumnoNombre,
        'alumno_id' => $alumnoId,
        'estado_texto' => $estadoTexto,
        'estado_clase' => $estadoClase,
        'accion_recomendada' => $accion,
        'observaciones' => array_slice($observaciones, 0, 3),
    ];
}
