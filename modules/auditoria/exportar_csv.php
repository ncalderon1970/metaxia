<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeVer = in_array($rolCodigo, ['superadmin', 'director'], true)
    || Auth::can('admin_sistema');

if (!$puedeVer) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$q = clean((string)($_GET['q'] ?? ''));
$moduloFiltro = clean((string)($_GET['modulo'] ?? ''));
$usuarioFiltro = (int)($_GET['usuario_id'] ?? 0);
$desde = clean((string)($_GET['desde'] ?? ''));
$hasta = clean((string)($_GET['hasta'] ?? ''));

function aud_csv_fecha(?string $value): string
{
    if (!$value) {
        return '';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i:s', $ts) : $value;
}

function aud_csv_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function aud_csv_output(string $filename, array $headers, array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'wb');

    fputcsv($out, $headers, ';');

    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }

    fclose($out);
    exit;
}

$where = [];
$params = [];

$where[] = '(l.colegio_id = ? OR l.colegio_id IS NULL)';
$params[] = $colegioId;

if ($q !== '') {
    $where[] = '(l.modulo LIKE ? OR l.accion LIKE ? OR l.entidad LIKE ? OR l.descripcion LIKE ? OR u.nombre LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($moduloFiltro !== '') {
    $where[] = 'l.modulo = ?';
    $params[] = $moduloFiltro;
}

if ($usuarioFiltro > 0) {
    $where[] = 'l.usuario_id = ?';
    $params[] = $usuarioFiltro;
}

if ($desde !== '') {
    $where[] = 'l.created_at >= ?';
    $params[] = $desde . ' 00:00:00';
}

if ($hasta !== '') {
    $where[] = 'l.created_at <= ?';
    $params[] = $hasta . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.colegio_id,
            l.usuario_id,
            l.modulo,
            l.accion,
            l.entidad,
            l.entidad_id,
            l.descripcion,
            l.ip,
            l.user_agent,
            l.created_at,
            u.nombre AS usuario_nombre,
            u.email AS usuario_email
        FROM logs_sistema l
        LEFT JOIN usuarios u ON u.id = l.usuario_id
        {$whereSql}
        ORDER BY l.id DESC
        LIMIT 20000
    ");

    $stmt->execute($params);

    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            $row['id'],
            aud_csv_fecha((string)$row['created_at']),
            aud_csv_label((string)$row['modulo']),
            aud_csv_label((string)$row['accion']),
            (string)($row['usuario_nombre'] ?? 'Sistema'),
            (string)($row['usuario_email'] ?? ''),
            (string)($row['entidad'] ?? ''),
            (string)($row['entidad_id'] ?? ''),
            (string)($row['descripcion'] ?? ''),
            (string)($row['ip'] ?? ''),
            (string)($row['user_agent'] ?? ''),
        ];
    }

    registrar_bitacora(
        'auditoria',
        'exportar_csv',
        'logs_sistema',
        null,
        'Exportación CSV de auditoría.'
    );

    aud_csv_output(
        'metis_auditoria_' . date('Ymd_His') . '.csv',
        [
            'ID',
            'Fecha',
            'Módulo',
            'Acción',
            'Usuario',
            'Email usuario',
            'Entidad',
            'ID entidad',
            'Descripción',
            'IP',
            'User agent',
        ],
        $rows
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error al exportar auditoría: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}