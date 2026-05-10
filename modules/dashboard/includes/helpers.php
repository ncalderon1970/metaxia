<?php
function dash_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function dash_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!dash_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = "SELECT COUNT(*) FROM {$table}";

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
    if (!dash_table_exists($pdo, $table)) {
        return 0;
    }

    if (dash_column_exists($pdo, $table, 'colegio_id')) {
        return dash_count($pdo, $table, 'colegio_id = ?', [$colegioId]);
    }

    return dash_count($pdo, $table);
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

    return ucwords(str_replace(['_', '-'], ' ', $value));
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
    return match (strtolower($value)) {
        'rojo', 'alta', 'pendiente' => 'danger',
        'amarillo', 'media', 'seguimiento', 'investigacion' => 'warn',
        'verde', 'baja', 'cerrado', 'resuelta', 'corregido' => 'ok',
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

$error = '';
