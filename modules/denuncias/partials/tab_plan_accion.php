<?php
// ── Tab: Plan de Acción (versionado por participante) ────────
$userId = (int)(Auth::user()['id'] ?? 0);

// Cargar participantes del caso (deduplicando víctima+denunciante)
// Agrupamos por persona (run o nombre) para mostrar condiciones combinadas
$participantesCaso = $participantes ?? [];
$participantesAgrupados = [];
foreach ($participantesCaso as $p) {
    $key = trim((string)($p['run'] ?? $p['nombre_referencial'] ?? $p['id']));
    if (!isset($participantesAgrupados[$key])) {
        $participantesAgrupados[$key] = $p;
        $participantesAgrupados[$key]['_roles'] = [];
    }
    $participantesAgrupados[$key]['_roles'][] = (string)($p['rol_en_caso'] ?? '');
    $participantesAgrupados[$key]['_ids'][]    = (int)$p['id'];
}

// Cargar planes vigentes por participante
$planesVigentes = [];
$historialPlanes = [];
try {
    $stmtPlanes = $pdo->prepare("
        SELECT pa.*, cp.nombre_referencial, cp.rol_en_caso, cp.run
        FROM caso_plan_accion pa
        INNER JOIN caso_participantes cp ON cp.id = pa.participante_id
        WHERE pa.caso_id = ? AND pa.colegio_id = ?
        ORDER BY pa.participante_id, pa.version DESC
    ");
    $stmtPlanes->execute([$casoId, $colegioId]);
    foreach ($stmtPlanes->fetchAll() as $plan) {
        $pid = (int)$plan['participante_id'];
        if ($plan['vigente']) {
            $planesVigentes[$pid] = $plan;
        }
        $historialPlanes[$pid][] = $plan;
    }
} catch (Throwable $e) {}

// Modos de acción
$accionPlan = trim((string)($_GET['plan_accion'] ?? ''));
$editarId   = (int)($_GET['editar_plan'] ?? 0);
$nuevoPara  = (int)($_GET['nuevo_plan'] ?? 0);
?>

<style>
/* ── Plan de Acción — tokens unificados ── */
.pa-card    { background:#fff;border:1px solid #e2e8f0;border-radius:14px;
              padding:1.25rem 1.5rem;margin-bottom:1rem;
              box-shadow:0 1px 3px rgba(15,23,42,.06); }
.pa-title   { font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
              color:#2563eb;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem; }
.pa-part-row{ display:grid;grid-template-columns:1fr auto;align-items:start;gap:1rem;
              padding:.85rem 1rem;border:1px solid #e2e8f0;border-radius:10px;
              margin-bottom:.65rem;background:#f8fafc; }
.pa-part-name{ font-size:.88rem;font-weight:600;color:#0f172a; }
.pa-part-sub { font-size:.76rem;color:#64748b;margin-top:.15rem; }
.pa-role-badge{ display:inline-flex;align-items:center;gap:.25rem;border-radius:20px;
                padding:.18rem .62rem;font-size:.72rem;font-weight:600;margin:.1rem; }
.pa-role-victima     { background:#fee2e2;color:#991b1b; }
.pa-role-denunciante { background:#fef3c7;color:#92400e; }
.pa-role-denunciado  { background:#fce7f3;color:#9d174d; }
.pa-role-testigo     { background:#dbeafe;color:#1e40af; }
.pa-role-otro        { background:#f1f5f9;color:#475569; }
.pa-plan-text{ font-size:.88rem;color:#334155;background:#eff6ff;
               border-left:3px solid #2563eb;padding:.65rem .85rem;
               border-radius:0 8px 8px 0;margin:.5rem 0;
               line-height:1.5;white-space:pre-line; }
.pa-plan-v  { font-size:.72rem;color:#94a3b8;margin-top:.25rem; }
.pa-actions { display:flex;gap:.4rem;flex-wrap:wrap; }
.pa-btn     { font-size:.76rem;font-weight:600;padding:.38rem .85rem;border-radius:8px;
              border:1.5px solid;background:#fff;cursor:pointer;font-family:inherit;
              display:inline-flex;align-items:center;gap:.35rem;transition:all .15s; }
.pa-btn-edit{ color:#2563eb;border-color:#bfdbfe; }
.pa-btn-edit:hover{ background:#2563eb;color:#fff; }
.pa-btn-new { color:#059669;border-color:#bbf7d0; }
.pa-btn-new:hover { background:#059669;color:#fff; }
.pa-form    { background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;
              padding:1.25rem 1.5rem;margin-top:.65rem; }
.pa-label   { display:block;font-size:.76rem;font-weight:600;color:#334155;margin-bottom:.35rem; }
.pa-ctrl    { width:100%;padding:.52rem .78rem;border:1px solid #cbd5e1;border-radius:8px;
              font-size:.88rem;box-sizing:border-box;background:#fff;color:#0f172a;
              font-family:inherit; }
.pa-ctrl:focus{ outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.pa-save-btn{ background:#1e3a8a;color:#fff;border:none;border-radius:8px;
              padding:.58rem 1.25rem;font-size:.84rem;font-weight:600;cursor:pointer;
              font-family:inherit;transition:background .15s; }
.pa-save-btn:hover{ background:#1e40af; }
.pa-hist    { font-size:.76rem;color:#94a3b8;margin-top:.75rem;padding-top:.65rem;
              border-top:1px dashed #e2e8f0; }
.pa-hist-item { display:flex;justify-content:space-between;padding:.35rem 0;
                border-bottom:1px solid #f1f5f9;font-size:.76rem; }
.pa-empty   { text-align:center;padding:2rem;color:#94a3b8;font-size:.84rem; }
.pa-sinplan { font-size:.78rem;color:#94a3b8;font-style:italic;padding:.4rem 0; }
</style>

<?php
$_iaPlan = false;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM colegio_modulos WHERE colegio_id=? AND modulo_codigo='ia' AND activo=1 AND (fecha_expiracion IS NULL OR fecha_expiracion>NOW())");
    $s->execute([$colegioId]);
    $_iaPlan = (bool)$s->fetchColumn();
} catch (Throwable $e) {}
$_mostrarIAPlan = $_iaPlan || (($currentUser['rol_codigo'] ?? '') === 'superadmin');
?>

<div class="pa-card">
    <div class="pa-title" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <span>
            <i class="bi bi-list-check" style="color:#2563eb;"></i>
            Plan de Acción por Participante
            <span style="font-weight:400;color:#94a3b8;font-size:.7rem;margin-left:.25rem;">
                — Define acciones concretas para cada interviniente
            </span>
        </span>
        <?php if ($_mostrarIAPlan): ?>
        <button type="button" id="btnSugerirPlan" onclick="sugerirPlanAccion()"
                style="display:inline-flex;align-items:center;gap:.4rem;background:#1e3a8a;
                       color:#fff;border:none;border-radius:999px;padding:.45rem .9rem;
                       font-size:.78rem;font-weight:700;cursor:pointer;">
            <i class="bi bi-stars"></i> Generar planes con IA
        </button>
        <?php endif; ?>
    </div>

    <!-- Panel sugerencias IA plan de acción -->
    <?php if ($_mostrarIAPlan): ?>
    <div id="panelSugerenciaPlan" style="display:none;margin-bottom:1.1rem;
         background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
            <span style="font-size:.82rem;font-weight:700;color:#1e3a8a;">
                <i class="bi bi-stars"></i> Planes sugeridos por IA
            </span>
            <button type="button" onclick="document.getElementById('panelSugerenciaPlan').style.display='none'"
                    style="background:none;border:none;cursor:pointer;color:#64748b;font-size:1rem;">✕</button>
        </div>
        <div id="cuerpoPlanesIA" style="font-size:.83rem;color:#334155;"></div>
    </div>
    <?php endif; ?>

    <?php if (!$participantesCaso): ?>
        <div class="pa-empty">
            <i class="bi bi-people" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
            No hay participantes registrados en el caso.
        </div>

    <?php else: ?>
        <?php foreach ($participantesAgrupados as $key => $p):
            $pId    = (int)$p['id'];
            $roles  = array_unique($p['_roles'] ?? [$p['rol_en_caso'] ?? '']);
            $planV  = $planesVigentes[$pId] ?? null;
            $histP  = $historialPlanes[$pId] ?? [];
            $modoEditar = ($editarId === $pId);
            $modoNuevo  = ($nuevoPara === $pId);
        ?>
        <div class="pa-part-row">
            <div>
                <!-- Nombre + roles (deduplicado) -->
                <div class="pa-part-name"><?= e((string)($p['nombre_referencial'] ?? '')) ?></div>
                <div class="pa-part-sub">
                    <?= e($p['tipo_persona'] ?? 'Persona') ?>
                    <?php if (!empty($p['run'])): ?> · RUN <?= e((string)$p['run']) ?><?php endif; ?>
                </div>
                <div style="margin-top:.3rem;">
                    <?php foreach ($roles as $rol): ?>
                        <?php
                        $rolCls = match(strtolower(trim($rol))) {
                            'victima','víctima' => 'pa-role-victima',
                            'denunciante'       => 'pa-role-denunciante',
                            'denunciado'        => 'pa-role-denunciado',
                            'testigo'           => 'pa-role-testigo',
                            default             => 'pa-role-otro',
                        };
                        ?>
                        <span class="pa-role-badge <?= $rolCls ?>">
                            <?= e(ucfirst(trim($rol))) ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <!-- Plan vigente -->
                <?php if ($planV): ?>
                    <div class="pa-plan-text"><?= e((string)$planV['plan_accion']) ?></div>
                    <?php if ($planV['medidas_preventivas']): ?>
                        <div style="font-size:.75rem;color:#374151;margin:.3rem 0;padding:.4rem .75rem;
                                    background:#f0fdf4;border-left:3px solid #059669;border-radius:0 6px 6px 0;">
                            <strong style="color:#059669;font-size:.72rem;text-transform:uppercase;">Medidas:</strong>
                            <?= e((string)$planV['medidas_preventivas']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="pa-plan-v">
                        Versión <?= (int)$planV['version'] ?> ·
                        <?= date('d-m-Y', strtotime((string)$planV['created_at'])) ?>
                    </div>
                <?php else: ?>
                    <div class="pa-sinplan">Sin plan de acción definido aún.</div>
                <?php endif; ?>

                <!-- Formulario: EDITAR versión actual -->
                <?php if ($modoEditar && $planV): ?>
                <div class="pa-form">
                    <div style="font-size:.8rem;font-weight:700;color:#2563eb;margin-bottom:.75rem;">
                        <i class="bi bi-pencil-square"></i> Modificar plan (quedará como versión <?= (int)$planV['version'] + 1 ?>)
                    </div>
                    <form method="post">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="_accion"        value="guardar_plan_accion">
                        <input type="hidden" name="participante_id" value="<?= $pId ?>">
                        <input type="hidden" name="es_modificacion" value="1">
                        <input type="hidden" name="plan_anterior_id" value="<?= (int)$planV['id'] ?>">

                        <div style="margin-bottom:.75rem;">
                            <label class="pa-label">Plan de acción *</label>
                            <textarea class="pa-ctrl" name="plan_accion" rows="4" required
                                ><?= e((string)$planV['plan_accion']) ?></textarea>
                        </div>
                        <div style="margin-bottom:.75rem;">
                            <label class="pa-label">Medidas preventivas</label>
                            <textarea class="pa-ctrl" name="medidas_preventivas" rows="2"
                                ><?= e((string)($planV['medidas_preventivas'] ?? '')) ?></textarea>
                        </div>
                        <div style="margin-bottom:.85rem;">
                            <label class="pa-label">Motivo de la modificación *</label>
                            <input class="pa-ctrl" type="text" name="motivo_version" required
                                   placeholder="Ej: Nueva información aportada, cambio de condición del caso...">
                        </div>
                        <div style="display:flex;gap:.5rem;">
                            <button type="submit" class="pa-save-btn">
                                <i class="bi bi-check-circle-fill"></i> Guardar modificación
                            </button>
                            <a href="?id=<?= $casoId ?>&tab=plan_accion"
                               style="font-size:.82rem;font-weight:600;color:#64748b;
                                      padding:.5rem .85rem;text-decoration:none;">
                                Cancelar
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Formulario: NUEVO plan (participante sin plan o plan adicional) -->
                <?php elseif ($modoNuevo || !$planV): ?>
                <div class="pa-form" <?= !$planV ? 'style="margin-top:.65rem;"' : '' ?>>
                    <div style="font-size:.8rem;font-weight:700;color:#059669;margin-bottom:.75rem;">
                        <i class="bi bi-plus-circle-fill"></i>
                        <?= $planV ? 'Crear versión ' . ((int)$planV['version'] + 1) : 'Definir plan de acción' ?>
                    </div>
                    <form method="post">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="_accion"         value="guardar_plan_accion">
                        <input type="hidden" name="participante_id"  value="<?= $pId ?>">
                        <input type="hidden" name="es_modificacion"  value="0">
                        <?php if ($planV): ?>
                        <input type="hidden" name="plan_anterior_id" value="<?= (int)$planV['id'] ?>">
                        <div style="margin-bottom:.75rem;">
                            <label class="pa-label">Motivo de la nueva versión *</label>
                            <input class="pa-ctrl" type="text" name="motivo_version" required
                                   placeholder="Ej: Actualización por nuevos antecedentes...">
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="motivo_version" value="Plan inicial">
                        <?php endif; ?>
                        <div style="margin-bottom:.75rem;">
                            <label class="pa-label">Plan de acción *</label>
                            <textarea class="pa-ctrl" name="plan_accion" rows="4" required
                                      placeholder="Acciones concretas definidas para este participante..."></textarea>
                        </div>
                        <div style="margin-bottom:.85rem;">
                            <label class="pa-label">Medidas preventivas</label>
                            <textarea class="pa-ctrl" name="medidas_preventivas" rows="2"
                                      placeholder="Medidas de resguardo o apoyo aplicadas..."></textarea>
                        </div>
                        <div style="display:flex;gap:.5rem;">
                            <button type="submit" class="pa-save-btn"
                                    style="background:#059669;">
                                <i class="bi bi-check-circle-fill"></i>
                                <?= $planV ? 'Crear nueva versión' : 'Guardar plan' ?>
                            </button>
                            <?php if ($planV): ?>
                            <a href="?id=<?= $casoId ?>&tab=plan_accion"
                               style="font-size:.82rem;font-weight:600;color:#64748b;
                                      padding:.5rem .85rem;text-decoration:none;">
                                Cancelar
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Historial de versiones -->
                <?php if (count($histP) > 1): ?>
                <div class="pa-hist">
                    <strong style="font-size:.72rem;color:#64748b;">Versiones anteriores:</strong>
                    <?php foreach ($histP as $hv):
                        if ($hv['vigente']) continue; ?>
                        <div class="pa-hist-item">
                            <span>
                                v<?= (int)$hv['version'] ?> ·
                                <?= e(mb_strimwidth((string)$hv['plan_accion'], 0, 55, '…')) ?>
                                <?php if ($hv['motivo_version']): ?>
                                    <em style="color:#94a3b8;"> — <?= e((string)$hv['motivo_version']) ?></em>
                                <?php endif; ?>
                            </span>
                            <span style="white-space:nowrap;margin-left:.5rem;">
                                <?= date('d-m-Y', strtotime((string)$hv['created_at'])) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Botones acción -->
            <div class="pa-actions" style="flex-direction:column;">
                <?php if ($planV && !$modoEditar && !$modoNuevo): ?>
                    <a href="?id=<?= $casoId ?>&tab=plan_accion&editar_plan=<?= $pId ?>"
                       class="pa-btn pa-btn-edit">
                        <i class="bi bi-pencil"></i> Modificar
                    </a>
                    <a href="?id=<?= $casoId ?>&tab=plan_accion&nuevo_plan=<?= $pId ?>"
                       class="pa-btn pa-btn-new">
                        <i class="bi bi-plus-circle"></i> Nueva versión
                    </a>
                <?php elseif (!$planV && !$modoNuevo): ?>
                    <a href="?id=<?= $casoId ?>&tab=plan_accion&nuevo_plan=<?= $pId ?>"
                       class="pa-btn pa-btn-new">
                        <i class="bi bi-plus-circle"></i> Definir plan
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function sugerirPlanAccion() {
    const btn    = document.getElementById('btnSugerirPlan');
    const panel  = document.getElementById('panelSugerenciaPlan');
    const cuerpo = document.getElementById('cuerpoPlanesIA');

    btn.disabled   = true;
    btn.innerHTML  = '<i class="bi bi-hourglass-split"></i> Consultando IA…';
    panel.style.display = 'block';
    cuerpo.innerHTML    = '<em style="color:#64748b;">Generando planes según el reglamento y los hechos del caso…</em>';

    const token = document.querySelector('[name="_token"]')?.value ?? '';
    const fd    = new FormData();
    fd.append('_token',  token);
    fd.append('caso_id', '<?= (int)$casoId ?>');
    fd.append('tipo',    'plan_accion');

    fetch('<?= APP_URL ?>/modules/denuncias/ajax/sugerir_ia.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(data => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-stars"></i> Generar planes con IA';

        if (!data.ok) {
            cuerpo.innerHTML = '<span style="color:#dc2626;">Error: ' + (data.error ?? 'desconocido') + '</span>';
            return;
        }

        const planes = data.datos?.planes ?? [];
        if (!planes.length) {
            cuerpo.innerHTML = '<em style="color:#64748b;">La IA no generó planes. Intenta nuevamente.</em>';
            return;
        }

        const colorRol = {victima:'#fee2e2',denunciante:'#fef3c7',denunciado:'#fce7f3',testigo:'#dbeafe'};

        cuerpo.innerHTML = planes.map((plan, i) => `
            <div style="background:#fff;border:1px solid #bfdbfe;border-radius:10px;
                        padding:.85rem;margin-bottom:.75rem;">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                    <span style="font-weight:700;font-size:.88rem;">${plan.nombre ?? '—'}</span>
                    <span style="background:${colorRol[plan.rol]??'#f1f5f9'};border-radius:20px;
                                 padding:.15rem .55rem;font-size:.72rem;font-weight:700;">
                        ${plan.rol ?? ''}
                    </span>
                    <span style="margin-left:auto;font-size:.72rem;color:#64748b;">
                        Plazo: ${plan.plazo_dias ?? '—'} días · ${plan.responsable ?? ''}
                    </span>
                </div>
                <p style="font-size:.82rem;color:#334155;margin:.4rem 0;line-height:1.55;
                           white-space:pre-line;">${plan.texto_plan ?? '—'}</p>
                <button type="button"
                        onclick="usarPlanIA(${i})"
                        style="margin-top:.5rem;background:#059669;color:#fff;border:none;
                               border-radius:8px;padding:.35rem .8rem;font-size:.76rem;
                               font-weight:700;cursor:pointer;">
                    <i class="bi bi-clipboard-check"></i> Usar este plan
                </button>
            </div>
        `).join('') + (!data.con_reglamento
            ? '<p style="font-size:.75rem;color:#b45309;margin-top:.25rem;"><i class="bi bi-exclamation-triangle"></i> Sin reglamento — sugerencia basada solo en marco legal nacional.</p>'
            : '');

        // Guardar para usar después
        window._planesIA = planes;
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-stars"></i> Generar planes con IA';
        cuerpo.innerHTML = '<span style="color:#dc2626;">Error de conexión.</span>';
    });
}

function usarPlanIA(idx) {
    const plan = (window._planesIA ?? [])[idx];
    if (!plan) return;

    // Buscar el textarea del participante por nombre (coincidencia parcial)
    const nombre    = (plan.nombre ?? '').toLowerCase();
    const textareas = document.querySelectorAll('textarea[name="plan_accion"]');

    // Buscar el pa-part-row que contiene ese nombre
    let encontrado = false;
    document.querySelectorAll('.pa-part-row').forEach(row => {
        const nameEl = row.querySelector('.pa-part-name');
        if (!nameEl) return;
        if (nameEl.textContent.toLowerCase().includes(nombre.substring(0, 10))) {
            const ta = row.querySelector('textarea[name="plan_accion"]');
            if (ta) {
                ta.value = plan.texto_plan ?? '';
                ta.focus();
                ta.scrollIntoView({ behavior:'smooth', block:'center' });
                encontrado = true;
            }
            // Si no hay textarea visible (modo ver), abrir modo edición
            if (!encontrado) {
                const btnNuevo = row.querySelector('.pa-btn-new, .pa-btn-edit');
                if (btnNuevo) {
                    // Guardar en sessionStorage para recuperar tras recarga
                    sessionStorage.setItem('ia_plan_' + (plan.rol ?? ''), plan.texto_plan ?? '');
                    btnNuevo.click();
                }
            }
        }
    });

    if (!encontrado && textareas.length > 0) {
        textareas[0].value = plan.texto_plan ?? '';
        textareas[0].focus();
    }
}
</script>
