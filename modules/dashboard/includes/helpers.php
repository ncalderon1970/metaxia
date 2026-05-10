<?php
declare(strict_types=1);

/**
 * Helpers del Dashboard Metis.
 *
 * Regla técnica actual:
 * - No consultar catálogos dinámicos del esquema.
 * - Validar por consultas reales y fallback seguro.
 * - Validar salud mediante consultas reales y try-catch.
 */

$error = '';

function dash_ident(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Identificador SQL inválido.');
    }

    return '`' . $name . '`';
}

function dash_table_exists(PDO $pdo, string $table): bool
{
    try {
        $sql = 'SELECT 1 FROM ' . dash_ident($table) . ' LIMIT 1';
        $pdo->query($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $sql = 'SELECT ' . dash_ident($column) . ' FROM ' . dash_ident($table) . ' LIMIT 0';
        $pdo->query($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function dash_scalar(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dash_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    try {
        $sql = 'SELECT COUNT(*) FROM ' . dash_ident($table);

        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dash_count_colegio(PDO $pdo, string $table, int $colegioId): int
{
    return dash_count($pdo, $table, 'colegio_id = ?', [$colegioId]);
}

function dash_estado_count(PDO $pdo, int $colegioId, int $estadoId, ?string $fallbackLegacy = null): int
{
    $sql = "
        SELECT COUNT(*)
        FROM casos c
        WHERE c.colegio_id = ?
          AND (
                c.estado_caso_id = ?
                " . ($fallbackLegacy !== null ? " OR (c.estado_caso_id IS NULL AND c.estado = ?)" : '') . "
          )
    ";

    $params = [$colegioId, $estadoId];
    if ($fallbackLegacy !== null) {
        $params[] = $fallbackLegacy;
    }

    return dash_scalar($pdo, $sql, $params);
}

function dash_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function dash_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    $map = [
        'recepcion'       => 'Recepción',
        'investigacion'   => 'En investigación',
        'resolucion'      => 'En resolución',
        'seguimiento'     => 'En seguimiento',
        'cerrado'         => 'Cerrado',
        'abierto'         => 'Abierto',
        'alta'            => 'Alta',
        'media'           => 'Media',
        'baja'            => 'Baja',
        'critica'         => 'Crítica',
        'critico'         => 'Crítico',
        'pendiente'       => 'Pendiente',
        'resuelta'        => 'Resuelta',
        'en_proceso'      => 'En proceso',
        'en_revision'     => 'En revisión',
        'cumplido'        => 'Cumplido',
        'no_cumplido'     => 'No cumplido',
        'parcial'         => 'Parcial',
        'no_aplica'       => 'No aplica',
        'posible'         => 'Posible',
        'iniciado'        => 'Iniciado',
        'resuelto'        => 'Resuelto',
        'derivado'        => 'Derivado',
        'desestimado'     => 'Desestimado',
        'acuerdo'         => 'Acuerdo',
        'otro'            => 'Otro',
        'victima'         => 'Víctima',
        'denunciante'     => 'Denunciante',
        'denunciado'      => 'Denunciado/a',
        'testigo'         => 'Testigo',
        'involucrado'     => 'Otro interviniente',
    ];

    $key = mb_strtolower($value, 'UTF-8');
    return $map[$key] ?? ucwords(str_replace(['_', '-'], ' ', $value));
}

function dash_corto(?string $value, int $length = 120): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return mb_strlen($value) > $length
        ? mb_substr($value, 0, $length) . '...'
        : $value;
}

function dash_badge(string $value): string
{
    return match (mb_strtolower(trim($value), 'UTF-8')) {
        'alta', 'critica', 'critico', 'pendiente', 'vencido', 'riesgo_alto' => 'danger',
        'media', 'seguimiento', 'investigacion', 'en investigación', 'en_proceso', 'en_revision', 'posible' => 'warn',
        'baja', 'cerrado', 'resuelta', 'corregido', 'cumplido', 'ok', 'no_aplica' => 'ok',
        default => 'soft',
    };
}

function dash_storage_ok(): bool
{
    $path = dirname(__DIR__, 3) . '/storage/evidencias';

    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }

    return is_dir($path) && is_writable($path);
}
