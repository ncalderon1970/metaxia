<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias — tab_clasificacion.php
 */

$cn  = $clasificacionNormativa ?? [];   // fila de caso_clasificacion_normativa
$caso = $caso ?? [];

// ── Áreas y ámbitos desde DB ─────────────────────────────────────────────────
$areasDB    = [];
$aspectosJS = '{}';
try {
    $stmtA   = $pdo->query("SELECT codigo, nombre FROM denuncia_areas WHERE activo=1 ORDER BY id");
    $areasDB = $stmtA->fetchAll();

    $stmtAsp = $pdo->query("
        SELECT da.codigo AS asp_codigo, da.nombre AS asp_nombre, ar.codigo AS area_codigo
        FROM denuncia_aspectos da
        JOIN denuncia_areas ar ON ar.id = da.area_id
        WHERE da.activo=1 ORDER BY da.id
    ");
    $asp = [];
    foreach ($stmtAsp->fetchAll() as $r) {
        $asp[$r['area_codigo']][] = ['codigo' => $r['asp_codigo'], 'nombre' => $r['asp_nombre']];
    }
    $aspectosJS = json_encode($asp, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {}

$chk = static fn($v): string => (int)$v === 1 ? 'checked' : '';
$sel = static fn($a, $b): string => (string)$a === (string)$b ? 'selected' : '';

$valorArea   = (string)($cn['area_mineduc']   ?? '');
$valorAmbito = (string)($cn['ambito_mineduc'] ?? '');
$gravedad    = (string)($cn['gravedad']        ?? 'media');

// Verificar módulo IA
$_iaOn = false;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM colegio_modulos WHERE colegio_id=? AND modulo_codigo='ia' AND activo=1");
    $s->execute([$colegioId]);
    $_iaOn = (bool)$s->fetchColumn();
} catch (Throwable $e) {}
$_iaOn = $_iaOn || (($currentUser['rol_codigo'] ?? '') === 'superadmin');
?>

<style>
.cl-card       { background:#fff;border:1px solid #e2e8f0;border-radius:12px;
                 padding:1.4rem;margin-bottom:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05); }
.cl-title      { font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
                 color:#2563eb;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem; }
.cl-label      { display:block;font-size:.76rem;font-weight:600;color:#334155;margin-bottom:.3rem; }
.cl-ctrl       { width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:8px;
                 font-size:.87rem;box-sizing:border-box;background:#fff;font-family:inherit; }
.cl-ctrl:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.cl-grid2      { display:grid;grid-template-columns:1fr 1fr;gap:.9rem;margin-bottom:.9rem; }
.cl-grid3      { display:grid;grid-template-columns:1fr 1fr 1fr;gap:.9rem;margin-bottom:.9rem; }
.cl-marker     { display:flex;align-items:center;gap:.5rem;padding:.5rem .7rem;
                 background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer; }
.cl-marker:has(input:checked) { background:#eff6ff;border-color:#93c5fd; }
.cl-marker input { accent-color:#2563eb;flex-shrink:0; }
.cl-marker strong { font-size:.82rem;color:#0f172a; }
.cl-mkgrid     { display:grid;grid-template-columns:1fr 1fr;gap:.45rem; }
.cl-sec-title  { font-size:.78rem;font-weight:700;color:#334155;margin:.9rem 0 .5rem;
                 display:flex;align-items:center;gap:.35rem; }
.cl-btn        { background:#1e3a8a;color:#fff;border:none;border-radius:8px;
                 padding:.55rem 1.4rem;font-size:.84rem;font-weight:600;cursor:pointer;
                 font-family:inherit; }
.cl-btn:hover  { background:#1e40af; }
.cl-divider    { border:none;border-top:1px solid #f1f5f9;margin:1rem 0; }
.cl-badge-ok   { display:inline-block;background:#dcfce7;color:#166534;border-radius:999px;
                 padding:.15rem .6rem;font-size:.72rem;font-weight:700; }
.cl-badge-warn { display:inline-block;background:#fef3c7;color:#92400e;border-radius:999px;
                 padding:.15rem .6rem;font-size:.72rem;font-weight:700; }
@media(max-width:680px){ .cl-grid2,.cl-grid3,.cl-mkgrid { grid-template-columns:1fr; } }
</style>

<!-- ═══════════════════════════════════════════════════════════
     PANEL — Clasificación normativa
════════════════════════════════════════════════════════════ -->
<div class="cl-card">
    <div class="cl-title" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <span><i class="bi bi-shield-check"></i> Clasificación normativa y marcadores</span>
        <?php if ($_iaOn): ?>
        <button type="button" onclick="sugerirClasif()"
                style="background:#1e3a8a;color:#fff;border:none;border-radius:999px;
                       padding:.4rem .85rem;font-size:.77rem;font-weight:700;cursor:pointer;">
            <i class="bi bi-stars"></i> Sugerir con IA
        </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($cn['id'])): ?>
    <div style="margin-bottom:.9rem;">
        <span class="cl-badge-ok"><i class="bi bi-check-circle-fill"></i> Clasificación registrada</span>
        <span style="font-size:.75rem;color:#64748b;margin-left:.5rem;">
            Última actualización: <?= e(substr((string)($cn['updated_at'] ?? ''), 0, 16)) ?>
        </span>
    </div>
    <?php else: ?>
    <div style="margin-bottom:.9rem;">
        <span class="cl-badge-warn"><i class="bi bi-exclamation-triangle-fill"></i> Sin clasificación registrada</span>
    </div>
    <?php endif; ?>

    <!-- Panel sugerencias IA -->
    <?php if ($_iaOn): ?>
    <div id="clIaPanel" style="display:none;margin-bottom:1rem;background:#eff6ff;
         border:1px solid #bfdbfe;border-radius:10px;padding:.9rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
            <span style="font-size:.82rem;font-weight:700;color:#1e3a8a;"><i class="bi bi-stars"></i> Sugerencia IA</span>
            <button type="button" onclick="document.getElementById('clIaPanel').style.display='none'"
                    style="background:none;border:none;cursor:pointer;color:#64748b;">✕</button>
        </div>
        <div id="clIaCuerpo" style="font-size:.82rem;color:#334155;"></div>
        <button type="button" id="clIaAplicar" onclick="aplicarIa()"
                style="display:none;margin-top:.7rem;background:#059669;color:#fff;border:none;
                       border-radius:999px;padding:.4rem .85rem;font-size:.77rem;font-weight:700;cursor:pointer;">
            <i class="bi bi-check-circle"></i> Aplicar al formulario
        </button>
    </div>
    <?php endif; ?>

    <form method="post" id="clForm">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion" value="actualizar_clasificacion">

        <!-- Área + Ámbito -->
        <div class="cl-grid2">
            <div>
                <label class="cl-label">Área MINEDUC</label>
                <select class="cl-ctrl" name="area_mineduc" id="clArea" onchange="filtrarAmbito(this.value)">
                    <option value="">— Seleccione —</option>
                    <?php foreach ($areasDB as $a): ?>
                    <option value="<?= e($a['codigo']) ?>" <?= $sel($valorArea, $a['codigo']) ?>>
                        <?= e(mb_convert_case($a['nombre'], MB_CASE_TITLE, 'UTF-8')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="cl-label">Ámbito</label>
                <select class="cl-ctrl" name="ambito_mineduc" id="clAmbito">
                    <option value="">— Seleccione área primero —</option>
                </select>
            </div>
        </div>

        <!-- Tipo conducta + Gravedad -->
        <div class="cl-grid2">
            <div>
                <label class="cl-label">Tipo de conducta</label>
                <select class="cl-ctrl" name="tipo_conducta" id="clTipoConducta">
                    <option value="">— Seleccione —</option>
                    <?php
                    $tipos = ['conflicto_convivencia'=>'Conflicto de convivencia','maltrato_escolar'=>'Maltrato escolar',
                              'acoso_escolar'=>'Acoso escolar','ciberacoso'=>'Ciberacoso',
                              'violencia_fisica'=>'Violencia física','violencia_psicologica'=>'Violencia psicológica',
                              'violencia_sexual'=>'Violencia sexual','discriminacion'=>'Discriminación',
                              'amenaza'=>'Amenaza / intimidación','agresion_grave'=>'Agresión grave',
                              'vulneracion_derechos'=>'Vulneración de derechos','otro'=>'Otro'];
                    $tcActual = (string)($cn['tipo_conducta'] ?? '');
                    foreach ($tipos as $v => $l): ?>
                    <option value="<?= e($v) ?>" <?= $sel($tcActual, $v) ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="cl-label">Gravedad</label>
                <select class="cl-ctrl" name="gravedad" id="clGravedad">
                    <option value="baja"   <?= $sel($gravedad,'baja')   ?>>Baja</option>
                    <option value="media"  <?= $sel($gravedad,'media')  ?>>Media</option>
                    <option value="alta"   <?= $sel($gravedad,'alta')   ?>>Alta</option>
                    <option value="critica"<?= $sel($gravedad,'critica') ?>>Crítica</option>
                </select>
            </div>
        </div>

        <!-- Indicadores booleanos -->
        <div class="cl-mkgrid" style="margin-bottom:.9rem;">
            <label class="cl-marker">
                <input type="checkbox" name="posible_aula_segura" value="1" <?= $chk($cn['posible_aula_segura'] ?? 0) ?>>
                <strong>Posible Aula Segura</strong>
            </label>
            <label class="cl-marker">
                <input type="checkbox" name="requiere_denuncia" value="1" <?= $chk($cn['requiere_denuncia'] ?? 0) ?>>
                <strong>Requiere denuncia / derivación externa</strong>
            </label>
            <label class="cl-marker">
                <input type="checkbox" name="reiteracion" value="1" <?= $chk($cn['reiteracion'] ?? 0) ?>>
                <strong>Conducta reiterada</strong>
            </label>
            <label class="cl-marker">
                <input type="checkbox" name="involucra_adulto" value="1" <?= $chk($cn['involucra_adulto'] ?? 0) ?>>
                <strong>Involucra adulto del establecimiento</strong>
            </label>
        </div>

        <hr class="cl-divider">

        <!-- Observaciones -->
        <div style="margin-bottom:.9rem;">
            <label class="cl-label">Observaciones normativas</label>
            <textarea class="cl-ctrl" name="observaciones_normativas" rows="3"
                      placeholder="Fundamento, contexto normativo aplicado, antecedentes relevantes..."
            ><?= e((string)($cn['observaciones_normativas'] ?? '')) ?></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;">
            <button type="submit" class="cl-btn">
                <i class="bi bi-check-circle-fill"></i> Guardar clasificación y marcadores
            </button>
        </div>
    </form>
</div>

<script>
const _asp     = <?= $aspectosJS ?>;
const _ambSaved= <?= json_encode($valorAmbito) ?>;
let   _iaDatos = null;

function filtrarAmbito(area, presel) {
    const s = document.getElementById('clAmbito');
    const items = _asp[area] ?? [];
    s.innerHTML = '';
    if (!area || !items.length) {
        s.innerHTML = '<option value="">— Seleccione área primero —</option>';
        return;
    }
    s.appendChild(Object.assign(document.createElement('option'), {value:'', textContent:'— Seleccione —'}));
    items.forEach(a => {
        const o = document.createElement('option');
        o.value = a.codigo;
        o.textContent = a.nombre;
        if (a.codigo === (presel ?? '')) o.selected = true;
        s.appendChild(o);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const a = document.getElementById('clArea').value;
    if (a) filtrarAmbito(a, _ambSaved);
});

<?php if ($_iaOn): ?>
function sugerirClasif() {
    const panel  = document.getElementById('clIaPanel');
    const cuerpo = document.getElementById('clIaCuerpo');
    const aplicar= document.getElementById('clIaAplicar');
    panel.style.display = 'block';
    aplicar.style.display = 'none';
    cuerpo.innerHTML = '<em style="color:#64748b;">Analizando el caso…</em>';

    const fd = new FormData();
    fd.append('_token', document.querySelector('[name="_token"]')?.value ?? '');
    fd.append('caso_id', '<?= (int)$casoId ?>');
    fd.append('tipo', 'clasificacion');

    fetch('<?= APP_URL ?>/modules/denuncias/ajax/sugerir_ia.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
        if (!d.ok) { cuerpo.innerHTML = '<span style="color:#dc2626;">'+( d.error ?? 'Error')+'</span>'; return; }
        _iaDatos = d.datos;
        const colors = {baja:'#059669',media:'#d97706',alta:'#dc2626',critica:'#7c2d12'};
        cuerpo.innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem;">
                <div><span style="font-size:.7rem;color:#64748b;">Tipo conducta</span><br><strong>${_iaDatos.tipo_conducta ?? '—'}</strong></div>
                <div><span style="font-size:.7rem;color:#64748b;">Gravedad</span><br>
                    <strong style="color:${colors[_iaDatos.gravedad]??'#0f172a'}">${(_iaDatos.gravedad??'—').toUpperCase()}</strong></div>
            </div>
            ${_iaDatos.justificacion ? `<p style="font-size:.8rem;color:#334155;padding:.5rem;background:#fff;border-radius:6px;">${_iaDatos.justificacion}</p>` : ''}
        `;
        aplicar.style.display = 'inline-block';
    })
    .catch(() => { cuerpo.innerHTML = '<span style="color:#dc2626;">Error de conexión.</span>'; });
}

function aplicarIa() {
    if (!_iaDatos) return;
    const d = _iaDatos;
    const setVal = (name, v) => { const el = document.querySelector(`[name="${name}"]`); if (el && v) el.value = v; };
    setVal('area_mineduc', d.area_mineduc);
    if (d.area_mineduc) filtrarAmbito(d.area_mineduc, d.ambito_mineduc);
    setVal('tipo_conducta', d.tipo_conducta);
    setVal('gravedad', d.gravedad);
    document.getElementById('clIaPanel').style.display = 'none';
}
<?php endif; ?>
</script>
