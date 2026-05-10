<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function ct_table_exists(PDO $pdo, string $table): bool
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

function ct_column_exists(PDO $pdo, string $table, string $column): bool
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

function ct_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ct_redirect(string $tipo, string $status, string $msg): void
{
    $url = APP_URL . '/modules/comunidad/index.php?tipo=' . urlencode($tipo);
    $url .= '&status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

$permitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $tipo = clean((string)($_POST['tipo'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    $nuevoActivo = (int)($_POST['nuevo_activo'] ?? -1);

    if (!in_array($tipo, $permitidos, true)) {
        throw new RuntimeException('Tipo de comunidad no válido.');
    }

    if ($id <= 0) {
        throw new RuntimeException('Registro no válido.');
    }

    if (!in_array($nuevoActivo, [0, 1], true)) {
        throw new RuntimeException('Estado no válido.');
    }

    if (!ct_table_exists($pdo, $tipo)) {
        throw new RuntimeException('La tabla ' . $tipo . ' no existe.');
    }

    if (!ct_column_exists($pdo, $tipo, 'activo')) {
        throw new RuntimeException('La tabla ' . $tipo . ' no tiene columna activo.');
    }

    $whereColegio = ct_column_exists($pdo, $tipo, 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $paramsBuscar = ct_column_exists($pdo, $tipo, 'colegio_id')
        ? [$id, $colegioId]
        : [$id];

    $stmtBuscar = $pdo->prepare("
        SELECT *
        FROM " . ct_quote($tipo) . "
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmtBuscar->execute($paramsBuscar);
    $registro = $stmtBuscar->fetch();

    if (!$registro) {
        throw new RuntimeException('Registro no encontrado o no pertenece al establecimiento.');
    }

    $pdo->beginTransaction();

    $paramsUpdate = [$nuevoActivo, date('Y-m-d H:i:s'), $id];

    if ($whereColegio !== '') {
        $paramsUpdate[] = $colegioId;
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE " . ct_quote($tipo) . "
        SET activo = ?,
            updated_at = ?
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmtUpdate->execute($paramsUpdate);

    $run = (string)($registro['run'] ?? '');
    $nombres = trim(
        (string)($registro['nombres'] ?? '') . ' ' .
        (string)($registro['apellido_paterno'] ?? '') . ' ' .
        (string)($registro['apellido_materno'] ?? '')
    );

    registrar_bitacora(
        'comunidad',
        $nuevoActivo === 1 ? 'activar_' . $tipo : 'inactivar_' . $tipo,
        $tipo,
        $id,
        ($nuevoActivo === 1 ? 'Registro activado: ' : 'Registro inactivado: ') .
        trim($run . ' ' . $nombres)
    );

    $pdo->commit();

    ct_redirect(
        $tipo,
        'ok',
        $nuevoActivo === 1
            ? 'Registro activado correctamente.'
            : 'Registro inactivado correctamente.'
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $tipoFallback = in_array((string)($_POST['tipo'] ?? ''), $permitidos, true)
        ? (string)$_POST['tipo']
        : 'alumnos';

    ct_redirect($tipoFallback, 'error', $e->getMessage());
}