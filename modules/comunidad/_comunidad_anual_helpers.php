<?php
declare(strict_types=1);

/**
 * Helpers Fase 23 · Comunidad educativa histórica anual.
 * No reemplaza _comunidad_helpers.php. Se incorpora gradualmente en Fase 23C+.
 */

function com_anual_safe_tipo(string $tipo): string
{
    return in_array($tipo, ['alumnos', 'apoderados', 'docentes', 'asistentes'], true) ? $tipo : 'alumnos';
}

function com_anual_table(string $tipo): string
{
    return match (com_anual_safe_tipo($tipo)) {
        'alumnos' => 'alumnos_anual',
        'apoderados' => 'apoderados_anual',
        'docentes' => 'docentes_anual',
        'asistentes' => 'asistentes_anual',
        default => 'alumnos_anual',
    };
}

function com_anual_year(?int $anio = null): int
{
    $anio = $anio ?: (int)date('Y');
    if ($anio < 2020 || $anio > 2100) {
        throw new RuntimeException('Año escolar fuera de rango permitido.');
    }
    return $anio;
}

function com_anual_nombre_visible(array $row): string
{
    $social = trim((string)($row['nombre_social'] ?? ''));
    if ($social !== '') {
        return $social;
    }

    $parts = [];
    foreach (['nombres', 'apellido_paterno', 'apellido_materno'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $full = trim(implode(' ', $parts));
    if ($full !== '') {
        return $full;
    }

    return trim((string)($row['nombre'] ?? '')) ?: 'Sin nombre';
}

function com_anual_fetch_by_run(PDO $pdo, string $tipo, int $colegioId, int $anioEscolar, string $run): ?array
{
    $table = com_anual_table($tipo);
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE colegio_id = ? AND anio_escolar = ? AND run = ? LIMIT 1");
    $stmt->execute([$colegioId, $anioEscolar, $run]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function com_anual_fetch_by_id(PDO $pdo, string $tipo, int $colegioId, int $anioEscolar, int $id): ?array
{
    $table = com_anual_table($tipo);
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND colegio_id = ? AND anio_escolar = ? LIMIT 1");
    $stmt->execute([$id, $colegioId, $anioEscolar]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function com_anual_calcular_edad(?string $fechaNacimiento, ?string $fechaReferencia): ?int
{
    if (!$fechaNacimiento || !$fechaReferencia) {
        return null;
    }

    try {
        $nacimiento = new DateTimeImmutable($fechaNacimiento);
        $referencia = new DateTimeImmutable($fechaReferencia);
        if ($referencia < $nacimiento) {
            return null;
        }
        return (int)$nacimiento->diff($referencia)->y;
    } catch (Throwable) {
        return null;
    }
}

function com_anual_snapshot_from_row(array $row, string $tipo, ?string $fechaReferencia = null): array
{
    $fechaReferencia = $fechaReferencia ?: date('Y-m-d');

    return [
        'persona_anual_id' => isset($row['id']) ? (int)$row['id'] : null,
        'persona_anual_tipo' => com_anual_safe_tipo($tipo),
        'snapshot_run' => $row['run'] ?? null,
        'snapshot_nombres' => $row['nombres'] ?? ($row['nombre'] ?? null),
        'snapshot_apellido_paterno' => $row['apellido_paterno'] ?? null,
        'snapshot_apellido_materno' => $row['apellido_materno'] ?? null,
        'snapshot_nombre_social' => $row['nombre_social'] ?? null,
        'snapshot_nacionalidad' => $row['nacionalidad'] ?? null,
        'snapshot_pertenece_pueblo_originario' => isset($row['pertenece_pueblo_originario']) ? (int)$row['pertenece_pueblo_originario'] : 0,
        'snapshot_pueblo_originario' => $row['pueblo_originario'] ?? null,
        'snapshot_sexo' => $row['sexo'] ?? null,
        'snapshot_genero' => $row['genero'] ?? null,
        'snapshot_fecha_nacimiento' => $row['fecha_nacimiento'] ?? null,
        'snapshot_edad' => com_anual_calcular_edad($row['fecha_nacimiento'] ?? null, $fechaReferencia),
        'snapshot_curso' => $row['curso'] ?? null,
        'snapshot_anio_escolar' => isset($row['anio_escolar']) ? (int)$row['anio_escolar'] : null,
        'snapshot_fecha_referencia' => $fechaReferencia,
    ];
}
