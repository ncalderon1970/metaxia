<?php
declare(strict_types=1);

function metis_obtener_interviniente_historico(array $row): array
{
    return [
        'run' => $row['snapshot_run'] ?? $row['run'] ?? '',
        'nombre' => trim(($row['snapshot_nombre_social'] ?? '') !== '' ? $row['snapshot_nombre_social'] : trim(($row['snapshot_nombres'] ?? '') . ' ' . ($row['snapshot_apellido_paterno'] ?? '') . ' ' . ($row['snapshot_apellido_materno'] ?? ''))),
        'curso' => $row['snapshot_curso'] ?? '',
        'edad' => $row['snapshot_edad'] ?? null,
        'anio_escolar' => $row['snapshot_anio_escolar'] ?? null,
    ];
}
