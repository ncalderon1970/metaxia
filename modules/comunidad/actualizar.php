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
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function ca_table_exists(PDO $pdo, string $table): bool
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

function ca_column_exists(PDO $pdo, string $table, string $column): bool
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

function ca_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ca_clean(?string $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function ca_upper(?string $value): ?string
{
    $value = ca_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function ca_email(?string $value): ?string
{
    $value = ca_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtolower($value, 'UTF-8');
}

function ca_redirect(string $tipo, string $status, string $msg): void
{
    $url = APP_URL . '/modules/comunidad/index.php?tipo=' . urlencode($tipo);
    $url .= '&status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

function ca_error(string $tipo, int $id, string $msg): void
{
    $url = APP_URL . '/modules/comunidad/editar.php?tipo=' . urlencode($tipo) . '&id=' . $id;
    $url .= '&error=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

function ca_update_dynamic(PDO $pdo, string $table, int $id, int $colegioId, array $data): void
{
    $sets = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!ca_column_exists($pdo, $table, $column)) {
            continue;
        }

        if ($column === 'id') {
            continue;
        }

        $sets[] = ca_quote($column) . ' = ?';
        $params[] = $value;
    }

    if (!$sets) {
        throw new RuntimeException('No hay columnas compatibles para actualizar.');
    }

    $whereColegio = ca_column_exists($pdo, $table, 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $params[] = $id;

    if ($whereColegio !== '') {
        $params[] = $colegioId;
    }

    $sql = "
        UPDATE " . ca_quote($table) . "
        SET " . implode(', ', $sets) . "
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

$tiposPermitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $accion = clean((string)($_POST['_accion'] ?? ''));
    $tipo = clean((string)($_POST['tipo'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($accion !== 'actualizar') {
        throw new RuntimeException('Acción no válida.');
    }

    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new RuntimeException('Tipo de comunidad no válido.');
    }

    if ($id <= 0) {
        throw new RuntimeException('Registro no válido.');
    }

    if (!ca_table_exists($pdo, $tipo)) {
        throw new RuntimeException('La tabla ' . $tipo . ' no existe.');
    }

    $run = Run::formatOrFail((string)($_POST['run'] ?? ''));
    $nombres = ca_upper((string)($_POST['nombres'] ?? ''));

    if ($nombres === null) {
        throw new RuntimeException('Debe ingresar nombres.');
    }

    $whereColegio = ca_column_exists($pdo, $tipo, 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $paramsActual = ca_column_exists($pdo, $tipo, 'colegio_id')
        ? [$id, $colegioId]
        : [$id];

    $stmtActual = $pdo->prepare("
        SELECT *
        FROM " . ca_quote($tipo) . "
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmtActual->execute($paramsActual);

    $registroActual = $stmtActual->fetch();

    if (!$registroActual) {
        throw new RuntimeException('Registro no encontrado o no pertenece al establecimiento.');
    }

    if (ca_column_exists($pdo, $tipo, 'colegio_id') && ca_column_exists($pdo, $tipo, 'run')) {
        $stmtExiste = $pdo->prepare("
            SELECT COUNT(*)
            FROM " . ca_quote($tipo) . "
            WHERE colegio_id = ?
              AND run = ?
              AND id <> ?
        ");
        $stmtExiste->execute([$colegioId, $run, $id]);

        if ((int)$stmtExiste->fetchColumn() > 0) {
            throw new RuntimeException('Ya existe otro registro con ese RUN en este establecimiento.');
        }
    }

    $data = [
        'run' => $run,
        'nombres' => $nombres,
        'apellido_paterno' => ca_upper((string)($_POST['apellido_paterno'] ?? '')),
        'apellido_materno' => ca_upper((string)($_POST['apellido_materno'] ?? '')),
        'email' => ca_email((string)($_POST['email'] ?? '')),
        'telefono' => ca_upper((string)($_POST['telefono'] ?? '')),
        'direccion' => ca_upper((string)($_POST['direccion'] ?? '')),
        'activo' => (int)($_POST['activo'] ?? 1) === 1 ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($tipo === 'alumnos') {
        $data['curso'] = ca_upper((string)($_POST['curso'] ?? ''));
        $data['fecha_nacimiento'] = ca_clean((string)($_POST['fecha_nacimiento'] ?? ''));

        // Campos Inclusión / NEE (Fase 1 SQL ya los agregó a la tabla)
        $condEsp = ca_clean((string)($_POST['condicion_especial'] ?? ''));
        if ($condEsp !== '') $data['condicion_especial'] = $condEsp;

        $dxTea = ca_clean((string)($_POST['diagnostico_tea'] ?? ''));
        if ($dxTea !== '') $data['diagnostico_tea'] = $dxTea;

        $data['tiene_pie']                     = isset($_POST['tiene_pie'])                     ? 1 : 0;
        $data['requiere_ajustes_razonables']   = isset($_POST['requiere_ajustes_razonables'])   ? 1 : 0;
        $data['tiene_certificado_discapacidad']= isset($_POST['tiene_certificado_discapacidad']) ? 1 : 0;
    }

    if ($tipo === 'apoderados') {
        $data['parentesco'] = ca_upper((string)($_POST['parentesco'] ?? ''));
    }

    if ($tipo === 'docentes') {
        $data['especialidad'] = ca_upper((string)($_POST['especialidad'] ?? ''));
    }

    if ($tipo === 'asistentes') {
        $data['cargo'] = ca_upper((string)($_POST['cargo'] ?? ''));
    }

    $pdo->beginTransaction();

    ca_update_dynamic($pdo, $tipo, $id, $colegioId, $data);

    // ── Si es alumno con condición especial, sincronizar alumno_condicion_especial ──
    if ($tipo === 'alumnos' && !empty($data['condicion_especial'])) {
        $userId = (int)(Auth::user()['id'] ?? 0);
        try {
            // Verificar si ya existe registro activo
            $stmtCheck = $pdo->prepare("
                SELECT id FROM alumno_condicion_especial
                WHERE alumno_id = ? AND colegio_id = ? AND activo = 1
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmtCheck->execute([$id, $colegioId]);
            $existeId = $stmtCheck->fetchColumn();

            if ($existeId) {
                // Actualizar registro existente
                $pdo->prepare("
                    UPDATE alumno_condicion_especial
                    SET tipo_condicion    = ?,
                        estado_diagnostico = ?,
                        tiene_pie          = ?,
                        requiere_ajustes   = ?,
                        tiene_certificado  = ?,
                        updated_at         = NOW()
                    WHERE id = ? AND colegio_id = ?
                ")->execute([
                    $data['condicion_especial'],
                    $data['diagnostico_tea'] ?? 'sospecha',
                    $data['tiene_pie'] ?? 0,
                    $data['requiere_ajustes_razonables'] ?? 0,
                    $data['tiene_certificado_discapacidad'] ?? 0,
                    $existeId, $colegioId,
                ]);
            } else {
                // Crear nuevo registro
                $pdo->prepare("
                    INSERT INTO alumno_condicion_especial
                        (colegio_id, alumno_id, tipo_condicion, estado_diagnostico,
                         tiene_pie, requiere_ajustes, tiene_certificado,
                         registrado_por, activo, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,1,NOW(),NOW())
                ")->execute([
                    $colegioId, $id,
                    $data['condicion_especial'],
                    $data['diagnostico_tea'] ?? 'sospecha',
                    $data['tiene_pie'] ?? 0,
                    $data['requiere_ajustes_razonables'] ?? 0,
                    $data['tiene_certificado_discapacidad'] ?? 0,
                    $userId ?: null,
                ]);
            }
        } catch (Throwable $e) {
            // No bloquear si la tabla no existe aún
            error_log('alumno_condicion_especial sync error: ' . $e->getMessage());
        }
    }

    registrar_bitacora(
        'comunidad',
        'actualizar_' . $tipo,
        $tipo,
        $id,
        'Registro manual actualizado en comunidad educativa: ' . $run
    );

    $pdo->commit();

    ca_redirect($tipo, 'ok', 'Registro actualizado correctamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $tipoFallback = in_array((string)($_POST['tipo'] ?? ''), $tiposPermitidos, true)
        ? (string)$_POST['tipo']
        : 'alumnos';

    $idFallback = (int)($_POST['id'] ?? 0);

    ca_error($tipoFallback, $idFallback, $e->getMessage());
}