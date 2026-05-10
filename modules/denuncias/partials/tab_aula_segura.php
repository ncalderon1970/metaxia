<?php
$as = $aulaSegura ?? [];
$asResumen = $resumenAulaSegura ?? [];
$asCatalogo = $aulaSeguraCatalogo ?? [];
$asHistorial = $aulaSeguraHistorial ?? [];
$asVisible = !empty($asResumen['visible']);
$causalesTexto = (string)($asResumen['causales_texto'] ?? 'Sin causales visibles');
$estadoClase = (string)($asResumen['estado_clase'] ?? 'warn');
$estadoTexto = (string)($asResumen['estado_texto'] ?? 'Posible Aula Segura');
$accionSugerida = (string)($asResumen['accion'] ?? 'Dirección debe evaluar la procedencia.');
$observacionPreliminar = (string)($caso['aula_segura_observacion_preliminar'] ?? '');
$marcadoAt = (string)($caso['aula_segura_marcado_at'] ?? '');
$rolActualAula = (string)($user['rol_codigo'] ?? '');
$puedeResolverAula = in_array($rolActualAula, ['superadmin', 'director', 'admin_sistema'], true) || Auth::can('admin_sistema');
$puedeActivarAula = Auth::canOperate();

function aula_val(array $data, string $key, string $default = ''): string
{
    return isset($data[$key]) && $data[$key] !== null ? (string)$data[$key] : $default;
}

function aula_date_val(array $data, string $key): string
{
    $value = aula_val($data, $key);
    return $value !== '' ? substr($value, 0, 10) : '';
}

function aula_dt_local(array $data, string $key): string
{
    $value = aula_val($data, $key);
    if ($value === '') { return ''; }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\\TH:i', $ts) : '';
}

// KPIs para el panel de estado
$estadoActual   = aula_val($as, 'estado', 'posible');
$resolucionVal  = aula_val($as, 'resolucion', '');
$suspCautelar   = (int)($as['suspension_cautelar'] ?? 0) === 1;
$descargosRec   = (int)($as['descargos_recibidos'] ?? 0) === 1;
$fechaInicio    = aula_val($as, 'fecha_inicio_procedimiento', '');
$fechaLimite    = aula_val($as, 'fecha_limite_resolucion', '');
$supereduc      = (int)($as['comunicacion_supereduc'] ?? 0) === 1;

$estadoLabels = [
    'posible'               => ['label' => 'Posible',               'cls' => 'as-estado-warn'],
    'en_evaluacion'         => ['label' => 'En evaluación',          'cls' => 'as-estado-blue'],
    'descartado'            => ['label' => 'Descartado',             'cls' => 'as-estado-muted'],
    'procedimiento_iniciado'=> ['label' => 'Procedimiento iniciado', 'cls' => 'as-estado-red'],
    'suspension_cautelar'   => ['label' => 'Suspensión cautelar',    'cls' => 'as-estado-red'],
    'resuelto'              => ['label' => 'Resuelto',               'cls' => 'as-estado-ok'],
    'reconsideracion'       => ['label' => 'Reconsideración',        'cls' => 'as-estado-warn'],
    'cerrado'               => ['label' => 'Cerrado',                'cls' => 'as-estado-ok'],
];
$estadoInfo = $estadoLabels[$estadoActual] ?? ['label' => ucfirst($estadoActual), 'cls' => 'as-estado-warn'];

$causalesMarcadas = 0;
foreach ($asCatalogo as $causal) {
    $codigo = (string)($causal['codigo'] ?? '');
    $campo = function_exists('ver_aula_segura_campo_por_codigo') ? ver_aula_segura_campo_por_codigo($codigo) : null;
    if ($campo !== null && (int)($as[$campo] ?? 0) === 1) $causalesMarcadas++;
}
?>

<style>
/* ══ AULA SEGURA — Sistema de diseño unificado ══════════════════ */

