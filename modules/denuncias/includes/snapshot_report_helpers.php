<?php
declare(strict_types=1);

/**
 * Helpers de reporte histórico para intervinientes.
 * Prioriza campos snapshot_* guardados al momento del hecho y usa datos legacy solo como fallback.
 */

if (!function_exists('metis_snapshot_nombre')) {
    function metis_snapshot_nombre(array $p): string
    {
        $social = trim((string)($p['snapshot_nombre_social'] ?? ''));
        if ($social !== '') {
            return $social;
        }

        $partes = [];
        foreach (['snapshot_nombres', 'snapshot_apellido_paterno', 'snapshot_apellido_materno'] as $k) {
            $v = trim((string)($p[$k] ?? ''));
            if ($v !== '') { $partes[] = $v; }
        }

        if ($partes) {
            return trim(implode(' ', $partes));
        }

        return trim((string)($p['nombre_referencial'] ?? $p['nombre'] ?? 'N/N')) ?: 'N/N';
    }
}

if (!function_exists('metis_snapshot_run')) {
    function metis_snapshot_run(array $p): string
    {
        return trim((string)($p['snapshot_run'] ?? $p['run'] ?? '0-0')) ?: '0-0';
    }
}

if (!function_exists('metis_snapshot_curso')) {
    function metis_snapshot_curso(array $p): string
    {
        return trim((string)($p['snapshot_curso'] ?? $p['curso'] ?? '')) ?: '—';
    }
}

if (!function_exists('metis_snapshot_edad')) {
    function metis_snapshot_edad(array $p): string
    {
        $edad = $p['snapshot_edad'] ?? null;
        if ($edad !== null && $edad !== '') {
            return (string)((int)$edad) . ' años';
        }
        return '—';
    }
}

if (!function_exists('metis_snapshot_anio')) {
    function metis_snapshot_anio(array $p): string
    {
        return trim((string)($p['snapshot_anio_escolar'] ?? '')) ?: '—';
    }
}

if (!function_exists('metis_snapshot_sexo_genero')) {
    function metis_snapshot_sexo_genero(array $p): string
    {
        $sexo = trim((string)($p['snapshot_sexo'] ?? ''));
        $genero = trim((string)($p['snapshot_genero'] ?? ''));
        if ($sexo !== '' && $genero !== '') { return $sexo . ' / ' . $genero; }
        return $sexo !== '' ? $sexo : ($genero !== '' ? $genero : '—');
    }
}
