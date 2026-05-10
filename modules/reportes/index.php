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

function rep_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function rep_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $allowed = [
        'casos', 'caso_alertas', 'caso_gestion_ejecutiva', 'caso_cierre',
        'caso_evidencias', 'caso_participantes', 'caso_declaraciones',
        'caso_pauta_riesgo', 'caso_clasificacion_normativa',
        'alumnos', 'apoderados', 'docentes', 'asistentes', 'estado_caso'
    ];

    if (!in_array($table, $allowed, true)) {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $pdo->query('SELECT 1 FROM ' . rep_quote($table) . ' LIMIT 1');
        return $cache[$table] = true;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function rep_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $schema = [
        'casos' => [
            'id','colegio_id','numero_caso','fecha_ingreso','created_at','estado','estado_caso_id',
            'prioridad','relato','contexto','denunciante_nombre','fecha_hora_incidente'
        ],
        'caso_alertas' => ['id','caso_id','estado','nivel'],
        'caso_gestion_ejecutiva' => ['id','caso_id','estado','fecha_compromiso'],
        'caso_cierre' => ['id','caso_id','colegio_id','estado_cierre','fecha_cierre'],
        'caso_evidencias' => ['id','caso_id'],
        'caso_participantes' => ['id','caso_id','rol_en_caso'],
        'caso_declaraciones' => ['id','caso_id'],
        'caso_pauta_riesgo' => ['id','caso_id','nivel_final','puntaje_total'],
        'caso_clasificacion_normativa' => ['id','caso_id','tipo_conducta','violencia_sexual'],
        'alumnos' => ['id','colegio_id'],
        'apoderados' => ['id','colegio_id'],
        'docentes' => ['id','colegio_id'],
        'asistentes' => ['id','colegio_id'],
    ];

    return in_array($column, $schema[$table] ?? [], true);
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
];

$error = '';

