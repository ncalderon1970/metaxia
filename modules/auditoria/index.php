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

$pageTitle = 'Auditoría · Metis';
$pageSubtitle = 'Bitácora de acciones, trazabilidad y control operativo del sistema';

function aud_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i:s', $ts) : $value;
}

function aud_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function aud_corto(?string $value, int $length = 120): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return mb_strlen($value) > $length
        ? mb_substr($value, 0, $length) . '...'
        : $value;
}

function aud_badge(string $modulo): string
{
    return match (strtolower($modulo)) {
        'denuncias' => 'blue',
        'seguimiento' => 'green',
        'alertas' => 'red',
        'evidencias' => 'teal',
        'reportes' => 'purple',
        'admin' => 'dark',
        'importar' => 'orange',
        default => 'soft',
    };
}

$error = '';

$q = clean((string)($_GET['q'] ?? ''));
$moduloFiltro = clean((string)($_GET['modulo'] ?? ''));
$usuarioFiltro = (int)($_GET['usuario_id'] ?? 0);
$desde = clean((string)($_GET['desde'] ?? ''));
$hasta = clean((string)($_GET['hasta'] ?? ''));

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

$exportParams = [];

if ($q !== '') {
    $exportParams['q'] = $q;
}

if ($moduloFiltro !== '') {
    $exportParams['modulo'] = $moduloFiltro;
}

if ($usuarioFiltro > 0) {
    $exportParams['usuario_id'] = $usuarioFiltro;
}

if ($desde !== '') {
    $exportParams['desde'] = $desde;
}

if ($hasta !== '') {
    $exportParams['hasta'] = $hasta;
}

$urlExportAuditoria = APP_URL . '/modules/auditoria/exportar_csv.php?' . http_build_query($exportParams);

$totalLogs = 0;
$totalHoy = 0;
$totalUsuarios = 0;
$totalModulos = 0;

$logs = [];
$modulos = [];
$usuarios = [];
$porModulo = [];
$porUsuario = [];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM logs_sistema l
        {$whereSql}
    ");
    $stmt->execute($params);
    $totalLogs = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM logs_sistema l
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
          AND DATE(l.created_at) = CURDATE()
    ");
    $stmt->execute([$colegioId]);
    $totalHoy = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT l.usuario_id)
        FROM logs_sistema l
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
          AND l.usuario_id IS NOT NULL
    ");
    $stmt->execute([$colegioId]);
    $totalUsuarios = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT l.modulo)
        FROM logs_sistema l
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
    ");
    $stmt->execute([$colegioId]);
    $totalModulos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT DISTINCT modulo
        FROM logs_sistema
        WHERE (colegio_id = ? OR colegio_id IS NULL)
          AND modulo IS NOT NULL
          AND modulo <> ''
        ORDER BY modulo ASC
    ");
    $stmt->execute([$colegioId]);
    $modulos = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.nombre, u.email
        FROM logs_sistema l
        INNER JOIN usuarios u ON u.id = l.usuario_id
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
        ORDER BY u.nombre ASC
    ");
    $stmt->execute([$colegioId]);
    $usuarios = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT l.modulo, COUNT(*) AS total
        FROM logs_sistema l
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
        GROUP BY l.modulo
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmt->execute([$colegioId]);
    $porModulo = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT COALESCE(u.nombre, 'Sistema') AS usuario_nombre, COUNT(*) AS total
        FROM logs_sistema l
        LEFT JOIN usuarios u ON u.id = l.usuario_id
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
        GROUP BY COALESCE(u.nombre, 'Sistema')
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmt->execute([$colegioId]);
    $porUsuario = $stmt->fetchAll();

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
        LIMIT 300
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Error al cargar auditoría: ' . $e->getMessage();
}

$maxModulo = 1;
foreach ($porModulo as $row) {
    $maxModulo = max($maxModulo, (int)$row['total']);
}

$maxUsuario = 1;
foreach ($porUsuario as $row) {
    $maxUsuario = max($maxUsuario, (int)$row['total']);
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.aud-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #111827 58%, #374151 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.aud-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.8rem;
    font-weight: 900;
}

.aud-hero p {
    margin: 0;
    color: #d1d5db;
    max-width: 820px;
    line-height: 1.55;
}

.aud-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.aud-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.aud-btn:hover {
    color: #fff;
}

.aud-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.aud-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.aud-kpi span {
    color: #64748b;
    display: block;
    font-size: .7rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.aud-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 2rem;
    line-height: 1;
    margin-top: .35rem;
}

.aud-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.aud-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.aud-panel-title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    font-weight: 900;
}

