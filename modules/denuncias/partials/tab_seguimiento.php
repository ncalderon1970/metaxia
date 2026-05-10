<?php
// ── Tab: Seguimiento (bitácora de sesiones por participante) ─

// Helpers
function seg_val(array $d, string $k, string $def = ''): string {
    return isset($d[$k]) && $d[$k] !== null ? (string)$d[$k] : $def;
}

// Deduplicar participantes víctima+denunciante
$participantesCaso = $participantes ?? [];
$participantesAgrupados = [];
foreach ($participantesCaso as $p) {
    $key = trim((string)($p['run'] ?? $p['nombre_referencial'] ?? $p['id']));
    if (!isset($participantesAgrupados[$key])) {
        $participantesAgrupados[$key] = $p;
        $participantesAgrupados[$key]['_roles'] = [];
        $participantesAgrupados[$key]['_ids']   = [];
    }
    $participantesAgrupados[$key]['_roles'][] = (string)($p['rol_en_caso'] ?? '');
    $participantesAgrupados[$key]['_ids'][]   = (int)$p['id'];
}

// Participante seleccionado
$pSelId = (int)($_GET['seg_part'] ?? array_values($participantesAgrupados)[0]['id'] ?? 0);

// Cargar plan vigente del participante seleccionado
$planVigente = null;
try {
    $stmtPlan = $pdo->prepare("
        SELECT * FROM caso_plan_accion
        WHERE caso_id = ? AND colegio_id = ? AND participante_id = ? AND vigente = 1
        LIMIT 1
    ");
    $stmtPlan->execute([$casoId, $colegioId, $pSelId]);
    $planVigente = $stmtPlan->fetch() ?: null;
} catch (Throwable $e) {}

// Cargar sesiones anteriores del participante seleccionado
$sesiones = [];
try {
    $stmtSes = $pdo->prepare("
        SELECT css.*, u.nombre AS registrado_por_nombre
        FROM caso_seguimiento_sesion css
        LEFT JOIN usuarios u ON u.id = css.registrado_por
        WHERE css.caso_id = ? AND css.colegio_id = ? AND css.participante_id = ?
        ORDER BY css.created_at DESC
        LIMIT 20
    ");
    $stmtSes->execute([$casoId, $colegioId, $pSelId]);
    $sesiones = $stmtSes->fetchAll();
} catch (Throwable $e) {}

$userId = (int)(Auth::user()['id'] ?? 0);
?>

<style>
/* ── Seguimiento — tokens unificados ── */
.seg-selector  { background:#fff;border:1px solid #e2e8f0;border-radius:14px;
                 padding:1rem 1.25rem;margin-bottom:1rem;
                 display:flex;align-items:center;gap:.85rem;flex-wrap:wrap; }
.seg-sel-label { font-size:.76rem;font-weight:600;color:#334155;white-space:nowrap; }
.seg-sel-ctrl  { font-size:.88rem;padding:.52rem .78rem;border:1px solid #cbd5e1;
                 border-radius:8px;background:#fff;flex:1;min-width:220px;color:#0f172a;
                 font-family:inherit; }
.seg-card      { background:#fff;border:1px solid #e2e8f0;border-radius:14px;
                 padding:1.25rem 1.5rem;margin-bottom:1rem;
                 box-shadow:0 1px 3px rgba(15,23,42,.06); }
.seg-card-title{ font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
                 color:#2563eb;margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem; }
.seg-plan-ref  { background:#eff6ff;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;
                 padding:.65rem .9rem;font-size:.88rem;color:#334155;margin-bottom:.75rem;
                 line-height:1.5;white-space:pre-line; }
.seg-plan-lbl  { font-size:.72rem;font-weight:600;color:#2563eb;text-transform:uppercase;
                 letter-spacing:.07em;display:block;margin-bottom:.25rem; }
.seg-label     { display:block;font-size:.76rem;font-weight:600;color:#334155;margin-bottom:.35rem; }
.seg-ctrl      { width:100%;padding:.52rem .78rem;border:1px solid #cbd5e1;border-radius:8px;
                 font-size:.88rem;box-sizing:border-box;background:#fff;color:#0f172a;
                 font-family:inherit; }
.seg-ctrl:focus{ outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.seg-form-grid { display:grid;grid-template-columns:1fr 1fr 1fr;gap:.85rem;margin-bottom:.85rem; }
.seg-save-btn  { background:#1e3a8a;color:#fff;border:none;border-radius:8px;
                 padding:.58rem 1.4rem;font-size:.84rem;font-weight:600;cursor:pointer;
                 font-family:inherit;transition:background .15s; }
.seg-save-btn:hover{ background:#1e40af; }
.seg-role-badge{ display:inline-flex;align-items:center;border-radius:20px;
                 padding:.18rem .62rem;font-size:.72rem;font-weight:600;margin:.1rem; }
.seg-role-victima     { background:#fee2e2;color:#991b1b; }
.seg-role-denunciante { background:#fef3c7;color:#92400e; }
.seg-role-denunciado  { background:#fce7f3;color:#9d174d; }
.seg-role-testigo     { background:#dbeafe;color:#1e40af; }
.seg-role-otro        { background:#f1f5f9;color:#475569; }
.seg-hist-item { border:1px solid #e2e8f0;border-radius:12px;padding:1rem 1.25rem;
                 margin-bottom:.65rem;background:#f8fafc; }
.seg-hist-hd   { display:flex;justify-content:space-between;align-items:flex-start;
                 flex-wrap:wrap;gap:.5rem;margin-bottom:.55rem; }
.seg-hist-fecha{ font-size:.72rem;color:#94a3b8;white-space:nowrap; }
.seg-badge     { border-radius:20px;padding:.18rem .6rem;font-size:.72rem;
                 font-weight:600;display:inline-block;margin:.1rem; }
.seg-badge-verde { background:#d1fae5;color:#065f46; }
.seg-badge-azul  { background:#dbeafe;color:#1e40af; }
.seg-badge-ambar { background:#fef3c7;color:#92400e; }
.seg-badge-rojo  { background:#fee2e2;color:#991b1b; }
.seg-empty     { text-align:center;padding:1.5rem;color:#94a3b8;font-size:.84rem; }
@media(max-width:680px){ .seg-form-grid{ grid-template-columns:1fr; } }
</style>

<!-- ── Selector de participante ──────────────────────────── -->
<form method="get" class="seg-selector">
    <input type="hidden" name="id"  value="<?= $casoId ?>">
    <input type="hidden" name="tab" value="seguimiento">
    <label class="seg-sel-label"><i class="bi bi-person-fill"></i> Trabajando con:</label>
    <select class="seg-sel-ctrl" name="seg_part" onchange="this.form.submit()">
        <?php foreach ($participantesAgrupados as $p):
            $roles = array_unique($p['_roles'] ?? []);
            $rolesStr = implode(' + ', array_map('ucfirst', $roles));
        ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pSelId ? 'selected' : '' ?>>
                <?= e((string)($p['nombre_referencial'] ?? '')) ?>
                <?= $rolesStr ? ' · ' . e($rolesStr) : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($pSelId > 0 && !empty($participantesCaso)): ?>

<!-- ── Plan vigente del participante (referencia) ─────────── -->
<?php if ($planVigente): ?>
<div class="seg-card" style="border-color:#bae6fd;">
    <div class="seg-card-title" style="color:#2563eb;">
        <i class="bi bi-list-check"></i> Plan de acción vigente
        <a href="?id=<?= $casoId ?>&tab=plan_accion"
           style="font-size:.76rem;font-weight:600;color:#2563eb;margin-left:auto;text-decoration:none;">
            <i class="bi bi-box-arrow-up-right"></i> Ir al Plan de Acción
        </a>
    </div>
    <span class="seg-plan-lbl">Plan (v<?= (int)$planVigente['version'] ?>):</span>
    <div class="seg-plan-ref"><?= e((string)$planVigente['plan_accion']) ?></div>
    <?php if ($planVigente['medidas_preventivas']): ?>
        <span class="seg-plan-lbl" style="color:#059669;">Medidas preventivas:</span>
        <div class="seg-plan-ref" style="border-color:#059669;background:#f0fdf4;">
            <?= e((string)$planVigente['medidas_preventivas']) ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:.75rem 1rem;
            margin-bottom:1rem;font-size:.82rem;color:#92400e;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    Este participante aún no tiene plan de acción definido.
    <a href="?id=<?= $casoId ?>&tab=plan_accion" style="font-weight:700;color:#92400e;">
        Definir ahora →
    </a>
</div>
<?php endif; ?>

<!-- ── Formulario nueva sesión ────────────────────────────── -->
<div class="seg-card">
    <div class="seg-card-title">
        <i class="bi bi-journal-plus"></i> Registrar sesión de seguimiento
    </div>

    <form method="post">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion"        value="guardar_sesion_seguimiento">
        <input type="hidden" name="participante_id" value="<?= $pSelId ?>">
        <?php if ($planVigente): ?>
        <input type="hidden" name="plan_accion_id"  value="<?= (int)$planVigente['id'] ?>">
        <?php endif; ?>

        <div style="margin-bottom:.85rem;">
            <label class="seg-label">Observación / Acuerdos de la sesión *</label>
            <textarea class="seg-ctrl" name="observacion_avance" rows="4" required
                      placeholder="¿Qué ocurrió en esta sesión? Acuerdos, compromisos, observaciones relevantes..."></textarea>
        </div>

        <div style="margin-bottom:.85rem;">
            <label class="seg-label">Medidas aplicadas en esta sesión</label>
            <textarea class="seg-ctrl" name="medidas_sesion" rows="2"
                      placeholder="Medidas concretas aplicadas o acordadas en esta sesión..."></textarea>
        </div>

        <div class="seg-form-grid">
            <div>
                <label class="seg-label">Estado del caso</label>
                <select class="seg-ctrl" name="estado_caso">
                    <option value="en_proceso">En Proceso</option>
                    <option value="en_revision">En Revisión</option>
                    <option value="resuelto">Resuelto</option>
                    <option value="cerrado">Cerrado</option>
                </select>
            </div>
            <div>
                <label class="seg-label">Cumplimiento del plan</label>
                <select class="seg-ctrl" name="cumplimiento_plan">
                    <option value="en_proceso">En Proceso</option>
                    <option value="parcial">Parcial</option>
                    <option value="cumplido">Cumplido</option>
                    <option value="no_cumplido">No Cumplido</option>
                </select>
            </div>
            <div>
                <label class="seg-label">Próxima revisión</label>
                <input class="seg-ctrl" type="date" name="proxima_revision">
            </div>
        </div>

        <div style="background:#f8fafd;border:1px solid #e2e8f0;border-radius:8px;
                    padding:.85rem 1rem;margin-bottom:.85rem;">
            <div style="font-size:.74rem;font-weight:700;color:#374151;margin-bottom:.6rem;">
                <i class="bi bi-telephone"></i> Comunicación al apoderado
            </div>
            <div class="seg-form-grid">
                <div>
                    <label class="seg-label">Modalidad</label>
                    <select class="seg-ctrl" name="comunicacion_apoderado">
                        <option value="">— No corresponde —</option>
                        <option value="presencial">Presencial</option>
                        <option value="telefono">Teléfono</option>
                        <option value="correo">Correo</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="libreta">Libreta</option>
                    </select>
                </div>
                <div>
                    <label class="seg-label">Fecha y hora</label>
                    <input class="seg-ctrl" type="datetime-local" name="fecha_comunicacion_apoderado">
                </div>
                <div>
                    <label class="seg-label">Notas</label>
                    <input class="seg-ctrl" type="text" name="notas_comunicacion"
                           placeholder="Resultado, acuerdos...">
                </div>
            </div>
        </div>

        <div style="text-align:right;">
            <button type="submit" class="seg-save-btn">
                <i class="bi bi-check-circle-fill"></i> Registrar sesión
            </button>
        </div>
    </form>
</div>

<!-- ── Historial de sesiones del participante ─────────────── -->
<?php if ($sesiones): ?>
<div class="seg-card">
    <div class="seg-card-title">
        <i class="bi bi-clock-history"></i>
        Historial de sesiones
        <span style="font-weight:400;color:#94a3b8;font-size:.72rem;">
            · <?= count($sesiones) ?> registro(s)
        </span>
    </div>

    <?php foreach ($sesiones as $ses): ?>
    <div class="seg-hist-item">
        <div class="seg-hist-hd">
            <div style="display:flex;flex-wrap:wrap;gap:.25rem;">
                <?php
                $estCls = match($ses['estado_caso'] ?? '') {
                    'resuelto','cerrado' => 'seg-badge-verde',
                    'en_revision'        => 'seg-badge-azul',
                    'en_proceso'         => 'seg-badge-ambar',
                    default              => 'seg-badge-azul',
                };
                $cumCls = match($ses['cumplimiento_plan'] ?? '') {
                    'cumplido'    => 'seg-badge-verde',
                    'parcial'     => 'seg-badge-ambar',
                    'no_cumplido' => 'seg-badge-rojo',
                    default       => 'seg-badge-azul',
                };
                ?>
                <span class="seg-badge <?= $estCls ?>">
                    <?= e(ucfirst(str_replace('_',' ',(string)($ses['estado_caso']??'')))) ?>
                </span>
                <span class="seg-badge <?= $cumCls ?>">
                    <?= e(ucfirst(str_replace('_',' ',(string)($ses['cumplimiento_plan']??'')))) ?>
                </span>
            </div>
            <div class="seg-hist-fecha">
                <?= !empty($ses['created_at']) ? date('d-m-Y H:i', strtotime((string)$ses['created_at'])) : '' ?>
                <?php if (!empty($ses['registrado_por_nombre'])): ?>
                    · <?= e((string)$ses['registrado_por_nombre']) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($ses['observacion_avance'])): ?>
        <div style="font-size:.83rem;color:#374151;margin-bottom:.35rem;">
            <strong style="font-size:.72rem;color:#2563eb;text-transform:uppercase;
                           letter-spacing:.05em;">Observación:</strong>
            <?= e((string)$ses['observacion_avance']) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($ses['medidas_sesion'])): ?>
        <div style="font-size:.8rem;color:#374151;margin-bottom:.35rem;">
            <strong style="font-size:.72rem;color:#059669;text-transform:uppercase;
                           letter-spacing:.05em;">Medidas:</strong>
            <?= e((string)$ses['medidas_sesion']) ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.73rem;color:#64748b;margin-top:.35rem;">
            <?php if (!empty($ses['proxima_revision'])): ?>
                <span><i class="bi bi-calendar3"></i>
                    Próx. revisión: <?= date('d-m-Y', strtotime((string)$ses['proxima_revision'])) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($ses['comunicacion_apoderado'])): ?>
                <span><i class="bi bi-telephone"></i>
                    <?= e(ucfirst((string)$ses['comunicacion_apoderado'])) ?>
                    <?= !empty($ses['fecha_comunicacion_apoderado'])
                        ? ' · ' . date('d-m-Y', strtotime((string)$ses['fecha_comunicacion_apoderado']))
                        : '' ?>
                    <?= !empty($ses['notas_comunicacion'])
                        ? ' — ' . e(mb_strimwidth((string)$ses['notas_comunicacion'], 0, 40, '…'))
                        : '' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="seg-empty">
    <i class="bi bi-journal-x" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
    No hay sesiones registradas para este participante aún.
</div>
<?php endif; ?>

<?php endif; // pSelId ?>
