<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/Run.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = in_array($rolCodigo, ['superadmin', 'director', 'admin_colegio'], true)
    || Auth::can('admin_sistema') || Auth::can('gestionar_usuarios');

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function pp_table_exists(PDO $pdo, string $table): bool
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

function pp_column_exists(PDO $pdo, string $table, string $column): bool
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

function pp_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function pp_clean(?string $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function pp_upper(?string $value): ?string
{
    $value = pp_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function pp_email(?string $value): ?string
{
    $value = pp_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtolower($value, 'UTF-8');
}

function pp_redirect(string $status, string $msg): void
{
    $url = APP_URL . '/modules/importar/pendientes.php?status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

function pp_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!pp_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = pp_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para insertar.');
    }

    $sql = "
        INSERT INTO " . pp_quote($table) . " (
            " . implode(', ', $columns) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function pp_existe_run(PDO $pdo, string $tipo, int $colegioId, string $run): bool
{
    if (!pp_column_exists($pdo, $tipo, 'colegio_id') || !pp_column_exists($pdo, $tipo, 'run')) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM " . pp_quote($tipo) . "
        WHERE colegio_id = ?
          AND run = ?
    ");
    $stmt->execute([$colegioId, $run]);

    return (int)$stmt->fetchColumn() > 0;
}

$tiposPermitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $accion = clean((string)($_POST['_accion'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        throw new RuntimeException('Pendiente no válido.');
    }

    if (!pp_table_exists($pdo, 'comunidad_importacion_pendientes')) {
        throw new RuntimeException('La tabla de pendientes no existe.');
    }

    $stmtPendiente = $pdo->prepare("
        SELECT *
        FROM comunidad_importacion_pendientes
        WHERE id = ?
          AND colegio_id = ?
        LIMIT 1
    ");
    $stmtPendiente->execute([$id, $colegioId]);
    $pendiente = $stmtPendiente->fetch();

    if (!$pendiente) {
        throw new RuntimeException('Pendiente no encontrado o no pertenece al establecimiento.');
    }

    if ((string)$pendiente['estado'] !== 'pendiente') {
        throw new RuntimeException('Este registro ya no está pendiente.');
    }

    $tipo = (string)$pendiente['tipo'];

    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new RuntimeException('Tipo de pendiente no válido.');
    }

    if ($accion === 'descartar') {
        $pdo->beginTransaction();

        $stmtDescartar = $pdo->prepare("
            UPDATE comunidad_importacion_pendientes
            SET estado = 'descartado',
                observacion = 'Descartado manualmente',
                corregido_por = ?,
                corregido_at = NOW()
            WHERE id = ?
              AND colegio_id = ?
              AND estado = 'pendiente'
            LIMIT 1
        ");
        $stmtDescartar->execute([$userId > 0 ? $userId : null, $id, $colegioId]);

        registrar_bitacora(
            'importar',
            'descartar_pendiente',
            'comunidad_importacion_pendientes',
            $id,
            'Pendiente de importación descartado.'
        );

        $pdo->commit();

        pp_redirect('ok', 'Pendiente descartado correctamente.');
    }

    if ($accion !== 'corregir') {
        throw new RuntimeException('Acción no válida.');
    }

    if (!pp_table_exists($pdo, $tipo)) {
        throw new RuntimeException('La tabla final ' . $tipo . ' no existe.');
    }

    $run = Run::formatOrFail((string)($_POST['run'] ?? ''));
    $nombres = pp_upper((string)($_POST['nombres'] ?? ''));

    if ($nombres === null) {
        throw new RuntimeException('Debe ingresar nombres.');
    }

    if (pp_existe_run($pdo, $tipo, $colegioId, $run)) {
        throw new RuntimeException('Ya existe un registro con ese RUN en la tabla final.');
    }

    $data = [
        'colegio_id' => $colegioId,
        'run' => $run,
        'nombres' => $nombres,
        'apellido_paterno' => pp_upper((string)($_POST['apellido_paterno'] ?? '')),
        'apellido_materno' => pp_upper((string)($_POST['apellido_materno'] ?? '')),
        'email' => pp_email((string)($_POST['email'] ?? '')),
        'telefono' => pp_upper((string)($_POST['telefono'] ?? '')),
        'direccion' => pp_upper((string)($_POST['direccion'] ?? '')),
        'activo' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($tipo === 'alumnos') {
        $data['curso'] = pp_upper((string)($_POST['curso'] ?? ''));
        $data['fecha_nacimiento'] = pp_clean((string)($_POST['fecha_nacimiento'] ?? ''));
    }

    if ($tipo === 'apoderados') {
        $data['parentesco'] = pp_upper((string)($_POST['parentesco'] ?? ''));
    }

    if ($tipo === 'docentes') {
        $data['especialidad'] = pp_upper((string)($_POST['especialidad'] ?? ''));
    }

    if ($tipo === 'asistentes') {
        $data['cargo'] = pp_upper((string)($_POST['cargo'] ?? ''));
    }

    $pdo->beginTransaction();

    $nuevoId = pp_insert_dynamic($pdo, $tipo, $data);

    $stmtUpdate = $pdo->prepare("
        UPDATE comunidad_importacion_pendientes
        SET estado = 'corregido',
            corregido_run = ?,
            corregido_por = ?,
            corregido_at = NOW(),
            observacion = ?
        WHERE id = ?
          AND colegio_id = ?
          AND estado = 'pendiente'
        LIMIT 1
    ");
    $stmtUpdate->execute([
        $run,
        $userId > 0 ? $userId : null,
        'Corregido y cargado en tabla final ' . $tipo . ' ID ' . $nuevoId,
        $id,
        $colegioId,
    ]);

    registrar_bitacora(
        'importar',
        'corregir_pendiente',
        $tipo,
        $nuevoId > 0 ? $nuevoId : null,
        'Pendiente corregido y cargado en comunidad educativa: ' . $run
    );

    $pdo->commit();

    pp_redirect('ok', 'Pendiente corregido y cargado correctamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    pp_redirect('error', $e->getMessage());
}