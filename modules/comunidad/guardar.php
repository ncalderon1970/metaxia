<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/Run.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function cg_table_exists(PDO $pdo, string $table): bool
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

function cg_column_exists(PDO $pdo, string $table, string $column): bool
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

function cg_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function cg_clean(?string $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function cg_upper(?string $value): ?string
{
    $value = cg_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function cg_email(?string $value): ?string
{
    $value = cg_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtolower($value, 'UTF-8');
}

function cg_safe_tipo(string $tipo): string
{
    $permitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];

    return in_array($tipo, $permitidos, true) ? $tipo : 'alumnos';
}

function cg_flash_back(string $tipo, string $message): void
{
    $tipo = cg_safe_tipo($tipo);

    $old = $_POST;
    unset($old['_token']);

    $_SESSION['comunidad_form_old'] = $old;
    $_SESSION['comunidad_form_error'] = $message;

    header('Location: ' . APP_URL . '/modules/comunidad/crear.php?tipo=' . urlencode($tipo));
    exit;
}

function cg_redirect_ok(string $tipo, string $msg): void
{
    $tipo = cg_safe_tipo($tipo);

    unset($_SESSION['comunidad_form_old'], $_SESSION['comunidad_form_error']);

    $url = APP_URL . '/modules/comunidad/index.php?tipo=' . urlencode($tipo);
    $url .= '&status=ok&msg=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

function cg_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!cg_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = cg_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para guardar.');
    }

    $sql = "
        INSERT INTO " . cg_quote($table) . " (
            " . implode(', ', $columns) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

$tipo = cg_safe_tipo(clean((string)($_POST['tipo'] ?? 'alumnos')));

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $accion = clean((string)($_POST['_accion'] ?? ''));

    if ($accion !== 'crear') {
        throw new RuntimeException('Acción no válida.');
    }

    if (!cg_table_exists($pdo, $tipo)) {
        throw new RuntimeException('La tabla ' . $tipo . ' no existe.');
    }

    $run = Run::formatOrFail((string)($_POST['run'] ?? ''));
    $nombres = cg_upper((string)($_POST['nombres'] ?? ''));

    if ($nombres === null) {
        throw new RuntimeException('Debe ingresar nombres.');
    }

    if (cg_column_exists($pdo, $tipo, 'colegio_id') && cg_column_exists($pdo, $tipo, 'run')) {
        $stmtExiste = $pdo->prepare("
            SELECT COUNT(*)
            FROM " . cg_quote($tipo) . "
            WHERE colegio_id = ?
              AND run = ?
        ");
        $stmtExiste->execute([$colegioId, $run]);

        if ((int)$stmtExiste->fetchColumn() > 0) {
            throw new RuntimeException('Ya existe un registro con ese RUN en este establecimiento. Corrige el RUN o verifica si corresponde editar el registro existente.');
        }
    }

    $data = [
        'colegio_id' => $colegioId,
        'run' => $run,
        'nombres' => $nombres,
        'apellido_paterno' => cg_upper((string)($_POST['apellido_paterno'] ?? '')),
        'apellido_materno' => cg_upper((string)($_POST['apellido_materno'] ?? '')),
        'email' => cg_email((string)($_POST['email'] ?? '')),
        'telefono' => cg_upper((string)($_POST['telefono'] ?? '')),
        'direccion' => cg_upper((string)($_POST['direccion'] ?? '')),
        'activo' => (int)($_POST['activo'] ?? 1) === 1 ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($tipo === 'alumnos') {
        $data['curso'] = cg_upper((string)($_POST['curso'] ?? ''));
        $data['fecha_nacimiento'] = cg_clean((string)($_POST['fecha_nacimiento'] ?? ''));
    }

    if ($tipo === 'apoderados') {
        $data['parentesco'] = cg_upper((string)($_POST['parentesco'] ?? ''));
    }

    if ($tipo === 'docentes') {
        $data['especialidad'] = cg_upper((string)($_POST['especialidad'] ?? ''));
    }

    if ($tipo === 'asistentes') {
        $data['cargo'] = cg_upper((string)($_POST['cargo'] ?? ''));
    }

    $pdo->beginTransaction();

    $nuevoId = cg_insert_dynamic($pdo, $tipo, $data);

    registrar_bitacora(
        'comunidad',
        'crear_' . $tipo,
        $tipo,
        $nuevoId > 0 ? $nuevoId : null,
        'Registro manual creado en comunidad educativa: ' . $run
    );

    $pdo->commit();

    cg_redirect_ok($tipo, 'Registro creado correctamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    cg_flash_back($tipo, $e->getMessage());
}