.aud-panel-body {
    padding: 1.2rem;
}

.aud-filter {
    display: grid;
    grid-template-columns: 1.2fr .8fr .8fr .75fr .75fr auto auto;
    gap: .8rem;
    align-items: end;
}

.aud-label {
    display: block;
    font-size: .76rem;
    font-weight: 900;
    color: #334155;
    margin-bottom: .35rem;
}

.aud-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .62rem .75rem;
    font-size: .88rem;
    outline: none;
}

.aud-submit,
.aud-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 999px;
    padding: .64rem 1rem;
    font-weight: 900;
    font-size: .84rem;
    text-decoration: none;
    white-space: nowrap;
}

.aud-link {
    background: #f8fafc;
    color: #0f172a;
    border: 1px solid #cbd5e1;
}

.aud-export {
    background: #ecfdf5;
    color: #047857;
    border-color: #bbf7d0;
}

.aud-layout {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.2rem;
}

.aud-bar-row {
    margin-bottom: .85rem;
}

.aud-bar-top {
    display: flex;
    justify-content: space-between;
    gap: .8rem;
    font-size: .86rem;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: .35rem;
}

.aud-bar-track {
    width: 100%;
    height: 13px;
    border-radius: 999px;
    background: #eef2f7;
    overflow: hidden;
}

.aud-bar-fill {
    height: 100%;
    border-radius: 999px;
    background: #111827;
}

.aud-table-scroll {
    width: 100%;
    overflow-x: auto;
}

.aud-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: .86rem;
}

.aud-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    padding: .75rem;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.aud-table td {
    padding: .85rem .75rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
}

.aud-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .6rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
}

