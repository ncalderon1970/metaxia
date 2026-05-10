<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

// Verificar tabla
$tablaOk = false;
try { $pdo->query("SELECT 1 FROM caso_pauta_riesgo LIMIT 1"); $tablaOk = true; } catch (Throwable $e) {}

// ── Filtros ─────────────────────────────────────────────────────
$filtroNivel  = clean((string)($_GET['nivel']  ?? ''));
$filtroDerivado = clean((string)($_GET['derivado'] ?? ''));
$filtroQ      = trim((string)($_GET['q'] ?? ''));

// ── KPIs ────────────────────────────────────────────────────────
$kpis = ['total'=>0,'bajo'=>0,'medio'=>0,'alto'=>0,'critico'=>0,'sin_derivar'=>0,'firmadas'=>0];

if ($tablaOk) {
    try {
        // Solo la más reciente por (caso_id, alumno_id, rol_en_caso)
        $stmtK = $pdo->prepare("
            SELECT pr.nivel_final, pr.derivado, pr.firmado
            FROM caso_pauta_riesgo pr
            INNER JOIN (
                SELECT MAX(id) AS max_id
                FROM caso_pauta_riesgo
                WHERE caso_id IN (SELECT id FROM casos WHERE colegio_id = ?)
                GROUP BY caso_id, alumno_id, rol_en_caso
            ) latest ON pr.id = latest.max_id
        ");
        $stmtK->execute([$colegioId]);
        foreach ($stmtK->fetchAll() as $r) {
            $kpis['total']++;
            $kpis[$r['nivel_final']] = ($kpis[$r['nivel_final']] ?? 0) + 1;
            if (!$r['derivado'] && in_array($r['nivel_final'], ['alto','critico'])) $kpis['sin_derivar']++;
            if ($r['firmado']) $kpis['firmadas']++;
        }
    } catch (Throwable $e) {}
}

// ── Listado ──────────────────────────────────────────────────────
$pautas = [];
if ($tablaOk) {
    $where = ["c.colegio_id = ?"];
    $params = [$colegioId];

    if ($filtroNivel !== '') { $where[] = "pr.nivel_final = ?"; $params[] = $filtroNivel; }
    if ($filtroDerivado === '0') { $where[] = "pr.derivado = 0 AND pr.nivel_final IN ('alto','critico')"; }
    if ($filtroDerivado === '1') { $where[] = "pr.derivado = 1"; }
    if ($filtroQ !== '') {
        $where[] = "(pr.nombre_alumno LIKE ? OR c.numero_caso LIKE ?)";
        $params[] = '%'.$filtroQ.'%';
        $params[] = '%'.$filtroQ.'%';
    }

    try {
        $stmtP = $pdo->prepare("
            SELECT pr.*,
                   c.numero_caso, c.estado AS caso_estado,
                   u.nombre AS usuario_nombre
            FROM caso_pauta_riesgo pr
            INNER JOIN (
                SELECT MAX(id) AS max_id
                FROM caso_pauta_riesgo
                WHERE caso_id IN (SELECT id FROM casos WHERE colegio_id = ?)
                GROUP BY caso_id, alumno_id, rol_en_caso
            ) latest ON pr.id = latest.max_id
            JOIN casos c ON c.id = pr.caso_id
            LEFT JOIN usuarios u ON u.id = pr.firmado_por_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(pr.nivel_final,'critico','alto','medio','bajo'), pr.created_at DESC
            LIMIT 200
        ");
        $stmtP->execute(array_merge([$colegioId], $params));
        $pautas = $stmtP->fetchAll();
    } catch (Throwable $e) {}
}

$NIVELES = [
    'bajo'    => ['emoji'=>'🟢','label'=>'Bajo',    'bg'=>'#ecfdf5','border'=>'#bbf7d0','color'=>'#047857'],
    'medio'   => ['emoji'=>'🟡','label'=>'Medio',   'bg'=>'#fffbeb','border'=>'#fde68a','color'=>'#92400e'],
    'alto'    => ['emoji'=>'🔴','label'=>'Alto',    'bg'=>'#fef2f2','border'=>'#fecaca','color'=>'#b91c1c'],
    'critico' => ['emoji'=>'⚫','label'=>'Crítico', 'bg'=>'#f1f5f9','border'=>'#334155','color'=>'#0f172a'],
];

$pageTitle = 'Panel de pautas de riesgo · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.pr-panel-hero {
    background: linear-gradient(135deg,#0f172a 0%,#1e3a8a 55%,#1d4ed8 100%);
    color:#fff; border-radius:16px; padding:1.6rem 2rem; margin-bottom:1.1rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
    box-shadow:0 8px 24px rgba(15,23,42,.18);
}
.pr-panel-hero h2 { margin:0 0 .25rem; font-size:1.3rem; font-weight:700; }
.pr-panel-hero p  { margin:0; font-size:.86rem; color:rgba(255,255,255,.72); }

.pr-kpi-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:.65rem; margin-bottom:1rem; }
.pr-kpi { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.8rem 1rem;
    text-align:center;cursor:pointer;transition:border-color .15s;text-decoration:none;display:block; }
.pr-kpi:hover { border-color:#93c5fd; }
.pr-kpi.active { border-color:#2563eb;background:#eff6ff; }
.pr-kpi span { display:block;font-size:.7rem;font-weight:600;text-transform:uppercase;
    letter-spacing:.06em;color:#64748b;margin-bottom:.25rem; }
.pr-kpi strong { display:block;font-size:1.6rem;font-weight:700;color:#0f172a;line-height:1; }
.pr-kpi.danger strong { color:#b91c1c; }
.pr-kpi.warn   strong { color:#92400e; }
.pr-kpi.ok     strong { color:#047857; }

.pr-filters { display:flex;gap:.65rem;align-items:center;flex-wrap:wrap;
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;
    padding:.65rem .85rem;margin-bottom:1rem; }
.pr-filter-control { border:1px solid #cbd5e1;border-radius:7px;padding:.42rem .7rem;
    font-size:.84rem;font-family:inherit;outline:none;background:#fff;color:#0f172a; }
.pr-filter-control:focus { border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.1); }
.pr-search { flex:1;min-width:180px; }
.pr-filter-btn { display:inline-flex;align-items:center;gap:.35rem;border:none;
    background:#1e3a8a;color:#fff;border-radius:7px;padding:.45rem .95rem;
    font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit; }
.pr-filter-clear { display:inline-flex;align-items:center;gap:.3rem;border:1px solid #e2e8f0;
    background:#fff;color:#64748b;border-radius:7px;padding:.45rem .85rem;
    font-size:.82rem;cursor:pointer;text-decoration:none; }

.pr-table-wrap { background:#fff;border:1px solid #e2e8f0;border-radius:14px;
    overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.05); }
.pr-table { width:100%;border-collapse:collapse; }
.pr-table th { background:#f8fafc;padding:.6rem .9rem;font-size:.72rem;font-weight:700;
    text-transform:uppercase;letter-spacing:.07em;color:#64748b;border-bottom:1px solid #e2e8f0;
    text-align:left;white-space:nowrap; }
.pr-table td { padding:.7rem .9rem;font-size:.84rem;color:#334155;
    border-bottom:0.5px solid #f1f5f9;vertical-align:middle; }
.pr-table tr:last-child td { border-bottom:none; }
.pr-table tr:hover td { background:#f8fafc; }

.pr-nivel-badge { display:inline-flex;align-items:center;gap:.3rem;border-radius:7px;
    padding:.2rem .6rem;font-size:.72rem;font-weight:700;border:1px solid; }
.pr-firma-badge { display:inline-flex;align-items:center;gap:.25rem;border-radius:7px;
    padding:.18rem .55rem;font-size:.7rem;font-weight:600; }
.pr-firma-badge.ok  { background:#ecfdf5;border:1px solid #bbf7d0;color:#047857; }
.pr-firma-badge.no  { background:#fff7ed;border:1px solid #fed7aa;color:#c2410c; }
.pr-action { display:inline-flex;align-items:center;gap:.3rem;border:1px solid #bfdbfe;
    background:#eff6ff;color:#2563eb;border-radius:7px;padding:.32rem .7rem;
    font-size:.76rem;font-weight:600;text-decoration:none;white-space:nowrap; }
.pr-action:hover { background:#dbeafe; }
.pr-empty { text-align:center;color:#94a3b8;padding:3rem;font-size:.88rem; }

@media(max-width:900px) {
    .pr-kpi-grid { grid-template-columns:repeat(3,1fr); }
    .pr-table { font-size:.78rem; }
}
</style>

<!-- Hero -->
<div class="pr-panel-hero">
    <div>
        <h2><i class="bi bi-clipboard2-pulse-fill"></i> Panel de pautas de valoración de riesgo</h2>
        <p>Vista centralizada de todas las evaluaciones de riesgo aplicadas — víctimas y testigos</p>
    </div>
    <a href="<?= APP_URL ?>/modules/admin/administracion_general.php"
       style="display:inline-flex;align-items:center;gap:.35rem;border:1px solid rgba(255,255,255,.3);
           color:#fff;border-radius:7px;padding:.45rem .95rem;font-size:.82rem;text-decoration:none;">
        <i class="bi bi-arrow-left"></i> Volver
    </a>
</div>

<?php if (!$tablaOk): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:1.25rem 1.5rem;
    color:#b91c1c;font-size:.88rem;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    La tabla <code>caso_pauta_riesgo</code> no existe. Ejecuta el SQL de instalación primero.
</div>
<?php else: ?>

<!-- KPIs -->
<div class="pr-kpi-grid">
    <a class="pr-kpi <?= $filtroNivel===''&&$filtroDerivado==='' ? 'active' : '' ?>"
       href="?">
        <span>Total pautas</span>
        <strong><?= $kpis['total'] ?></strong>
    </a>
    <a class="pr-kpi ok <?= $filtroNivel==='bajo' ? 'active' : '' ?>"
       href="?nivel=bajo">
        <span>🟢 Bajo</span>
        <strong class="ok"><?= $kpis['bajo'] ?></strong>
    </a>
    <a class="pr-kpi warn <?= $filtroNivel==='medio' ? 'active' : '' ?>"
       href="?nivel=medio">
        <span>🟡 Medio</span>
        <strong class="warn"><?= $kpis['medio'] ?></strong>
    </a>
    <a class="pr-kpi <?= $filtroNivel==='alto' ? 'active' : '' ?>"
       href="?nivel=alto">
        <span>🔴 Alto</span>
        <strong class="danger"><?= $kpis['alto'] ?></strong>
    </a>
    <a class="pr-kpi <?= $filtroNivel==='critico' ? 'active' : '' ?>"
       href="?nivel=critico">
        <span>⚫ Crítico</span>
        <strong style="color:#0f172a;"><?= $kpis['critico'] ?></strong>
    </a>
    <a class="pr-kpi <?= $filtroDerivado==='0' ? 'active' : '' ?>"
       href="?derivado=0">
        <span>⚑ Sin derivar</span>
        <strong class="<?= $kpis['sin_derivar'] > 0 ? 'danger' : 'ok' ?>">
            <?= $kpis['sin_derivar'] ?>
        </strong>
    </a>
</div>

<!-- Filtros -->
<form method="get" class="pr-filters">
    <input type="text" name="q" class="pr-filter-control pr-search"
           placeholder="Buscar alumno o N° caso..." value="<?= e($filtroQ) ?>">
    <select name="nivel" class="pr-filter-control">
        <option value="">Todos los niveles</option>
        <?php foreach ($NIVELES as $k => $n): ?>
        <option value="<?= $k ?>" <?= $filtroNivel===$k?'selected':'' ?>><?= $n['emoji'] ?> <?= $n['label'] ?></option>
        <?php endforeach; ?>
    </select>
    <select name="derivado" class="pr-filter-control">
        <option value="">Todas</option>
        <option value="0" <?= $filtroDerivado==='0'?'selected':'' ?>>⚑ Pendientes de derivar</option>
        <option value="1" <?= $filtroDerivado==='1'?'selected':'' ?>>✅ Derivadas</option>
    </select>
    <button type="submit" class="pr-filter-btn">
        <i class="bi bi-funnel"></i> Filtrar
    </button>
    <?php if ($filtroNivel || $filtroDerivado || $filtroQ): ?>
    <a href="?" class="pr-filter-clear"><i class="bi bi-x-lg"></i> Limpiar</a>
    <?php endif; ?>
</form>

<!-- Tabla -->
<div class="pr-table-wrap">
    <?php if (empty($pautas)): ?>
        <div class="pr-empty">
            <i class="bi bi-clipboard2" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
            No hay pautas que coincidan con los filtros.
        </div>
    <?php else: ?>
    <table class="pr-table">
        <thead>
            <tr>
                <th>N° Caso</th>
                <th>Alumno / Testigo</th>
                <th>Rol</th>
                <th>Nivel</th>
                <th>Puntaje</th>
                <th>Evaluaciones</th>
                <th>Firma</th>
                <th>Derivación</th>
                <th>Fecha</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pautas as $p):
            $nv   = (string)($p['nivel_final'] ?? 'bajo');
            $info = $NIVELES[$nv] ?? $NIVELES['bajo'];
        ?>
            <tr>
                <td>
                    <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$p['caso_id'] ?>"
                       style="font-weight:700;color:#2563eb;text-decoration:none;">
                        <?= e((string)$p['numero_caso']) ?>
                    </a>
                </td>
                <td style="font-weight:600;color:#0f172a;"><?= e((string)($p['nombre_alumno'] ?? '—')) ?></td>
                <td>
                    <span style="font-size:.74rem;font-weight:600;padding:.15rem .5rem;border-radius:7px;
                        <?= $p['rol_en_caso']==='victima'
                            ? 'background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;'
                            : 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;' ?>">
                        <?= ucfirst((string)$p['rol_en_caso']) ?>
                    </span>
                </td>
                <td>
                    <span class="pr-nivel-badge"
                          style="background:<?= $info['bg'] ?>;border-color:<?= $info['border'] ?>;color:<?= $info['color'] ?>;">
                        <?= $info['emoji'] ?> <?= $info['label'] ?>
                    </span>
                </td>
                <td style="font-weight:700;color:<?= $info['color'] ?>;">
                    <?= (int)$p['puntaje_total'] ?><span style="font-weight:400;color:#94a3b8;">/70</span>
                </td>
                <td style="text-align:center;">
                    <?php
                    $nAplic = (int)($p['numero_aplicacion'] ?? 1);
                    echo '<span style="background:#f1f5f9;border-radius:7px;padding:.15rem .55rem;font-size:.74rem;font-weight:600;color:#475569;">'.$nAplic.'</span>';
                    ?>
                </td>
                <td>
                    <?php if (!empty($p['firma_hash'])): ?>
                        <span class="pr-firma-badge ok"
                              title="<?= e((string)($p['usuario_nombre'] ?? '')) ?> · <?= e((string)($p['firma_timestamp'] ?? '')) ?>&#10;<?= e((string)$p['firma_hash']) ?>">
                            <i class="bi bi-shield-fill-check"></i>
                            <?= strtoupper(substr((string)$p['firma_hash'], 0, 8)) ?>
                        </span>
                    <?php else: ?>
                        <span class="pr-firma-badge no"><i class="bi bi-shield-exclamation"></i> Sin firma</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($p['derivado']): ?>
                        <span style="color:#047857;font-size:.78rem;font-weight:600;">
                            ✅ <?= e((string)($p['entidad_derivacion'] ?? '')) ?>
                        </span>
                    <?php elseif (in_array($nv, ['alto','critico'])): ?>
                        <span style="color:#b91c1c;font-size:.78rem;font-weight:600;">⚑ Pendiente</span>
                    <?php else: ?>
                        <span style="color:#94a3b8;font-size:.78rem;">—</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;color:#64748b;font-size:.78rem;">
                    <?= date('d/m/Y', strtotime((string)$p['created_at'])) ?>
                </td>
                <td>
                    <a class="pr-action"
                       href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$p['caso_id'] ?>&tab=pauta_riesgo">
                        <i class="bi bi-clipboard2-pulse-fill"></i> Ver pauta
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
ob_end_flush();
?>