/* Hero banner */
.as-hero {
    background:
        radial-gradient(circle at 85% 20%, rgba(245,158,11,.22), transparent 35%),
        linear-gradient(135deg, #451a03 0%, #78350f 55%, #b45309 100%);
    border-radius: 16px;
    padding: 1.5rem 1.75rem;
    margin-bottom: 1.25rem;
    color: #fff;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 1.25rem;
    align-items: center;
}
.as-hero-badge {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.28);
    border-radius: 20px;
    padding: .22rem .75rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: .55rem;
}
.as-hero-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 .35rem;
    letter-spacing: -.01em;
}
.as-hero-desc {
    font-size: .84rem;
    color: rgba(255,255,255,.78);
    line-height: 1.5;
    margin: 0;
    max-width: 600px;
}
.as-hero-side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .65rem;
    flex-shrink: 0;
}
.as-hero-action-label {
    font-size: .72rem;
    font-weight: 700;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .07em;
    text-align: right;
}
.as-hero-action-text {
    font-size: .84rem;
    color: #fef3c7;
    font-weight: 600;
    text-align: right;
    max-width: 240px;
    line-height: 1.4;
}
.as-btn-reporte {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.35);
    border-radius: 8px;
    padding: .48rem 1rem;
    font-size: .84rem;
    font-weight: 600;
    color: #fff;
    text-decoration: none;
    transition: background .15s;
    white-space: nowrap;
}
.as-btn-reporte:hover { background: rgba(255,255,255,.25); color: #fff; }

/* KPI row */
.as-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .85rem;
    margin-bottom: 1.25rem;
}
.as-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: .9rem 1rem;
    box-shadow: 0 1px 3px rgba(15,23,42,.05);
}
.as-kpi-label {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #64748b;
    margin-bottom: .3rem;
}
.as-kpi-value {
    font-size: .95rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.2;
}
.as-kpi-value.ok     { color: #059669; }
.as-kpi-value.warn   { color: #d97706; }
.as-kpi-value.danger { color: #dc2626; }
.as-kpi-value.muted  { color: #94a3b8; }

/* Estado badge */
.as-estado-ok    { display:inline-block;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:20px;padding:.18rem .75rem;font-size:.76rem;font-weight:700; }
.as-estado-warn  { display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:20px;padding:.18rem .75rem;font-size:.76rem;font-weight:700; }
.as-estado-red   { display:inline-block;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:20px;padding:.18rem .75rem;font-size:.76rem;font-weight:700; }
.as-estado-blue  { display:inline-block;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:20px;padding:.18rem .75rem;font-size:.76rem;font-weight:700; }
.as-estado-muted { display:inline-block;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:20px;padding:.18rem .75rem;font-size:.76rem;font-weight:700; }

/* Info grid (2 columnas) */
.as-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.as-info-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15,23,42,.05);
}
.as-info-card-head {
    padding: .7rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}
.as-info-card-title {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #2563eb;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.as-info-list { padding: .75rem 1rem; display: grid; gap: .55rem; }
.as-info-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: .75rem;
    align-items: baseline;
    padding: .35rem 0;
    border-bottom: 1px solid #f1f5f9;
}
.as-info-row:last-child { border-bottom: none; }
.as-info-key {
    font-size: .76rem;
    font-weight: 600;
    color: #64748b;
    line-height: 1.3;
}
.as-info-val {
    font-size: .88rem;
    color: #0f172a;
    font-weight: 400;
    line-height: 1.4;
}
.as-info-val.empty { color: #94a3b8; font-style: italic; font-weight: 400; }

/* Formulario */
.as-form { display: grid; gap: 1rem; }

.as-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15,23,42,.05);
}
.as-section-head {
    padding: .75rem 1.1rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: .65rem;
    background: #f8fafc;
}
.as-section-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .9rem;
    flex-shrink: 0;
}
.as-section-icon.amber  { background: #fef3c7; color: #d97706; }
.as-section-icon.blue   { background: #dbeafe; color: #2563eb; }
.as-section-icon.green  { background: #d1fae5; color: #059669; }
.as-section-icon.red    { background: #fee2e2; color: #dc2626; }
.as-section-icon.purple { background: #ede9fe; color: #7c3aed; }
.as-section-icon.slate  { background: #f1f5f9; color: #475569; }

.as-section-title {
    font-size: .76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #0f172a;
}
.as-section-desc {
    font-size: .76rem;
    color: #94a3b8;
    margin-left: auto;
    font-weight: 400;
}
.as-section-body { padding: 1rem 1.1rem; }

/* Grid campos */
.as-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: .85rem; }
.as-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: .85rem; }
.as-full { grid-column: 1 / -1; }

/* Label e input */
.as-label {
    display: block;
    font-size: .76rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: .32rem;
}
.as-control {
    width: 100%;
    padding: .52rem .78rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: .88rem;
    font-family: inherit;
    color: #0f172a;
    background: #fff;
    box-sizing: border-box;
    transition: border-color .15s, box-shadow .15s;
}
.as-control:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
textarea.as-control { resize: vertical; min-height: 80px; }
.as-hint {
    font-size: .72rem;
    color: #94a3b8;
    margin-top: .22rem;
    line-height: 1.4;
}

/* Checkboxes tipo toggle-card */
.as-check-grid { display: grid; gap: .5rem; }
.as-check {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .65rem .85rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    background: #f8fafc;
    transition: all .13s;
}
.as-check:hover { border-color: #93c5fd; background: #eff6ff; }
.as-check input[type=checkbox] {
    width: 16px;
    height: 16px;
    accent-color: #2563eb;
    margin-top: .1rem;
    flex-shrink: 0;
    cursor: pointer;
}
.as-check.active { border-color: #bfdbfe; background: #eff6ff; }
.as-check-title {
    font-size: .88rem;
    font-weight: 600;
    color: #0f172a;
    line-height: 1.25;
}
.as-check-sub {
    font-size: .76rem;
    color: #64748b;
    margin-top: .12rem;
    font-weight: 400;
}

/* Causal cards */
.as-causales { display: grid; gap: .5rem; }
.as-causal {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .7rem .9rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    background: #f8fafc;
    transition: all .13s;
}
.as-causal:hover { border-color: #93c5fd; }
.as-causal.active { border-color: #fde68a; background: #fffbeb; }
.as-causal input[type=checkbox] {
    width: 16px; height: 16px;
    accent-color: #d97706;
    margin-top: .12rem;
    flex-shrink: 0;
    cursor: pointer;
}
.as-causal-name { font-size: .88rem; font-weight: 600; color: #0f172a; line-height: 1.25; }
.as-causal-tipo {
    display: inline-block;
    font-size: .7rem;
    font-weight: 600;
    padding: .08rem .5rem;
    border-radius: 20px;
    margin-top: .18rem;
    background: #fef3c7;
    color: #92400e;
}

/* Acciones del formulario */
.as-form-actions {
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: 1rem 1.1rem;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 0 0 14px 14px;
}
.as-btn-save {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: #1e3a8a;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: .58rem 1.35rem;
    font-size: .84rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background .15s;
}
.as-btn-save:hover { background: #1e40af; }
.as-link-back {
    font-size: .84rem;
    color: #64748b;
    text-decoration: none;
    font-weight: 500;
}
.as-link-back:hover { color: #0f172a; }

/* Timeline historial */
.as-timeline { display: grid; gap: .65rem; }
.as-timeline-item {
    display: grid;
    grid-template-columns: 3px 1fr;
    gap: 0 .85rem;
}
.as-timeline-line {
    background: #bfdbfe;
    border-radius: 2px;
}
.as-timeline-body {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: .75rem .9rem;
    margin-bottom: 0;
}
.as-timeline-action { font-size: .84rem; font-weight: 600; color: #0f172a; }
.as-timeline-detail { font-size: .84rem; color: #334155; margin-top: .18rem; line-height: 1.45; }
.as-timeline-meta { font-size: .72rem; color: #94a3b8; margin-top: .3rem; }

/* Permisos */
.as-restricted-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: .6rem .9rem;
    font-size: .8rem;
    color: #92400e;
    display: flex;
    align-items: center;
    gap: .45rem;
    margin-bottom: 1rem;
}

/* Responsive */
@media (max-width: 980px) {
    .as-hero       { grid-template-columns: 1fr; }
    .as-hero-side  { align-items: flex-start; }
    .as-kpis       { grid-template-columns: repeat(2, 1fr); }
    .as-info-grid  { grid-template-columns: 1fr; }
    .as-grid-2,
    .as-grid-3     { grid-template-columns: 1fr; }
    .as-info-row   { grid-template-columns: 1fr; gap: .15rem; }
}
@media (max-width: 600px) {
    .as-kpis { grid-template-columns: 1fr 1fr; }
}
</style>

<?php if (!$asVisible): ?>

<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:2.5rem 2rem;
            text-align:center;box-shadow:0 1px 3px rgba(15,23,42,.05);">
    <i class="bi bi-shield-exclamation" style="font-size:2.5rem;color:#d97706;display:block;margin-bottom:.75rem;"></i>
    <h3 style="margin:0 0 .4rem;font-size:1rem;font-weight:700;color:#0f172a;">
        Aula Segura no fue marcada en la denuncia
    </h3>
    <p style="margin:0 0 .6rem;font-size:.88rem;color:#64748b;max-width:480px;margin-inline:auto;line-height:1.55;">
        Si al revisar los antecedentes se detecta que corresponde iniciar el procedimiento de
        <strong>Aula Segura</strong>, puedes activarlo ahora.
        La activación quedará registrada en el historial del expediente.
    </p>
    <p style="margin:0 0 1.5rem;font-size:.8rem;color:#94a3b8;max-width:420px;margin-inline:auto;">
        Esta acción solo activa la pestaña — la decisión formal de iniciar el procedimiento
        debe ser fundada por Dirección dentro del formulario.
    </p>
    <div style="display:flex;align-items:center;justify-content:center;gap:.85rem;flex-wrap:wrap;">
        <?php if ($puedeActivarAula): ?>
        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="marcar_posible_aula_segura">
            <button type="submit"
                    onclick="return confirm('¿Confirmas activar Aula Segura para este expediente?\nEsta acción queda registrada en el historial.');"
                    style="display:inline-flex;align-items:center;gap:.45rem;background:#b45309;
                           color:#fff;border:none;border-radius:8px;padding:.6rem 1.25rem;
                           font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;">
                <i class="bi bi-shield-fill-exclamation"></i>
                Activar Aula Segura en este caso
            </button>
        </form>
        <?php else: ?>
        <div style="display:inline-flex;align-items:center;gap:.45rem;background:#f8fafc;color:#64748b;
                    border:1px solid #e2e8f0;border-radius:8px;padding:.6rem 1.25rem;
                    font-size:.84rem;font-weight:600;">
            <i class="bi bi-lock"></i>
            No tienes permisos para activar Aula Segura
        </div>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&tab=resumen"
           style="display:inline-flex;align-items:center;gap:.4rem;background:#eff6ff;color:#2563eb;
                  border:1px solid #bfdbfe;border-radius:8px;padding:.6rem 1rem;
                  font-size:.84rem;font-weight:600;text-decoration:none;">
            <i class="bi bi-arrow-left"></i> Volver al resumen
        </a>
    </div>
</div>

<?php else: ?>

<!-- ══ HERO BANNER ══════════════════════════════════════════════ -->
<div class="as-hero">
    <div>
        <div class="as-hero-badge">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= e($estadoTexto) ?>
        </div>
        <h2 class="as-hero-title">Procedimiento Aula Segura</h2>
        <p class="as-hero-desc">
            Pestaña activada por alerta preliminar en la denuncia. La marca es preliminar —
            no inicia el procedimiento por sí sola. La decisión formal debe ser fundada por Dirección.
        </p>
    </div>
    <div class="as-hero-side">
        <div>
            <div class="as-hero-action-label">Acción recomendada</div>
            <div class="as-hero-action-text"><?= e($accionSugerida) ?></div>
        </div>
        <a class="as-btn-reporte" target="_blank"
           href="<?= APP_URL ?>/modules/denuncias/reporte_aula_segura.php?id=<?= (int)$casoId ?>">
            <i class="bi bi-printer"></i> Reporte Aula Segura
        </a>
    </div>
</div>

<!-- ══ KPIs DE ESTADO ═══════════════════════════════════════════ -->
<div class="as-kpis">
    <div class="as-kpi">
        <div class="as-kpi-label">Estado actual</div>
        <div class="as-kpi-value">
            <span class="<?= e($estadoInfo['cls']) ?>"><?= e($estadoInfo['label']) ?></span>
        </div>
    </div>
    <div class="as-kpi">
        <div class="as-kpi-label">Causales marcadas</div>
        <div class="as-kpi-value <?= $causalesMarcadas > 0 ? 'ok' : 'warn' ?>">
            <?= $causalesMarcadas ?> <?= $causalesMarcadas === 1 ? 'causal' : 'causales' ?>
        </div>
    </div>
    <div class="as-kpi">
        <div class="as-kpi-label">Inicio procedimiento</div>
        <div class="as-kpi-value <?= $fechaInicio ? '' : 'muted' ?>">
            <?= $fechaInicio ? e(caso_fecha($fechaInicio)) : 'No iniciado' ?>
        </div>
    </div>
    <div class="as-kpi">
        <div class="as-kpi-label">Supereduc</div>
        <div class="as-kpi-value <?= $supereduc ? 'ok' : 'muted' ?>">
            <?= $supereduc ? 'Comunicado' : 'Pendiente' ?>
        </div>
    </div>
</div>

<!-- ══ ESTADO E INFORMACIÓN ════════════════════════════════════ -->
<div class="as-info-grid">

    <!-- Alerta preliminar -->
    <div class="as-info-card">
        <div class="as-info-card-head">
            <span class="as-info-card-title">
                <i class="bi bi-flag-fill"></i> Alerta preliminar
            </span>
            <span style="font-size:.72rem;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:20px;padding:.1rem .6rem;font-weight:600;">Origen: denuncia</span>
        </div>
        <div class="as-info-list">
            <div class="as-info-row">
                <span class="as-info-key">Fecha de marca</span>
                <span class="as-info-val <?= $marcadoAt ? '' : 'empty' ?>">
                    <?= $marcadoAt ? e(caso_fecha($marcadoAt)) : 'Sin fecha registrada' ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Estado</span>
                <span class="as-info-val"><span class="<?= e($estadoInfo['cls']) ?>"><?= e($estadoInfo['label']) ?></span></span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Causales identificadas</span>
                <span class="as-info-val <?= $causalesTexto === 'Sin causales visibles' ? 'empty' : '' ?>">
                    <?= e($causalesTexto) ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Observación inicial</span>
                <span class="as-info-val <?= $observacionPreliminar ? '' : 'empty' ?>">
                    <?= $observacionPreliminar ? e($observacionPreliminar) : 'Sin observación preliminar.' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Control normativo -->
    <div class="as-info-card">
        <div class="as-info-card-head">
            <span class="as-info-card-title">
                <i class="bi bi-clipboard2-pulse-fill"></i> Control normativo
            </span>
            <span style="font-size:.72rem;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:20px;padding:.1rem .6rem;font-weight:600;">Operativo</span>
        </div>
        <div class="as-info-list">
            <div class="as-info-row">
                <span class="as-info-key">Inicio procedimiento</span>
                <span class="as-info-val <?= $fechaInicio ? '' : 'empty' ?>">
                    <?= $fechaInicio ? e(caso_fecha($fechaInicio)) : 'No iniciado' ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Suspensión cautelar</span>
                <span class="as-info-val">
                    <?php if ($suspCautelar): ?>
                        <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:.1rem .6rem;font-size:.76rem;font-weight:600;border:1px solid #fecaca;">Sí</span>
                    <?php else: ?>
                        <span class="empty">No</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Fecha límite resolución</span>
                <span class="as-info-val <?= $fechaLimite ? '' : 'empty' ?>">
                    <?= $fechaLimite ? e(caso_fecha($fechaLimite)) : '—' ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Descargos</span>
                <span class="as-info-val">
                    <?= $descargosRec
                        ? '<span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:.1rem .6rem;font-size:.76rem;font-weight:600;border:1px solid #6ee7b7;">Recibidos</span>'
                        : '<span class="empty">Pendiente / No aplica</span>' ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Resolución</span>
                <span class="as-info-val <?= $resolucionVal ? '' : 'empty' ?>">
                    <?= $resolucionVal ? e(ucfirst(str_replace('_',' ',$resolucionVal))) : 'Sin resolución' ?>
                </span>
            </div>
            <div class="as-info-row">
                <span class="as-info-key">Comunicación Supereduc</span>
                <span class="as-info-val">
                    <?= $supereduc
                        ? '<span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:.1rem .6rem;font-size:.76rem;font-weight:600;border:1px solid #6ee7b7;">Registrada</span>'
                        : '<span class="empty">Pendiente / No aplica</span>' ?>
                </span>
            </div>
        </div>
    </div>

</div>

<!-- ══ FORMULARIO PROCEDIMIENTO ════════════════════════════════ -->
<form method="post" class="as-form">
    <?= CSRF::field() ?>
    <input type="hidden" name="_accion" value="actualizar_aula_segura">

    <?php if (!$puedeResolverAula): ?>
    <div class="as-restricted-note">
        <i class="bi bi-lock-fill"></i>
        Las opciones de resolución, descarte e inicio formal solo pueden ser registradas por Director/a o perfil autorizado.
    </div>
    <?php endif; ?>

    <!-- 1. Causales -->
    <div class="as-section">
        <div class="as-section-head">
            <div class="as-section-icon amber"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div>
                <div class="as-section-title">Causales legales y reglamentarias</div>
            </div>
            <div class="as-section-desc">Debe marcarse al menos una causal</div>
        </div>
        <div class="as-section-body">
            <div class="as-causales">
                <?php foreach ($asCatalogo as $causal):
                    $codigo = (string)($causal['codigo'] ?? '');
                    $campo = function_exists('ver_aula_segura_campo_por_codigo') ? ver_aula_segura_campo_por_codigo($codigo) : null;
                    $marcada = $campo !== null && (int)($as[$campo] ?? 0) === 1;
                    if ($campo === null) continue;
                ?>
                <label class="as-causal <?= $marcada ? 'active' : '' ?>">
                    <input type="checkbox" name="<?= e($campo) ?>" value="1" <?= $marcada ? 'checked' : '' ?>>
                    <div>
                        <div class="as-causal-name"><?= e((string)($causal['nombre'] ?? $codigo)) ?></div>
                        <span class="as-causal-tipo"><?= e(ucfirst((string)($causal['tipo'] ?? 'legal'))) ?></span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 2. Antecedentes base -->
    <div class="as-section">
        <div class="as-section-head">
            <div class="as-section-icon blue"><i class="bi bi-file-text-fill"></i></div>
            <div class="as-section-title">Antecedentes base</div>
        </div>
        <div class="as-section-body">
            <div class="as-grid-2" style="margin-bottom:.85rem;">
                <div>
                    <label class="as-label">Fuente de información</label>
                    <input class="as-control" type="text" name="fuente_informacion"
                           value="<?= e(aula_val($as, 'fuente_informacion')) ?>"
                           placeholder="Denuncia, funcionario, evidencia, declaración">
                </div>
                <div>
                    <label class="as-label">Evidencia inicial / referencia</label>
                    <input class="as-control" type="text" name="evidencia_inicial"
                           value="<?= e(aula_val($as, 'evidencia_inicial')) ?>"
                           placeholder="Archivo, folio, relato o referencia interna">
                </div>
            </div>
            <div>
                <label class="as-label">Descripción objetiva del hecho</label>
                <textarea class="as-control" name="descripcion_hecho" rows="3"
                          placeholder="Describir el hecho base sin conclusiones anticipadas."><?= e(aula_val($as, 'descripcion_hecho')) ?></textarea>
            </div>
        </div>
    </div>

    <!-- 3. Evaluación directiva -->
    <div class="as-section">
        <div class="as-section-head">
            <div class="as-section-icon purple"><i class="bi bi-person-badge-fill"></i></div>
            <div class="as-section-title">Evaluación directiva</div>
            <?php if (!$puedeResolverAula): ?>
            <div class="as-section-desc"><i class="bi bi-lock"></i> Solo Director/a</div>
            <?php endif; ?>
        </div>
        <div class="as-section-body">
            <div class="as-grid-3">
                <div>
                    <label class="as-label">Estado Aula Segura</label>
                    <?php $estadoActual = aula_val($as, 'estado', 'posible'); ?>
                    <select class="as-control" name="estado">
                        <option value="posible"                <?= $estadoActual === 'posible'                ? 'selected' : '' ?>>Posible</option>
                        <option value="en_evaluacion"          <?= $estadoActual === 'en_evaluacion'          ? 'selected' : '' ?>>En evaluación</option>
                        <option value="descartado"             <?= $estadoActual === 'descartado'             ? 'selected' : '' ?> <?= !$puedeResolverAula ? 'disabled' : '' ?>>Descartado por Dirección</option>
                        <option value="procedimiento_iniciado" <?= $estadoActual === 'procedimiento_iniciado' ? 'selected' : '' ?> <?= !$puedeResolverAula ? 'disabled' : '' ?>>Procedimiento iniciado</option>
                        <option value="suspension_cautelar"    <?= $estadoActual === 'suspension_cautelar'    ? 'selected' : '' ?> <?= !$puedeResolverAula ? 'disabled' : '' ?>>Suspensión cautelar</option>
                        <option value="resuelto"               <?= $estadoActual === 'resuelto'               ? 'selected' : '' ?> <?= !$puedeResolverAula ? 'disabled' : '' ?>>Resuelto</option>
                        <option value="reconsideracion"        <?= $estadoActual === 'reconsideracion'        ? 'selected' : '' ?> <?= !$puedeResolverAula ? 'disabled' : '' ?>>Reconsideración</option>
                        <option value="cerrado"                <?= $estadoActual === 'cerrado'                ? 'selected' : '' ?> <?= !$puedeResolverAula ? 'disabled' : '' ?>>Cerrado</option>
                    </select>
                </div>
                <div>
                    <label class="as-label">Decisión de Dirección</label>
                    <input class="as-control" type="text" name="decision_director"
                           value="<?= e(aula_val($as, 'decision_director')) ?>"
                           placeholder="Inicia / descarta / mantiene en evaluación">
                </div>
                <div>
                    <label class="as-label">Fecha evaluación</label>
                    <input class="as-control" type="date" name="fecha_evaluacion_directiva"
                           value="<?= e(aula_date_val($as, 'fecha_evaluacion_directiva')) ?>">
                </div>
                <div>
                    <label class="as-label">Falta Reglamento Interno</label>
                    <input class="as-control" type="text" name="falta_reglamento"
                           value="<?= e(aula_val($as, 'falta_reglamento')) ?>"
                           placeholder="Solo si aplica causal reglamentaria">
                </div>
                <div class="as-full">
                    <label class="as-label">Fundamento de proporcionalidad</label>
                    <textarea class="as-control" name="fundamento_proporcionalidad" rows="3"
                              placeholder="Justificar la afectación grave de la convivencia y la proporcionalidad de la medida."><?= e(aula_val($as, 'fundamento_proporcionalidad')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Inicio, comunicación y suspensión -->
    <div class="as-section">
        <div class="as-section-head">
            <div class="as-section-icon red"><i class="bi bi-calendar-event-fill"></i></div>
            <div class="as-section-title">Inicio, comunicación y suspensión cautelar</div>
        </div>
        <div class="as-section-body">
            <div class="as-grid-3">
                <div>
                    <label class="as-label">Fecha inicio procedimiento</label>
                    <input class="as-control" type="date" name="fecha_inicio_procedimiento"
                           value="<?= e(aula_date_val($as, 'fecha_inicio_procedimiento')) ?>">
                </div>
                <div>
                    <label class="as-label">Comunicación al apoderado</label>
                    <input class="as-control" type="datetime-local" name="comunicacion_apoderado_at"
                           value="<?= e(aula_dt_local($as, 'comunicacion_apoderado_at')) ?>">
                </div>
                <div>
                    <label class="as-label">Medio de comunicación</label>
                    <input class="as-control" type="text" name="medio_comunicacion_apoderado"
                           value="<?= e(aula_val($as, 'medio_comunicacion_apoderado')) ?>"
                           placeholder="Presencial, correo, carta, teléfono">
                </div>
                <div class="as-full">
                    <label class="as-label">Observación comunicación apoderado</label>
                    <textarea class="as-control" name="observacion_comunicacion_apoderado" rows="2"><?= e(aula_val($as, 'observacion_comunicacion_apoderado')) ?></textarea>
                </div>
            </div>

            <div style="border-top:1px solid #f1f5f9;margin:1rem 0;padding-top:1rem;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.65rem;">
                    Suspensión cautelar
                </div>
                <div class="as-grid-3">
                    <div>
                        <label class="as-check <?= (int)($as['suspension_cautelar'] ?? 0) === 1 ? 'active' : '' ?>">
                            <input type="checkbox" name="suspension_cautelar" value="1"
                                   <?= (int)($as['suspension_cautelar'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <div>
                                <div class="as-check-title">Suspensión cautelar</div>
                                <div class="as-check-sub">Marcar si se activó la medida</div>
                            </div>
                        </label>
                    </div>
                    <div>
                        <label class="as-label">Fecha notificación suspensión</label>
                        <input class="as-control" type="date" name="fecha_notificacion_suspension"
                               value="<?= e(aula_date_val($as, 'fecha_notificacion_suspension')) ?>">
                    </div>
                    <div>
                        <label class="as-label">Fecha límite resolución</label>
                        <input class="as-control" type="date" name="fecha_limite_resolucion"
                               value="<?= e(aula_date_val($as, 'fecha_limite_resolucion')) ?>">
                        <div class="as-hint">Si se deja vacío y hay suspensión, Metis calcula 10 días hábiles desde la notificación.</div>
                    </div>
                    <div class="as-full">
                        <label class="as-label">Fundamento suspensión cautelar</label>
                        <textarea class="as-control" name="fundamento_suspension" rows="3"><?= e(aula_val($as, 'fundamento_suspension')) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. Descargos, resolución y reconsideración -->
    <div class="as-section">
        <div class="as-section-head">
            <div class="as-section-icon green"><i class="bi bi-check2-circle"></i></div>
            <div class="as-section-title">Descargos, resolución y reconsideración</div>
        </div>
        <div class="as-section-body">
            <!-- Descargos -->
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.65rem;">Descargos</div>
            <div class="as-grid-3" style="margin-bottom:1rem;">
                <div>
                    <label class="as-check <?= (int)($as['descargos_recibidos'] ?? 0) === 1 ? 'active' : '' ?>">
                        <input type="checkbox" name="descargos_recibidos" value="1"
                               <?= (int)($as['descargos_recibidos'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <div>
                            <div class="as-check-title">Descargos recibidos</div>
                            <div class="as-check-sub">Marcar cuando se reciban</div>
                        </div>
                    </label>
                </div>
                <div>
                    <label class="as-label">Fecha descargos</label>
                    <input class="as-control" type="date" name="fecha_descargos"
                           value="<?= e(aula_date_val($as, 'fecha_descargos')) ?>">
                </div>
                <div>
                    <label class="as-label">Observación descargos</label>
                    <input class="as-control" type="text" name="observacion_descargos"
                           value="<?= e(aula_val($as, 'observacion_descargos')) ?>"
                           placeholder="Síntesis o referencia">
                </div>
            </div>

            <!-- Resolución -->
            <div style="border-top:1px solid #f1f5f9;padding-top:1rem;margin-bottom:.65rem;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.65rem;">Resolución</div>
            </div>
            <div class="as-grid-3" style="margin-bottom:1rem;">
                <div>
                    <label class="as-label">Tipo de resolución</label>
                    <?php $resolucionActual = aula_val($as, 'resolucion'); ?>
                    <select class="as-control" name="resolucion">
                        <option value=""                    <?= $resolucionActual === ''                    ? 'selected' : '' ?>>Sin resolución</option>
                        <option value="expulsion"           <?= $resolucionActual === 'expulsion'           ? 'selected' : '' ?>>Expulsión</option>
                        <option value="cancelacion_matricula" <?= $resolucionActual === 'cancelacion_matricula' ? 'selected' : '' ?>>Cancelación de matrícula</option>
                        <option value="otra_medida"         <?= $resolucionActual === 'otra_medida'         ? 'selected' : '' ?>>Otra medida</option>
                        <option value="no_aplica"           <?= $resolucionActual === 'no_aplica'           ? 'selected' : '' ?>>No aplica</option>
                    </select>
                </div>
                <div>
                    <label class="as-label">Fecha resolución</label>
                    <input class="as-control" type="date" name="fecha_resolucion"
                           value="<?= e(aula_date_val($as, 'fecha_resolucion')) ?>">
                </div>
                <div>
                    <label class="as-label">Fecha notificación resolución</label>
                    <input class="as-control" type="date" name="fecha_notificacion_resolucion"
                           value="<?= e(aula_date_val($as, 'fecha_notificacion_resolucion')) ?>">
                </div>
                <div class="as-full">
                    <label class="as-label">Fundamento resolución</label>
                    <textarea class="as-control" name="fundamento_resolucion" rows="3"><?= e(aula_val($as, 'fundamento_resolucion')) ?></textarea>
                </div>
            </div>

            <!-- Reconsideración -->
            <div style="border-top:1px solid #f1f5f9;padding-top:1rem;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.65rem;">Reconsideración</div>
            </div>
            <div class="as-grid-3">
                <div>
                    <label class="as-check <?= (int)($as['reconsideracion_presentada'] ?? 0) === 1 ? 'active' : '' ?>">
                        <input type="checkbox" name="reconsideracion_presentada" value="1"
                               <?= (int)($as['reconsideracion_presentada'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <div>
                            <div class="as-check-title">Reconsideración presentada</div>
                            <div class="as-check-sub">Marcar si fue presentada</div>
                        </div>
                    </label>
                </div>
                <div>
                    <label class="as-label">Fecha reconsideración</label>
                    <input class="as-control" type="date" name="fecha_reconsideracion"
                           value="<?= e(aula_date_val($as, 'fecha_reconsideracion')) ?>">
                </div>
                <div>
                    <label class="as-label">Fecha límite reconsideración</label>
                    <input class="as-control" type="date" name="fecha_limite_reconsideracion"
                           value="<?= e(aula_date_val($as, 'fecha_limite_reconsideracion')) ?>">
                </div>
                <div>
                    <label class="as-label">Fecha resolución reconsideración</label>
                    <input class="as-control" type="date" name="fecha_resolucion_reconsideracion"
                           value="<?= e(aula_date_val($as, 'fecha_resolucion_reconsideracion')) ?>">
                </div>
                <div>
                    <label class="as-label">Resultado reconsideración</label>
                    <input class="as-control" type="text" name="resultado_reconsideracion"
                           value="<?= e(aula_val($as, 'resultado_reconsideracion')) ?>"
                           placeholder="Acoge, rechaza, modifica, otro">
                </div>
                <div class="as-full">
                    <label class="as-label">Fundamento reconsideración</label>
                    <textarea class="as-control" name="fundamento_reconsideracion" rows="3"><?= e(aula_val($as, 'fundamento_reconsideracion')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- 6. Supereduc y observaciones -->
    <div class="as-section">
        <div class="as-section-head">
            <div class="as-section-icon slate"><i class="bi bi-send-fill"></i></div>
            <div class="as-section-title">Comunicación a Supereduc y observaciones finales</div>
        </div>
        <div class="as-section-body">
            <div class="as-grid-3">
                <div>
                    <label class="as-check <?= (int)($as['comunicacion_supereduc'] ?? 0) === 1 ? 'active' : '' ?>">
                        <input type="checkbox" name="comunicacion_supereduc" value="1"
                               <?= (int)($as['comunicacion_supereduc'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <div>
                            <div class="as-check-title">Comunicación a Supereduc</div>
                            <div class="as-check-sub">Marcar cuando se realice</div>
                        </div>
                    </label>
                </div>
                <div>
                    <label class="as-label">Fecha comunicación</label>
                    <input class="as-control" type="date" name="fecha_comunicacion_supereduc"
                           value="<?= e(aula_date_val($as, 'fecha_comunicacion_supereduc')) ?>">
                </div>
                <div>
                    <label class="as-label">Medio de comunicación</label>
                    <input class="as-control" type="text" name="medio_comunicacion_supereduc"
                           value="<?= e(aula_val($as, 'medio_comunicacion_supereduc')) ?>"
                           placeholder="Plataforma, oficio, correo, otro">
                </div>
                <div class="as-full">
                    <label class="as-label">Observación Supereduc</label>
                    <textarea class="as-control" name="observacion_supereduc" rows="2"><?= e(aula_val($as, 'observacion_supereduc')) ?></textarea>
                </div>
                <div class="as-full">
                    <label class="as-label">Observaciones internas Aula Segura</label>
                    <textarea class="as-control" name="observaciones" rows="4"><?= e(aula_val($as, 'observaciones')) ?></textarea>
                </div>
            </div>
        </div>

        <div class="as-form-actions">
            <button class="as-btn-save" type="submit">
                <i class="bi bi-floppy-fill"></i> Guardar Aula Segura
            </button>
            <a class="as-link-back"
               href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&tab=resumen">
                Volver al resumen
            </a>
        </div>
    </div>

</form>

<!-- ══ HISTORIAL ════════════════════════════════════════════════ -->
<div class="as-section" style="margin-top:1rem;">
    <div class="as-section-head">
        <div class="as-section-icon slate"><i class="bi bi-clock-history"></i></div>
        <div class="as-section-title">Historial de movimientos</div>
        <div class="as-section-desc"><?= count($asHistorial) ?> registro(s)</div>
    </div>
    <div class="as-section-body">
        <?php if (!$asHistorial): ?>
            <div style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.88rem;">
                <i class="bi bi-clock" style="display:block;font-size:1.5rem;margin-bottom:.4rem;opacity:.5;"></i>
                Aún no hay movimientos específicos de Aula Segura.
            </div>
        <?php else: ?>
            <div class="as-timeline">
                <?php foreach ($asHistorial as $item): ?>
                <div class="as-timeline-item">
                    <div class="as-timeline-line"></div>
                    <div class="as-timeline-body">
                        <div class="as-timeline-action"><?= e((string)($item['accion'] ?? 'Movimiento')) ?></div>
                        <?php if (!empty($item['detalle'])): ?>
                        <div class="as-timeline-detail"><?= e((string)$item['detalle']) ?></div>
                        <?php endif; ?>
                        <div class="as-timeline-meta">
                            <i class="bi bi-calendar3"></i> <?= e(caso_fecha((string)($item['created_at'] ?? ''))) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-person"></i> <?= e((string)($item['usuario_nombre'] ?? 'Sistema')) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