.aud-badge.blue { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
.aud-badge.green { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.aud-badge.red { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.aud-badge.teal { background:#ecfeff; border-color:#99f6e4; color:#0f766e; }
.aud-badge.purple { background:#f5f3ff; border-color:#ddd6fe; color:#6d28d9; }
.aud-badge.orange { background:#fff7ed; border-color:#fed7aa; color:#c2410c; }
.aud-badge.dark { background:#f8fafc; border-color:#cbd5e1; color:#0f172a; }

.aud-muted {
    color: #64748b;
    font-size: .76rem;
}

.aud-main {
    font-weight: 900;
    color: #0f172a;
}

.aud-error {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 800;
}

.aud-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}

@media (max-width: 1350px) {
    .aud-filter {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .aud-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 850px) {
    .aud-filter,
    .aud-layout,
    .aud-kpis {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="aud-hero">
    <h2>Auditoría y bitácora</h2>
    <p>
        Registro de acciones realizadas en Metis: creación de casos, seguimiento,
        alertas, evidencias, administración, importaciones y accesos a documentos.
    </p>

    <div class="aud-actions">
        <a class="aud-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>

        <a class="aud-btn" href="<?= APP_URL ?>/modules/reportes/index.php">
            <i class="bi bi-bar-chart"></i>
            Reportes
        </a>

        <a class="aud-btn" href="<?= APP_URL ?>/modules/admin/index.php">
            <i class="bi bi-gear"></i>
            Administración
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="aud-error">
        <i class="bi bi-exclamation-triangle"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="aud-kpis">
    <div class="aud-kpi"><span>Eventos filtrados</span><strong><?= number_format($totalLogs, 0, ',', '.') ?></strong></div>
    <div class="aud-kpi"><span>Eventos hoy</span><strong><?= number_format($totalHoy, 0, ',', '.') ?></strong></div>
    <div class="aud-kpi"><span>Usuarios activos</span><strong><?= number_format($totalUsuarios, 0, ',', '.') ?></strong></div>
    <div class="aud-kpi"><span>Módulos usados</span><strong><?= number_format($totalModulos, 0, ',', '.') ?></strong></div>
</section>

<section class="aud-panel">
    <div class="aud-panel-head">
        <h3 class="aud-panel-title">
            <i class="bi bi-funnel"></i>
            Filtros de auditoría
        </h3>

        <a class="aud-link" href="<?= APP_URL ?>/modules/auditoria/index.php">
            Limpiar
        </a>
    </div>

    <div class="aud-panel-body">
        <form method="get" class="aud-filter">
            <div>
                <label class="aud-label">Buscar</label>
                <input class="aud-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Módulo, acción, entidad, descripción o usuario">
            </div>

            <div>
                <label class="aud-label">Módulo</label>
                <select class="aud-control" name="modulo">
                    <option value="">Todos</option>
                    <?php foreach ($modulos as $m): ?>
                        <?php $modulo = (string)($m['modulo'] ?? ''); ?>
                        <option value="<?= e($modulo) ?>" <?= $moduloFiltro === $modulo ? 'selected' : '' ?>>
                            <?= e(aud_label($modulo)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="aud-label">Usuario</label>
                <select class="aud-control" name="usuario_id">
                    <option value="0">Todos</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $usuarioFiltro === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="aud-label">Desde</label>
                <input class="aud-control" type="date" name="desde" value="<?= e($desde) ?>">
            </div>

            <div>
                <label class="aud-label">Hasta</label>
                <input class="aud-control" type="date" name="hasta" value="<?= e($hasta) ?>">
            </div>

            <div>
                <button class="aud-submit" type="submit">
                    <i class="bi bi-search"></i>
                    Filtrar
                </button>
            </div>

            <div>
                <a class="aud-link aud-export" href="<?= e($urlExportAuditoria) ?>">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    Exportar CSV
                </a>
            </div>
        </form>
    </div>
</section>

<div class="aud-layout">
    <section class="aud-panel">
        <div class="aud-panel-head">
            <h3 class="aud-panel-title">Actividad por módulo</h3>
        </div>

        <div class="aud-panel-body">
            <?php if (!$porModulo): ?>
                <div class="aud-empty">Sin actividad registrada.</div>
            <?php else: ?>
                <?php foreach ($porModulo as $row): ?>
                    <?php
                    $total = (int)$row['total'];
                    $percent = ($total / $maxModulo) * 100;
                    ?>
                    <div class="aud-bar-row">
                        <div class="aud-bar-top">
                            <span><?= e(aud_label((string)$row['modulo'])) ?></span>
                            <span><?= number_format($total, 0, ',', '.') ?></span>
                        </div>
                        <div class="aud-bar-track">
                            <div class="aud-bar-fill" style="width:<?= (float)$percent ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="aud-panel">
        <div class="aud-panel-head">
            <h3 class="aud-panel-title">Actividad por usuario</h3>
        </div>

        <div class="aud-panel-body">
            <?php if (!$porUsuario): ?>
                <div class="aud-empty">Sin actividad registrada.</div>
            <?php else: ?>
                <?php foreach ($porUsuario as $row): ?>
                    <?php
                    $total = (int)$row['total'];
                    $percent = ($total / $maxUsuario) * 100;
                    ?>
                    <div class="aud-bar-row">
                        <div class="aud-bar-top">
                            <span><?= e($row['usuario_nombre']) ?></span>
                            <span><?= number_format($total, 0, ',', '.') ?></span>
                        </div>
                        <div class="aud-bar-track">
                            <div class="aud-bar-fill" style="width:<?= (float)$percent ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="aud-panel">
    <div class="aud-panel-head">
        <h3 class="aud-panel-title">Registro de eventos</h3>
        <span class="aud-muted">Se muestran hasta 300 eventos</span>
    </div>

    <?php if (!$logs): ?>
        <div class="aud-empty">
            No hay eventos con los criterios actuales.
        </div>
    <?php else: ?>
        <div class="aud-table-scroll">
            <table class="aud-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Módulo</th>
                        <th>Acción</th>
                        <th>Usuario</th>
                        <th>Entidad</th>
                        <th>Descripción</th>
                        <th>IP</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php $modulo = (string)($log['modulo'] ?? 'sistema'); ?>

                        <tr>
                            <td><div class="aud-main"><?= e(aud_fecha((string)$log['created_at'])) ?></div></td>

                            <td>
                                <span class="aud-badge <?= e(aud_badge($modulo)) ?>">
                                    <?= e(aud_label($modulo)) ?>
                                </span>
                            </td>

                            <td><div class="aud-main"><?= e(aud_label((string)$log['accion'])) ?></div></td>

                            <td>
                                <?php if (!empty($log['usuario_nombre'])): ?>
                                    <div class="aud-main"><?= e($log['usuario_nombre']) ?></div>
                                    <div class="aud-muted"><?= e($log['usuario_email'] ?? '') ?></div>
                                <?php else: ?>
                                    <span class="aud-muted">Sistema</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($log['entidad'])): ?>
                                    <div class="aud-main"><?= e($log['entidad']) ?></div>
                                    <?php if (!empty($log['entidad_id'])): ?>
                                        <div class="aud-muted">ID <?= (int)$log['entidad_id'] ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="aud-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td><?= e(aud_corto((string)($log['descripcion'] ?? ''), 180)) ?></td>
                            <td><span class="aud-muted"><?= e($log['ip'] ?? '-') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>