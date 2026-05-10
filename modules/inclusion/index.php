<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo  = DB::conn();
$user = Auth::user();
$cid  = (int)($user['colegio_id'] ?? 0);

// Filtros
$filtroTipo   = trim((string)($_GET['tipo']   ?? ''));
$filtroEstado = trim((string)($_GET['estado'] ?? ''));
$filtroTea    = (string)($_GET['tea'] ?? '');
$buscar       = trim((string)($_GET['q']      ?? ''));

$pageTitle = 'Inclusión y NEE · Metis';

// ── KPIs ────────────────────────────────────────────────────
$kpis = ['total'=>0,'tea'=>0,'pie'=>0,'derivados'=>0,'sin_derivar'=>0,
         'confirmados'=>0,'en_proceso'=>0,'con_cert'=>0,'ajustes'=>0];
try {
    $stmtK = $pdo->prepare("
        SELECT
            COUNT(DISTINCT ace.alumno_id)                                             AS total,
            SUM(ace.tipo_condicion LIKE 'tea%')                                       AS tea,
            SUM(ace.tiene_pie = 1)                                                    AS pie,
            SUM(ace.derivado_salud = 1)                                               AS derivados,
            SUM(ace.tipo_condicion LIKE 'tea%' AND ace.derivado_salud = 0
                AND ace.estado_diagnostico IN ('sospecha','en_proceso'))               AS sin_derivar,
            SUM(ace.estado_diagnostico = 'confirmado')                                AS confirmados,
            SUM(ace.estado_diagnostico = 'en_proceso')                                AS en_proceso,
            SUM(ace.tiene_certificado = 1)                                            AS con_cert,
            SUM(ace.requiere_ajustes = 1)                                             AS ajustes
        FROM alumno_condicion_especial ace
        WHERE ace.colegio_id = ? AND ace.activo = 1
    ");
    $stmtK->execute([$cid]);
    $row = $stmtK->fetch();
    if ($row) {
        foreach ($kpis as $k => $_) $kpis[$k] = (int)($row[$k] ?? 0);
    }
} catch (Throwable $e) { /* tabla no existe */ }

// ── Listado principal ────────────────────────────────────────
$where  = ['ace.colegio_id = ?', 'ace.activo = 1'];
$params = [$cid];

if ($filtroTipo !== '') {
    $where[] = 'ace.tipo_condicion = ?';
    $params[] = $filtroTipo;
} elseif ($filtroTea !== '') {
    $where[] = "ace.tipo_condicion LIKE 'tea%'";
}

if ($filtroEstado !== '') {
    $where[] = 'ace.estado_diagnostico = ?';
    $params[] = $filtroEstado;
}

if ($buscar !== '') {
    $like = '%' . $buscar . '%';
    $where[] = "(CONCAT_WS(' ',a.nombres,a.apellido_paterno,a.apellido_materno) LIKE ?
                 OR a.run LIKE ? OR a.curso LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$alumnos = [];
try {
    $stmtL = $pdo->prepare("
        SELECT
            a.id              AS alumno_id,
            a.run,
            a.curso,
            CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombres) AS nombre,
            ace.id            AS condicion_id,
            ace.tipo_condicion,
            ace.estado_diagnostico,
            ace.nivel_apoyo,
            ace.tiene_pie,
            ace.tiene_certificado,
            ace.derivado_salud,
            ace.fecha_derivacion,
            ace.destino_derivacion,
            ace.estado_derivacion,
            ace.requiere_ajustes,
            ace.fecha_deteccion,
            ace.created_at,
            COALESCE(cat.nombre, ace.tipo_condicion) AS condicion_nombre
        FROM alumno_condicion_especial ace
        INNER JOIN alumnos a             ON a.id  = ace.alumno_id
        LEFT  JOIN catalogo_condicion_especial cat ON cat.codigo = ace.tipo_condicion
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            (ace.tipo_condicion LIKE 'tea%' AND ace.derivado_salud = 0
             AND ace.estado_diagnostico IN ('sospecha','en_proceso')) DESC,
            a.apellido_paterno ASC, a.nombres ASC
        LIMIT 500
    ");
    $stmtL->execute($params);
    $alumnos = $stmtL->fetchAll();
} catch (Throwable $e) { /* tabla no existe */ }

// Catálogo para filtro
$catalogo = [];
try {
    $stmtC = $pdo->query("SELECT codigo, nombre, categoria FROM catalogo_condicion_especial WHERE activo=1 ORDER BY categoria, nombre");
    $catalogo = $stmtC->fetchAll();
} catch (Throwable $e) {}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
:root { --inc:#0369a1; --inc-tea:#f59e0b; --inc-ok:#059669; --inc-warn:#dc2626; }
.inc-hero { background:linear-gradient(135deg,#0c4a6e,#0369a1,#0ea5e9);border-radius:14px;color:#fff;padding:2rem 2.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden; }
.inc-hero::before { content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 80% at 95% 50%,rgba(14,165,233,.2),transparent 65%); }
.inc-kpis { display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;margin-bottom:1.5rem; }
.inc-kpi  { background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;text-align:center;cursor:default;transition:box-shadow .15s; }
.inc-kpi:hover { box-shadow:0 4px 16px rgba(0,0,0,.09); }
.inc-kpi-val { font-size:1.75rem;font-weight:800;color:var(--inc);line-height:1; }
.inc-kpi-val.warn { color:var(--inc-warn); }
.inc-kpi-val.ok   { color:var(--inc-ok); }
.inc-kpi-val.tea  { color:var(--inc-tea); }
.inc-kpi-lbl { font-size:.69rem;color:#64748b;margin-top:.2rem;font-weight:600; }
.inc-panel { background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07); }
.inc-panel-hd { padding:1.25rem 1.5rem 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem; }
.inc-panel-title { font-size:.92rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:.5rem; }
.inc-panel-body { padding:1rem 1.5rem 1.5rem; }
.filter-bar { display:flex;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem; }
.filter-search { position:relative;flex:1;min-width:200px;max-width:300px; }
.filter-search input { width:100%;padding:.5rem .75rem .5rem 2.2rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.84rem;background:#f8fafc;box-sizing:border-box; }
.filter-search input:focus { outline:none;border-color:var(--inc);background:#fff; }
.filter-search i { position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem; }
.filter-sel { font-size:.79rem;font-weight:600;padding:.45rem .75rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;color:#374151;cursor:pointer;outline:none; }
.inc-table { width:100%;border-collapse:separate;border-spacing:0;font-size:.83rem; }
.inc-table th { font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;padding:.6rem .85rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;white-space:nowrap; }
.inc-table td { padding:.65rem .85rem;border-top:1px solid #f1f5f9;vertical-align:middle; }
.inc-table tr:hover td { background:#f0f9ff; }
.inc-table tr.alerta-sin-derivar td { background:#fffbeb; }
.badge-tea  { background:#fef3c7;color:#92400e;border-radius:7px;padding:.12rem .55rem;font-size:.7rem;font-weight:700;display:inline-block; }
.badge-nee  { background:#ede9fe;color:#5b21b6;border-radius:7px;padding:.12rem .55rem;font-size:.7rem;font-weight:700;display:inline-block; }
.badge-pie  { background:#e0f2fe;color:#0369a1;border-radius:7px;padding:.12rem .55rem;font-size:.7rem;font-weight:700;display:inline-block; }
.badge-ok   { background:#d1fae5;color:#065f46;border-radius:7px;padding:.12rem .55rem;font-size:.7rem;font-weight:700;display:inline-block; }
.badge-warn { background:#fee2e2;color:#991b1b;border-radius:7px;padding:.12rem .55rem;font-size:.7rem;font-weight:700;display:inline-block; }
.badge-pend { background:#fff3cd;color:#856404;border-radius:7px;padding:.12rem .55rem;font-size:.7rem;font-weight:700;display:inline-block; }
.btn-prot { font-size:.73rem;font-weight:600;padding:.3rem .75rem;border-radius:7px;border:1.5px solid #bae6fd;background:#e0f2fe;color:#0369a1;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap; }
.btn-prot:hover { background:var(--inc);color:#fff;border-color:var(--inc); }
.btn-ficha { font-size:.73rem;font-weight:600;padding:.3rem .75rem;border-radius:7px;border:1.5px solid #d1fae5;background:#f0fdf4;color:#065f46;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem; }
.btn-ficha:hover { background:var(--inc-ok);color:#fff; }
.alerta-derivar { background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.83rem;color:#92400e;display:flex;align-items:center;gap:.6rem; }
.inc-empty { text-align:center;padding:2.5rem;color:#94a3b8;font-size:.88rem; }
@media(max-width:700px){ .inc-kpis { grid-template-columns:repeat(2,1fr); } }
</style>

<div class="inc-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#bae6fd;display:block;margin-bottom:.4rem;position:relative;">
                <i class="bi bi-heart-pulse-fill"></i> Metis · Gestión Educativa
            </span>
            <h1 style="font-size:1.75rem;font-weight:800;color:#fff;margin-bottom:.3rem;position:relative;">Inclusión y NEE</h1>
            <p style="font-size:.87rem;color:#bae6fd;margin:0;position:relative;">
                Registro de condiciones especiales · Protocolo TEA (Ley 21.545) · Ajustes razonables
            </p>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-self:center;position:relative;">
            <a href="?tea=1" style="background:rgba(245,158,11,.85);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;font-weight:700;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="bi bi-heart-pulse-fill"></i> Solo TEA
            </a>
            <a href="<?= APP_URL ?>/modules/inclusion/reporte_inclusion.php" target="_blank"
               style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="bi bi-file-earmark-bar-graph"></i> Reporte
            </a>
            <a href="<?= APP_URL ?>/modules/inclusion/reporte_inclusion.php?modo=csv" 
               style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="bi bi-filetype-csv"></i> CSV
            </a>
            <a href="?" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="bi bi-grid-3x3-gap"></i> Todos
            </a>
        </div>
    </div>
</div>

<!-- KPIs -->
<div class="inc-kpis">
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= $kpis['total'] ?></div>
        <div class="inc-kpi-lbl">Con condición registrada</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val tea"><?= $kpis['tea'] ?></div>
        <div class="inc-kpi-lbl">Con TEA</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val ok"><?= $kpis['pie'] ?></div>
        <div class="inc-kpi-lbl">En PIE</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val ok"><?= $kpis['derivados'] ?></div>
        <div class="inc-kpi-lbl">Derivados a salud</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val <?= $kpis['sin_derivar'] > 0 ? 'warn' : 'ok' ?>"><?= $kpis['sin_derivar'] ?></div>
        <div class="inc-kpi-lbl">TEA sin derivar ⚠️</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val ok"><?= $kpis['confirmados'] ?></div>
        <div class="inc-kpi-lbl">Dx confirmado</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= $kpis['en_proceso'] ?></div>
        <div class="inc-kpi-lbl">En proceso Dx</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val ok"><?= $kpis['con_cert'] ?></div>
        <div class="inc-kpi-lbl">Con certificado</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= $kpis['ajustes'] ?></div>
        <div class="inc-kpi-lbl">Con ajustes razonables</div>
    </div>
</div>

<!-- Alerta derivaciones pendientes -->
<?php if ($kpis['sin_derivar'] > 0): ?>
<div class="alerta-derivar">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:1.2rem;flex-shrink:0;"></i>
    <div>
        <strong><?= $kpis['sin_derivar'] ?> alumno(s) con TEA sin derivación a salud registrada.</strong>
        El Art. 12 de la Ley 21.545 establece la obligación del establecimiento de derivar
        a los estudiantes con sospecha o diagnóstico TEA al sistema de salud.
        <a href="?tea=1&estado=sospecha" style="color:#92400e;font-weight:700;">Ver alumnos pendientes →</a>
    </div>
</div>
<?php endif; ?>

<!-- Listado -->
<div class="inc-panel">
    <div class="inc-panel-hd">
        <div class="inc-panel-title">
            <span style="width:28px;height:28px;border-radius:8px;background:#e0f2fe;color:var(--inc);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;">
                <i class="bi bi-heart-pulse-fill"></i>
            </span>
            Alumnos con condición especial registrada
            <span style="font-size:.75rem;font-weight:400;color:#64748b;">(<?= count($alumnos) ?> registros)</span>
        </div>
    </div>
    <div class="inc-panel-body">

        <form method="get" class="filter-bar">
            <div class="filter-search">
                <i class="bi bi-search"></i>
                <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Buscar alumno por nombre, RUN o curso…">
            </div>
            <select name="tipo" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todos los tipos</option>
                <?php
                $cat_act = '';
                foreach ($catalogo as $c):
                    if ($c['categoria'] !== $cat_act):
                        if ($cat_act !== '') echo '</optgroup>';
                        $cl = ['tea'=>'TEA','nee'=>'NEE','otro'=>'Otros'];
                        echo '<optgroup label="' . e($cl[$c['categoria']] ?? $c['categoria']) . '">';
                        $cat_act = $c['categoria'];
                    endif;
                ?>
                    <option value="<?= e($c['codigo']) ?>" <?= $filtroTipo === $c['codigo'] ? 'selected' : '' ?>>
                        <?= e($c['nombre']) ?>
                    </option>
                <?php endforeach; if ($cat_act) echo '</optgroup>'; ?>
            </select>
            <select name="estado" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todos los estados</option>
                <option value="sospecha"    <?= $filtroEstado==='sospecha'     ? 'selected':'' ?>>Sospecha</option>
                <option value="en_proceso"  <?= $filtroEstado==='en_proceso'   ? 'selected':'' ?>>En proceso</option>
                <option value="confirmado"  <?= $filtroEstado==='confirmado'   ? 'selected':'' ?>>Confirmado</option>
                <option value="descartado"  <?= $filtroEstado==='descartado'   ? 'selected':'' ?>>Descartado</option>
            </select>
            <button type="submit" class="filter-sel" style="background:#fff;cursor:pointer;">
                <i class="bi bi-search"></i> Filtrar
            </button>
            <a href="?" class="filter-sel" style="text-decoration:none;color:#64748b;background:#fff;">
                <i class="bi bi-x-circle"></i> Limpiar
            </a>
        </form>

        <?php if (!$alumnos): ?>
            <div class="inc-empty">
                <i class="bi bi-heart-pulse" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3;"></i>
                No hay registros de condición especial.<?php if ($kpis['total'] === 0): ?>
                <br>Usa la pestaña <strong>Inclusión / NEE</strong> en la ficha de cada alumno para registrar.
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="inc-table">
                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>RUN</th>
                        <th>Curso</th>
                        <th>Condición</th>
                        <th>Estado Dx</th>
                        <th>PIE</th>
                        <th>Derivación salud</th>
                        <th>Ajustes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($alumnos as $al):
                    $esTea     = str_starts_with((string)($al['tipo_condicion'] ?? ''), 'tea');
                    $sinDerivar = $esTea && !(int)$al['derivado_salud']
                                  && in_array($al['estado_diagnostico'], ['sospecha','en_proceso'], true);
                ?>
                    <tr <?= $sinDerivar ? 'class="alerta-sin-derivar"' : '' ?>>
                        <td style="font-weight:600;color:#0f172a;"><?= e((string)$al['nombre']) ?></td>
                        <td style="font-family:monospace;font-size:.78rem;color:#64748b;"><?= e((string)$al['run']) ?></td>
                        <td style="font-size:.82rem;"><?= e((string)($al['curso'] ?? '—')) ?></td>
                        <td>
                            <span class="badge-<?= $esTea ? 'tea' : 'nee' ?>">
                                <?= e((string)($al['condicion_nombre'] ?? $al['tipo_condicion'])) ?>
                            </span>
                            <?php if ($al['nivel_apoyo']): ?>
                                <span style="font-size:.68rem;color:#92400e;margin-left:.2rem;">N<?= (int)$al['nivel_apoyo'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $estadoBadge = match($al['estado_diagnostico'] ?? '') {
                                'confirmado' => '<span class="badge-ok">Confirmado</span>',
                                'en_proceso' => '<span class="badge-pend">En proceso</span>',
                                'sospecha'   => '<span class="badge-warn">Sospecha</span>',
                                'descartado' => '<span style="font-size:.7rem;color:#94a3b8;">Descartado</span>',
                                default      => '<span style="color:#94a3b8;">—</span>',
                            };
                            echo $estadoBadge;
                            ?>
                        </td>
                        <td>
                            <?= (int)$al['tiene_pie'] ? '<span class="badge-pie">PIE</span>' : '<span style="color:#94a3b8;font-size:.78rem;">No</span>' ?>
                        </td>
                        <td>
                            <?php if ((int)$al['derivado_salud']): ?>
                                <span class="badge-ok"><i class="bi bi-check"></i> Derivado</span>
                                <?php if ($al['fecha_derivacion']): ?>
                                    <div style="font-size:.7rem;color:#64748b;"><?= date('d-m-Y', strtotime((string)$al['fecha_derivacion'])) ?></div>
                                <?php endif; ?>
                            <?php elseif ($sinDerivar): ?>
                                <span class="badge-warn"><i class="bi bi-exclamation-triangle"></i> Pendiente</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:.78rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= (int)$al['requiere_ajustes'] ? '<span class="badge-pie"><i class="bi bi-tools"></i> Sí</span>' : '<span style="color:#94a3b8;font-size:.78rem;">No</span>' ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                <a href="<?= APP_URL ?>/modules/alumnos/ver.php?id=<?= (int)$al['alumno_id'] ?>&tab=inclusion"
                                   class="btn-ficha"><i class="bi bi-person-badge"></i> Ficha</a>
                                <?php if ($esTea): ?>
                                    <a href="<?= APP_URL ?>/modules/inclusion/protocolo_tea.php?alumno_id=<?= (int)$al['alumno_id'] ?>&condicion_id=<?= (int)$al['condicion_id'] ?>&origen=inclusion"
                                       class="btn-prot"><i class="bi bi-clipboard2-check"></i> Protocolo</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
