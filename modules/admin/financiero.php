<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');
$puedeAdministrar = in_array($rolCodigo, ['superadmin'], true)
    || (method_exists('Auth', 'can') && Auth::can('admin_sistema'));

if (!$puedeAdministrar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Panel financiero · Metis';
$pageSubtitle = 'Control SaaS de colegios, planes, vigencias, ingresos UF y riesgo comercial';

function fin_table_exists(PDO $pdo, string $table): bool
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

function fin_column_exists(PDO $pdo, string $table, string $column): bool
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

function fin_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function fin_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!fin_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = 'SELECT COUNT(*) FROM ' . fin_quote($table);

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

function fin_sum(PDO $pdo, string $table, string $column, ?string $where = null, array $params = []): float
{
    if (!fin_table_exists($pdo, $table) || !fin_column_exists($pdo, $table, $column)) {
        return 0.0;
    }

    try {
        $sql = 'SELECT COALESCE(SUM(' . fin_quote($column) . '), 0) FROM ' . fin_quote($table);

        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function fin_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y', $ts) : $value;
}

function fin_pick(array $row, string $key, string $default = '-'): string
{
    return isset($row[$key]) && trim((string)$row[$key]) !== ''
        ? (string)$row[$key]
        : $default;
}

function fin_money_uf(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function fin_estado_vencimiento(?string $fecha): array
{
    if (!$fecha) {
        return ['Sin vencimiento', 'soft', null];
    }

    $hoy = new DateTimeImmutable('today');
    $vencimiento = DateTimeImmutable::createFromFormat('Y-m-d', substr($fecha, 0, 10));

    if (!$vencimiento) {
        return ['Fecha inválida', 'warn', null];
    }

    $dias = (int)$hoy->diff($vencimiento)->format('%r%a');

    if ($dias < 0) {
        return ['Vencido hace ' . abs($dias) . ' día(s)', 'danger', $dias];
    }

    if ($dias <= 30) {
        return ['Vence en ' . $dias . ' día(s)', 'warn', $dias];
    }

    return ['Vigente', 'ok', $dias];
}

function fin_riesgo_comercial(array $row): array
{
    $activo = (int)($row['activo'] ?? 1) === 1;
    $estado = strtolower((string)($row['estado_comercial'] ?? 'activo'));
    [$txtVencimiento, $classVencimiento, $dias] = fin_estado_vencimiento($row['fecha_vencimiento'] ?? null);

    if (!$activo || in_array($estado, ['suspendido', 'cerrado'], true)) {
        return ['Alto', 'danger', 'Colegio inactivo, suspendido o cerrado.'];
    }

    if ($dias !== null && $dias < 0) {
        return ['Alto', 'danger', $txtVencimiento];
    }

    if ($dias !== null && $dias <= 30) {
        return ['Medio', 'warn', $txtVencimiento];
    }

    if ($estado === 'demo') {
        return ['Medio', 'warn', 'Plan demo requiere conversión o cierre.'];
    }

    return ['Bajo', 'ok', 'Sin alerta comercial inmediata.'];
}

function fin_build_filters(PDO $pdo): array
{
    $q = clean((string)($_GET['q'] ?? ''));
    $plan = clean((string)($_GET['plan'] ?? 'todos'));
    $estado = clean((string)($_GET['estado'] ?? 'todos'));
    $riesgo = clean((string)($_GET['riesgo'] ?? 'todos'));

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = '(
            nombre LIKE ?
            OR rbd LIKE ?
            OR comuna LIKE ?
            OR region LIKE ?
            OR contacto_email LIKE ?
            OR sostenedor_nombre LIKE ?
        )';
        for ($i = 0; $i < 6; $i++) {
            $params[] = '%' . $q . '%';
        }
    }

    if ($plan !== 'todos') {
        $where[] = 'plan_codigo = ?';
        $params[] = $plan;
    }

    if ($estado !== 'todos') {
        if ($estado === 'activos') {
            $where[] = 'activo = 1';
        } elseif ($estado === 'inactivos') {
            $where[] = 'activo = 0';
        } elseif ($estado === 'vencidos') {
            $where[] = 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()';
        } elseif ($estado === 'por_vencer') {
            $where[] = 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
        } elseif (in_array($estado, ['activo', 'demo', 'suspendido', 'vencido', 'cerrado'], true)) {
            $where[] = 'estado_comercial = ?';
            $params[] = $estado;
        }
    }

    if ($riesgo === 'alto') {
        $where[] = "(activo = 0 OR estado_comercial IN ('suspendido', 'cerrado') OR (fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()))";
    } elseif ($riesgo === 'medio') {
        $where[] = "(estado_comercial = 'demo' OR (fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)))";
    } elseif ($riesgo === 'bajo') {
        $where[] = "activo = 1 AND estado_comercial NOT IN ('demo', 'suspendido', 'cerrado') AND (fecha_vencimiento IS NULL OR fecha_vencimiento > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
    }

    return [$q, $plan, $estado, $riesgo, $where, $params];
}

function fin_export_csv(array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="metis_panel_financiero_colegios.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'wb');

    fputcsv($out, [
        'Colegio',
        'RBD',
        'Comuna',
        'Region',
        'Plan',
        'Estado comercial',
        'Activo sistema',
        'UF mensual',
        'UF anual',
        'Fecha inicio',
        'Fecha vencimiento',
        'Riesgo',
        'Detalle riesgo',
        'Contacto',
        'Correo contacto',
        'Telefono contacto',
    ], ';');

    foreach ($rows as $row) {
        [$riesgoTexto, , $riesgoDetalle] = fin_riesgo_comercial($row);
        fputcsv($out, [
            (string)($row['nombre'] ?? ''),
            (string)($row['rbd'] ?? ''),
            (string)($row['comuna'] ?? ''),
            (string)($row['region'] ?? ''),
            (string)($row['plan_nombre'] ?? $row['plan_codigo'] ?? ''),
            (string)($row['estado_comercial'] ?? ''),
            ((int)($row['activo'] ?? 1) === 1) ? 'Activo' : 'Inactivo',
            number_format((float)($row['precio_uf_mensual'] ?? 0), 2, '.', ''),
            number_format((float)($row['precio_uf_mensual'] ?? 0) * 12, 2, '.', ''),
            (string)($row['fecha_inicio'] ?? ''),
            (string)($row['fecha_vencimiento'] ?? ''),
            $riesgoTexto,
            $riesgoDetalle,
            (string)($row['contacto_nombre'] ?? ''),
            (string)($row['contacto_email'] ?? ''),
            (string)($row['contacto_telefono'] ?? ''),
        ], ';');
    }

    fclose($out);
    exit;
}

if (!fin_table_exists($pdo, 'colegios')) {
    http_response_code(500);
    exit('La tabla colegios no existe. Ejecuta primero la migración multi-colegio.');
}

[$q, $filtroPlan, $filtroEstado, $filtroRiesgo, $where, $params] = fin_build_filters($pdo);
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT *
    FROM colegios
    {$whereSql}
    ORDER BY
        activo DESC,
        CASE
            WHEN fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE() THEN 1
            WHEN fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 2
            ELSE 3
        END ASC,
        precio_uf_mensual DESC,
        nombre ASC
    LIMIT 500
");
$stmt->execute($params);
$colegios = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    fin_export_csv($colegios);
}

