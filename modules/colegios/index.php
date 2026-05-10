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

$rolCodigo = (string)($user['rol_codigo'] ?? '');
$userId = (int)($user['id'] ?? 0);

$puedeAdministrar = in_array($rolCodigo, ['superadmin'], true) || Auth::can('admin_sistema');

if (!$puedeAdministrar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Colegios · Metis';
$pageSubtitle = 'Administración de establecimientos, planes, vencimientos y límites operativos';

function col_table_exists(PDO $pdo, string $table): bool
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

function col_column_exists(PDO $pdo, string $table, string $column): bool
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

function col_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function col_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function col_upper(?string $value): ?string
{
    $value = col_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function col_email(?string $value): ?string
{
    $value = col_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtolower($value, 'UTF-8');
}

function col_date(?string $value): ?string
{
    $value = col_clean($value);

    if ($value === null) {
        return null;
    }

    $ts = strtotime($value);

    return $ts ? date('Y-m-d', $ts) : null;
}

function col_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y', $ts) : $value;
}

function col_estado_vencimiento(?string $fecha): array
{
    if (!$fecha) {
        return ['Sin vencimiento', 'soft'];
    }

    $hoy = new DateTimeImmutable('today');
    $vencimiento = DateTimeImmutable::createFromFormat('Y-m-d', substr($fecha, 0, 10));

    if (!$vencimiento) {
        return ['Fecha inválida', 'warn'];
    }

    $dias = (int)$hoy->diff($vencimiento)->format('%r%a');

    if ($dias < 0) {
        return ['Vencido hace ' . abs($dias) . ' día(s)', 'danger'];
    }

    if ($dias <= 30) {
        return ['Vence en ' . $dias . ' día(s)', 'warn'];
    }

    return ['Vigente', 'ok'];
}

function col_pick(array $row, string $key, string $default = '-'): string
{
    return isset($row[$key]) && trim((string)$row[$key]) !== ''
        ? (string)$row[$key]
        : $default;
}

function col_redirect(string $status, string $msg, ?int $editId = null): void
{
    $url = APP_URL . '/modules/colegios/index.php?status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    if ($editId !== null) {
        $url .= '&edit=' . $editId;
    }

    header('Location: ' . $url);
    exit;
}