$kpis = [
    'total_casos' => 0,
    'abiertos' => 0,
    'cerrados' => 0,
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

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$queryString  = http_build_query(array_filter($filters, static fn($v) => $v !== ''));
$exportUrl    = APP_URL . '/modules/reportes/exportar_csv.php' . ($queryString ? '?' . $queryString : '');
$pdfEstUrl    = APP_URL . '/modules/informes/pdf_estadistico.php' . ($queryString ? '?' . $queryString : '');


// ── Datos para gráficos ──────────────────────────────────────
$graficos = [
    'casos_por_mes'        => [],
    'casos_por_mes_hecho'  => [],
    'develaciones_tardias' => 0,
    'por_nivel_riesgo'     => ['bajo'=>0,'medio'=>0,'alto'=>0,'critico'=>0],
    'por_tipo'             => [],
    'por_estado'           => [],
    'tiempo_resolucion'    => [],
];

try {
    // Casos por mes (últimos 12 meses)
    $sM = $pdo->prepare("
        SELECT DATE_FORMAT(COALESCE(fecha_ingreso, created_at), '%Y-%m') AS mes,
               COUNT(*) AS total
        FROM casos WHERE colegio_id=?
          AND COALESCE(fecha_ingreso, created_at) >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY mes ORDER BY mes ASC
    ");
    $sM->execute([$colegioId]);
    foreach ($sM->fetchAll() as $r) {
        $graficos['casos_por_mes'][$r['mes']] = (int)$r['total'];
    }

    // Casos por mes del hecho (fecha_hora_incidente)
    try {
        if (rep_column_exists($pdo, 'casos', 'fecha_hora_incidente')) {
            $sH = $pdo->prepare("
                SELECT DATE_FORMAT(fecha_hora_incidente, '%Y-%m') AS mes, COUNT(*) AS total
                FROM casos WHERE colegio_id=?
                  AND fecha_hora_incidente IS NOT NULL
                  AND fecha_hora_incidente <= NOW()
                  AND estado != 'borrador'
                GROUP BY mes ORDER BY mes ASC
            ");
            $sH->execute([$colegioId]);
            foreach ($sH->fetchAll() as $r) {
                $graficos['casos_por_mes_hecho'][$r['mes']] = (int)$r['total'];
            }

            // Develaciones tardías: hecho registrado >30 días después de ocurrido
            $sD = $pdo->prepare("
                SELECT COUNT(*) FROM casos
                WHERE colegio_id=?
                  AND fecha_hora_incidente IS NOT NULL
                  AND fecha_hora_incidente <= NOW()
                  AND DATEDIFF(DATE(COALESCE(fecha_ingreso, created_at)), DATE(fecha_hora_incidente)) > 30
                  AND estado != 'borrador'
            ");
            $sD->execute([$colegioId]);
            $graficos['develaciones_tardias'] = (int)$sD->fetchColumn();
        }
    } catch (Throwable $e) {}

    // Por nivel de riesgo (pauta)
    try {
        $sR = $pdo->prepare("SELECT nivel_final, COUNT(*) AS n
            FROM caso_pauta_riesgo pr
            INNER JOIN casos c ON c.id=pr.caso_id
            WHERE c.colegio_id=? AND pr.puntaje_total > 0
            GROUP BY nivel_final");
        $sR->execute([$colegioId]);
        foreach ($sR->fetchAll() as $r) {
            $k = strtolower(trim((string)($r['nivel_final']??'')));
            if (isset($graficos['por_nivel_riesgo'][$k]))
                $graficos['por_nivel_riesgo'][$k] = (int)$r['n'];
        }
    } catch (Throwable $e) {}

    // Por tipo de conducta (clasificacion)
    try {
        $sTc = $pdo->prepare("SELECT
                CASE cn.tipo_conducta
                    WHEN 'maltrato_escolar'      THEN 'Maltrato escolar'
                    WHEN 'acoso_escolar'         THEN 'Acoso escolar'
                    WHEN 'violencia_fisica'      THEN 'Violencia física'
                    WHEN 'violencia_psicologica' THEN 'Violencia psicológica'
                    WHEN 'ciberacoso'            THEN 'Ciberacoso'
                    WHEN 'discriminacion'        THEN 'Discriminación'
                    WHEN 'violencia_sexual'      THEN 'Violencia sexual'
                    WHEN 'conflicto_convivencia' THEN 'Conflicto convivencia'
                    ELSE cn.tipo_conducta
                END AS tipo_conducta,
                COUNT(*) AS n
            FROM caso_clasificacion_normativa cn
            INNER JOIN casos c ON c.id=cn.caso_id
            WHERE c.colegio_id=? AND cn.tipo_conducta IS NOT NULL AND cn.tipo_conducta!=''
            GROUP BY cn.tipo_conducta ORDER BY n DESC LIMIT 8");
        $sTc->execute([$colegioId]);
        foreach ($sTc->fetchAll() as $r) {
            $graficos['por_tipo'][$r['tipo_conducta']] = (int)$r['n'];
        }
    } catch (Throwable $e) {}

    // Por estado formal — usa estado_caso.nombre o casos.estado como fallback
    $sSt = $pdo->prepare("SELECT
        COALESCE(ec.nombre, CONCAT(UPPER(LEFT(c.estado,1)), LOWER(SUBSTR(c.estado,2))), 'Sin estado') AS nombre,
        COUNT(*) AS n
        FROM casos c LEFT JOIN estado_caso ec ON ec.id=c.estado_caso_id
        WHERE c.colegio_id=? AND c.estado != 'borrador'
        GROUP BY nombre ORDER BY n DESC");
    $sSt->execute([$colegioId]);
    foreach ($sSt->fetchAll() as $r) {
        if (($r['nombre'] ?? '') !== '') {
            $graficos['por_estado'][$r['nombre']] = (int)$r['n'];
        }
    }

} catch (Throwable $e) {}

$graficosJson = json_encode($graficos, JSON_UNESCAPED_UNICODE);

// Debug: contar datos disponibles por gráfico
$graficosDebug = [
    'casos_mes'   => array_sum($graficos['casos_por_mes']),
    'riesgo'      => array_sum($graficos['por_nivel_riesgo']),
    'tipo'        => array_sum($graficos['por_tipo']),
    'estado'      => array_sum($graficos['por_estado']),
];

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
.rep-btn { display: inline-flex; align-items: center; gap: .42rem; border-radius: 7px; padding: .62rem 1rem; font-size: .84rem; font-weight: 900; text-decoration: none; border: 1px solid rgba(255,255,255,.28); color: #fff; background: rgba(255,255,255,.12); }
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
.rep-label { display:block; font-size:.76rem; font-weight:900; color:#334155; margin-bottom:.35rem; }
.rep-control { border:1px solid #cbd5e1; border-radius:13px; padding:.65rem .78rem; outline:none; background:#fff; font-size:.9rem; }
.rep-submit, .rep-link { display:inline-flex; align-items:center; justify-content:center; gap:.35rem; border:0; background:#0f172a; color:#fff; border-radius:8px; padding:.48rem .85rem; font-weight:600; font-size:.8rem; text-decoration:none; white-space:nowrap; cursor:pointer; min-width:60px; }
.rep-link { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.rep-link.green { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
.rep-link.warn { background:#fffbeb; color:#92400e; border-color:#fde68a; }
.rep-badge { display:inline-flex; align-items:center; border-radius:7px; padding:.24rem .62rem; font-size:.72rem; font-weight:900; border:1px solid #e2e8f0; background:#fff; color:#475569; white-space:nowrap; margin:.12rem; }
.rep-error { border-radius:14px; padding:.9rem 1rem; margin-bottom:1rem; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-weight:800; }
.rep-empty { text-align:center; padding:2.5rem 1rem; color:#94a3b8; }
@media (max-width: 1300px) { .rep-kpis { grid-template-columns: repeat(3, minmax(0,1fr)); } }
@media (max-width: 760px) { .rep-kpis { grid-template-columns:1fr; } .rep-hero { padding:1.35rem; } }
</style>

<section class="rep-hero">
    <h2>Reportes ejecutivos de convivencia</h2>
    <p>
        Indicadores consolidados por período, estado y prioridad. Esta vista permite revisar la carga operacional,
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
        <h3 class="rep-panel-title"><i class="bi bi-calendar-range"></i> Período de análisis</h3>
        <?php if ($filters['desde'] !== '' || $filters['hasta'] !== ''): ?>
            <a class="rep-link" href="<?= APP_URL ?>/modules/reportes/index.php">Limpiar</a>
        <?php endif; ?>
    </div>
    <div class="rep-panel-body">
        <form method="get" style="display:flex;align-items:flex-end;gap:.8rem;flex-wrap:wrap;">
            <div>
                <label class="rep-label">Desde</label>
                <input class="rep-control" type="date" name="desde" value="<?= e($filters['desde']) ?>" style="width:180px;">
            </div>
            <div>
                <label class="rep-label">Hasta</label>
                <input class="rep-control" type="date" name="hasta" value="<?= e($filters['hasta']) ?>" style="width:180px;">
            </div>
            <div>
                <button class="rep-submit" type="submit"><i class="bi bi-search"></i>Aplicar</button>
            </div>
            <div style="margin-left:auto;color:#64748b;font-size:.8rem;align-self:center;">
                <?php if ($filters['desde'] !== '' || $filters['hasta'] !== ''): ?>
                    Mostrando datos desde <?= $filters['desde'] !== '' ? e($filters['desde']) : 'el inicio' ?>
                    hasta <?= $filters['hasta'] !== '' ? e($filters['hasta']) : 'hoy' ?>
                <?php else: ?>
                    Mostrando todos los datos históricos
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<section class="rep-kpis">
    <div class="rep-kpi"><span>Total casos</span><strong><?= number_format($kpis['total_casos'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Abiertos</span><strong><?= number_format($kpis['abiertos'], 0, ',', '.') ?></strong></div>
    <div class="rep-kpi"><span>Cerrados</span><strong><?= number_format($kpis['cerrados'], 0, ',', '.') ?></strong></div>
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


<!-- ══ GRÁFICOS ESTADÍSTICOS ═══════════════════════════════ -->
<?php $totalDatos = array_sum($graficosDebug);
if ($totalDatos === 0):
?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;
            padding:1rem 1.25rem;margin-bottom:1rem;font-size:.84rem;color:#92400e;">
    <i class="bi bi-info-circle-fill"></i>
    <strong>Sin datos suficientes para mostrar gráficos.</strong>
    Los gráficos se generan con casos registrados, clasificaciones y pautas de riesgo.
    Ingresa al menos un caso con clasificación y pauta de riesgo.
</div>
<?php endif; ?>
<div style="margin-top:1.5rem;">

    <!-- Fila 1: Casos por mes -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1rem;">
        <div style="font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
                    color:#1a3a5c;margin-bottom:1rem;">
            <i class="bi bi-bar-chart-fill"></i> Casos por mes (últimos 12 meses)
        </div>
        <canvas id="chartMes" height="60"></canvas>
    </div>

    <!-- Fila 2: Nivel de riesgo + Tipo de conducta -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
                        color:#1a3a5c;margin-bottom:1rem;">
                <i class="bi bi-shield-exclamation"></i> Nivel de riesgo (pauta)
            </div>
            <canvas id="chartRiesgo" height="120"></canvas>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.5rem;">
            <div style="font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
                        color:#1a3a5c;margin-bottom:1rem;">
                <i class="bi bi-tag-fill"></i> Tipo de conducta
            </div>
            <canvas id="chartTipo" height="120"></canvas>
        </div>
    </div>

    <!-- Fila 3: Por estado -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1rem;">
        <div style="font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
                    color:#1a3a5c;margin-bottom:1rem;">
            <i class="bi bi-layers-fill"></i> Distribución por estado formal
        </div>
        <canvas id="chartEstado" height="60"></canvas>
    </div>

    <!-- Fila 4: Hecho vs Registro -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1rem;">
        <div style="font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
                    color:#1a3a5c;margin-bottom:.4rem;">
            <i class="bi bi-clock-history"></i> Temporalidad: mes del hecho vs mes de registro
        </div>
        <p style="font-size:.77rem;color:#64748b;margin:.15rem 0 .9rem;line-height:1.5;">
            Compara cuándo ocurrieron los hechos con cuándo fueron ingresados al sistema.
            Una brecha amplia indica develación o conocimiento tardío.
            En delitos de índole sexual no existe prescripción de la acción penal (Art. 369 quáter CP).
        </p>
        <?php if (!empty($graficos['casos_por_mes_hecho'])): ?>
            <canvas id="chartHechoVsRegistro" height="80"></canvas>
            <?php if ($graficos['develaciones_tardias'] > 0): ?>
            <div style="margin-top:.85rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
                        padding:.6rem 1rem;font-size:.8rem;color:#7f1d1d;display:flex;gap:.6rem;align-items:center;">
                <i class="bi bi-hourglass-split" style="font-size:1rem;flex-shrink:0;"></i>
                <span>
                    <strong><?= $graficos['develaciones_tardias'] ?> caso(s)</strong> con brecha superior a 30 días entre el hecho y el registro.
                    Verifica si involucran delitos sexuales — son <strong>imprescriptibles</strong>. Revisa clasificación normativa de cada caso.
                </span>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.82rem;">
                <i class="bi bi-info-circle"></i>
                Sin datos de fecha del hecho registrados. La comparación aparecerá al ingresar casos con fecha del incidente.
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/js/chart.umd.min.js"></script>
<script>
(function() {
    var G = <?= $graficosJson ?>;

    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#64748b';

    // ── 1. Casos por mes ─────────────────────────────────────
    var meses = Object.keys(G.casos_por_mes);
    var totMes = Object.values(G.casos_por_mes);
    // Format labels dd-mm
    var labMes = meses.map(function(m) {
        var p = m.split('-');
        var mNames = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        return mNames[parseInt(p[1])-1] + ' ' + p[0].slice(2);
    });
    new Chart(document.getElementById('chartMes'), {
        type: 'bar',
        data: {
            labels: labMes,
            datasets: [{
                label: 'Casos ingresados',
                data: totMes,
                backgroundColor: '#2563eb',
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 },
                     grid: { color: '#f1f5f9' } },
                x: { grid: { display: false } }
            }
        }
    });

    // ── 2. Nivel de riesgo ───────────────────────────────────
    new Chart(document.getElementById('chartRiesgo'), {
        type: 'bar',
        data: {
            labels: ['Bajo','Medio','Alto','Crítico'],
            datasets: [{
                label: 'Casos',
                data: [G.por_nivel_riesgo.bajo, G.por_nivel_riesgo.medio,
                       G.por_nivel_riesgo.alto, G.por_nivel_riesgo.critico],
                backgroundColor: ['#059669','#d97706','#dc2626','#0f172a'],
                borderRadius: 5, borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 },
                     grid: { color: '#f1f5f9' } },
                x: { grid: { display: false } }
            }
        }
    });

    // ── 4. Tipo de conducta ──────────────────────────────────
    var tipoLabels = Object.keys(G.por_tipo).map(function(k) {
        return k.replace(/_/g,' ').replace(/\w/g, function(c){ return c.toUpperCase(); });
    });
    var tipoData = Object.values(G.por_tipo);
    var colores  = ['#2563eb','#7c3aed','#059669','#d97706','#dc2626',
                    '#0891b2','#db2777','#65a30d'];
    new Chart(document.getElementById('chartTipo'), {
        type: 'bar',
        data: {
            labels: tipoLabels,
            datasets: [{
                label: 'Casos',
                data: tipoData,
                backgroundColor: colores.slice(0, tipoData.length),
                borderRadius: 5, borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1 },
                     grid: { color: '#f1f5f9' } },
                y: { grid: { display: false } }
            }
        }
    });

    // ── 5. Por estado formal ─────────────────────────────────
    var estLabels = Object.keys(G.por_estado);
    var estData   = Object.values(G.por_estado);
    new Chart(document.getElementById('chartEstado'), {
        type: 'bar',
        data: {
            labels: estLabels,
            datasets: [{
                label: 'Casos',
                data: estData,
                backgroundColor: '#1a3a5c',
                borderRadius: 5, borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 },
                     grid: { color: '#f1f5f9' } },
                x: { grid: { display: false } }
            }
        }
    });

    // ── 6. Hecho vs Registro ─────────────────────────────────
    if (document.getElementById('chartHechoVsRegistro')) {
        var mHecho   = G.casos_por_mes_hecho || {};
        var mIngreso = G.casos_por_mes        || {};
        var allM = Array.from(new Set(
            Object.keys(mIngreso).concat(Object.keys(mHecho))
        )).sort();
        var mN = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        var labC = allM.map(function(m) {
            var p = m.split('-');
            return mN[parseInt(p[1])-1] + ' ' + p[0].slice(2);
        });
        new Chart(document.getElementById('chartHechoVsRegistro'), {
            type: 'bar',
            data: {
                labels: labC,
                datasets: [
                    {
                        label: 'Mes de registro',
                        data: allM.map(function(m){ return mIngreso[m] || 0; }),
                        backgroundColor: '#2563eb',
                        borderRadius: 4, borderSkipped: false,
                    },
                    {
                        label: 'Mes del hecho',
                        data: allM.map(function(m){ return mHecho[m] || 0; }),
                        backgroundColor: '#dc2626',
                        borderRadius: 4, borderSkipped: false,
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: {
                    legend: { display: true, position: 'top',
                              labels: { boxWidth: 12, padding: 12 } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 },
                         grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>