$totalColegios = fin_count($pdo, 'colegios');
$totalActivos = fin_count($pdo, 'colegios', 'activo = 1');
$totalInactivos = fin_count($pdo, 'colegios', 'activo = 0');
$totalDemo = fin_count($pdo, 'colegios', "estado_comercial = 'demo'");
$totalSuspendidos = fin_count($pdo, 'colegios', "estado_comercial IN ('suspendido', 'cerrado')");
$totalVencidos = fin_count($pdo, 'colegios', 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()');
$totalPorVencer = fin_count($pdo, 'colegios', 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)');

$mrrUf = fin_sum($pdo, 'colegios', 'precio_uf_mensual', "activo = 1 AND estado_comercial IN ('activo', 'demo')");
$arrUf = $mrrUf * 12;
$mrrRealUf = fin_sum($pdo, 'colegios', 'precio_uf_mensual', "activo = 1 AND estado_comercial = 'activo'");
$mrrDemoUf = fin_sum($pdo, 'colegios', 'precio_uf_mensual', "activo = 1 AND estado_comercial = 'demo'");
$mrrRiesgoUf = fin_sum($pdo, 'colegios', 'precio_uf_mensual', "activo = 1 AND (estado_comercial = 'demo' OR (fecha_vencimiento IS NOT NULL AND fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)))");

$planes = [];
try {
    $stmtPlanes = $pdo->query("
        SELECT
            COALESCE(NULLIF(plan_codigo, ''), 'sin_plan') AS plan_codigo,
            COALESCE(NULLIF(plan_nombre, ''), 'Sin plan') AS plan_nombre,
            COUNT(*) AS total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) AS activos,
            COALESCE(SUM(CASE WHEN activo = 1 AND estado_comercial IN ('activo', 'demo') THEN precio_uf_mensual ELSE 0 END), 0) AS mrr_uf
        FROM colegios
        GROUP BY COALESCE(NULLIF(plan_codigo, ''), 'sin_plan'), COALESCE(NULLIF(plan_nombre, ''), 'Sin plan')
        ORDER BY mrr_uf DESC, total DESC
    ");
    $planes = $stmtPlanes->fetchAll();
} catch (Throwable $e) {
    $planes = [];
}

$usuariosTotal = fin_count($pdo, 'usuarios');
$usuariosActivos = fin_count($pdo, 'usuarios', 'activo = 1');
$casosTotal = fin_count($pdo, 'casos');
$alumnosTotal = fin_count($pdo, 'alumnos');

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.fin-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.fin-hero h2 { margin: 0 0 .45rem; font-size: 1.85rem; font-weight: 900; }
.fin-hero p { margin: 0; color: #ccfbf1; max-width: 980px; line-height: 1.55; }
.fin-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
.fin-btn {
    display: inline-flex; align-items: center; gap: .42rem; border-radius: 999px;
    padding: .62rem 1rem; font-size: .84rem; font-weight: 900; text-decoration: none;
    border: 1px solid rgba(255,255,255,.28); color: #fff; background: rgba(255,255,255,.12);
}
.fin-btn:hover { color: #fff; }
.fin-kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .9rem; margin-bottom: 1.2rem; }
.fin-kpi {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 18px;
    padding: 1rem; box-shadow: 0 12px 28px rgba(15,23,42,.06);
}
.fin-kpi span {
    color: #64748b; display: block; font-size: .68rem; font-weight: 900;
    letter-spacing: .08em; text-transform: uppercase;
}
.fin-kpi strong { display: block; color: #0f172a; font-size: 1.75rem; line-height: 1; margin-top: .35rem; }
.fin-layout { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(360px, .9fr); gap: 1.2rem; align-items: start; }
.fin-panel {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06); overflow: hidden; margin-bottom: 1.2rem;
}
.fin-panel-head {
    padding: 1rem 1.2rem; border-bottom: 1px solid #e2e8f0;
    display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap;
}
.fin-panel-title { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 900; }
.fin-panel-body { padding: 1.2rem; }
.fin-filter { display: grid; grid-template-columns: 1.2fr .75fr .75fr .75fr auto auto; gap: .8rem; align-items: end; }
.fin-label { display: block; color: #334155; font-size: .76rem; font-weight: 900; margin-bottom: .35rem; }
.fin-control {
    width: 100%; border: 1px solid #cbd5e1; border-radius: 13px;
    padding: .66rem .78rem; outline: none; background: #fff; font-size: .9rem;
}
.fin-submit, .fin-link {
    display: inline-flex; align-items: center; justify-content: center; gap: .35rem; border: 0;
    background: #0f172a; color: #fff; border-radius: 999px; padding: .66rem 1rem;
    font-weight: 900; font-size: .84rem; text-decoration: none; white-space: nowrap; cursor: pointer;
}
.fin-link { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.fin-link.green { background: #ecfdf5; color: #047857; border-color: #bbf7d0; }
.fin-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 18px; padding: 1rem; margin-bottom: .85rem; }
.fin-card-head { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.fin-card-title { color: #0f172a; font-weight: 900; margin-bottom: .25rem; }
.fin-meta { color: #64748b; font-size: .78rem; line-height: 1.4; margin-top: .25rem; }
.fin-badge {
    display: inline-flex; align-items: center; border-radius: 999px; padding: .24rem .62rem;
    font-size: .72rem; font-weight: 900; border: 1px solid #e2e8f0;
    background: #fff; color: #475569; white-space: nowrap; margin: .12rem;
}
.fin-badge.ok { background: #ecfdf5; border-color: #bbf7d0; color: #047857; }
.fin-badge.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.fin-badge.danger { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
.fin-badge.blue { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.fin-badge.soft { background: #f8fafc; color: #475569; }
.fin-progress { width: 100%; background: #e2e8f0; height: 10px; border-radius: 999px; overflow: hidden; margin-top: .55rem; }
.fin-progress span { display: block; height: 100%; background: #059669; }
.fin-plan-row { display: grid; grid-template-columns: 1fr auto; gap: .8rem; align-items: center; padding: .8rem 0; border-bottom: 1px solid #f1f5f9; }
.fin-plan-row:last-child { border-bottom: 0; }
.fin-empty { text-align: center; padding: 2rem 1rem; color: #94a3b8; }
.fin-note {
    background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
    border-radius: 16px; padding: .95rem 1rem; line-height: 1.5; font-size: .88rem;
}

@media (max-width: 1300px) {
    .fin-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .fin-layout { grid-template-columns: 1fr; }
    .fin-filter { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 720px) {
    .fin-kpis, .fin-filter { grid-template-columns: 1fr; }
    .fin-hero { padding: 1.35rem; }
}
</style>

<section class="fin-hero">
    <h2>Panel financiero SaaS</h2>
    <p>
        Vista central para controlar colegios activos, planes, vencimientos, ingresos mensuales en UF,
        ingreso anual estimado, riesgo comercial y conversión de planes demo.
    </p>

    <div class="fin-actions">
        <a class="fin-btn" href="<?= APP_URL ?>/modules/admin/index.php">
            <i class="bi bi-gear"></i>
            Administración
        </a>

        <a class="fin-btn" href="<?= APP_URL ?>/modules/colegios/index.php">
            <i class="bi bi-building"></i>
            Colegios
        </a>

        <a class="fin-btn" href="<?= APP_URL ?>/modules/admin/financiero.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">
            <i class="bi bi-filetype-csv"></i>
            Exportar CSV
        </a>
    </div>
</section>

<section class="fin-kpis">
    <div class="fin-kpi">
        <span>MRR UF</span>
        <strong><?= e(fin_money_uf($mrrUf)) ?></strong>
    </div>

    <div class="fin-kpi">
        <span>ARR UF</span>
        <strong><?= e(fin_money_uf($arrUf)) ?></strong>
    </div>

    <div class="fin-kpi">
        <span>MRR real UF</span>
        <strong style="color:#047857;"><?= e(fin_money_uf($mrrRealUf)) ?></strong>
    </div>

    <div class="fin-kpi">
        <span>MRR demo UF</span>
        <strong style="color:#92400e;"><?= e(fin_money_uf($mrrDemoUf)) ?></strong>
    </div>

    <div class="fin-kpi">
        <span>MRR en riesgo UF</span>
        <strong style="color:<?= $mrrRiesgoUf > 0 ? '#b91c1c' : '#047857' ?>;"><?= e(fin_money_uf($mrrRiesgoUf)) ?></strong>
    </div>

    <div class="fin-kpi">
        <span>Colegios activos</span>
        <strong><?= number_format($totalActivos, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="fin-kpis">
    <div class="fin-kpi">
        <span>Total colegios</span>
        <strong><?= number_format($totalColegios, 0, ',', '.') ?></strong>
    </div>

    <div class="fin-kpi">
        <span>Demo</span>
        <strong style="color:#92400e;"><?= number_format($totalDemo, 0, ',', '.') ?></strong>
    </div>

    <div class="fin-kpi">
        <span>Vencidos</span>
        <strong style="color:#b91c1c;"><?= number_format($totalVencidos, 0, ',', '.') ?></strong>
    </div>

    <div class="fin-kpi">
        <span>Por vencer</span>
        <strong style="color:#92400e;"><?= number_format($totalPorVencer, 0, ',', '.') ?></strong>
    </div>

    <div class="fin-kpi">
        <span>Suspendidos/cerrados</span>
        <strong><?= number_format($totalSuspendidos, 0, ',', '.') ?></strong>
    </div>

    <div class="fin-kpi">
        <span>Usuarios activos</span>
        <strong><?= number_format($usuariosActivos, 0, ',', '.') ?></strong>
    </div>
</section>

<div class="fin-layout">
    <section>
        <div class="fin-panel">
            <div class="fin-panel-head">
                <h3 class="fin-panel-title">
                    <i class="bi bi-funnel"></i>
                    Filtros financieros
                </h3>
            </div>

            <div class="fin-panel-body">
                <form method="get" class="fin-filter">
                    <div>
                        <label class="fin-label">Buscar</label>
                        <input
                            class="fin-control"
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Colegio, RBD, comuna, sostenedor o contacto"
                        >
                    </div>

                    <div>
                        <label class="fin-label">Plan</label>
                        <select class="fin-control" name="plan">
                            <option value="todos" <?= $filtroPlan === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="demo" <?= $filtroPlan === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="base" <?= $filtroPlan === 'base' ? 'selected' : '' ?>>Base</option>
                            <option value="profesional" <?= $filtroPlan === 'profesional' ? 'selected' : '' ?>>Profesional</option>
                            <option value="enterprise" <?= $filtroPlan === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                        </select>
                    </div>

                    <div>
                        <label class="fin-label">Estado</label>
                        <select class="fin-control" name="estado">
                            <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="activos" <?= $filtroEstado === 'activos' ? 'selected' : '' ?>>Activos</option>
                            <option value="inactivos" <?= $filtroEstado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                            <option value="demo" <?= $filtroEstado === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="suspendido" <?= $filtroEstado === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
                            <option value="vencidos" <?= $filtroEstado === 'vencidos' ? 'selected' : '' ?>>Vencidos</option>
                            <option value="por_vencer" <?= $filtroEstado === 'por_vencer' ? 'selected' : '' ?>>Por vencer</option>
                        </select>
                    </div>

                    <div>
                        <label class="fin-label">Riesgo</label>
                        <select class="fin-control" name="riesgo">
                            <option value="todos" <?= $filtroRiesgo === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="alto" <?= $filtroRiesgo === 'alto' ? 'selected' : '' ?>>Alto</option>
                            <option value="medio" <?= $filtroRiesgo === 'medio' ? 'selected' : '' ?>>Medio</option>
                            <option value="bajo" <?= $filtroRiesgo === 'bajo' ? 'selected' : '' ?>>Bajo</option>
                        </select>
                    </div>

                    <div>
                        <button class="fin-submit" type="submit">
                            <i class="bi bi-search"></i>
                            Filtrar
                        </button>
                    </div>

                    <div>
                        <a class="fin-link" href="<?= APP_URL ?>/modules/admin/financiero.php">
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="fin-panel">
            <div class="fin-panel-head">
                <h3 class="fin-panel-title">
                    <i class="bi bi-building"></i>
                    Cartera de colegios
                </h3>

                <span class="fin-badge blue"><?= number_format(count($colegios), 0, ',', '.') ?> mostrado(s)</span>
            </div>

            <div class="fin-panel-body">
                <?php if (!$colegios): ?>
                    <div class="fin-empty">
                        No hay colegios con los filtros actuales.
                    </div>
                <?php else: ?>
                    <?php foreach ($colegios as $colegio): ?>
                        <?php
                        [$riesgoTexto, $riesgoClass, $riesgoDetalle] = fin_riesgo_comercial($colegio);
                        [$vencimientoTexto, $vencimientoClass] = fin_estado_vencimiento($colegio['fecha_vencimiento'] ?? null);
                        $ufMensual = (float)($colegio['precio_uf_mensual'] ?? 0);
                        $ufAnual = $ufMensual * 12;
                        $colegioId = (int)($colegio['id'] ?? 0);
                        $usuariosColegio = fin_count($pdo, 'usuarios', 'colegio_id = ?', [$colegioId]);
                        $alumnosColegio = fin_count($pdo, 'alumnos', 'colegio_id = ?', [$colegioId]);
                        $casosColegio = fin_count($pdo, 'casos', 'colegio_id = ?', [$colegioId]);
                        ?>

                        <article class="fin-card">
                            <div class="fin-card-head">
                                <div>
                                    <div class="fin-card-title">
                                        <?= e((string)($colegio['nombre'] ?? 'Sin nombre')) ?>
                                    </div>

                                    <div class="fin-meta">
                                        RBD: <?= e(fin_pick($colegio, 'rbd')) ?> ·
                                        <?= e(fin_pick($colegio, 'comuna')) ?> ·
                                        <?= e(fin_pick($colegio, 'region')) ?>
                                    </div>
                                </div>

                                <div>
                                    <span class="fin-badge <?= e($riesgoClass) ?>">
                                        Riesgo <?= e($riesgoTexto) ?>
                                    </span>
                                </div>
                            </div>

                            <div style="margin-top:.7rem;">
                                <span class="fin-badge blue">
                                    <?= e(fin_pick($colegio, 'plan_nombre', 'Sin plan')) ?>
                                </span>

                                <span class="fin-badge soft">
                                    Estado: <?= e(fin_pick($colegio, 'estado_comercial', 'activo')) ?>
                                </span>

                                <span class="fin-badge <?= (int)($colegio['activo'] ?? 1) === 1 ? 'ok' : 'danger' ?>">
                                    <?= (int)($colegio['activo'] ?? 1) === 1 ? 'Activo sistema' : 'Inactivo sistema' ?>
                                </span>

                                <span class="fin-badge <?= e($vencimientoClass) ?>">
                                    <?= e($vencimientoTexto) ?>
                                </span>
                            </div>

                            <div style="margin-top:.35rem;">
                                <span class="fin-badge ok">
                                    MRR: <?= e(fin_money_uf($ufMensual)) ?> UF
                                </span>

                                <span class="fin-badge ok">
                                    ARR: <?= e(fin_money_uf($ufAnual)) ?> UF
                                </span>

                                <span class="fin-badge soft">
                                    Usuarios: <?= number_format($usuariosColegio, 0, ',', '.') ?>
                                </span>

                                <span class="fin-badge soft">
                                    Alumnos: <?= number_format($alumnosColegio, 0, ',', '.') ?>
                                </span>

                                <span class="fin-badge soft">
                                    Casos: <?= number_format($casosColegio, 0, ',', '.') ?>
                                </span>
                            </div>

                            <div class="fin-meta">
                                Vigencia: <?= e(fin_fecha($colegio['fecha_inicio'] ?? null)) ?> al <?= e(fin_fecha($colegio['fecha_vencimiento'] ?? null)) ?>
                            </div>

                            <div class="fin-meta">
                                Contacto: <?= e(fin_pick($colegio, 'contacto_nombre')) ?> ·
                                <?= e(fin_pick($colegio, 'contacto_email')) ?> ·
                                <?= e(fin_pick($colegio, 'contacto_telefono')) ?>
                            </div>

                            <div class="fin-meta">
                                Alerta: <?= e($riesgoDetalle) ?>
                            </div>

                            <div style="margin-top:.8rem;display:flex;gap:.45rem;flex-wrap:wrap;">
                                <a class="fin-link" href="<?= APP_URL ?>/modules/colegios/index.php?edit=<?= $colegioId ?>">
                                    <i class="bi bi-pencil-square"></i>
                                    Editar colegio
                                </a>

                                <a class="fin-link" href="<?= APP_URL ?>/modules/admin/usuarios.php?colegio_id=<?= $colegioId ?>">
                                    <i class="bi bi-person-gear"></i>
                                    Usuarios
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="fin-panel">
            <div class="fin-panel-head">
                <h3 class="fin-panel-title">
                    <i class="bi bi-pie-chart"></i>
                    Distribución por plan
                </h3>
            </div>

            <div class="fin-panel-body">
                <?php if (!$planes): ?>
                    <div class="fin-empty">No hay planes para mostrar.</div>
                <?php else: ?>
                    <?php foreach ($planes as $plan): ?>
                        <?php
                        $mrrPlan = (float)($plan['mrr_uf'] ?? 0);
                        $porcentaje = $mrrUf > 0 ? min(100, round(($mrrPlan / $mrrUf) * 100)) : 0;
                        ?>

                        <div class="fin-plan-row">
                            <div>
                                <div class="fin-card-title"><?= e((string)$plan['plan_nombre']) ?></div>
                                <div class="fin-meta">
                                    <?= number_format((int)$plan['total'], 0, ',', '.') ?> colegio(s) ·
                                    <?= number_format((int)$plan['activos'], 0, ',', '.') ?> activo(s)
                                </div>
                                <div class="fin-progress"><span style="width:<?= (int)$porcentaje ?>%;"></span></div>
                            </div>

                            <div style="text-align:right;">
                                <span class="fin-badge ok"><?= e(fin_money_uf($mrrPlan)) ?> UF</span>
                                <div class="fin-meta"><?= (int)$porcentaje ?>% MRR</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="fin-panel">
            <div class="fin-panel-head">
                <h3 class="fin-panel-title">
                    <i class="bi bi-exclamation-triangle"></i>
                    Alertas comerciales
                </h3>
            </div>

            <div class="fin-panel-body">
                <div class="fin-note">
                    Prioriza los colegios vencidos, próximos a vencer, suspendidos o en demo.
                    El MRR en riesgo considera planes demo y establecimientos con vencimiento dentro de los próximos 30 días.
                </div>

                <div style="margin-top:1rem;display:grid;gap:.55rem;">
                    <span class="fin-badge <?= $totalVencidos > 0 ? 'danger' : 'ok' ?>">
                        <?= number_format($totalVencidos, 0, ',', '.') ?> colegio(s) vencido(s)
                    </span>

                    <span class="fin-badge <?= $totalPorVencer > 0 ? 'warn' : 'ok' ?>">
                        <?= number_format($totalPorVencer, 0, ',', '.') ?> colegio(s) por vencer
                    </span>

                    <span class="fin-badge <?= $totalDemo > 0 ? 'warn' : 'ok' ?>">
                        <?= number_format($totalDemo, 0, ',', '.') ?> colegio(s) demo
                    </span>

                    <span class="fin-badge <?= $totalSuspendidos > 0 ? 'danger' : 'ok' ?>">
                        <?= number_format($totalSuspendidos, 0, ',', '.') ?> suspendido(s) / cerrado(s)
                    </span>
                </div>
            </div>
        </div>

        <div class="fin-panel">
            <div class="fin-panel-head">
                <h3 class="fin-panel-title">
                    <i class="bi bi-database"></i>
                    Uso general plataforma
                </h3>
            </div>

            <div class="fin-panel-body">
                <div class="fin-card">
                    <span class="fin-badge blue">Usuarios: <?= number_format($usuariosTotal, 0, ',', '.') ?></span>
                    <span class="fin-badge blue">Activos: <?= number_format($usuariosActivos, 0, ',', '.') ?></span>
                    <span class="fin-badge soft">Alumnos: <?= number_format($alumnosTotal, 0, ',', '.') ?></span>
                    <span class="fin-badge soft">Casos: <?= number_format($casosTotal, 0, ',', '.') ?></span>
                </div>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