function col_count_by_colegio(PDO $pdo, string $table, int $colegioId): int
{
    if (!col_table_exists($pdo, $table) || !col_column_exists($pdo, $table, 'colegio_id')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . col_quote($table) . " WHERE colegio_id = ?");
        $stmt->execute([$colegioId]);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function col_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!col_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = col_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para crear el colegio.');
    }

    $sql = "
        INSERT INTO " . col_quote($table) . "
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function col_update_dynamic(PDO $pdo, string $table, int $id, array $data): void
{
    $sets = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!col_column_exists($pdo, $table, $column)) {
            continue;
        }

        $sets[] = col_quote($column) . ' = ?';
        $params[] = $value;
    }

    if (!$sets) {
        throw new RuntimeException('No hay columnas compatibles para actualizar el colegio.');
    }

    $params[] = $id;

    $sql = "
        UPDATE " . col_quote($table) . "
        SET " . implode(', ', $sets) . "
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function col_payload_desde_post(int $userId): array
{
    $planCodigo = col_clean((string)($_POST['plan_codigo'] ?? 'base')) ?? 'base';

    $planes = [
        'demo' => 'Demo',
        'base' => 'Plan Base',
        'profesional' => 'Plan Profesional',
        'enterprise' => 'Plan Enterprise',
    ];

    $estadoComercial = col_clean((string)($_POST['estado_comercial'] ?? 'activo')) ?? 'activo';

    $permitidosEstado = ['activo', 'demo', 'suspendido', 'vencido', 'cerrado'];

    if (!in_array($estadoComercial, $permitidosEstado, true)) {
        $estadoComercial = 'activo';
    }

    $precio = (float)str_replace(',', '.', (string)($_POST['precio_uf_mensual'] ?? '0'));
    $maxUsuarios = max(1, (int)($_POST['max_usuarios'] ?? 10));
    $maxAlumnos = max(1, (int)($_POST['max_alumnos'] ?? 1000));

    $nombre = col_upper((string)($_POST['nombre'] ?? ''));

    if ($nombre === null) {
        throw new RuntimeException('Debe indicar el nombre del colegio.');
    }

    return [
        'nombre' => $nombre,
        'rbd' => col_upper((string)($_POST['rbd'] ?? '')),
        'rut_sostenedor' => col_upper((string)($_POST['rut_sostenedor'] ?? '')),
        'sostenedor_nombre' => col_upper((string)($_POST['sostenedor_nombre'] ?? '')),
        'director_nombre' => col_upper((string)($_POST['director_nombre'] ?? '')),
        'contacto_nombre' => col_upper((string)($_POST['contacto_nombre'] ?? '')),
        'contacto_email' => col_email((string)($_POST['contacto_email'] ?? '')),
        'contacto_telefono' => col_upper((string)($_POST['contacto_telefono'] ?? '')),
        'direccion' => col_upper((string)($_POST['direccion'] ?? '')),
        'comuna' => col_upper((string)($_POST['comuna'] ?? '')),
        'region' => col_upper((string)($_POST['region'] ?? '')),
        'plan_codigo' => $planCodigo,
        'plan_nombre' => $planes[$planCodigo] ?? 'Plan Base',
        'precio_uf_mensual' => $precio,
        'max_usuarios' => $maxUsuarios,
        'max_alumnos' => $maxAlumnos,
        'fecha_inicio' => col_date((string)($_POST['fecha_inicio'] ?? '')),
        'fecha_vencimiento' => col_date((string)($_POST['fecha_vencimiento'] ?? '')),
        'estado_comercial' => $estadoComercial,
        'observaciones' => col_upper((string)($_POST['observaciones'] ?? '')),
        'activo' => isset($_POST['activo']) ? 1 : 0,
        'creado_por' => $userId > 0 ? $userId : null,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

if (!col_table_exists($pdo, 'colegios')) {
    http_response_code(500);
    exit('La tabla colegios no existe. Ejecuta primero la Fase 0.5.33A.');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'crear') {
            $data = col_payload_desde_post($userId);
            $data['created_at'] = date('Y-m-d H:i:s');

            $pdo->beginTransaction();

            $nuevoId = col_insert_dynamic($pdo, 'colegios', $data);

            registrar_bitacora(
                'colegios',
                'crear_colegio',
                'colegios',
                $nuevoId,
                'Colegio creado: ' . (string)$data['nombre']
            );

            $pdo->commit();

            col_redirect('ok', 'Colegio creado correctamente.');
        }

        if ($accion === 'actualizar') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Colegio no válido.');
            }

            $data = col_payload_desde_post($userId);
            unset($data['creado_por']);

            $pdo->beginTransaction();

            col_update_dynamic($pdo, 'colegios', $id, $data);

            registrar_bitacora(
                'colegios',
                'actualizar_colegio',
                'colegios',
                $id,
                'Colegio actualizado: ' . (string)$data['nombre']
            );

            $pdo->commit();

            col_redirect('ok', 'Colegio actualizado correctamente.', $id);
        }

        if ($accion === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $nuevoActivo = (int)($_POST['nuevo_activo'] ?? -1);

            if ($id <= 0 || !in_array($nuevoActivo, [0, 1], true)) {
                throw new RuntimeException('Estado no válido.');
            }

            $stmt = $pdo->prepare("SELECT nombre FROM colegios WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $nombre = (string)($stmt->fetchColumn() ?: 'Colegio');

            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("
                UPDATE colegios
                SET activo = ?,
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->execute([$nuevoActivo, $id]);

            registrar_bitacora(
                'colegios',
                $nuevoActivo === 1 ? 'activar_colegio' : 'inactivar_colegio',
                'colegios',
                $id,
                ($nuevoActivo === 1 ? 'Colegio activado: ' : 'Colegio inactivado: ') . $nombre
            );

            $pdo->commit();

            col_redirect(
                'ok',
                $nuevoActivo === 1 ? 'Colegio activado correctamente.' : 'Colegio inactivado correctamente.'
            );
        }

        if ($accion === 'toggle_modulo_ia') {
            $id          = (int)($_POST['id'] ?? 0);
            $nuevoActivo = (int)($_POST['nuevo_activo'] ?? -1);
            $fechaExp    = col_date((string)($_POST['fecha_expiracion'] ?? ''));

            if ($id <= 0 || !in_array($nuevoActivo, [0, 1], true)) {
                throw new RuntimeException('Datos de módulo no válidos.');
            }

            $pdo->prepare("
                INSERT INTO colegio_modulos (colegio_id, modulo_codigo, activo, fecha_activacion, fecha_expiracion)
                VALUES (?, 'ia', ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    activo           = VALUES(activo),
                    fecha_activacion = IF(VALUES(activo) = 1, NOW(), fecha_activacion),
                    fecha_expiracion = VALUES(fecha_expiracion),
                    updated_at       = NOW()
            ")->execute([$id, $nuevoActivo, $fechaExp]);

            $stmt = $pdo->prepare("SELECT nombre FROM colegios WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $nombreColegio = (string)($stmt->fetchColumn() ?: 'Colegio');

            registrar_bitacora(
                'colegios',
                $nuevoActivo === 1 ? 'activar_modulo_ia' : 'desactivar_modulo_ia',
                'colegios',
                $id,
                ($nuevoActivo === 1 ? 'Módulo IA activado: ' : 'Módulo IA desactivado: ') . $nombreColegio
            );

            col_redirect(
                'ok',
                $nuevoActivo === 1 ? 'Módulo IA activado correctamente.' : 'Módulo IA desactivado.'
            );
        }

        throw new RuntimeException('Acción no válida.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    col_redirect('error', $e->getMessage());
}

$q = clean((string)($_GET['q'] ?? ''));
$filtroEstado = clean((string)($_GET['estado'] ?? 'todos'));
$filtroPlan = clean((string)($_GET['plan'] ?? 'todos'));
$editId = (int)($_GET['edit'] ?? 0);
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        nombre LIKE ?
        OR rbd LIKE ?
        OR comuna LIKE ?
        OR contacto_email LIKE ?
        OR sostenedor_nombre LIKE ?
    )";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($filtroEstado !== 'todos') {
    if ($filtroEstado === 'activos') {
        $where[] = 'activo = 1';
    } elseif ($filtroEstado === 'inactivos') {
        $where[] = 'activo = 0';
    } elseif ($filtroEstado === 'vencidos') {
        $where[] = 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()';
    } elseif ($filtroEstado === 'por_vencer') {
        $where[] = 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    }
}

if ($filtroPlan !== 'todos') {
    $where[] = 'plan_codigo = ?';
    $params[] = $filtroPlan;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT *
    FROM colegios
    {$whereSql}
    ORDER BY activo DESC, nombre ASC
    LIMIT 300
");
$stmt->execute($params);
$colegios = $stmt->fetchAll();

// Cargar estado del módulo IA para todos los colegios listados
$modulosIa = [];
try {
    $colegioIds = array_column($colegios, 'id');
    if ($colegioIds) {
        $in = implode(',', array_fill(0, count($colegioIds), '?'));
        $stmtIa = $pdo->prepare("
            SELECT colegio_id, activo, fecha_activacion, fecha_expiracion
            FROM colegio_modulos
            WHERE colegio_id IN ($in) AND modulo_codigo = 'ia'
        ");
        $stmtIa->execute($colegioIds);
        foreach ($stmtIa->fetchAll() as $row) {
            $modulosIa[(int)$row['colegio_id']] = $row;
        }
    }
} catch (Throwable $e) { /* tabla puede no existir en ambientes locales */ }

$stmtEdit = null;
$editColegio = null;

if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM colegios WHERE id = ? LIMIT 1");
    $stmtEdit->execute([$editId]);
    $editColegio = $stmtEdit->fetch() ?: null;
}

$totalColegios = (int)$pdo->query("SELECT COUNT(*) FROM colegios")->fetchColumn();
$totalActivos = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE activo = 1")->fetchColumn();
$totalInactivos = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE activo = 0")->fetchColumn();
$totalVencidos = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()")->fetchColumn();
$totalPorVencer = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

$stmtMrr = $pdo->query("
    SELECT COALESCE(SUM(precio_uf_mensual), 0)
    FROM colegios
    WHERE activo = 1
      AND estado_comercial IN ('activo', 'demo')
");
$mrrUf = (float)$stmtMrr->fetchColumn();

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.col-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.col-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.col-hero p {
    margin: 0;
    color: #ccfbf1;
    max-width: 900px;
    line-height: 1.55;
}

.col-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.col-btn {
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

.col-btn:hover {
    color: #fff;
}

.col-kpis {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.col-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.col-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.col-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.8rem;
    line-height: 1;
    margin-top: .35rem;
}

.col-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(380px, .95fr);
    gap: 1.2rem;
    align-items: start;
}

.col-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.col-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.col-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.col-panel-body {
    padding: 1.2rem;
}

.col-filter {
    display: grid;
    grid-template-columns: 1.2fr .75fr .75fr auto auto;
    gap: .8rem;
    align-items: end;
}

.col-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .85rem;
}

.col-field.full {
    grid-column: 1 / -1;
}

.col-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.col-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.col-submit,
.col-link,
.col-action {
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

.col-submit.green,
.col-link.green,
.col-action.green {
    background: #059669;
    color: #fff;
    border: 1px solid #10b981;
}

.col-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.col-action.red {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.col-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: .85rem;
}

.col-card-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
}

.col-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .25rem;
}

.col-meta {
    color: #64748b;
    font-size: .78rem;
    line-height: 1.4;
    margin-top: .25rem;
}

.col-badge {
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

.col-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.col-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.col-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.col-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.col-row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-top: .75rem;
}

.col-row-actions form {
    margin: 0;
}

.col-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.col-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.col-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.col-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}

@media (max-width: 1250px) {
    .col-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .col-layout {
        grid-template-columns: 1fr;
    }

    .col-filter {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 720px) {
    .col-kpis,
    .col-filter,
    .col-form {
        grid-template-columns: 1fr;
    }

    .col-hero {
        padding: 1.35rem;
    }
}

/* ── Módulo IA por colegio ───────────────────────────────── */
.col-ia-panel {
    margin-top: .75rem;
    padding: .65rem .8rem;
    border-radius: 12px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .6rem;
}
.col-ia-panel.off {
    background: #f8fafc;
    border-color: #e2e8f0;
}
.col-ia-label {
    font-size: .78rem;
    font-weight: 700;
    color: #047857;
    display: flex;
    align-items: center;
    gap: .3rem;
}
.col-ia-panel.off .col-ia-label { color: #64748b; }
.col-ia-form {
    display: none;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
    margin-top: .5rem;
    width: 100%;
}
.col-ia-form.visible { display: flex; }
.col-ia-form input[type="date"] {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: .38rem .6rem;
    font-size: .82rem;
    font-family: inherit;
}
</style>

<section class="col-hero">
    <h2>Administración de colegios</h2>
    <p>
        Gestión central de establecimientos, planes comerciales, límites operativos,
        fechas de vigencia, contactos institucionales y estado de activación.
    </p>

    <div class="col-actions">
        <a class="col-btn" href="<?= APP_URL ?>/modules/admin/index.php">
            <i class="bi bi-gear"></i>
            Administración
        </a>

        <a class="col-btn" href="<?= APP_URL ?>/modules/admin/usuarios.php">
            <i class="bi bi-person-gear"></i>
            Usuarios
        </a>

        <a class="col-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>

        <a class="col-btn" href="<?= APP_URL ?>/modules/admin/control_proyecto.php">
            <i class="bi bi-kanban"></i>
            Centro de control
        </a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="col-msg ok"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="col-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<section class="col-kpis">
    <div class="col-kpi">
        <span>Total colegios</span>
        <strong><?= number_format($totalColegios, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Activos</span>
        <strong style="color:#047857;"><?= number_format($totalActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Inactivos</span>
        <strong><?= number_format($totalInactivos, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Vencidos</span>
        <strong style="color:#b91c1c;"><?= number_format($totalVencidos, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Por vencer</span>
        <strong style="color:#92400e;"><?= number_format($totalPorVencer, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>MRR UF</span>
        <strong><?= number_format($mrrUf, 2, ',', '.') ?></strong>
    </div>
</section>

<div class="col-layout">
    <section>
        <div class="col-panel">
            <div class="col-panel-head">
                <h3 class="col-panel-title">
                    <i class="bi bi-funnel"></i>
                    Filtros
                </h3>
            </div>

            <div class="col-panel-body">
                <form method="get" class="col-filter">
                    <div>
                        <label class="col-label">Buscar</label>
                        <input
                            class="col-control"
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Nombre, RBD, comuna, sostenedor o correo"
                        >
                    </div>

                    <div>
                        <label class="col-label">Estado</label>
                        <select class="col-control" name="estado">
                            <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="activos" <?= $filtroEstado === 'activos' ? 'selected' : '' ?>>Activos</option>
                            <option value="inactivos" <?= $filtroEstado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                            <option value="vencidos" <?= $filtroEstado === 'vencidos' ? 'selected' : '' ?>>Vencidos</option>
                            <option value="por_vencer" <?= $filtroEstado === 'por_vencer' ? 'selected' : '' ?>>Por vencer</option>
                        </select>
                    </div>

                    <div>
                        <label class="col-label">Plan</label>
                        <select class="col-control" name="plan">
                            <option value="todos" <?= $filtroPlan === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="demo" <?= $filtroPlan === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="base" <?= $filtroPlan === 'base' ? 'selected' : '' ?>>Base</option>
                            <option value="profesional" <?= $filtroPlan === 'profesional' ? 'selected' : '' ?>>Profesional</option>
                            <option value="enterprise" <?= $filtroPlan === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                        </select>
                    </div>

                    <div>
                        <button class="col-submit" type="submit">
                            <i class="bi bi-search"></i>
                            Filtrar
                        </button>
                    </div>

                    <div>
                        <a class="col-link" href="<?= APP_URL ?>/modules/colegios/index.php">
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-panel">
            <div class="col-panel-head">
                <h3 class="col-panel-title">
                    <i class="bi bi-building"></i>
                    Colegios registrados
                </h3>

                <span class="col-badge"><?= number_format(count($colegios), 0, ',', '.') ?> mostrado(s)</span>
            </div>

            <div class="col-panel-body">
                <?php if (!$colegios): ?>
                    <div class="col-empty">
                        No hay colegios registrados con los filtros actuales.
                    </div>
                <?php else: ?>
                    <?php foreach ($colegios as $colegio): ?>
                        <?php
                        [$vencimientoTexto, $vencimientoClass] = col_estado_vencimiento($colegio['fecha_vencimiento'] ?? null);
                        $estaActivo = (int)($colegio['activo'] ?? 1) === 1;
                        $nuevoActivo = $estaActivo ? 0 : 1;
                        $colegioId = (int)$colegio['id'];
                        $usuariosColegio = col_count_by_colegio($pdo, 'usuarios', $colegioId);
                        $alumnosColegio = col_count_by_colegio($pdo, 'alumnos', $colegioId);
                        $casosColegio = col_count_by_colegio($pdo, 'casos', $colegioId);
                        ?>

                        <article class="col-card">
                            <div class="col-card-head">
                                <div>
                                    <div class="col-card-title">
                                        <?= e((string)$colegio['nombre']) ?>
                                    </div>

                                    <div class="col-meta">
                                        RBD: <?= e(col_pick($colegio, 'rbd')) ?> ·
                                        Comuna: <?= e(col_pick($colegio, 'comuna')) ?> ·
                                        Región: <?= e(col_pick($colegio, 'region')) ?>
                                    </div>
                                </div>

                                <div>
                                    <span class="col-badge <?= $estaActivo ? 'ok' : 'danger' ?>">
                                        <?= $estaActivo ? 'Activo' : 'Inactivo' ?>
                                    </span>

                                    <span class="col-badge <?= e($vencimientoClass) ?>">
                                        <?= e($vencimientoTexto) ?>
                                    </span>
                                </div>
                            </div>

                            <div style="margin-top:.6rem;">
                                <span class="col-badge soft">
                                    Plan: <?= e(col_pick($colegio, 'plan_nombre', 'Plan Base')) ?>
                                </span>

                                <span class="col-badge soft">
                                    UF mensual: <?= number_format((float)($colegio['precio_uf_mensual'] ?? 0), 2, ',', '.') ?>
                                </span>

                                <span class="col-badge soft">
                                    Usuarios: <?= number_format($usuariosColegio, 0, ',', '.') ?> / <?= number_format((int)($colegio['max_usuarios'] ?? 0), 0, ',', '.') ?>
                                </span>

                                <span class="col-badge soft">
                                    Alumnos: <?= number_format($alumnosColegio, 0, ',', '.') ?> / <?= number_format((int)($colegio['max_alumnos'] ?? 0), 0, ',', '.') ?>
                                </span>

                                <span class="col-badge soft">
                                    Casos: <?= number_format($casosColegio, 0, ',', '.') ?>
                                </span>
                            </div>

                            <div class="col-meta">
                                Contacto: <?= e(col_pick($colegio, 'contacto_nombre')) ?> ·
                                <?= e(col_pick($colegio, 'contacto_email')) ?> ·
                                <?= e(col_pick($colegio, 'contacto_telefono')) ?>
                            </div>

                            <div class="col-meta">
                                Vigencia:
                                <?= e(col_fecha($colegio['fecha_inicio'] ?? null)) ?>
                                al
                                <?= e(col_fecha($colegio['fecha_vencimiento'] ?? null)) ?>
                                · Estado comercial:
                                <?= e((string)($colegio['estado_comercial'] ?? 'activo')) ?>
                            </div>

                            <div class="col-row-actions">
                                <a class="col-link" href="<?= APP_URL ?>/modules/colegios/index.php?edit=<?= (int)$colegio['id'] ?>">
                                    <i class="bi bi-pencil-square"></i>
                                    Editar
                                </a>

                                <a class="col-link" href="<?= APP_URL ?>/modules/admin/usuarios.php?colegio_id=<?= (int)$colegio['id'] ?>">
                                    <i class="bi bi-person-gear"></i>
                                    Usuarios
                                </a>

                                <form method="post">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="_accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$colegio['id'] ?>">
                                    <input type="hidden" name="nuevo_activo" value="<?= (int)$nuevoActivo ?>">

                                    <button
                                        class="col-action <?= $estaActivo ? 'red' : 'green' ?>"
                                        type="submit"
                                        onclick="return confirm('¿Confirmas <?= $estaActivo ? 'inactivar' : 'activar' ?> este colegio?');"
                                    >
                                        <i class="bi <?= $estaActivo ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                        <?= $estaActivo ? 'Inactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            </div>

                            <?php
                            // ── Bloque módulo IA ──────────────────────────────────────
                            $iaData      = $modulosIa[$colegioId] ?? null;
                            $iaActiva    = $iaData && (int)$iaData['activo'] === 1;
                            $iaFechaExp  = $iaData['fecha_expiracion'] ?? null;
                            $iaPanelId   = 'ia-form-' . $colegioId;

                            // ¿El módulo IA está vencido?
                            $iaVencida = $iaActiva && $iaFechaExp && strtotime($iaFechaExp) < time();
                            if ($iaVencida) { $iaActiva = false; }

                            [$iaVencTexto, $iaVencClase] = col_estado_vencimiento($iaFechaExp);
                            ?>
                            <div class="col-ia-panel <?= $iaActiva ? '' : 'off' ?>">
                                <div class="col-ia-label">
                                    <i class="bi bi-stars"></i>
                                    Módulo Análisis IA:
                                    <?php if ($iaActiva): ?>
                                        <strong style="color:#047857;">Activo</strong>
                                        <?php if ($iaFechaExp): ?>
                                            <span class="col-badge <?= e($iaVencClase) ?>" style="margin-left:.3rem;">
                                                <?= e($iaVencTexto) ?> (<?= e(col_fecha($iaFechaExp)) ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="col-badge ok" style="margin-left:.3rem;">Sin vencimiento</span>
                                        <?php endif; ?>
                                    <?php elseif ($iaVencida): ?>
                                        <strong style="color:#b91c1c;">Vencido</strong>
                                        <span class="col-badge danger" style="margin-left:.3rem;"><?= e($iaVencTexto) ?></span>
                                    <?php else: ?>
                                        <strong style="color:#94a3b8;">No contratado</strong>
                                    <?php endif; ?>
                                </div>

                                <?php if ($iaActiva || $iaVencida): ?>
                                    <!-- Desactivar -->
                                    <form method="post" style="margin:0;">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="_accion" value="toggle_modulo_ia">
                                        <input type="hidden" name="id" value="<?= $colegioId ?>">
                                        <input type="hidden" name="nuevo_activo" value="0">
                                        <button class="col-action red" type="submit"
                                                onclick="return confirm('¿Desactivar el módulo IA para este colegio?');"
                                                style="font-size:.78rem;padding:.35rem .75rem;">
                                            <i class="bi bi-x-circle"></i> Desactivar IA
                                        </button>
                                    </form>
                                    <!-- Renovar -->
                                    <button type="button"
                                            class="col-action green"
                                            style="font-size:.78rem;padding:.35rem .75rem;"
                                            onclick="document.getElementById('<?= $iaPanelId ?>').classList.toggle('visible')">
                                        <i class="bi bi-arrow-clockwise"></i> Renovar
                                    </button>
                                <?php else: ?>
                                    <!-- Activar -->
                                    <button type="button"
                                            class="col-action green"
                                            style="font-size:.78rem;padding:.35rem .75rem;"
                                            onclick="document.getElementById('<?= $iaPanelId ?>').classList.toggle('visible')">
                                        <i class="bi bi-stars"></i> Activar IA
                                    </button>
                                <?php endif; ?>

                                <!-- Formulario inline activación / renovación -->
                                <form method="post" class="col-ia-form" id="<?= $iaPanelId ?>">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="_accion" value="toggle_modulo_ia">
                                    <input type="hidden" name="id" value="<?= $colegioId ?>">
                                    <input type="hidden" name="nuevo_activo" value="1">
                                    <label style="font-size:.78rem;font-weight:700;color:#334155;">
                                        Vence:
                                    </label>
                                    <input type="date"
                                           name="fecha_expiracion"
                                           value="<?= e($iaFechaExp ? substr($iaFechaExp, 0, 10) : '') ?>"
                                           min="<?= date('Y-m-d') ?>"
                                           placeholder="Sin límite">
                                    <button class="col-submit green" type="submit"
                                            style="font-size:.78rem;padding:.38rem .85rem;border-radius:999px;">
                                        <i class="bi bi-check-circle"></i> Confirmar
                                    </button>
                                    <button type="button"
                                            onclick="document.getElementById('<?= $iaPanelId ?>').classList.remove('visible')"
                                            style="background:none;border:none;color:#64748b;cursor:pointer;font-size:.82rem;">
                                        Cancelar
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="col-panel">
            <div class="col-panel-head">
                <h3 class="col-panel-title">
                    <i class="bi <?= $editColegio ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                    <?= $editColegio ? 'Editar colegio' : 'Nuevo colegio' ?>
                </h3>

                <?php if ($editColegio): ?>
                    <a class="col-link" href="<?= APP_URL ?>/modules/colegios/index.php">
                        Nuevo
                    </a>
                <?php endif; ?>
            </div>

            <div class="col-panel-body">
                <form method="post" class="col-form">
                    <?= CSRF::field() ?>

                    <input type="hidden" name="_accion" value="<?= $editColegio ? 'actualizar' : 'crear' ?>">

                    <?php if ($editColegio): ?>
                        <input type="hidden" name="id" value="<?= (int)$editColegio['id'] ?>">
                    <?php endif; ?>

                    <div class="col-field full">
                        <label class="col-label">Nombre del colegio *</label>
                        <input
                            class="col-control"
                            type="text"
                            name="nombre"
                            value="<?= e((string)($editColegio['nombre'] ?? '')) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label class="col-label">RBD</label>
                        <input class="col-control" type="text" name="rbd" value="<?= e((string)($editColegio['rbd'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">RUT sostenedor</label>
                        <input class="col-control" type="text" name="rut_sostenedor" value="<?= e((string)($editColegio['rut_sostenedor'] ?? '')) ?>">
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Sostenedor</label>
                        <input class="col-control" type="text" name="sostenedor_nombre" value="<?= e((string)($editColegio['sostenedor_nombre'] ?? '')) ?>">
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Director/a</label>
                        <input class="col-control" type="text" name="director_nombre" value="<?= e((string)($editColegio['director_nombre'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Contacto</label>
                        <input class="col-control" type="text" name="contacto_nombre" value="<?= e((string)($editColegio['contacto_nombre'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Correo contacto</label>
                        <input class="col-control" type="email" name="contacto_email" value="<?= e((string)($editColegio['contacto_email'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Teléfono contacto</label>
                        <input class="col-control" type="text" name="contacto_telefono" value="<?= e((string)($editColegio['contacto_telefono'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Comuna</label>
                        <input class="col-control" type="text" name="comuna" value="<?= e((string)($editColegio['comuna'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Región</label>
                        <input class="col-control" type="text" name="region" value="<?= e((string)($editColegio['region'] ?? '')) ?>">
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Dirección</label>
                        <input class="col-control" type="text" name="direccion" value="<?= e((string)($editColegio['direccion'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Plan</label>
                        <?php $planActual = (string)($editColegio['plan_codigo'] ?? 'base'); ?>
                        <select class="col-control" name="plan_codigo">
                            <option value="demo" <?= $planActual === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="base" <?= $planActual === 'base' ? 'selected' : '' ?>>Base</option>
                            <option value="profesional" <?= $planActual === 'profesional' ? 'selected' : '' ?>>Profesional</option>
                            <option value="enterprise" <?= $planActual === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                        </select>
                    </div>

                    <div>
                        <label class="col-label">UF mensual</label>
                        <input
                            class="col-control"
                            type="number"
                            step="0.01"
                            min="0"
                            name="precio_uf_mensual"
                            value="<?= e((string)($editColegio['precio_uf_mensual'] ?? '0')) ?>"
                        >
                    </div>

                    <div>
                        <label class="col-label">Máx. usuarios</label>
                        <input class="col-control" type="number" min="1" name="max_usuarios" value="<?= e((string)($editColegio['max_usuarios'] ?? '10')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Máx. alumnos</label>
                        <input class="col-control" type="number" min="1" name="max_alumnos" value="<?= e((string)($editColegio['max_alumnos'] ?? '1000')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Fecha inicio</label>
                        <input class="col-control" type="date" name="fecha_inicio" value="<?= e((string)($editColegio['fecha_inicio'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Fecha vencimiento</label>
                        <input class="col-control" type="date" name="fecha_vencimiento" value="<?= e((string)($editColegio['fecha_vencimiento'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Estado comercial</label>
                        <?php $estadoComercial = (string)($editColegio['estado_comercial'] ?? 'activo'); ?>
                        <select class="col-control" name="estado_comercial">
                            <option value="activo" <?= $estadoComercial === 'activo' ? 'selected' : '' ?>>Activo</option>
                            <option value="demo" <?= $estadoComercial === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="suspendido" <?= $estadoComercial === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
                            <option value="vencido" <?= $estadoComercial === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                            <option value="cerrado" <?= $estadoComercial === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                        </select>
                    </div>

                    <div>
                        <label class="col-label">Estado sistema</label>
                        <label style="display:flex;align-items:center;gap:.45rem;font-weight:900;color:#334155;margin-top:.7rem;">
                            <input
                                type="checkbox"
                                name="activo"
                                value="1"
                                <?= (int)($editColegio['activo'] ?? 1) === 1 ? 'checked' : '' ?>
                            >
                            Colegio activo
                        </label>
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Observaciones</label>
                        <textarea class="col-control" name="observaciones" rows="4"><?= e((string)($editColegio['observaciones'] ?? '')) ?></textarea>
                    </div>

                    <div class="col-field full">
                        <button class="col-submit green" type="submit">
                            <i class="bi bi-save"></i>
                            <?= $editColegio ? 'Guardar cambios' : 'Crear colegio' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
