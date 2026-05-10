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
$userId = (int)($user['id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Vincular apoderado · Metis';
$pageSubtitle = 'Relación familiar y contactos asociados al estudiante';

function va_table_exists(PDO $pdo, string $table): bool
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

function va_column_exists(PDO $pdo, string $table, string $column): bool
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

function va_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function va_first_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (va_column_exists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function va_sql_alias(PDO $pdo, string $table, string $tableAlias, array $candidates, string $as): string
{
    $column = va_first_column($pdo, $table, $candidates);

    if ($column === null) {
        return "NULL AS " . va_quote($as);
    }

    return va_quote($tableAlias) . "." . va_quote($column) . " AS " . va_quote($as);
}

function va_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $default;
}

function va_nombre(array $row): string
{
    $nombres = va_pick($row, ['nombres', 'nombre', 'nombre_completo'], '');
    $paterno = va_pick($row, ['apellido_paterno', 'paterno', 'ap_paterno', 'primer_apellido'], '');
    $materno = va_pick($row, ['apellido_materno', 'materno', 'ap_materno', 'segundo_apellido'], '');

    $nombre = trim($nombres . ' ' . $paterno . ' ' . $materno);

    return $nombre !== '' ? $nombre : 'Sin nombre';
}

function va_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function va_clean(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function va_redirect(int $alumnoId, string $status, string $msg): void
{
    $url = APP_URL . '/modules/comunidad/vincular_apoderado.php?alumno_id=' . $alumnoId;
    $url .= '&status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

function va_validar_tablas(PDO $pdo): void
{
    foreach (['alumnos', 'apoderados', 'alumno_apoderado'] as $tabla) {
        if (!va_table_exists($pdo, $tabla)) {
            throw new RuntimeException('Falta la tabla requerida: ' . $tabla);
        }
    }
}

function va_obtener_alumno(PDO $pdo, int $alumnoId, int $colegioId): array
{
    $whereColegio = va_column_exists($pdo, 'alumnos', 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $params = va_column_exists($pdo, 'alumnos', 'colegio_id')
        ? [$alumnoId, $colegioId]
        : [$alumnoId];

    $stmt = $pdo->prepare("
        SELECT *
        FROM alumnos
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmt->execute($params);

    $alumno = $stmt->fetch();

    if (!$alumno) {
        throw new RuntimeException('Alumno no encontrado o no pertenece al establecimiento.');
    }

    return $alumno;
}

function va_obtener_apoderado(PDO $pdo, int $apoderadoId, int $colegioId): array
{
    $whereColegio = va_column_exists($pdo, 'apoderados', 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $params = va_column_exists($pdo, 'apoderados', 'colegio_id')
        ? [$apoderadoId, $colegioId]
        : [$apoderadoId];

    $stmt = $pdo->prepare("
        SELECT *
        FROM apoderados
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmt->execute($params);

    $apoderado = $stmt->fetch();

    if (!$apoderado) {
        throw new RuntimeException('Apoderado no encontrado o no pertenece al establecimiento.');
    }

    return $apoderado;
}

function va_apoderado_select_relacion(PDO $pdo): string
{
    $fields = [
        va_sql_alias($pdo, 'apoderados', 'a', ['run', 'rut'], 'run'),
        va_sql_alias($pdo, 'apoderados', 'a', ['nombres', 'nombre', 'nombre_completo'], 'nombres'),
        va_sql_alias($pdo, 'apoderados', 'a', ['apellido_paterno', 'paterno', 'ap_paterno', 'primer_apellido'], 'apellido_paterno'),
        va_sql_alias($pdo, 'apoderados', 'a', ['apellido_materno', 'materno', 'ap_materno', 'segundo_apellido'], 'apellido_materno'),
        va_sql_alias($pdo, 'apoderados', 'a', ['email', 'correo', 'correo_electronico'], 'email'),
        va_sql_alias($pdo, 'apoderados', 'a', ['telefono', 'fono', 'celular'], 'telefono'),
        va_sql_alias($pdo, 'apoderados', 'a', ['direccion', 'domicilio'], 'direccion'),
    ];

    return implode(",\n            ", $fields);
}

function va_order_by_existing(PDO $pdo, string $table): string
{
    $order = [];

    foreach ([
        'apellido_paterno',
        'paterno',
        'ap_paterno',
        'apellido_materno',
        'materno',
        'ap_materno',
        'nombres',
        'nombre',
        'nombre_completo',
        'id',
    ] as $column) {
        if (va_column_exists($pdo, $table, $column)) {
            $order[] = va_quote($column) . ' ASC';
        }
    }

    return $order ? 'ORDER BY ' . implode(', ', $order) : '';
}

function va_relaciones(PDO $pdo, int $alumnoId, int $colegioId): array
{
    $apoderadoSelect = va_apoderado_select_relacion($pdo);

    $stmt = $pdo->prepare("
        SELECT
            aa.*,
            {$apoderadoSelect}
        FROM alumno_apoderado aa
        INNER JOIN apoderados a  ON a.id  = aa.apoderado_id
        INNER JOIN alumnos    al ON al.id = aa.alumno_id
        WHERE aa.alumno_id = ?
          AND al.colegio_id = ?
        ORDER BY aa.es_titular DESC, aa.activo DESC, aa.id DESC
    ");
    $stmt->execute([$alumnoId, $colegioId]);

    return $stmt->fetchAll();
}

function va_buscar_apoderados(PDO $pdo, string $q, int $colegioId): array
{
    if ($q === '') {
        return [];
    }

    $where = [];
    $params = [];

    if (va_column_exists($pdo, 'apoderados', 'colegio_id')) {
        $where[] = 'colegio_id = ?';
        $params[] = $colegioId;
    }

    if (va_column_exists($pdo, 'apoderados', 'activo')) {
        $where[] = 'activo = 1';
    }

    $searchParts = [];

    foreach ([
        'run',
        'rut',
        'nombres',
        'nombre',
        'nombre_completo',
        'apellido_paterno',
        'paterno',
        'ap_paterno',
        'apellido_materno',
        'materno',
        'ap_materno',
        'email',
        'correo',
        'correo_electronico',
        'telefono',
        'fono',
        'celular',
        'parentesco',
    ] as $col) {
        if (va_column_exists($pdo, 'apoderados', $col)) {
            $searchParts[] = va_quote($col) . ' LIKE ?';
            $params[] = '%' . $q . '%';
        }
    }

    if ($searchParts) {
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderSql = va_order_by_existing($pdo, 'apoderados');

    $stmt = $pdo->prepare("
        SELECT *
        FROM apoderados
        {$whereSql}
        {$orderSql}
        LIMIT 80
    ");
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function va_marcar_principal_unico(PDO $pdo, int $colegioId, int $alumnoId, ?int $exceptoRelacionId = null): void
{
    if ($exceptoRelacionId !== null) {
        $stmt = $pdo->prepare("
            UPDATE alumno_apoderado
            SET es_titular = 0,
                updated_at = NOW()
            WHERE colegio_id = ?
              AND alumno_id = ?
              AND id <> ?
        ");
        $stmt->execute([$colegioId, $alumnoId, $exceptoRelacionId]);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE alumno_apoderado
        SET es_titular = 0,
            updated_at = NOW()
        WHERE colegio_id = ?
          AND alumno_id = ?
    ");
    $stmt->execute([$colegioId, $alumnoId]);
}

$alumnoId = (int)($_GET['alumno_id'] ?? $_POST['alumno_id'] ?? 0);
$q = clean((string)($_GET['q'] ?? ''));
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));

$error = '';
$alumno = [];
$relaciones = [];
$resultados = [];

try {
    va_validar_tablas($pdo);

    if ($alumnoId <= 0) {
        throw new RuntimeException('Debe indicar un alumno.');
    }

    $alumno = va_obtener_alumno($pdo, $alumnoId, $colegioId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'vincular') {
            $apoderadoId = (int)($_POST['apoderado_id'] ?? 0);

            if ($apoderadoId <= 0) {
                throw new RuntimeException('Debe seleccionar un apoderado válido.');
            }

            $apoderado = va_obtener_apoderado($pdo, $apoderadoId, $colegioId);

            $parentesco = va_clean((string)($_POST['parentesco'] ?? va_pick($apoderado, ['parentesco'], '')));
            $esPrincipal = isset($_POST['es_titular']) ? 1 : 0;
            $viveConEstudiante = isset($_POST['vive_con_estudiante']) ? 1 : 0;
            $autorizadoRetirar = isset($_POST['autorizado_retirar']) ? 1 : 0;
            $contactoEmergencia = isset($_POST['puede_retirar']) ? 1 : 0;
            $observacion = va_clean((string)($_POST['observacion'] ?? ''));

            $pdo->beginTransaction();

            $stmtExiste = $pdo->prepare("
                SELECT *
                FROM alumno_apoderado
                WHERE colegio_id = ?
                  AND alumno_id = ?
                  AND apoderado_id = ?
                LIMIT 1
            ");
            $stmtExiste->execute([$colegioId, $alumnoId, $apoderadoId]);
            $existente = $stmtExiste->fetch();

            if ($esPrincipal === 1) {
                va_marcar_principal_unico($pdo, $colegioId, $alumnoId);
            }

            if ($existente) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE alumno_apoderado
                    SET parentesco = ?,
                        es_titular = ?,
                        vive_con_estudiante = ?,
                        autorizado_retirar = ?,
                        puede_retirar = ?,
                        observacion = ?,
                        activo = 1,
                        updated_at = NOW()
                    WHERE id = ?
                      AND colegio_id = ?
                    LIMIT 1
                ");
                $stmtUpdate->execute([
                    $parentesco,
                    $esPrincipal,
                    $viveConEstudiante,
                    $autorizadoRetirar,
                    $contactoEmergencia,
                    $observacion,
                    (int)$existente['id'],
                    $colegioId,
                ]);

                $relacionId = (int)$existente['id'];
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO alumno_apoderado (
                        colegio_id,
                        alumno_id,
                        apoderado_id,
                        parentesco,
                        es_titular,
                        vive_con_estudiante,
                        autorizado_retirar,
                        puede_retirar,
                        observacion,
                        activo,
                        creado_por,
                        created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW()
                    )
                ");
                $stmtInsert->execute([
                    $colegioId,
                    $alumnoId,
                    $apoderadoId,
                    $parentesco,
                    $esPrincipal,
                    $viveConEstudiante,
                    $autorizadoRetirar,
                    $contactoEmergencia,
                    $observacion,
                    $userId > 0 ? $userId : null,
                ]);

                $relacionId = (int)$pdo->lastInsertId();
            }

            registrar_bitacora(
                'comunidad',
                'vincular_apoderado_alumno',
                'alumno_apoderado',
                $relacionId,
                'Se vinculó apoderado ' . va_pick($apoderado, ['run', 'rut'], '') . ' al alumno ' . va_pick($alumno, ['run', 'rut'], '')
            );

            $pdo->commit();

            va_redirect($alumnoId, 'ok', 'Apoderado vinculado correctamente.');
        }

        if ($accion === 'actualizar_relacion') {
            $relacionId = (int)($_POST['relacion_id'] ?? 0);

            if ($relacionId <= 0) {
                throw new RuntimeException('Relación no válida.');
            }

            $parentesco = va_clean((string)($_POST['parentesco'] ?? ''));
            $esPrincipal = isset($_POST['es_titular']) ? 1 : 0;
            $viveConEstudiante = isset($_POST['vive_con_estudiante']) ? 1 : 0;
            $autorizadoRetirar = isset($_POST['autorizado_retirar']) ? 1 : 0;
            $contactoEmergencia = isset($_POST['puede_retirar']) ? 1 : 0;
            $observacion = va_clean((string)($_POST['observacion'] ?? ''));

            $pdo->beginTransaction();

            if ($esPrincipal === 1) {
                va_marcar_principal_unico($pdo, $colegioId, $alumnoId, $relacionId);
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE alumno_apoderado
                SET parentesco = ?,
                    es_titular = ?,
                    vive_con_estudiante = ?,
                    autorizado_retirar = ?,
                    puede_retirar = ?,
                    observacion = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND colegio_id = ?
                  AND alumno_id = ?
                LIMIT 1
            ");
            $stmtUpdate->execute([
                $parentesco,
                $esPrincipal,
                $viveConEstudiante,
                $autorizadoRetirar,
                $contactoEmergencia,
                $observacion,
                $relacionId,
                $colegioId,
                $alumnoId,
            ]);

            registrar_bitacora(
                'comunidad',
                'actualizar_relacion_alumno_apoderado',
                'alumno_apoderado',
                $relacionId,
                'Se actualizó relación alumno/apoderado.'
            );

            $pdo->commit();

            va_redirect($alumnoId, 'ok', 'Relación actualizada correctamente.');
        }

        if ($accion === 'toggle_relacion') {
            $relacionId = (int)($_POST['relacion_id'] ?? 0);
            $nuevoActivo = (int)($_POST['nuevo_activo'] ?? -1);

            if ($relacionId <= 0 || !in_array($nuevoActivo, [0, 1], true)) {
                throw new RuntimeException('Estado de relación no válido.');
            }

            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("
                UPDATE alumno_apoderado
                SET activo = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND colegio_id = ?
                  AND alumno_id = ?
                LIMIT 1
            ");
            $stmtUpdate->execute([$nuevoActivo, $relacionId, $colegioId, $alumnoId]);

            registrar_bitacora(
                'comunidad',
                $nuevoActivo === 1 ? 'activar_relacion_alumno_apoderado' : 'inactivar_relacion_alumno_apoderado',
                'alumno_apoderado',
                $relacionId,
                $nuevoActivo === 1
                    ? 'Se activó relación alumno/apoderado.'
                    : 'Se inactivó relación alumno/apoderado.'
            );

            $pdo->commit();

            va_redirect(
                $alumnoId,
                'ok',
                $nuevoActivo === 1
                    ? 'Relación activada correctamente.'
                    : 'Relación inactivada correctamente.'
            );
        }

        throw new RuntimeException('Acción no válida.');
    }

    $relaciones = va_relaciones($pdo, $alumnoId, $colegioId);
    $resultados = va_buscar_apoderados($pdo, $q, $colegioId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();

    try {
        if ($alumnoId > 0 && !$alumno) {
            $alumno = va_obtener_alumno($pdo, $alumnoId, $colegioId);
        }

        if ($alumnoId > 0) {
            $relaciones = va_relaciones($pdo, $alumnoId, $colegioId);
            $resultados = va_buscar_apoderados($pdo, $q, $colegioId);
        }
    } catch (Throwable $ignored) {
    }
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.va-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.va-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.va-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 900px;
    line-height: 1.55;
}

.va-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.va-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.va-btn.green {
    background: #059669;
    border-color: #10b981;
}

.va-btn:hover {
    color: #fff;
}

.va-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(370px, .95fr);
    gap: 1.2rem;
    align-items: start;
}

.va-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.va-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.va-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.va-panel-body {
    padding: 1.2rem;
}

.va-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: .85rem;
}

.va-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .25rem;
}

.va-meta {
    color: #64748b;
    font-size: .78rem;
    line-height: 1.4;
    margin-top: .25rem;
}

.va-filter {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: .8rem;
    align-items: end;
}

.va-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .8rem;
    margin-top: .85rem;
}

.va-field.full {
    grid-column: 1 / -1;
}

.va-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.va-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.va-checks {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    margin-top: .7rem;
}

.va-check {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    padding: .42rem .7rem;
    color: #334155;
    font-size: .78rem;
    font-weight: 900;
}

.va-submit,
.va-link,
.va-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 999px;
    padding: .66rem 1rem;
    font-weight: 900;
    font-size: .84rem;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
}

.va-submit.green,
.va-action.green,
.va-link.green {
    background: #059669;
    color: #fff;
    border: 1px solid #10b981;
}

.va-action.red {
    background: #dc2626;
}

.va-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.va-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .62rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .12rem;
}

.va-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.va-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.va-badge.blue {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.va-badge.red {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.va-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.va-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.va-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.va-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}

.va-empty-action {
    margin-top: 1rem;
}

@media (max-width: 1050px) {
    .va-layout,
    .va-filter,
    .va-form-grid {
        grid-template-columns: 1fr;
    }

    .va-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="va-hero">
    <h2>Relación alumno / apoderado</h2>
    <p>
        Vincula apoderados al estudiante, define apoderado principal, contacto de emergencia,
        autorización de retiro y convivencia con el estudiante.
    </p>

    <div class="va-actions">
        <a class="va-btn" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=alumnos">
            <i class="bi bi-arrow-left"></i>
            Volver a alumnos
        </a>

        <a class="va-btn" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=apoderados">
            <i class="bi bi-people"></i>
            Ver apoderados
        </a>

        <a class="va-btn green" href="<?= APP_URL ?>/modules/comunidad/crear_apoderado_alumno.php?alumno_id=<?= (int)$alumnoId ?>">
            <i class="bi bi-person-plus"></i>
            Crear y vincular apoderado
        </a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="va-msg ok"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="va-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="va-msg error"><?= e($error) ?></div>
<?php endif; ?>

<div class="va-layout">
    <section>
        <div class="va-panel">
            <div class="va-panel-head">
                <h3 class="va-panel-title">
                    <i class="bi bi-mortarboard"></i>
                    Alumno
                </h3>

                <a class="va-link green" href="<?= APP_URL ?>/modules/comunidad/crear_apoderado_alumno.php?alumno_id=<?= (int)$alumnoId ?>">
                    <i class="bi bi-person-plus"></i>
                    Crear apoderado
                </a>
            </div>

            <div class="va-panel-body">
                <?php if ($alumno): ?>
                    <article class="va-card">
                        <div class="va-card-title">
                            <?= e(va_nombre($alumno)) ?>
                        </div>

                        <div>
                            <span class="va-badge blue">RUN: <?= e(va_pick($alumno, ['run', 'rut'], '-')) ?></span>
                            <span class="va-badge"><?= e(va_pick($alumno, ['curso'], 'Sin curso')) ?></span>
                            <span class="va-badge <?= (int)($alumno['activo'] ?? 1) === 1 ? 'ok' : 'warn' ?>">
                                <?= (int)($alumno['activo'] ?? 1) === 1 ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </div>

                        <div class="va-meta">
                            Email: <?= e(va_pick($alumno, ['email', 'correo', 'correo_electronico'], '-')) ?> ·
                            Teléfono: <?= e(va_pick($alumno, ['telefono', 'fono', 'celular'], '-')) ?>
                        </div>
                    </article>
                <?php else: ?>
                    <div class="va-empty">No se pudo cargar el alumno.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="va-panel">
            <div class="va-panel-head">
                <h3 class="va-panel-title">
                    <i class="bi bi-search"></i>
                    Buscar apoderado para vincular
                </h3>
            </div>

            <div class="va-panel-body">
                <form method="get" class="va-filter">
                    <input type="hidden" name="alumno_id" value="<?= (int)$alumnoId ?>">

                    <div>
                        <label class="va-label">Buscar por RUN, nombre, correo o teléfono</label>
                        <input
                            class="va-control"
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Ej: 12345678-9, MARÍA, correo@dominio.cl"
                            required
                        >
                    </div>

                    <div>
                        <button class="va-submit" type="submit">
                            <i class="bi bi-search"></i>
                            Buscar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="va-panel">
            <div class="va-panel-head">
                <h3 class="va-panel-title">
                    Resultados de búsqueda
                </h3>

                <span class="va-badge"><?= number_format(count($resultados), 0, ',', '.') ?> resultado(s)</span>
            </div>

            <div class="va-panel-body">
                <?php if ($q === ''): ?>
                    <div class="va-empty">
                        Ingresa un criterio para buscar apoderados registrados.

                        <div class="va-empty-action">
                            <a class="va-link green" href="<?= APP_URL ?>/modules/comunidad/crear_apoderado_alumno.php?alumno_id=<?= (int)$alumnoId ?>">
                                <i class="bi bi-person-plus"></i>
                                Crear y vincular nuevo apoderado
                            </a>
                        </div>
                    </div>
                <?php elseif (!$resultados): ?>
                    <div class="va-empty">
                        No se encontraron apoderados activos con ese criterio.

                        <div class="va-empty-action">
                            <a class="va-link green" href="<?= APP_URL ?>/modules/comunidad/crear_apoderado_alumno.php?alumno_id=<?= (int)$alumnoId ?>">
                                <i class="bi bi-person-plus"></i>
                                Crear y vincular nuevo apoderado
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($resultados as $apo): ?>
                        <article class="va-card">
                            <div class="va-card-title">
                                <?= e(va_nombre($apo)) ?>
                            </div>

                            <div>
                                <span class="va-badge blue">RUN: <?= e(va_pick($apo, ['run', 'rut'], '-')) ?></span>
                                <span class="va-badge"><?= e(va_pick($apo, ['parentesco'], 'Sin parentesco')) ?></span>
                            </div>

                            <div class="va-meta">
                                Email: <?= e(va_pick($apo, ['email', 'correo', 'correo_electronico'], '-')) ?> ·
                                Teléfono: <?= e(va_pick($apo, ['telefono', 'fono', 'celular'], '-')) ?>
                            </div>

                            <form method="post" class="va-form-grid">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="_accion" value="vincular">
                                <input type="hidden" name="alumno_id" value="<?= (int)$alumnoId ?>">
                                <input type="hidden" name="apoderado_id" value="<?= (int)$apo['id'] ?>">

                                <div>
                                    <label class="va-label">Parentesco</label>
                                    <input
                                        class="va-control"
                                        type="text"
                                        name="parentesco"
                                        value="<?= e(va_pick($apo, ['parentesco'], '')) ?>"
                                        placeholder="MADRE, PADRE, TUTOR, ABUELA, OTRO"
                                    >
                                </div>

                                <div>
                                    <label class="va-label">Observación</label>
                                    <input
                                        class="va-control"
                                        type="text"
                                        name="observacion"
                                        placeholder="Ej: contacto preferente, restricción, etc."
                                    >
                                </div>

                                <div class="va-field full">
                                    <div class="va-checks">
                                        <label class="va-check">
                                            <input type="checkbox" name="es_titular" value="1">
                                            Apoderado principal
                                        </label>

                                        <label class="va-check">
                                            <input type="checkbox" name="vive_con_estudiante" value="1">
                                            Vive con estudiante
                                        </label>

                                        <label class="va-check">
                                            <input type="checkbox" name="autorizado_retirar" value="1">
                                            Autorizado a retirar
                                        </label>

                                        <label class="va-check">
                                            <input type="checkbox" name="puede_retirar" value="1">
                                            Contacto emergencia
                                        </label>
                                    </div>
                                </div>

                                <div class="va-field full">
                                    <button class="va-submit green" type="submit">
                                        <i class="bi bi-link-45deg"></i>
                                        Vincular apoderado
                                    </button>
                                </div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="va-panel">
            <div class="va-panel-head">
                <h3 class="va-panel-title">
                    <i class="bi bi-people"></i>
                    Apoderados vinculados
                </h3>

                <span class="va-badge"><?= number_format(count($relaciones), 0, ',', '.') ?></span>
            </div>

            <div class="va-panel-body">
                <?php if (!$relaciones): ?>
                    <div class="va-empty">
                        Este alumno aún no tiene apoderados vinculados.

                        <div class="va-empty-action">
                            <a class="va-link green" href="<?= APP_URL ?>/modules/comunidad/crear_apoderado_alumno.php?alumno_id=<?= (int)$alumnoId ?>">
                                <i class="bi bi-person-plus"></i>
                                Crear y vincular apoderado
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($relaciones as $rel): ?>
                        <?php
                        $relActivo = (int)($rel['activo'] ?? 1) === 1;
                        $nuevoActivo = $relActivo ? 0 : 1;
                        ?>

                        <article class="va-card">
                            <div class="va-card-title">
                                <?= e(va_nombre($rel)) ?>
                            </div>

                            <div>
                                <span class="va-badge blue">RUN: <?= e(va_pick($rel, ['run', 'rut'], '-')) ?></span>
                                <span class="va-badge"><?= e(va_pick($rel, ['parentesco'], 'Sin parentesco')) ?></span>

                                <?php if ((int)($rel['es_titular'] ?? 0) === 1): ?>
                                    <span class="va-badge ok">Principal</span>
                                <?php endif; ?>

                                <?php if ((int)($rel['puede_retirar'] ?? 0) === 1): ?>
                                    <span class="va-badge warn">Emergencia</span>
                                <?php endif; ?>

                                <?php if ((int)($rel['autorizado_retirar'] ?? 0) === 1): ?>
                                    <span class="va-badge ok">Retiro autorizado</span>
                                <?php endif; ?>

                                <?php if ((int)($rel['vive_con_estudiante'] ?? 0) === 1): ?>
                                    <span class="va-badge blue">Vive con estudiante</span>
                                <?php endif; ?>

                                <span class="va-badge <?= $relActivo ? 'ok' : 'red' ?>">
                                    <?= $relActivo ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </div>

                            <div class="va-meta">
                                Email: <?= e(va_pick($rel, ['email', 'correo', 'correo_electronico'], '-')) ?> ·
                                Teléfono: <?= e(va_pick($rel, ['telefono', 'fono', 'celular'], '-')) ?>
                            </div>

                            <?php if (!empty($rel['observacion'])): ?>
                                <div class="va-meta">
                                    Observación: <?= e((string)$rel['observacion']) ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" class="va-form-grid">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="_accion" value="actualizar_relacion">
                                <input type="hidden" name="alumno_id" value="<?= (int)$alumnoId ?>">
                                <input type="hidden" name="relacion_id" value="<?= (int)$rel['id'] ?>">

                                <div>
                                    <label class="va-label">Parentesco</label>
                                    <input class="va-control" type="text" name="parentesco" value="<?= e((string)($rel['parentesco'] ?? '')) ?>">
                                </div>

                                <div>
                                    <label class="va-label">Observación</label>
                                    <input class="va-control" type="text" name="observacion" value="<?= e((string)($rel['observacion'] ?? '')) ?>">
                                </div>

                                <div class="va-field full">
                                    <div class="va-checks">
                                        <label class="va-check">
                                            <input type="checkbox" name="es_titular" value="1" <?= (int)($rel['es_titular'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            Principal
                                        </label>

                                        <label class="va-check">
                                            <input type="checkbox" name="vive_con_estudiante" value="1" <?= (int)($rel['vive_con_estudiante'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            Vive con estudiante
                                        </label>

                                        <label class="va-check">
                                            <input type="checkbox" name="autorizado_retirar" value="1" <?= (int)($rel['autorizado_retirar'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            Retiro autorizado
                                        </label>

                                        <label class="va-check">
                                            <input type="checkbox" name="puede_retirar" value="1" <?= (int)($rel['puede_retirar'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            Emergencia
                                        </label>
                                    </div>
                                </div>

                                <div class="va-field full">
                                    <button class="va-submit green" type="submit">
                                        <i class="bi bi-save"></i>
                                        Guardar relación
                                    </button>
                                </div>
                            </form>

                            <form method="post" style="margin-top:.65rem;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="_accion" value="toggle_relacion">
                                <input type="hidden" name="alumno_id" value="<?= (int)$alumnoId ?>">
                                <input type="hidden" name="relacion_id" value="<?= (int)$rel['id'] ?>">
                                <input type="hidden" name="nuevo_activo" value="<?= (int)$nuevoActivo ?>">

                                <button
                                    class="va-action <?= $relActivo ? 'red' : 'green' ?>"
                                    type="submit"
                                    onclick="return confirm('¿Confirmas <?= $relActivo ? 'inactivar' : 'activar' ?> esta relación?');"
                                >
                                    <i class="bi <?= $relActivo ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                    <?= $relActivo ? 'Inactivar relación' : 'Activar relación' ?>
                                </button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>