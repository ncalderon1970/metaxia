<?php
declare(strict_types=1);

function importar_anual_anio_actual(): int
{
    $year = (int)date('Y');
    return $year > 0 ? $year : 2026;
}

function importar_anual_normalizar_run(string $run): string
{
    $run = strtoupper(trim($run));
    $run = str_replace(['.', ' '], '', $run);
    return $run;
}

function importar_anual_run_key(string $run): string
{
    return preg_replace('/[^0-9K]/', '', strtoupper($run)) ?? '';
}

function importar_anual_upper(?string $value): string
{
    return mb_strtoupper(trim((string)$value), 'UTF-8');
}

function importar_anual_lower(?string $value): string
{
    return mb_strtolower(trim((string)$value), 'UTF-8');
}

function importar_anual_nombre_social(?string $value): string
{
    return trim((string)$value);
}

function importar_anual_validar_anio($anio): int
{
    $anio = (int)$anio;
    if ($anio < 2020 || $anio > 2100) {
        return importar_anual_anio_actual();
    }
    return $anio;
}

function importar_anual_buscar_por_run(PDO $pdo, string $tabla, int $colegioId, int $anioEscolar, string $run): ?array
{
    $permitidas = ['alumnos_anual', 'apoderados_anual', 'docentes_anual', 'asistentes_anual'];
    if (!in_array($tabla, $permitidas, true)) {
        throw new InvalidArgumentException('Tabla anual no permitida.');
    }

    $stmt = $pdo->prepare("\n        SELECT *\n        FROM {$tabla}\n        WHERE colegio_id = ?\n          AND anio_escolar = ?\n          AND REPLACE(REPLACE(UPPER(run), '.', ''), '-', '') = ?\n        LIMIT 1\n    ");
    $stmt->execute([$colegioId, $anioEscolar, importar_anual_run_key($run)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
