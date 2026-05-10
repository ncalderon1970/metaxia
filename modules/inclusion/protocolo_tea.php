<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo         = DB::conn();
$user        = Auth::user() ?? [];
$cid         = (int)($user['colegio_id'] ?? 0);
$alumnoId    = (int)($_GET['alumno_id']    ?? 0);
$condicionId = (int)($_GET['condicion_id'] ?? 0);
$origen      = trim((string)($_GET['origen'] ?? 'inclusion')); // inclusion | alumno

if ($alumnoId <= 0) { http_response_code(400); exit('Alumno no válido.'); }

// Cargar alumno
$stmtAl = $pdo->prepare("
    SELECT *, CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres) AS nombre
    FROM alumnos WHERE id = ? AND colegio_id = ? LIMIT 1
");
$stmtAl->execute([$alumnoId, $cid]);
$alumno = $stmtAl->fetch();
if (!$alumno) { http_response_code(404); exit('Alumno no encontrado.'); }

// Cargar condición especial
$condicion = null;
try {
    if ($condicionId > 0) {
        $s = $pdo->prepare("
            SELECT ace.*, COALESCE(cat.nombre, ace.tipo_condicion) AS condicion_nombre
            FROM alumno_condicion_especial ace
            LEFT JOIN catalogo_condicion_especial cat ON cat.codigo = ace.tipo_condicion
            WHERE ace.id = ? AND ace.alumno_id = ? AND ace.colegio_id = ? LIMIT 1
        ");
        $s->execute([$condicionId, $alumnoId, $cid]);
        $condicion = $s->fetch() ?: null;
    }
    if (!$condicion) {
        $s2 = $pdo->prepare("
            SELECT ace.*, COALESCE(cat.nombre, ace.tipo_condicion) AS condicion_nombre
            FROM alumno_condicion_especial ace
            LEFT JOIN catalogo_condicion_especial cat ON cat.codigo = ace.tipo_condicion
            WHERE ace.alumno_id = ? AND ace.colegio_id = ? AND ace.tipo_condicion LIKE 'tea%'
            ORDER BY ace.created_at DESC LIMIT 1
        ");
        $s2->execute([$alumnoId, $cid]);
        $condicion = $s2->fetch() ?: null;
        if ($condicion) $condicionId = (int)$condicion['id'];
    }
} catch (Throwable $e) {}

// Cargar protocolo existente
$protocolo = null;
try {
    $sp = $pdo->prepare("
        SELECT * FROM caso_protocolo_tea
        WHERE colegio_id = ? AND alumno_condicion_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $sp->execute([$cid, $condicionId]);
    $protocolo = $sp->fetch() ?: null;
} catch (Throwable $e) {}

// Cargar historial de sesiones del protocolo
$historialSesiones = [];
try {
    if ($protocolo) {
        $sh = $pdo->prepare("
            SELECT css.*, u.nombre AS responsable_nombre
            FROM caso_seguimiento_sesion css
            LEFT JOIN usuarios u ON u.id = css.registrado_por
            WHERE css.caso_id = 0 AND css.colegio_id = ? AND css.participante_id = ?
            ORDER BY css.created_at DESC LIMIT 10
        ");
        $sh->execute([$cid, $alumnoId]);
        $historialSesiones = $sh->fetchAll();
    }
} catch (Throwable $e) {}

$msgOk = $msgErr = '';

// POST: guardar protocolo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);

    $d  = fn(string $k) => trim((string)($_POST[$k] ?? ''));
    $i  = fn(string $k) => (int)($_POST[$k] ?? 0);
    $dt = fn(string $k) => (function() use ($d, $k) {
        $v = $d($k); return ($v !== '' && strtotime($v)) ? $v : null;
    })();
    $userId = (int)($user['id'] ?? 0);

    try {
        $data = [
            'colegio_id'                  => $cid,
            'alumno_condicion_id'         => $condicionId ?: null,
            'deteccion_registrada'        => $i('deteccion_registrada'),
            'fecha_deteccion'             => $dt('fecha_deteccion'),
            'comunicacion_familia'        => $i('comunicacion_familia'),
            'fecha_comunicacion_familia'  => $dt('fecha_comunicacion_familia'),
            'derivacion_salud'            => $i('derivacion_salud'),
            'fecha_derivacion'            => $dt('fecha_derivacion'),
            'establecimiento_salud'       => $d('establecimiento_salud') ?: null,
            'profesional_receptor'        => $d('profesional_receptor')  ?: null,
            'coordinacion_pie'            => $i('coordinacion_pie'),
            'ajustes_metodologicos'       => $i('ajustes_metodologicos'),
            'seguimiento_establecido'     => $i('seguimiento_establecido'),
            'fecha_proximo_seguimiento'   => $dt('fecha_proximo_seguimiento'),
            'respuesta_salud_recibida'    => $i('respuesta_salud_recibida'),
            'fecha_respuesta_salud'       => $dt('fecha_respuesta_salud'),
            'diagnostico_confirmado'      => $i('diagnostico_confirmado'),
            'observaciones'               => $d('observaciones') ?: null,
            'completado_por'              => $userId ?: null,
        ];

        // Calcular estado automático
        $pasos = array_sum([$data['deteccion_registrada'],$data['comunicacion_familia'],
            $data['derivacion_salud'],$data['coordinacion_pie'],
            $data['ajustes_metodologicos'],$data['seguimiento_establecido']]);
        $data['estado_protocolo'] = $data['diagnostico_confirmado'] ? 'completado'
            : ($pasos >= 4 ? 'en_proceso' : ($pasos >= 1 ? 'iniciado' : 'pendiente'));

        // Sincronizar derivación en alumno_condicion_especial
        if ($data['derivacion_salud'] && $condicionId) {
            $pdo->prepare("UPDATE alumno_condicion_especial
                SET derivado_salud=1, fecha_derivacion=?, destino_derivacion=?, estado_derivacion='pendiente'
                WHERE id=? AND colegio_id=?")
                ->execute([$data['fecha_derivacion'], $data['establecimiento_salud'], $condicionId, $cid]);
        }
        // Sincronizar en tabla alumnos
        if ($data['derivacion_salud']) {
            $pdo->prepare("UPDATE alumnos SET derivado_salud_tea=1, fecha_derivacion_tea=?,
                destino_derivacion_tea=? WHERE id=? AND colegio_id=?")
                ->execute([$data['fecha_derivacion'], $data['establecimiento_salud'], $alumnoId, $cid]);
        }

        if ($protocolo) {
            $sets = array_map(fn($k) => "`$k` = ?", array_keys($data));
            $sets[] = 'updated_at = NOW()';
            $vals   = array_values($data);
            $vals[] = $protocolo['id'];
            $pdo->prepare('UPDATE caso_protocolo_tea SET ' . implode(',', $sets) . ' WHERE id = ?')
                ->execute($vals);
        } else {
            $cols = array_keys($data);
            $vals = array_values($data);
            $pdo->prepare('INSERT INTO caso_protocolo_tea (' . implode(',', array_map(fn($c)=>"`$c`",$cols)) . ')
                VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')
                ->execute($vals);
        }

        $msgOk = 'Protocolo guardado correctamente.';
        // Reload protocolo
        $sp->execute([$cid, $condicionId]);
        $protocolo = $sp->fetch() ?: null;

    } catch (Throwable $e) {
        $msgErr = 'Error al guardar: ' . $e->getMessage();
    }
}

$p     = $protocolo ?? [];
$check = fn(string $k) => !empty($p[$k]) ? ' checked' : '';
$val   = fn(string $k) => e((string)($p[$k] ?? ''));

$pasosCumplidos = array_sum([
    (int)($p['deteccion_registrada']    ?? 0),
    (int)($p['comunicacion_familia']    ?? 0),
    (int)($p['derivacion_salud']        ?? 0),
    (int)($p['coordinacion_pie']        ?? 0),
    (int)($p['ajustes_metodologicos']   ?? 0),
    (int)($p['seguimiento_establecido'] ?? 0),
]);
$pct = round(($pasosCumplidos / 6) * 100);

$volverUrl = $origen === 'alumno'
    ? APP_URL . '/modules/alumnos/ver.php?id=' . $alumnoId . '&tab=inclusion'
    : APP_URL . '/modules/inclusion/index.php';

$pageTitle = 'Protocolo TEA · ' . e((string)($alumno['nombre'] ?? ''));
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.pt-hero      { background:linear-gradient(135deg,#78350f,#b45309,#f59e0b);
                border-radius:14px;color:#fff;padding:2rem 2.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden; }
.pt-hero::before{ content:'';position:absolute;inset:0;
                  background:radial-gradient(ellipse 60% 80% at 95% 50%,rgba(245,158,11,.25),transparent 65%); }
.pt-card      { background:#fff;border:1px solid #e2e8f0;border-radius:12px;
                padding:1.5rem;margin-bottom:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.06); }
.pt-section   { font-size:.72rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
                color:#b45309;border-bottom:1px solid #fef3c7;padding-bottom:.4rem;margin-bottom:1.1rem; }
.pt-progress  { height:12px;background:#e2e8f0;border-radius:20px;overflow:hidden;margin-bottom:.4rem; }
.pt-bar       { height:100%;border-radius:20px;transition:width .4s;
                background:linear-gradient(90deg,#f59e0b,#10b981); }
.pt-step      { display:flex;align-items:flex-start;gap:1rem;padding:.85rem 1rem;
                border:1px solid #e2e8f0;border-radius:10px;margin-bottom:.6rem;
                background:#f8fafc;transition:all .15s;cursor:pointer; }
.pt-step:has(input:checked){ background:#f0fdf4;border-color:#6ee7b7; }
.pt-step:hover{ border-color:#f59e0b; }
.pt-step-num  { width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#64748b;
                font-size:.78rem;font-weight:800;display:flex;align-items:center;
                justify-content:center;flex-shrink:0;margin-top:.1rem; }
.pt-step:has(input:checked) .pt-step-num{ background:#d1fae5;color:#065f46; }
.pt-step-title{ font-size:.87rem;font-weight:700;color:#0f172a; }
.pt-step-desc { font-size:.76rem;color:#64748b;margin:.15rem 0 .4rem; }
.pt-ley       { font-size:.7rem;background:#dbeafe;color:#1e40af;border-radius:12px;
                padding:.1rem .5rem;display:inline-block;margin-bottom:.5rem; }
.pt-grid-2    { display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.5rem; }
.pt-grid-3    { display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-top:.5rem; }
.pt-label     { display:block;font-size:.75rem;font-weight:600;color:#374151;margin-bottom:.2rem; }
.pt-ctrl      { width:100%;padding:.42rem .65rem;border:1px solid #cdd5e0;border-radius:6px;
                font-size:.82rem;box-sizing:border-box;background:#fff; }
.pt-ctrl:focus{ outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.12); }
.pt-save      { background:#b45309;color:#fff;border:none;border-radius:8px;
                padding:.65rem 1.5rem;font-size:.87rem;font-weight:800;cursor:pointer; }
.pt-save:hover{ background:#92400e; }
.pt-badge     { border-radius:12px;padding:.15rem .6rem;font-size:.72rem;font-weight:700;display:inline-block; }
.pt-badge-ok  { background:#d1fae5;color:#065f46; }
.pt-badge-warn{ background:#fef3c7;color:#92400e; }
.pt-badge-pend{ background:#fee2e2;color:#991b1b; }
.pt-hist-item { border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;
                margin-bottom:.5rem;background:#f8fafc;font-size:.82rem; }
.alert-ok  { background:#d1fae5;color:#065f46;border-radius:8px;padding:.65rem 1rem;
             margin-bottom:1rem;font-size:.85rem; }
.alert-err { background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;
             margin-bottom:1rem;font-size:.85rem; }
@media(max-width:680px){ .pt-grid-2,.pt-grid-3{ grid-template-columns:1fr; } }
</style>

<!-- Hero -->
<div class="pt-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
                         color:#fde68a;display:block;margin-bottom:.3rem;position:relative;">
                <i class="bi bi-heart-pulse-fill"></i> Protocolo TEA · Ley 21.545 Art. 12
            </span>
            <h1 style="font-size:1.55rem;font-weight:800;color:#fff;margin-bottom:.25rem;position:relative;">
                <?= e((string)($alumno['nombre'] ?? '')) ?>
            </h1>
            <div style="font-size:.84rem;color:#fde68a;position:relative;">
                RUN: <?= e((string)($alumno['run'] ?? '')) ?>
                <?= !empty($alumno['curso']) ? ' · ' . e((string)$alumno['curso']) : '' ?>
                <?php if ($condicion): ?>
                    · <?= e((string)($condicion['condicion_nombre'] ?? '')) ?>
                    <?= !empty($condicion['nivel_apoyo']) ? ' Nivel ' . (int)$condicion['nivel_apoyo'] : '' ?>
                    <?php
                    $estadoDx = match($condicion['estado_diagnostico'] ?? '') {
                        'confirmado' => ['ok','Dx Confirmado'],
                        'en_proceso' => ['warn','Dx en Proceso'],
                        'sospecha'   => ['pend','Sospecha'],
                        default      => ['',''],
                    };
                    if ($estadoDx[1]): ?>
                        <span class="pt-badge pt-badge-<?= $estadoDx[0] ?>" style="margin-left:.35rem;">
                            <?= $estadoDx[1] ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-self:center;position:relative;">
            <a href="<?= e($volverUrl) ?>"
               style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);
                      border-radius:8px;font-size:.83rem;font-weight:600;padding:.45rem 1rem;
                      text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
            <a href="<?= APP_URL ?>/modules/alumnos/ver.php?id=<?= $alumnoId ?>&tab=inclusion"
               style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);
                      border-radius:8px;font-size:.83rem;font-weight:600;padding:.45rem 1rem;
                      text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;">
                <i class="bi bi-person-badge"></i> Ficha
            </a>
        </div>
    </div>
</div>

<?php if ($msgOk !== ''): ?>
    <div class="alert-ok"><i class="bi bi-check-circle-fill"></i> <?= e($msgOk) ?></div>
<?php endif; ?>
<?php if ($msgErr !== ''): ?>
    <div class="alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($msgErr) ?></div>
<?php endif; ?>

<!-- Progreso -->
<div class="pt-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.65rem;flex-wrap:wrap;gap:.5rem;">
        <div>
            <span style="font-size:.85rem;font-weight:700;color:#0f172a;">
                Avance del protocolo:
            </span>
            <strong style="color:<?= $pct >= 100 ? '#059669' : ($pct >= 50 ? '#f59e0b' : '#dc2626') ?>;">
                <?= $pct ?>%
            </strong>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <span style="font-size:.78rem;color:#64748b;"><?= $pasosCumplidos ?>/6 pasos</span>
            <?php if (!empty($p['estado_protocolo'])): ?>
                <span class="pt-badge pt-badge-<?= match($p['estado_protocolo']) {
                    'completado' => 'ok', 'en_proceso' => 'warn', default => 'pend' } ?>">
                    <?= e(ucfirst((string)$p['estado_protocolo'])) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($p['fecha_proximo_seguimiento'])): ?>
                <span style="font-size:.75rem;color:#64748b;">
                    <i class="bi bi-calendar3"></i>
                    Próximo: <?= date('d-m-Y', strtotime((string)$p['fecha_proximo_seguimiento'])) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="pt-progress">
        <div class="pt-bar" style="width:<?= $pct ?>%;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:.68rem;color:#94a3b8;margin-top:.3rem;">
        <span>Detección</span><span>Familia</span><span>Derivación</span>
        <span>PIE</span><span>Ajustes</span><span>Seguimiento</span>
    </div>
</div>

<!-- Formulario protocolo -->
<form method="post">
    <?= CSRF::field() ?>

    <!-- Paso 1-6: Checklist -->
    <div class="pt-card">
        <div class="pt-section"><i class="bi bi-clipboard2-check-fill"></i> Checklist Art. 12 Ley 21.545</div>

        <!-- Paso 1 -->
        <label class="pt-step">
            <div class="pt-step-num">1</div>
            <div style="flex:1;">
                <input type="checkbox" name="deteccion_registrada" value="1"
                       style="display:none;" <?= $check('deteccion_registrada') ?>>
                <div class="pt-step-title">Señales detectadas y registradas</div>
                <div class="pt-step-desc">Las señales de alerta TEA fueron observadas y documentadas por el establecimiento.</div>
                <span class="pt-ley">Ley 21.545 — Principio de detección temprana</span>
                <div class="pt-grid-2">
                    <div>
                        <label class="pt-label">Fecha de detección</label>
                        <input class="pt-ctrl" type="date" name="fecha_deteccion" value="<?= $val('fecha_deteccion') ?>">
                    </div>
                </div>
            </div>
            <div style="margin-top:.1rem;">
                <i class="bi bi-<?= !empty($p['deteccion_registrada']) ? 'check-circle-fill text-success' : 'circle' ?>"
                   style="font-size:1.2rem;color:<?= !empty($p['deteccion_registrada']) ? '#059669' : '#cbd5e1' ?>;"></i>
            </div>
        </label>

        <!-- Paso 2 -->
        <label class="pt-step">
            <div class="pt-step-num">2</div>
            <div style="flex:1;">
                <input type="checkbox" name="comunicacion_familia" value="1"
                       style="display:none;" <?= $check('comunicacion_familia') ?>>
                <div class="pt-step-title">Familia informada de la sospecha</div>
                <div class="pt-step-desc">Los padres o apoderados fueron notificados de las señales observadas y del proceso.</div>
                <span class="pt-ley">Ley 21.430 Art. 10 — Derecho preferente de los padres</span>
                <div class="pt-grid-2">
                    <div>
                        <label class="pt-label">Fecha de comunicación</label>
                        <input class="pt-ctrl" type="date" name="fecha_comunicacion_familia" value="<?= $val('fecha_comunicacion_familia') ?>">
                    </div>
                </div>
            </div>
            <div style="margin-top:.1rem;">
                <i class="bi bi-<?= !empty($p['comunicacion_familia']) ? 'check-circle-fill' : 'circle' ?>"
                   style="font-size:1.2rem;color:<?= !empty($p['comunicacion_familia']) ? '#059669' : '#cbd5e1' ?>;"></i>
            </div>
        </label>

        <!-- Paso 3 — OBLIGATORIO -->
        <label class="pt-step" style="border-color:<?= empty($p['derivacion_salud']) ? '#fca5a5' : '#6ee7b7' ?>;">
            <div class="pt-step-num" style="background:<?= empty($p['derivacion_salud']) ? '#fee2e2' : '#d1fae5' ?>;
                 color:<?= empty($p['derivacion_salud']) ? '#dc2626' : '#065f46' ?>;">3</div>
            <div style="flex:1;">
                <input type="checkbox" name="derivacion_salud" value="1"
                       style="display:none;" <?= $check('derivacion_salud') ?>>
                <div class="pt-step-title">
                    Derivación formal a sistema de salud
                    <span style="font-size:.68rem;background:#fee2e2;color:#dc2626;
                           border-radius:8px;padding:.1rem .4rem;margin-left:.35rem;font-weight:700;">
                        OBLIGATORIO
                    </span>
                </div>
                <div class="pt-step-desc">Derivación al establecimiento de salud para proceso diagnóstico.</div>
                <span class="pt-ley">Art. 12 Ley 21.545 — Derivación obligatoria</span>
                <div class="pt-grid-3">
                    <div>
                        <label class="pt-label">Fecha de derivación</label>
                        <input class="pt-ctrl" type="date" name="fecha_derivacion" value="<?= $val('fecha_derivacion') ?>">
                    </div>
                    <div>
                        <label class="pt-label">Establecimiento de salud</label>
                        <input class="pt-ctrl" type="text" name="establecimiento_salud"
                               value="<?= $val('establecimiento_salud') ?>" placeholder="CESFAM, Hospital...">
                    </div>
                    <div>
                        <label class="pt-label">Profesional receptor</label>
                        <input class="pt-ctrl" type="text" name="profesional_receptor"
                               value="<?= $val('profesional_receptor') ?>" placeholder="Dr., Psicólogo/a...">
                    </div>
                </div>
            </div>
            <div style="margin-top:.1rem;">
                <i class="bi bi-<?= !empty($p['derivacion_salud']) ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"
                   style="font-size:1.2rem;color:<?= !empty($p['derivacion_salud']) ? '#059669' : '#dc2626' ?>;"></i>
            </div>
        </label>

        <!-- Paso 4 -->
        <label class="pt-step">
            <div class="pt-step-num">4</div>
            <div style="flex:1;">
                <input type="checkbox" name="coordinacion_pie" value="1"
                       style="display:none;" <?= $check('coordinacion_pie') ?>>
                <div class="pt-step-title">Coordinación con PIE</div>
                <div class="pt-step-desc">Se coordinó con el Programa de Integración Escolar para apoyos mientras se realiza el diagnóstico.</div>
                <span class="pt-ley">Art. 19 Ley 21.545 — Acompañamiento</span>
            </div>
            <div style="margin-top:.1rem;">
                <i class="bi bi-<?= !empty($p['coordinacion_pie']) ? 'check-circle-fill' : 'circle' ?>"
                   style="font-size:1.2rem;color:<?= !empty($p['coordinacion_pie']) ? '#059669' : '#cbd5e1' ?>;"></i>
            </div>
        </label>

        <!-- Paso 5 -->
        <label class="pt-step">
            <div class="pt-step-num">5</div>
            <div style="flex:1;">
                <input type="checkbox" name="ajustes_metodologicos" value="1"
                       style="display:none;" <?= $check('ajustes_metodologicos') ?>>
                <div class="pt-step-title">Ajustes metodológicos y de evaluación activos</div>
                <div class="pt-step-desc">Se implementaron ajustes razonables en metodología y evaluación.</div>
                <span class="pt-ley">Art. 18 Ley 21.545 — Ajustes razonables obligatorios</span>
            </div>
            <div style="margin-top:.1rem;">
                <i class="bi bi-<?= !empty($p['ajustes_metodologicos']) ? 'check-circle-fill' : 'circle' ?>"
                   style="font-size:1.2rem;color:<?= !empty($p['ajustes_metodologicos']) ? '#059669' : '#cbd5e1' ?>;"></i>
            </div>
        </label>

        <!-- Paso 6 -->
        <label class="pt-step">
            <div class="pt-step-num">6</div>
            <div style="flex:1;">
                <input type="checkbox" name="seguimiento_establecido" value="1"
                       style="display:none;" <?= $check('seguimiento_establecido') ?>>
                <div class="pt-step-title">Plan de seguimiento establecido</div>
                <div class="pt-step-desc">Se definió un calendario de revisión y seguimiento de la derivación a salud.</div>
                <span class="pt-ley">Ley 21.545 — Principio de seguimiento continuo</span>
                <div class="pt-grid-2">
                    <div>
                        <label class="pt-label">Fecha próxima revisión</label>
                        <input class="pt-ctrl" type="date" name="fecha_proximo_seguimiento"
                               value="<?= $val('fecha_proximo_seguimiento') ?>">
                    </div>
                </div>
            </div>
            <div style="margin-top:.1rem;">
                <i class="bi bi-<?= !empty($p['seguimiento_establecido']) ? 'check-circle-fill' : 'circle' ?>"
                   style="font-size:1.2rem;color:<?= !empty($p['seguimiento_establecido']) ? '#059669' : '#cbd5e1' ?>;"></i>
            </div>
        </label>
    </div>

    <!-- Resultado diagnóstico -->
    <div class="pt-card">
        <div class="pt-section"><i class="bi bi-hospital"></i> Resultado de la derivación</div>
        <div class="pt-grid-2" style="margin-bottom:.85rem;">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.84rem;font-weight:600;">
                <input type="checkbox" name="respuesta_salud_recibida" value="1"
                       style="accent-color:#059669;" <?= $check('respuesta_salud_recibida') ?>>
                Respuesta de salud recibida
            </label>
            <div>
                <label class="pt-label">Fecha respuesta</label>
                <input class="pt-ctrl" type="date" name="fecha_respuesta_salud" value="<?= $val('fecha_respuesta_salud') ?>">
            </div>
        </div>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.87rem;font-weight:700;color:#059669;">
            <input type="checkbox" name="diagnostico_confirmado" value="1"
                   style="accent-color:#059669;" <?= $check('diagnostico_confirmado') ?>>
            Diagnóstico TEA confirmado por profesional de salud
        </label>
    </div>

    <!-- Observaciones -->
    <div class="pt-card">
        <div class="pt-section"><i class="bi bi-pencil-square"></i> Observaciones generales</div>
        <textarea class="pt-ctrl" name="observaciones" rows="3"
                  placeholder="Antecedentes relevantes, acuerdos con la familia, situaciones especiales..."><?= $val('observaciones') ?></textarea>
        <div style="text-align:right;margin-top:1rem;">
            <button type="submit" class="pt-save">
                <i class="bi bi-check-circle-fill"></i> Guardar protocolo
            </button>
        </div>
    </div>
</form>

<!-- Script: toggle checkboxes al hacer click en el step -->
<script>
document.querySelectorAll('.pt-step').forEach(function(step) {
    step.addEventListener('click', function(e) {
        if (e.target.tagName === 'INPUT' && e.target.type !== 'checkbox') return;
        if (e.target.tagName === 'A') return;
        var chk = step.querySelector('input[type=checkbox]');
        if (chk && e.target !== chk) chk.checked = !chk.checked;
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
