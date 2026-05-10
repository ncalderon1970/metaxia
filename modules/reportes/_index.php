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

$pageTitle = 'Reportes ejecutivos · Metis';
$pageSubtitle = 'Indicadores institucionales de convivencia escolar';

function rep_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rep_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function rep_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function rep_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y', $ts) : $value;
}

function rep_fecha_hora(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function rep_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Sin dato';
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function rep_corto(?string $value, int $length = 130): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '...' : $value;
}

function rep_badge_class(?string $value): string
{
    return match (mb_strtolower(trim((string)$value))) {
        'rojo', 'alta', 'pendiente', 'vencida', 'vencido' => 'danger',
        'amarillo', 'media', 'en proceso', 'seguimiento', 'investigacion', 'investigación' => 'warn',
        'verde', 'baja', 'cerrado', 'cumplida', 'resuelto', 'corregido' => 'ok',
        default => 'soft',
    };
}

function rep_count(PDO $pdo, string $table, string $where = '', array $params = []): int
{
    if (!rep_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = 'SELECT COUNT(*) FROM ' . rep_quote($table);
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function rep_base_where(PDO $pdo, int $colegioId, array &$params, string $alias = 'c'): array
{
    $where = [];

    if (rep_column_exists($pdo, 'casos', 'colegio_id')) {
        $where[] = $alias . '.colegio_id = ?';
        $params[] = $colegioId;
    }

    return $where;
}

function rep_case_filters(PDO $pdo, int $colegioId, array $filters, array &$params, string $alias = 'c'): string
{
    $where = rep_base_where($pdo, $colegioId, $params, $alias);

    if (($filters['desde'] ?? '') !== '' && rep_column_exists($pdo, 'casos', 'fecha_ingreso')) {
        $where[] = 'DATE(' . $alias . '.fecha_ingreso) >= ?';
        $params[] = $filters['desde'];
    }

    if (($filters['hasta'] ?? '') !== '' && rep_column_exists($pdo, 'casos', 'fecha_ingreso')) {
        $where[] = 'DATE(' . $alias . '.fecha_ingreso) <= ?';
        $params[] = $filters['hasta'];
    }

    if (($filters['estado'] ?? 'todos') !== 'todos' && rep_column_exists($pdo, 'casos', 'estado')) {
        $where[] = $alias . '.estado = ?';
        $params[] = $filters['estado'];
    }

    if (($filters['semaforo'] ?? 'todos') !== 'todos' && rep_column_exists($pdo, 'casos', 'semaforo')) {
        $where[] = $alias . '.semaforo = ?';
        $params[] = $filters['semaforo'];
    }

    if (($filters['prioridad'] ?? 'todos') !== 'todos' && rep_column_exists($pdo, 'casos', 'prioridad')) {
        $where[] = $alias . '.prioridad = ?';
        $params[] = $filters['prioridad'];
    }

    if (($filters['q'] ?? '') !== '') {
        $parts = [];
        foreach (['numero_caso', 'relato', 'contexto', 'denunciante_nombre'] as $column) {
            if (rep_column_exists($pdo, 'casos', $column)) {
                $parts[] = $alias . '.' . rep_quote($column) . ' LIKE ?';
                $params[] = '%' . $filters['q'] . '%';
            }
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

function rep_case_count(PDO $pdo, int $colegioId, array $filters, string $extraWhere = '', array $extraParams = []): int
{
    if (!rep_table_exists($pdo, 'casos')) {
        return 0;
    }

    $params = [];
    $whereSql = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');

    if ($extraWhere !== '') {
        $whereSql .= $whereSql === '' ? 'WHERE ' . $extraWhere : ' AND ' . $extraWhere;
        $params = array_merge($params, $extraParams);
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM casos c {$whereSql}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$filters = [
    'desde' => clean((string)($_GET['desde'] ?? '')),
    'hasta' => clean((string)($_GET['hasta'] ?? '')),
    'estado' => clean((string)($_GET['estado'] ?? 'todos')),
    'semaforo' => clean((string)($_GET['semaforo'] ?? 'todos')),
    'prioridad' => clean((string)($_GET['prioridad'] ?? 'todos')),
    'q' => clean((string)($_GET['q'] ?? '')),
];

$casos = [];
$error = '';

$kpis = [
    'total_casos' => 0,
    'abiertos' => 0,
    'cerrados' => 0,
    'rojos' => 0,
    'alta' => 0,
    'alertas_pendientes' => 0,
    'acciones_pendientes' => 0,
    'acciones_vencidas' => 0,
    'cierres_formales' => 0,
    'evidencias' => 0,
    'participantes' => 0,
    'declaraciones' => 0,
    'comunidad' => 0,
];

try {
    if (!rep_table_exists($pdo, 'casos')) {
        throw new RuntimeException('La tabla casos no existe.');
    }

    $kpis['total_casos'] = rep_case_count($pdo, $colegioId, $filters);

    if (rep_column_exists($pdo, 'casos', 'estado')) {
        $kpis['abiertos'] = rep_case_count($pdo, $colegioId, $filters, "c.estado = 'abierto'");
        $kpis['cerrados'] = rep_case_count($pdo, $colegioId, $filters, "c.estado = 'cerrado'");
    }

    if (rep_column_exists($pdo, 'casos', 'semaforo')) {
        $kpis['rojos'] = rep_case_count($pdo, $colegioId, $filters, "c.semaforo = 'rojo'");
    }

    if (rep_column_exists($pdo, 'casos', 'prioridad')) {
        $kpis['alta'] = rep_case_count($pdo, $colegioId, $filters, "c.prioridad = 'alta'");
    }

    if (rep_table_exists($pdo, 'caso_alertas') && rep_column_exists($pdo, 'caso_alertas', 'estado')) {
        $params = [];
        $where = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');
        $where .= $where === '' ? "WHERE a.estado = 'pendiente'" : " AND a.estado = 'pendiente'";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_alertas a INNER JOIN casos c ON c.id = a.caso_id {$where}");
        $stmt->execute($params);
        $kpis['alertas_pendientes'] = (int)$stmt->fetchColumn();
    }

    if (rep_table_exists($pdo, 'caso_gestion_ejecutiva')) {
        $params = [];
        $where = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');
        if (rep_column_exists($pdo, 'caso_gestion_ejecutiva', 'estado')) {
            $where .= $where === '' ? "WHERE ge.estado IN ('pendiente','en_proceso','en proceso')" : " AND ge.estado IN ('pendiente','en_proceso','en proceso')";
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_gestion_ejecutiva ge INNER JOIN casos c ON c.id = ge.caso_id {$where}");
            $stmt->execute($params);
            $kpis['acciones_pendientes'] = (int)$stmt->fetchColumn();
        }

        if (rep_column_exists($pdo, 'caso_gestion_ejecutiva', 'fecha_compromiso')) {
            $params = [];
            $where = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');
            $where .= $where === '' ? "WHERE ge.fecha_compromiso < CURDATE()" : " AND ge.fecha_compromiso < CURDATE()";
            if (rep_column_exists($pdo, 'caso_gestion_ejecutiva', 'estado')) {
                $where .= " AND ge.estado NOT IN ('cumplida','descartada')";
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_gestion_ejecutiva ge INNER JOIN casos c ON c.id = ge.caso_id {$where}");
            $stmt->execute($params);
            $kpis['acciones_vencidas'] = (int)$stmt->fetchColumn();
        }
    }

    if (rep_table_exists($pdo, 'caso_cierre')) {
        $params = [];
        $where = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_cierre cc INNER JOIN casos c ON c.id = cc.caso_id {$where}");
        $stmt->execute($params);
        $kpis['cierres_formales'] = (int)$stmt->fetchColumn();
    }

    foreach ([
        'caso_evidencias' => 'evidencias',
        'caso_participantes' => 'participantes',
        'caso_declaraciones' => 'declaraciones',
    ] as $table => $key) {
        if (rep_table_exists($pdo, $table)) {
            $params = [];
            $where = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} x INNER JOIN casos c ON c.id = x.caso_id {$where}");
            $stmt->execute($params);
            $kpis[$key] = (int)$stmt->fetchColumn();
        }
    }

    foreach (['alumnos', 'apoderados', 'docentes', 'asistentes'] as $table) {
        if (rep_table_exists($pdo, $table)) {
            if (rep_column_exists($pdo, $table, 'colegio_id')) {
                $kpis['comunidad'] += rep_count($pdo, $table, 'colegio_id = ?', [$colegioId]);
            } else {
                $kpis['comunidad'] += rep_count($pdo, $table);
            }
        }
    }

    $params = [];
    $whereSql = rep_case_filters($pdo, $colegioId, $filters, $params, 'c');

    $select = [
        'c.id',
        rep_column_exists($pdo, 'casos', 'numero_caso') ? 'c.numero_caso' : "CONCAT('CASO-', c.id) AS numero_caso",
        rep_column_exists($pdo, 'casos', 'fecha_ingreso') ? 'c.fecha_ingreso' : 'NULL AS fecha_ingreso',
        rep_column_exists($pdo, 'casos', 'estado') ? 'c.estado' : 'NULL AS estado',
        rep_column_exists($pdo, 'casos', 'semaforo') ? 'c.semaforo' : 'NULL AS semaforo',
        rep_column_exists($pdo, 'casos', 'prioridad') ? 'c.prioridad' : 'NULL AS prioridad',
        rep_column_exists($pdo, 'casos', 'relato') ? 'c.relato' : 'NULL AS relato',
        rep_column_exists($pdo, 'casos', 'updated_at') ? 'c.updated_at' : 'NULL AS updated_at',
    ];

    $joinEstado = '';
    if (rep_table_exists($pdo, 'estado_caso') && rep_column_exists($pdo, 'casos', 'estado_caso_id')) {
        $select[] = 'ec.nombre AS estado_formal';
        $joinEstado = ' LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id ';
    } else {
        $select[] = 'NULL AS estado_formal';
    }

    if (rep_table_exists($pdo, 'caso_alertas')) {
        $select[] = "(SELECT COUNT(*) FROM caso_alertas a WHERE a.caso_id = c.id AND (a.estado = 'pendiente' OR a.estado IS NULL)) AS alertas_pendientes";
    } else {
        $select[] = '0 AS alertas_pendientes';
    }

    if (rep_table_exists($pdo, 'caso_gestion_ejecutiva')) {
        $select[] = "(SELECT COUNT(*) FROM caso_gestion_ejecutiva ge WHERE ge.caso_id = c.id AND (ge.estado IN ('pendiente','en_proceso','en proceso') OR ge.estado IS NULL)) AS acciones_abiertas";
    } else {
        $select[] = '0 AS acciones_abiertas';
    }

    if (rep_table_exists($pdo, 'caso_evidencias')) {
        $select[] = "(SELECT COUNT(*) FROM caso_evidencias ev WHERE ev.caso_id = c.id) AS evidencias";
    } else {
        $select[] = '0 AS evidencias';
    }

    $stmt = $pdo->prepare('
        SELECT ' . implode(",\n               ", $select) . "
        FROM casos c
        {$joinEstado}
        {$whereSql}
        ORDER BY c.id DESC
        LIMIT 150
    ");
    $stmt->execute($params);
    $casos = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$queryString  = http_build_query(array_filter($filters, static fn($v) => $v !== '' && $v !== 'todos'));
$exportUrl    = APP_URL . '/modules/reportes/exportar_csv.php' . ($queryString ? '?' . $queryString : '');
$pdfEstUrl    = APP_URL . '/modules/informes/pdf_estadistico.php' . ($queryString ? '?' . $queryString : '');

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.rep-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.rep-hero h2 { margin: 0 0 .45rem; font-size: 1.85rem; font-weight: 900; }
.rep-hero p { margin: 0; color: #bfdbfe; max-width: 900px; line-height: 1.55; }
.rep-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
.rep-btn { display: inline-flex; align-items: center; gap: .42rem; border-radius: 999px; padding: .62rem 1rem; font-size: .84rem; font-weight: 900; text-decoration: none; border: 1px solid rgba(255,255,255,.28); color: #fff; background: rgba(255,255,255,.12); }
.rep-btn.green { background:#059669; border-color:#10b981; }
.rep-btn:hover { color:#fff; }
.rep-kpis { display:grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap:.9rem; margin-bottom:1.2rem; }
.rep-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:1rem; box-shadow:0 12px 28px rgba(15,23,42,.06); }
.rep-kpi span { color:#64748b; display:block; font-size:.68rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
.rep-kpi strong { display:block; color:#0f172a; font-size:1.85rem; line-height:1; margin-top:.35rem; }
.rep-panel { background:#fff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 12px 28px rgba(15,23,42,.06); overflow:hidden; margin-bottom:1.2rem; }
.rep-panel-head { padding:1rem 1.2rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.rep-panel-title { margin:0; color:#0f172a; font-size:1rem; font-weight:900; }
.rep-panel-body { padding:1.2rem; }
.rep-filter { display:grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap:.8rem; align-items:end; }
.rep-label { display:block; font-size:.76rem; font-weight:900; color:#334155; margin-bottom:.35rem; }
.rep-control { width:100%; border:1px solid #cbd5e1; border-radius:13px; padding:.65rem .78rem; outline:none; background:#fff; font-size:.9rem; }
.rep-submit, .rep-link { display:inline-flex; align-items:center; justify-content:center; gap:.35rem; border:0; background:#0f172a; color:#fff; border-radius:8px; padding:.48rem .85rem; font-weight:600; font-size:.8rem; text-decoration:none; white-space:nowrap; cursor:pointer; min-width:60px; }
.rep-link { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.rep-link.green { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
.rep-link.warn { background:#fffbeb; color:#92400e; border-color:#fde68a; }
.rep-actions-cell { display:flex; gap:.35rem; align-items:center; flex-wrap:nowrap; }
.rep-table-scroll { width:100%; overflow-x:auto; }
.rep-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.86rem; }
.rep-table th { background:#f8fafc; color:#64748b; font-size:.68rem; text-transform:uppercase; letter-spacing:.08em; padding:.75rem; border-bottom:1px solid #e2e8f0; white-space:nowrap; text-align:left; }
.rep-table td { padding:.85rem .75rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.rep-main { color:#0f172a; font-weight:900; }
.rep-muted { color:#64748b; font-size:.76rem; margin-top:.15rem; line-height:1.35; }
.rep-badge { display:inline-flex; align-items:center; border-radius:999px; padding:.24rem .62rem; font-size:.72rem; font-weight:900; border:1px solid #e2e8f0; background:#fff; color:#475569; white-space:nowrap; margin:.12rem; }
.rep-badge.ok { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.rep-badge.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.rep-badge.danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.rep-badge.soft { background:#f8fafc; color:#475569; }
.rep-error { border-radius:14px; padding:.9rem 1rem; margin-bottom:1rem; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-weight:800; }
.rep-empty { text-align:center; padding:2.5rem 1rem; color:#94a3b8; }
@media (max-width: 1300px) { .rep-kpis { grid-template-columns: repeat(3, minmax(0,1fr)); } .rep-filter { grid-template-columns: repeat(3, minmax(0,1fr)); } }
@media (max-width: 760px) { .rep-kpis, .rep-filter { grid-template-columns:1fr; } .rep-hero { padding:1.35rem; } }
</style>

<section class="rep-hero">
    <h2>Reportes ejecutivos de convivencia</h2>
    <p>
        Indicadores consolidados por período, estado, prioridad y semáforo. Esta vista permite revisar la carga operacional,
        riesgo, gestión pendiente, cierres formales y trazabilidad general del sistema.
    </p>

    <div class="rep-actions">
        <a class="rep-btn" href="<?= APP_URL ?>/modules/dashboard/index.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <a class="rep-btn" href="<?= APP_URL ?>/modules/denuncias/index.php"><i class="bi bi-megaphone"></i>Denuncias</a>
        <a class="rep-btn green" href="<?= e($exportUrl) ?>"><i class="bi bi-file-earmark-spreadsheet"></i>Exportar CSV</a>
        <a class="rep-btn" href="<?= e($pdfEstUrl) ?>" target="_blank" style="background:rgba(239,68,68,.18);border-color:rgba(239,68,68,.5);"><i class="bi bi-file-earmark-pdf"></i>PDF Estadístico</a>
        <a class="rep-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php"><i class="bi bi-shield-check"></i>Diagnóstico</a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="rep-error"><?= e($error) ?></div>
<?php endif; ?>

<section class="rep-panel">
    <div class="rep-panel-head">
        <h3 class="rep-panel-title"><i class="bi bi-funnel"></i> Filtros del reporte</h3>
        <a class="rep-link" href="<?= APP_URL ?>/modules/reportes/index.php">Limpiar filtros</a>
    </div>
    <div class="rep-panel-body">
        <form method="get" class="rep-filter">
            <div>
                <label class="rep-label">Desde</label>
                <input class="rep-control" type="date" name="desde" value="<?= e($filters['desde']) ?>">
            </div>
            <div>
                <label class="rep-label">Hasta</label>
                <input class="rep-control" type="date" name="hasta" value="<?= e($filters['hasta']) ?>">
            </div>
            <div>
                <label class="rep-label">Estado</label>
                <select class="rep-control" name="estado">
                    <option value="todos" <?= $filters['estado'] === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="abierto" <?= $filters['estado'] === 'abierto' ? 'selected' : '' ?>>Abierto</option>
                    <option value="cerrado" <?= $filters['estado'] === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                </select>
            </div>
            <div>
                <label class="rep-label">Semáforo</label>
                <select class="rep-control" name="semaforo">
                    <option value="todos" <?= $filters['semaforo'] === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="verde" <?= $filters['semaforo'] === 'verde' ? 'selected' : '' ?>>Verde</option>
                    <option value="amarillo" <?= $filters['semaforo'] === 'amarillo' ? 'selected' : '' ?>>Amarillo</option>
                    <option value="rojo" <?= $filters['semaforo'] === 'rojo' ? 'selected' : '' ?>>Rojo</option>
                </select>
            </div>
            <div>
                <label class="rep-label">Prioridad</label>
                <select class="rep-control" name="prioridad">
                    <option value="todos" <?= $filters['prioridad'] === 'todos' ? 'selected' : '' ?>>Todas</option>
                    <option value="baja" <?= $filters['prioridad'] === 'baja' ? 'selected' : '' ?>>Baja</option>
                    <option value="media" <?= $filters['prioridad'] === 'media' ? 'selected' : '' ?>>Media</option>
                    <option value="alta" <?= $filters['prioridad'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                </select>
            </div>
            <div>
                <label class="rep-label">Buscar</label>
                <input class="rep-control" type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="N° caso, relato, denunciante">
            </div>
            <div>
                <button class="rep-submit" type="submit"><i class="bi bi-search"></i>Filtrar</button>
            </div>
        </form>
    </div>
</section>

<section class="rep-kpis">
    <div class="rep-kpi"><span>Total casos</span><strong><?= number_format($kpis['total_casos'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Abiertos</span><strong><?= number_format($kpis['abiertos'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Cerrados</span><strong><?= number_format($kpis['cerrados'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Semáforo rojo</span><strong style="color:#b91c1c;"><?= number_format($kpis['rojos'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Prioridad alta</span><strong style="color:#92400e;"><?= number_format($kpis['alta'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Alertas pendientes</span><strong style="color:#b91c1c;"><?= number_format($kpis['alertas_pendientes'], 0, ',', '.') ?></strong></div>
</section>

<section class="rep-kpis">
    <div class="rep-kpi"><span>Acciones abiertas</span><strong><?= number_format($kpis['acciones_pendientes'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Acciones vencidas</span><strong style="color:#b91c1c;"><?= number_format($kpis['acciones_vencidas'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Cierres formales</span><strong><?= number_format($kpis['cierres_formales'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Evidencias</span><strong><?= number_format($kpis['evidencias'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Declaraciones</span><strong><?= number_format($kpis['declaraciones'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Comunidad</span><strong><?= number_format($kpis['comunidad'], 0, ',', '.') ?></strong></div>
</section>

<section class="rep-panel">
    <div class="rep-panel-head">
        <h3 class="rep-panel-title"><i class="bi bi-table"></i> Casos del reporte</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <span class="rep-badge"><?= number_format(count($casos), 0, ',', '.') ?> visible(s)</span>
            <a class="rep-link green" href="<?= e($exportUrl) ?>"><i class="bi bi-download"></i>CSV</a>
        </div>
    </div>

    <?php if (!$casos): ?>
        <div class="rep-empty">No hay casos para los filtros seleccionados.</div>
    <?php else: ?>
        <div class="rep-table-scroll">
            <table class="rep-table">
                <thead>
                    <tr>
                        <th>Caso</th>
                        <th>Ingreso</th>
                        <th>Estado</th>
                        <th>Riesgo</th>
                        <th>Gestión</th>
                        <th>Síntesis</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($casos as $caso): ?>
                        <tr>
                            <td>
                                <div class="rep-main"><?= e((string)$caso['numero_caso']) ?></div>
                                <div class="rep-muted">Actualizado: <?= e(rep_fecha_hora((string)($caso['updated_at'] ?? ''))) ?></div>
                            </td>
                            <td><?= e(rep_fecha((string)($caso['fecha_ingreso'] ?? ''))) ?></td>
                            <td>
                                <span class="rep-badge <?= e(rep_badge_class((string)($caso['estado'] ?? ''))) ?>">
                                    <?= e((string)($caso['estado_formal'] ?: rep_label((string)($caso['estado'] ?? '')))) ?>
                                </span>
                            </td>
                            <td>
                                <span class="rep-badge <?= e(rep_badge_class((string)($caso['semaforo'] ?? ''))) ?>">
                                    <?= e(rep_label((string)($caso['semaforo'] ?? ''))) ?>
                                </span>
                                <span class="rep-badge <?= e(rep_badge_class((string)($caso['prioridad'] ?? ''))) ?>">
                                    <?= e(rep_label((string)($caso['prioridad'] ?? ''))) ?>
                                </span>
                            </td>
                            <td>
                                <span class="rep-badge <?= ((int)$caso['alertas_pendientes'] > 0) ? 'danger' : 'ok' ?>">
                                    <?= (int)$caso['alertas_pendientes'] ?> alerta(s)
                                </span>
                                <span class="rep-badge <?= ((int)$caso['acciones_abiertas'] > 0) ? 'warn' : 'ok' ?>">
                                    <?= (int)$caso['acciones_abiertas'] ?> acción(es)
                                </span>
                                <span class="rep-badge soft">
                                    <?= (int)$caso['evidencias'] ?> evidencia(s)
                                </span>
                            </td>
                            <td>
                                <div class="rep-muted"><?= e(rep_corto((string)($caso['relato'] ?? ''), 160)) ?></div>
                            </td>
                            <td>
                                <div class="rep-actions-cell">
                                    <a class="rep-link" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['id'] ?>&tab=resumen">Ver</a>
                                    <a class="rep-link warn" href="<?= APP_URL ?>/modules/denuncias/reporte_ejecutivo.php?id=<?= (int)$caso['id'] ?>">HTML</a>
                                    <a class="rep-link" href="<?= APP_URL ?>/modules/informes/informe_caso_pdf.php?id=<?= (int)$caso['id'] ?>" target="_blank" style="background:#fef2f2;color:#b91c1c;border-color:#fecaca;">PDF</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
