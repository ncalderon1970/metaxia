<?php
// Tab: Análisis IA — Módulo premium
// Controlado por colegio_modulos WHERE modulo_codigo = 'ia'
?>

<?php
// ============================================================
// Fase IA — Sección de Análisis IA al fondo del tab Seguimiento
// ============================================================
$ultimoAnalisis = null;
try {
    $stmtUltimo = $pdo->prepare("
        SELECT * FROM caso_analisis_ia
        WHERE caso_id = ? AND colegio_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmtUltimo->execute([$casoId, $colegioId]);
    $ultimoAnalisis = $stmtUltimo->fetch() ?: null;
} catch (Throwable $e) { /* tabla no existe aún */ }

$tieneReglamento = false;
try {
    $stmtReg = $pdo->prepare("SELECT id FROM colegio_reglamentos WHERE colegio_id = ? AND activo = 1 LIMIT 1");
    $stmtReg->execute([$colegioId]);
    $tieneReglamento = (bool)$stmtReg->fetchColumn();
} catch (Throwable $e) { /* silencioso */ }

$medidasIaGuardadas = [];
if ($ultimoAnalisis && !empty($ultimoAnalisis['medidas_json'])) {
    $medidasIaGuardadas = json_decode((string)$ultimoAnalisis['medidas_json'], true) ?: [];
}

$csrfToken = CSRF::token();
?>

<style>
/* ── Análisis IA — tokens unificados ── */
.ia-section         { margin-top:2.5rem; }
.ia-header          { display:flex;justify-content:space-between;align-items:center;
                      padding:1rem 1.25rem;
                      background:linear-gradient(135deg,#1e3a8a,#2563eb);
                      border-radius:14px 14px 0 0;color:#fff; }
.ia-header-left     { display:flex;align-items:center;gap:.65rem; }
.ia-title           { font-size:.84rem;font-weight:700;letter-spacing:.02em; }
.ia-subtitle        { font-size:.76rem;opacity:.8;margin-top:.1rem; }
.ia-badge-reg       { font-size:.72rem;padding:.18rem .6rem;border-radius:20px;font-weight:600; }
.ia-badge-reg.ok    { background:rgba(255,255,255,.2);color:#fff; }
.ia-badge-reg.warn  { background:#fef3c7;color:#92400e; }
.ia-btn-analizar    { background:#f5c518;color:#1e3a8a;border:none;border-radius:8px;
                      padding:.52rem 1.2rem;font-size:.84rem;font-weight:700;
                      cursor:pointer;font-family:inherit;
                      display:flex;align-items:center;gap:.4rem;transition:all .15s; }
.ia-btn-analizar:hover { transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.25); }
.ia-btn-analizar:disabled { opacity:.55;cursor:not-allowed;transform:none; }
.ia-body            { border:1px solid #bfdbfe;border-top:none;
                      border-radius:0 0 14px 14px;background:#f8fafc;padding:1.5rem; }
.ia-empty           { text-align:center;padding:2rem 1rem;color:#94a3b8; }
.ia-empty-icon      { font-size:2.5rem;display:block;margin-bottom:.5rem; }
.ia-loading         { display:none;text-align:center;padding:2rem;color:#1e3a8a; }
.ia-loading-spinner { width:36px;height:36px;border:3px solid #bfdbfe;
                      border-top-color:#1e3a8a;border-radius:50%;
                      animation:ia-spin .8s linear infinite;margin:0 auto .75rem; }
@keyframes ia-spin  { to { transform:rotate(360deg); } }
.ia-result          { display:none; }
.ia-result.visible  { display:block; }
.ia-resumen         { background:#fff;border-radius:10px;padding:1rem 1.25rem;
                      border-left:4px solid #2563eb;margin-bottom:1.25rem;
                      font-size:.88rem;line-height:1.6;color:#334155; }
.ia-meta-row        { display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem; }
.ia-meta-pill       { padding:.2rem .7rem;border-radius:20px;font-size:.72rem;font-weight:600; }
.ia-grav-critica    { background:#fee2e2;color:#991b1b; }
.ia-grav-alta       { background:#fce8cc;color:#92400e; }
.ia-grav-media      { background:#fef3c7;color:#92400e; }
.ia-grav-baja       { background:#d1fae5;color:#065f46; }
.ia-pill-tokens     { background:#eff6ff;color:#1e3a8a; }
.ia-pill-reg        { background:#d1fae5;color:#065f46; }
.ia-pill-noreg      { background:#f1f5f9;color:#64748b; }
.ia-alerta          { background:#fef3c7;border:1px solid #fde68a;border-radius:8px;
                      padding:.7rem 1rem;margin-bottom:1.25rem;font-size:.84rem;
                      color:#92400e;display:flex;gap:.5rem;align-items:flex-start; }
.ia-fundamento      { background:#eff6ff;border-radius:8px;padding:.85rem 1rem;
                      font-size:.84rem;color:#334155;margin-bottom:1.25rem;
                      border-left:3px solid #2563eb; }
.ia-medidas-title   { font-size:.84rem;font-weight:700;color:#1e3a8a;
                      margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem; }
.ia-medidas-grid    { display:grid;gap:.85rem; }
.ia-medida-card     { background:#fff;border:1px solid #e2e8f0;border-radius:10px;
                      padding:1rem 1.1rem; }
.ia-medida-card.aprobada { border-color:#059669;background:#f0fdf4; }
.ia-medida-tipo     { display:inline-block;padding:.14rem .58rem;border-radius:20px;
                      font-size:.72rem;font-weight:600;background:#eff6ff;
                      color:#1e3a8a;margin-bottom:.5rem; }
.ia-medida-desc     { font-size:.88rem;color:#334155;line-height:1.55;margin-bottom:.65rem; }
.ia-medida-meta     { display:flex;flex-wrap:wrap;gap:.4rem;font-size:.76rem;
                      color:#94a3b8;margin-bottom:.75rem; }
.ia-medida-meta span{ background:#f1f5f9;border-radius:4px;padding:.1rem .45rem; }
.ia-medida-meta .alta  { color:#991b1b;font-weight:700; }
.ia-medida-meta .media { color:#92400e;font-weight:700; }
.ia-medida-meta .baja  { color:#065f46;font-weight:700; }
.ia-btn-aprobar     { background:#1e3a8a;color:#fff;border:none;border-radius:8px;
                      padding:.42rem .9rem;font-size:.78rem;font-weight:600;
                      cursor:pointer;font-family:inherit;transition:background .15s; }
.ia-btn-aprobar:hover  { background:#1e40af; }
.ia-btn-aprobado    { background:#059669;color:#fff;border:none;border-radius:8px;
                      padding:.42rem .9rem;font-size:.78rem;font-weight:600;cursor:default; }
.ia-btn-aprobar:disabled { opacity:.5;cursor:wait; }
.ia-historial-info  { font-size:.76rem;color:#94a3b8;text-align:right;margin-top:.75rem; }
.ia-error-msg       { background:#fee2e2;color:#991b1b;padding:.75rem 1rem;
                      border-radius:8px;font-size:.84rem;display:none;margin-bottom:1rem; }
</style>

<section class="ia-section" id="ia-analisis">

    <!-- Cabecera IA -->
    <div class="ia-header">
        <div class="ia-header-left">
            <i class="bi bi-stars" style="font-size:1.4rem;"></i>
            <div>
                <div class="ia-title">Análisis IA — Recomendaciones de Medidas</div>
                <div class="ia-subtitle">
                    <?php
                    $marcoLabels = [
                        'ley21809'  => 'Ley 21.809',
                        'ley21545'  => 'Ley 21.545 (TEA)',
                        'ley21430'  => 'Ley 21.430 (NNA)',
                        'rex782'    => 'REX 782',
                        'reglamento'=> 'Reglamento Interno',
                        'combinado' => 'Marco combinado',
                    ];
                    $marcoActivo = (string)($caso['marco_legal'] ?? 'ley21809');
                    $marcoLabel  = $marcoLabels[$marcoActivo] ?? $marcoActivo;
                    $involucraTeaCase = (int)($caso['involucra_nna_tea'] ?? 0);
                    ?>
                    Fundamentado en <?= e($marcoLabel) ?> · REX 782 · Ley 21.430
                    <?php if ($involucraTeaCase): ?>
                        · <span class="ia-badge-reg" style="background:#fef3c7;color:#92400e;">
                            <i class="bi bi-heart-pulse-fill"></i> Protocolo TEA activo
                          </span>
                    <?php endif; ?>
                    <?php if ($tieneReglamento): ?>
                        <span class="ia-badge-reg ok"><i class="bi bi-check-circle"></i> Reglamento cargado</span>
                    <?php else: ?>
                        <span class="ia-badge-reg warn"><i class="bi bi-exclamation-circle"></i> Sin reglamento</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <button type="button" class="ia-btn-analizar" id="iaBtnAnalizar">
            <i class="bi bi-cpu-fill"></i>
            <?= $ultimoAnalisis ? 'Nuevo análisis' : 'Analizar con IA' ?>
        </button>
    </div>

    <div class="ia-body">

        <div class="ia-error-msg" id="iaErrorMsg"></div>

        <!-- Loading -->
        <div class="ia-loading" id="iaLoading">
            <div class="ia-loading-spinner"></div>
            <div style="font-size:.88rem;font-weight:600;">Analizando el caso...</div>
            <div style="font-size:.78rem;color:#888;margin-top:.3rem;">
                Procesando hechos, participantes y normativa aplicable. Puede tomar hasta 30 segundos.
            </div>
        </div>

        <!-- Estado vacío (sin análisis previo) -->
        <div class="ia-empty" id="iaEmpty" style="<?= $ultimoAnalisis ? 'display:none' : '' ?>">
            <span class="ia-empty-icon">🤖</span>
            <div style="font-weight:600;margin-bottom:.3rem;">Aún no se ha realizado un análisis</div>
            <div style="font-size:.82rem;">
                Haz clic en <strong>Analizar con IA</strong> para obtener medidas preventivas
                recomendadas en base a los hechos del caso y la normativa vigente.
                <?php if (!$tieneReglamento): ?>
                    <br><br>
                    <a href="<?= APP_URL ?>/modules/admin/reglamento.php" target="_blank"
                       style="color:#1e3a8a;font-weight:600;">
                        <i class="bi bi-arrow-up-right-square"></i>
                        Cargar Reglamento Interno
                    </a>
                    para obtener recomendaciones contextualizadas a tu establecimiento.
                <?php endif; ?>
            </div>
        </div>

        <!-- Resultado del análisis -->
        <div class="ia-result <?= $ultimoAnalisis ? 'visible' : '' ?>" id="iaResult">

            <?php if ($ultimoAnalisis): ?>

                <div class="ia-meta-row">
                    <?php
                    $grav = (string)($ultimoAnalisis['gravedad_ia'] ?? 'media');
                    $gravLabels = ['critica' => 'Gravedad: Crítica', 'alta' => 'Gravedad: Alta',
                                   'media'   => 'Gravedad: Media',   'baja' => 'Gravedad: Baja'];
                    ?>
                    <span class="ia-meta-pill ia-grav-<?= e($grav) ?>">
                        <?= e($gravLabels[$grav] ?? 'Gravedad: ' . $grav) ?>
                    </span>
                    <?php if (!empty($ultimoAnalisis['tokens_usados'])): ?>
                        <span class="ia-meta-pill ia-pill-tokens">
                            <?= number_format((int)$ultimoAnalisis['tokens_usados']) ?> tokens
                        </span>
                    <?php endif; ?>
                    <?php if ($ultimoAnalisis['reglamento_id']): ?>
                        <span class="ia-meta-pill ia-pill-reg"><i class="bi bi-check"></i> Con reglamento interno</span>
                    <?php else: ?>
                        <span class="ia-meta-pill ia-pill-noreg">Sin reglamento interno</span>
                    <?php endif; ?>
                    <?php if ($involucraTeaCase): ?>
                        <span class="ia-meta-pill" style="background:#fef3c7;color:#92400e;">
                            <i class="bi bi-heart-pulse-fill"></i> Ley 21.545 aplicada
                        </span>
                    <?php endif; ?>
                    <?php if ((int)($caso['requiere_coordinacion_senape'] ?? 0)): ?>
                        <span class="ia-meta-pill" style="background:#e0f2fe;color:#2563eb;">
                            <i class="bi bi-building"></i> Coord. SENAPE
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($ultimoAnalisis['alerta_normativa'])): ?>
                    <div class="ia-alerta">
                        <i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;flex-shrink:0;"></i>
                        <div><strong>Alerta normativa:</strong> <?= e((string)$ultimoAnalisis['alerta_normativa']) ?></div>
                    </div>
                <?php endif; ?>

                <div class="ia-resumen"><?= nl2br(e((string)$ultimoAnalisis['analisis_texto'])) ?></div>

                <?php if ($medidasIaGuardadas): ?>
                    <div class="ia-medidas-title">
                        <i class="bi bi-clipboard2-check-fill"></i>
                        Medidas propuestas (<?= count($medidasIaGuardadas) ?>)
                        <span style="font-size:.75rem;font-weight:400;color:#888;">
                            · Aprueba las que deseas agregar al plan de intervención
                        </span>
                    </div>
                    <div class="ia-medidas-grid" id="iaMedidasGrid">
                        <?php foreach ($medidasIaGuardadas as $idx => $m): ?>
                            <div class="ia-medida-card" id="iaMedida-<?= $idx ?>">
                                <span class="ia-medida-tipo"><?= e(ucwords(str_replace('_', ' ', (string)($m['tipo'] ?? 'preventiva')))) ?></span>
                                <div class="ia-medida-desc"><?= nl2br(e((string)($m['descripcion'] ?? ''))) ?></div>
                                <div class="ia-medida-meta">
                                    <?php if (!empty($m['responsable'])): ?>
                                        <span><i class="bi bi-person"></i> <?= e($m['responsable']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($m['plazo_dias'])): ?>
                                        <span><i class="bi bi-calendar"></i> Plazo: <?= (int)$m['plazo_dias'] ?> días</span>
                                    <?php endif; ?>
                                    <?php if (!empty($m['prioridad'])): ?>
                                        <span class="<?= e($m['prioridad']) ?>">Prioridad: <?= e(ucfirst((string)$m['prioridad'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($m['para_condicion']) && $m['para_condicion'] !== 'general'): ?>
                                        <span>Para: <?= e(ucfirst((string)$m['para_condicion'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="ia-btn-aprobar"
                                    data-idx="<?= $idx ?>"
                                    data-tipo="<?= e((string)($m['tipo'] ?? 'preventiva')) ?>"
                                    data-desc="<?= e(htmlspecialchars((string)($m['descripcion'] ?? ''), ENT_QUOTES)) ?>"
                                    data-resp="<?= e(htmlspecialchars((string)($m['responsable'] ?? ''), ENT_QUOTES)) ?>"
                                    data-plazo="<?= (int)($m['plazo_dias'] ?? 0) ?>"
                                    data-analisis="<?= (int)($ultimoAnalisis['id'] ?? 0) ?>"
                                    data-ley="<?= e(htmlspecialchars((string)($m['ley_base'] ?? ''), ENT_QUOTES)) ?>"
                                >
                                    <i class="bi bi-check-circle"></i> Aprobar medida
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="ia-historial-info">
                    Análisis realizado el <?= date('d-m-Y H:i', strtotime((string)$ultimoAnalisis['created_at'])) ?>
                    · Modelo: <?= e((string)($ultimoAnalisis['modelo_usado'] ?? '')) ?>
                </div>

            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function () {
    'use strict';

    var casoId     = <?= (int)$casoId ?>;
    var ajaxUrl    = '<?= APP_URL ?>/modules/denuncias/ajax/analizar_ia.php';
    var aprobUrl   = '<?= APP_URL ?>/modules/denuncias/ajax/aprobar_medida_ia.php';
    var csrfToken  = '<?= e($csrfToken) ?>';
    var csrfField  = '_token';

    var btnAnalizar = document.getElementById('iaBtnAnalizar');
    var loading     = document.getElementById('iaLoading');
    var result      = document.getElementById('iaResult');
    var empty       = document.getElementById('iaEmpty');
    var errorMsg    = document.getElementById('iaErrorMsg');

    function showError(msg) {
        errorMsg.textContent = msg;
        errorMsg.style.display = 'block';
        setTimeout(function () { errorMsg.style.display = 'none'; }, 8000);
    }

    function gravedadClass(g) {
        return { critica: 'ia-grav-critica', alta: 'ia-grav-alta',
                 media: 'ia-grav-media', baja: 'ia-grav-baja' }[g] || 'ia-grav-media';
    }

    function gravedadLabel(g) {
        return { critica: 'Gravedad: Crítica', alta: 'Gravedad: Alta',
                 media: 'Gravedad: Media', baja: 'Gravedad: Baja' }[g] || 'Gravedad: ' + g;
    }

    function tipoLabel(t) {
        return t.replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
    }

    function renderMeta(data) {
        var html = '<div class="ia-meta-row">';
        html += '<span class="ia-meta-pill ' + gravedadClass(data.gravedad_ia) + '">' + gravedadLabel(data.gravedad_ia) + '</span>';
        if (data.tokens_usados) {
            html += '<span class="ia-meta-pill ia-pill-tokens">' + data.tokens_usados.toLocaleString() + ' tokens</span>';
        }
        html += data.con_reglamento
            ? '<span class="ia-meta-pill ia-pill-reg">✓ Con reglamento interno</span>'
            : '<span class="ia-meta-pill ia-pill-noreg">Sin reglamento interno</span>';
        if (data.involucra_tea) {
            html += '<span class="ia-meta-pill" style="background:#fef3c7;color:#92400e;"><i class="bi bi-heart-pulse-fill"></i> Ley 21.545 aplicada</span>';
        }
        if (data.marco_legal && data.marco_legal !== 'ley21809') {
            var marcoLabels = {ley21545:'Ley 21.545 (TEA)',ley21430:'Ley 21.430 (NNA)',
                               rex782:'REX 782',reglamento:'Reglamento',combinado:'Marco combinado'};
            html += '<span class="ia-meta-pill" style="background:#f0fdf4;color:#065f46;">Marco: ' + (marcoLabels[data.marco_legal]||data.marco_legal) + '</span>';
        }
        html += '</div>';
        return html;
    }

    function renderAlerta(data) {
        if (!data.alerta_normativa) return '';
        return '<div class="ia-alerta"><i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0"></i>' +
               '<div><strong>Alerta normativa:</strong> ' + escHtml(data.alerta_normativa) + '</div></div>';
    }

    function renderMedidas(medidas, analisisId) {
        if (!medidas || !medidas.length) return '';
        var html = '<div class="ia-medidas-title"><i class="bi bi-clipboard2-check-fill"></i> ' +
                   'Medidas propuestas (' + medidas.length + ') ' +
                   '<span style="font-size:.75rem;font-weight:400;color:#888;">· Aprueba las que deseas agregar al plan</span></div>';
        html += '<div class="ia-medidas-grid" id="iaMedidasGrid">';
        medidas.forEach(function (m, idx) {
            html += '<div class="ia-medida-card" id="iaMedida-' + idx + '">' +
                '<span class="ia-medida-tipo">' + tipoLabel(m.tipo || 'preventiva') + '</span>' +
                '<div class="ia-medida-desc">' + escHtml(m.descripcion || '') + '</div>' +
                '<div class="ia-medida-meta">';
            if (m.responsable) html += '<span><i class="bi bi-person"></i> ' + escHtml(m.responsable) + '</span>';
            if (m.plazo_dias)  html += '<span><i class="bi bi-calendar"></i> Plazo: ' + m.plazo_dias + ' días</span>';
            if (m.prioridad)   html += '<span class="' + m.prioridad + '">Prioridad: ' + tipoLabel(m.prioridad) + '</span>';
            if (m.para_condicion && m.para_condicion !== 'general')
                html += '<span>Para: ' + tipoLabel(m.para_condicion) + '</span>';
            if (m.ley_base)
                html += '<span style="background:#dbeafe;color:#1e40af;">' + escHtml(m.ley_base) + '</span>';
            html += '</div>' +
                '<button type="button" class="ia-btn-aprobar" ' +
                    'data-idx="' + idx + '" ' +
                    'data-tipo="' + escAttr(m.tipo || 'preventiva') + '" ' +
                    'data-desc="' + escAttr(m.descripcion || '') + '" ' +
                    'data-resp="' + escAttr(m.responsable || '') + '" ' +
                    'data-plazo="' + (m.plazo_dias || 0) + '" ' +
                    'data-analisis="' + analisisId + '">' +
                    '<i class="bi bi-check-circle"></i> Aprobar medida' +
                '</button>' +
            '</div>';
        });
        html += '</div>';
        return html;
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) { return escHtml(s); }

    // ── Click "Analizar con IA" ────────────────────────────
    btnAnalizar.addEventListener('click', function () {
        btnAnalizar.disabled = true;
        if (empty)  empty.style.display = 'none';
        if (result) result.classList.remove('visible');
        loading.style.display = 'block';

        var fd = new FormData();
        fd.append(csrfField, csrfToken);
        fd.append('caso_id', casoId);

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loading.style.display = 'none';
                btnAnalizar.disabled  = false;
                btnAnalizar.innerHTML = '<i class="bi bi-cpu-fill"></i> Nuevo análisis';

                if (!data.ok) {
                    showError(data.error || 'Error desconocido.');
                    if (empty) empty.style.display = 'block';
                    return;
                }

                var now = new Date();
                var fechaStr = now.toLocaleDateString('es-CL') + ' ' + now.toLocaleTimeString('es-CL', {hour:'2-digit',minute:'2-digit'});

                result.innerHTML =
                    renderMeta(data) +
                    renderAlerta(data) +
                    '<div class="ia-resumen">' + escHtml(data.resumen || '') + '</div>' +
                    (data.fundamento_legal ? '<div class="ia-fundamento"><strong>Fundamento legal:</strong> ' + escHtml(data.fundamento_legal) + '</div>' : '') +
                    renderMedidas(data.medidas || [], data.analisis_id || 0) +
                    '<div class="ia-historial-info">Análisis realizado el ' + fechaStr + '</div>';

                result.classList.add('visible');
                bindAprobarButtons();
                result.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function (err) {
                loading.style.display = 'none';
                btnAnalizar.disabled  = false;
                showError('Error de conexión: ' + err.message);
                if (empty) empty.style.display = 'block';
            });
    });

    // ── Aprobar medida ─────────────────────────────────────
    function bindAprobarButtons() {
        document.querySelectorAll('.ia-btn-aprobar').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx      = btn.dataset.idx;
                var card     = document.getElementById('iaMedida-' + idx);
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Guardando...';

                var fd = new FormData();
                fd.append(csrfField,   csrfToken);
                fd.append('caso_id',   casoId);
                fd.append('tipo',      btn.dataset.tipo);
                fd.append('descripcion', btn.dataset.desc);
                fd.append('responsable', btn.dataset.resp);
                fd.append('plazo_dias',  btn.dataset.plazo);
                fd.append('analisis_id', btn.dataset.analisis);

                fetch(aprobUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            btn.className = 'ia-btn-aprobado';
                            btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Medida aprobada';
                            if (card) card.classList.add('aprobada');
                        } else {
                            showError(data.error || 'No se pudo guardar la medida.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-check-circle"></i> Aprobar medida';
                        }
                    })
                    .catch(function (err) {
                        showError('Error al guardar: ' + err.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-check-circle"></i> Aprobar medida';
                    });
            });
        });
    }

    // Activar botones del análisis cargado desde el servidor
    bindAprobarButtons();

})();
</script>
