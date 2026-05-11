<?php
declare(strict_types=1);

if (!function_exists('metis_e')) {
    function metis_e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('metis_anio_escolar_actual')) {
    function metis_anio_escolar_actual(): int
    {
        $year = (int) date('Y');
        return $year > 2000 ? $year : 2026;
    }
}

if (!function_exists('metis_anio_escolar_request')) {
    function metis_anio_escolar_request(): int
    {
        $anio = (int) ($_GET['anio_escolar'] ?? $_POST['anio_escolar'] ?? metis_anio_escolar_actual());
        if ($anio < 2020 || $anio > 2100) {
            return metis_anio_escolar_actual();
        }
        return $anio;
    }
}

if (!function_exists('metis_normalizar_run_busqueda')) {
    function metis_normalizar_run_busqueda(string $run): string
    {
        return strtoupper(preg_replace('/[^0-9Kk]/', '', $run));
    }
}

if (!function_exists('metis_nombre_preferente')) {
    function metis_nombre_preferente(array $row): string
    {
        $nombreSocial = trim((string)($row['nombre_social'] ?? ''));
        $base = trim(implode(' ', array_filter([
            (string)($row['nombres'] ?? ''),
            (string)($row['apellido_paterno'] ?? ''),
            (string)($row['apellido_materno'] ?? ''),
        ])));
        return $nombreSocial !== '' ? $nombreSocial . ' (' . $base . ')' : $base;
    }
}

if (!function_exists('metis_tabla_anual_por_tipo')) {
    function metis_tabla_anual_por_tipo(string $tipo): string
    {
        $map = [
            'alumnos' => 'alumnos_anual',
            'apoderados' => 'apoderados_anual',
            'docentes' => 'docentes_anual',
            'asistentes' => 'asistentes_anual',
        ];
        return $map[$tipo] ?? 'alumnos_anual';
    }
}
