<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';

Auth::requireLogin();

$pdo  = DB::conn();
$user = Auth::user();
$cid  = (int)$user['colegio_id'];
$id   = cleanInt($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM alumnos WHERE id = ? AND colegio_id = ?");
$stmt->execute([$id, $cid]);
$al = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$al) { http_response_code(404); exit('Alumno no encontrado.'); }

// Apoderados vinculados
$stmtApo = $pdo->prepare("
    SELECT ap.*, aa.es_titular
    FROM apoderados ap
    JOIN alumno_apoderado aa ON aa.apoderado_id = ap.id
    WHERE aa.alumno_id = ?
    ORDER BY aa.es_titular DESC, ap.nombre
");
$stmtApo->execute([$id]);
$apoderados = $stmtApo->fetchAll(PDO::FETCH_ASSOC);

// Casos en que participa
$stmtCasos = $pdo->prepare("
    SELECT c.numero_caso, c.estado, c.semaforo, c.fecha_ingreso,
           cp.rol_en_caso, c.id AS caso_id
    FROM caso_participantes cp
    JOIN casos c ON c.id = cp.caso_id
    WHERE cp.persona_id = ? AND cp.tipo_persona = 'alumno'
      AND c.colegio_id = ?
    ORDER BY c.id DESC
");
$stmtCasos->execute([$id, $cid]);
$casosVinculados = $stmtCasos->fetchAll(PDO::FETCH_ASSOC);

$ok = isset($_GET['ok']);
$nombreCompleto = trim("{$al['nombres']} {$al['apellido_paterno']} {$al['apellido_materno']}");

// Condición especial (Fase 1 SQL)
$condicionActiva = null;
$historialCondiciones = [];
try {
    $stmtCond = $pdo->prepare("
        SELECT ace.*, u.nombre AS registrado_por_nombre,
               COALESCE(cat.nombre, ace.nombre_condicion, ace.tipo_condicion) AS nombre_mostrar
        FROM alumno_condicion_especial ace
        LEFT JOIN usuarios u ON u.id = ace.registrado_por
        LEFT JOIN catalogo_condicion_especial cat ON cat.codigo = ace.tipo_condicion
        WHERE ace.alumno_id = ? AND ace.colegio_id = ?
        ORDER BY ace.activo DESC, ace.created_at DESC
    ");
    $stmtCond->execute([$id, $cid]);
    $historialCondiciones = $stmtCond->fetchAll(PDO::FETCH_ASSOC);
    $condicionActiva = !empty($historialCondiciones) ? $historialCondiciones[0] : null;
} catch (Throwable $e) { /* tabla aun no existe */ }

// Catálogo para el formulario
$catalogoCondiciones = [];
try {
    $stmtCat = $pdo->query("SELECT codigo, nombre, categoria FROM catalogo_condicion_especial WHERE activo=1 ORDER BY categoria, nombre");
    $catalogoCondiciones = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Mensajes de acción
$msgOk  = (string)($_GET['msg_ok']  ?? '');
$msgErr = (string)($_GET['msg_err'] ?? '');

// Tab activo
$tabActivo = clean((string)($_GET['tab'] ?? 'datos'));
if (!in_array($tabActivo, ['datos','apoderados','casos','inclusion'], true)) $tabActivo = 'datos';

$pageTitle = e($nombreCompleto) . ' · Alumnos · Metis';

// ── POST: guardar condición especial (debe ir antes de cualquier output) ──
$msgErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tabActivo === 'inclusion') {
    CSRF::requireValid($_POST['_token'] ?? null);
    require_once dirname(__DIR__, 2) . '/core/CSRF.php';
    $accion = (string)($_POST['_accion'] ?? '');
    $redirect = APP_URL . '/modules/alumnos/ver.php?id=' . $id . '&tab=inclusion';

    if ($accion === 'guardar_condicion') {
        try {
            $tipo          = clean((string)($_POST['tipo_condicion']    ?? ''));
            $nombreCond    = clean((string)($_POST['nombre_condicion']  ?? ''));
            $estadoDx      = clean((string)($_POST['estado_diagnostico']?? 'sospecha'));
            $nivelApoyo    = (int)($_POST['nivel_apoyo'] ?? 0) ?: null;
            $tienePie      = (int)($_POST['tiene_pie'] ?? 0);
            $tieneCert     = (int)($_POST['tiene_certificado'] ?? 0);
            $nroCert       = clean((string)($_POST['nro_certificado']   ?? ''));
            $fecDeteccion  = clean((string)($_POST['fecha_deteccion']   ?? ''));
            $fecDx         = clean((string)($_POST['fecha_diagnostico'] ?? ''));
            $derivado      = (int)($_POST['derivado_salud'] ?? 0);
            $fecDerivacion = clean((string)($_POST['fecha_derivacion']  ?? ''));
            $destino       = clean((string)($_POST['destino_derivacion']?? ''));
            $estadoDeriv   = clean((string)($_POST['estado_derivacion'] ?? ''));
            $reqAjustes    = (int)($_POST['requiere_ajustes'] ?? 0);
            $descAjustes   = clean((string)($_POST['descripcion_ajustes'] ?? ''));
            $obs           = clean((string)($_POST['observaciones'] ?? ''));
            $fuente        = clean((string)($_POST['fuente_informacion'] ?? ''));
            $userId        = (int)($user['id'] ?? 0);

            if ($tipo === '') throw new RuntimeException('Debes seleccionar el tipo de condición.');

            $pdo->prepare("
                INSERT INTO alumno_condicion_especial
                    (colegio_id, alumno_id, tipo_condicion, nombre_condicion,
                     estado_diagnostico, nivel_apoyo, tiene_pie, tiene_certificado,
                     nro_certificado, fecha_deteccion, fecha_diagnostico,
                     derivado_salud, fecha_derivacion, destino_derivacion, estado_derivacion,
                     requiere_ajustes, descripcion_ajustes, observaciones,
                     fuente_informacion, registrado_por, activo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
            ")->execute([
                $cid, $id, $tipo, $nombreCond ?: null,
                $estadoDx, $nivelApoyo, $tienePie, $tieneCert,
                $nroCert ?: null,
                ($fecDeteccion !== '' && strtotime($fecDeteccion)) ? $fecDeteccion : null,
                ($fecDx !== '' && strtotime($fecDx)) ? $fecDx : null,
                $derivado,
                ($fecDerivacion !== '' && strtotime($fecDerivacion)) ? $fecDerivacion : null,
                $destino ?: null, $estadoDeriv ?: null,
                $reqAjustes, $descAjustes ?: null,
                $obs ?: null, $fuente ?: null, $userId ?: null,
            ]);

            // Actualizar columna rápida en alumnos
            $pdo->prepare("UPDATE alumnos SET
                condicion_especial = ?,
                diagnostico_tea    = CASE WHEN ? = 'tea' THEN ? ELSE diagnostico_tea END,
                nivel_apoyo_tea    = CASE WHEN ? = 'tea' THEN ? ELSE nivel_apoyo_tea END,
                tiene_pie          = ?,
                derivado_salud_tea = CASE WHEN ? = 'tea' THEN ? ELSE derivado_salud_tea END,
                fecha_derivacion_tea = CASE WHEN ? = 'tea' AND ? IS NOT NULL THEN ? ELSE fecha_derivacion_tea END,
                destino_derivacion_tea = CASE WHEN ? = 'tea' THEN ? ELSE destino_derivacion_tea END,
                requiere_ajustes_razonables = ?
                WHERE id = ? AND colegio_id = ?
            ")->execute([
                $tipo,
                $tipo, $estadoDx,
                $tipo, $nivelApoyo,
                $tienePie,
                $tipo, $derivado,
                $tipo, ($fecDerivacion !== '' ? $fecDerivacion : null), ($fecDerivacion !== '' ? $fecDerivacion : null),
                $tipo, $destino ?: null,
                $reqAjustes,
                $id, $cid,
            ]);

            header('Location: ' . $redirect . '&msg_ok=Condición+registrada+correctamente.');
            exit;
        } catch (Throwable $e) {
            $msgErr = $e->getMessage();
        }
    }
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.ver-hero { background:linear-gradient(135deg,#064e3b 0%,#065f46 55%,#059669 100%);border-radius:12px;color:#fff;padding:2rem 2.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden; }
.ver-hero::before { content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 80% at 95% 50%,rgba(16,185,129,.2) 0%,transparent 65%); }
.ver-hero-name { font-size:1.6rem;font-weight:800;letter-spacing:-.025em;color:#fff;position:relative;margin-bottom:.25rem; }
.ver-hero-sub { font-size:.875rem;color:#a7f3d0;position:relative; }
.info-card { background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);padding:1.5rem;margin-bottom:1.25rem; }
.info-section-title { font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#10b981;border-bottom:1px solid #d1fae5;padding-bottom:.4rem;margin-bottom:1rem; }
.info-row { display:flex;gap:1.5rem;flex-wrap:wrap;row-gap:.65rem; }
.info-item { min-width:140px;flex:1; }
.info-label { font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:.2rem; }
.info-val { font-size:.875rem;color:#1e293b;font-weight:500; }
.badge-titular { font-size:.68rem;font-weight:700;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:20px;padding:.2em .6em; }
.caso-link { display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;font-weight:600;color:#1d4ed8;text-decoration:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:.2rem .6rem;transition:all .15s; }
.caso-link:hover { background:#1d4ed8;color:#fff; }
/* Tabs */
.al-tabs    { display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1.5rem;flex-wrap:wrap; }
.al-tab     { padding:.6rem 1.2rem;font-size:.83rem;font-weight:600;color:#64748b;
              cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;
              text-decoration:none;transition:all .15s;display:flex;align-items:center;gap:.4rem; }
.al-tab:hover{ color:#059669; }
.al-tab.active{ color:#059669;border-bottom-color:#059669; }
.al-tab .tab-badge{ background:#f0fdf4;color:#059669;border-radius:20px;
                    font-size:.68rem;padding:.1rem .45rem;font-weight:700; }
.al-tab .tab-badge.warn{ background:#fff3cd;color:#856404; }
.al-tab .tab-badge.info{ background:#e0f2fe;color:#0369a1; }
/* Inclusión */
.inc-header { display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem; }
.inc-badge  { display:inline-flex;align-items:center;gap:.4rem;border-radius:20px;
              padding:.3rem .85rem;font-size:.78rem;font-weight:700; }
.inc-badge-tea  { background:#fef3c7;color:#92400e;border:1px solid #fde68a; }
.inc-badge-pie  { background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd; }
.inc-badge-cert { background:#d1fae5;color:#065f46;border:1px solid #6ee7b7; }
.inc-kpis   { display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem; }
.inc-kpi    { flex:1 1 120px;background:#f8fafd;border:1px solid #e2e8f0;border-radius:10px;
              padding:.75rem 1rem;text-align:center; }
.inc-kpi-val{ font-size:1.4rem;font-weight:800;color:#1a3a5c;line-height:1; }
.inc-kpi-lbl{ font-size:.68rem;color:#64748b;margin-top:.2rem; }
.inc-form-grid{ display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-top:1rem; }
.inc-form-grid .full{ grid-column:1/-1; }
.inc-label  { display:block;font-size:.78rem;font-weight:600;color:#374151;margin-bottom:.3rem; }
.inc-ctrl   { width:100%;padding:.45rem .7rem;border:1px solid #cdd5e0;border-radius:7px;
              font-size:.83rem;box-sizing:border-box;background:#fff; }
.inc-ctrl:focus{ outline:none;border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.1); }
.inc-submit { background:#059669;color:#fff;border:none;border-radius:8px;
              padding:.55rem 1.2rem;font-size:.84rem;font-weight:700;cursor:pointer; }
.inc-submit:hover{ background:#047857; }
.inc-hist   { margin-top:1.25rem; }
.inc-hist-row{ display:flex;justify-content:space-between;align-items:flex-start;
               padding:.75rem 1rem;background:#f8fafd;border-radius:8px;
               border:1px solid #e2e8f0;margin-bottom:.5rem;font-size:.82rem; }
.derivacion-card{ background:#fff3cd;border:1px solid #fde68a;border-radius:10px;
                  padding:1rem 1.25rem;margin-bottom:1rem; }
.derivacion-card.ok{ background:#d1fae5;border-color:#6ee7b7; }
.alert-ok { background:#d1fae5;color:#065f46;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.85rem; }
.alert-err{ background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.85rem; }
.breadcrumb-bar { display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#64748b;margin-bottom:1.25rem; }
.breadcrumb-bar a { color:#10b981;text-decoration:none;font-weight:600; }
.btn-edit { font-size:.8rem;font-weight:700;padding:.45rem 1.1rem;border-radius:8px;background:#fffbeb;color:#92400e;border:1.5px solid #fde68a;text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:.4rem; }
.btn-edit:hover { background:#f59e0b;color:#fff;border-color:#f59e0b; }
</style>

<div class="breadcrumb-bar">
    <a href="<?= APP_URL ?>/modules/dashboard/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <i class="bi bi-chevron-right" style="font-size:.65rem;"></i>
    <a href="<?= APP_URL ?>/modules/alumnos/index.php"><i class="bi bi-mortarboard"></i> Alumnos</a>
    <i class="bi bi-chevron-right" style="font-size:.65rem;"></i>
    <span><?= e($nombreCompleto) ?></span>
</div>

<?php if ($ok): ?>
<div class="alert alert-success alert-dismissible mb-3" style="border-radius:10px;">
    <i class="bi bi-check-circle-fill me-2"></i>Datos guardados correctamente.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="ver-hero">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3" style="position:relative;">
        <div>
            <div style="font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6ee7b7;margin-bottom:.3rem;">
                <i class="bi bi-mortarboard-fill me-1"></i>Ficha de alumno
            </div>
            <div class="ver-hero-name"><?= e($nombreCompleto) ?></div>
            <div class="ver-hero-sub">
                RUN: <?= e($al['run']) ?> ·
                Curso: <?= e($al['curso'] ?: '—') ?> ·
                <?= $al['activo'] ? '<span style="color:#6ee7b7;font-weight:700;">Activo</span>' : '<span style="color:#fca5a5;font-weight:700;">Inactivo</span>' ?>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;">
            <a href="<?= APP_URL ?>/modules/alumnos/editar.php?id=<?= $id ?>" class="btn-edit">
                <i class="bi bi-pencil-square"></i> Editar
            </a>
        </div>
    </div>
</div>

<?php if ($msgOk  !== ''): ?><div class="alert-ok"><i class="bi bi-check-circle-fill"></i> <?= e($msgOk) ?></div><?php endif; ?>
<?php if ($msgErr !== ''): ?><div class="alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($msgErr) ?></div><?php endif; ?>

<!-- Navegación por pestañas -->
<nav class="al-tabs">
    <a class="al-tab <?= $tabActivo==='datos'      ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=datos">
        <i class="bi bi-person-badge"></i> Datos
    </a>
    <a class="al-tab <?= $tabActivo==='apoderados' ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=apoderados">
        <i class="bi bi-people-fill"></i> Apoderados
        <?php if ($apoderados): ?>
            <span class="tab-badge"><?= count($apoderados) ?></span>
        <?php endif; ?>
    </a>
    <a class="al-tab <?= $tabActivo==='casos'      ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=casos">
        <i class="bi bi-folder2"></i> Casos
        <?php if ($casosVinculados): ?>
            <span class="tab-badge warn"><?= count($casosVinculados) ?></span>
        <?php endif; ?>
    </a>
    <a class="al-tab <?= $tabActivo==='inclusion'  ? 'active' : '' ?>"
       href="?id=<?= $id ?>&tab=inclusion">
        <i class="bi bi-heart-pulse-fill"></i> Inclusión / NEE
        <?php if ($condicionActiva): ?>
            <span class="tab-badge info"><?= e(strtoupper(substr((string)($condicionActiva['tipo_condicion']??''),0,3))) ?></span>
        <?php endif; ?>
    </a>
</nav>

<!-- ══ TAB: DATOS ══════════════════════════════════════════ -->
<?php if ($tabActivo === 'datos'): ?>
<div class="info-card">
    <div class="info-section-title"><i class="bi bi-person-badge me-1"></i>Datos personales</div>
    <div class="info-row">
        <div class="info-item"><div class="info-label">RUN</div><div class="info-val" style="font-family:monospace;"><?= e($al['run']) ?></div></div>
        <div class="info-item"><div class="info-label">Nombres</div><div class="info-val"><?= e($al['nombres']) ?></div></div>
        <div class="info-item"><div class="info-label">Apellido paterno</div><div class="info-val"><?= e($al['apellido_paterno']) ?></div></div>
        <div class="info-item"><div class="info-label">Apellido materno</div><div class="info-val"><?= e($al['apellido_materno'] ?: '—') ?></div></div>
        <div class="info-item">
            <div class="info-label">Fecha nacimiento</div>
            <div class="info-val"><?= $al['fecha_nacimiento'] ? date('d/m/Y', strtotime($al['fecha_nacimiento'])) : '—' ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Sexo</div>
            <div class="info-val"><?= match($al['sexo'] ?? '') { 'M' => 'Masculino', 'F' => 'Femenino', 'O' => 'Otro', default => '—' } ?></div>
        </div>
    </div>
</div>

<!-- Datos académicos -->
<div class="info-card">
    <div class="info-section-title"><i class="bi bi-book me-1"></i>Información académica</div>
    <div class="info-row">
        <div class="info-item"><div class="info-label">Nivel</div><div class="info-val"><?= e(($al['nivel'] ?? '') ?: '—') ?></div></div>
        <div class="info-item"><div class="info-label">Curso</div><div class="info-val"><?= e($al['curso'] ?: '—') ?></div></div>
        <div class="info-item"><div class="info-label">Estado</div><div class="info-val"><?= $al['activo'] ? '✅ Activo' : '❌ Inactivo' ?></div></div>
    </div>
    <?php if (!empty($al['observaciones'])): ?>
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #f1f5f9;">
        <div class="info-label" style="margin-bottom:.35rem;">Observaciones</div>
        <p style="font-size:.875rem;color:#374151;margin:0;"><?= nl2br(e($al['observaciones'])) ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Apoderados -->
<div class="info-card">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:1rem;">
        <div class="info-section-title" style="margin-bottom:0;border:none;">
            <i class="bi bi-people me-1"></i>Apoderados vinculados
        </div>
        <a href="<?= APP_URL ?>/modules/apoderados/vincular.php?alumno_id=<?= $id ?>"
           style="font-size:.75rem;font-weight:600;color:#10b981;text-decoration:none;">
            <i class="bi bi-plus-circle me-1"></i>Vincular apoderado
        </a>
    </div>
    <?php if (!$apoderados): ?>
    <p style="font-size:.84rem;color:#94a3b8;">Sin apoderados vinculados.</p>
    <?php else: ?>
    <div class="row g-2">
    <?php foreach ($apoderados as $apo): ?>
    <div class="col-md-6">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.85rem 1rem;">
            <div style="font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.2rem;">
                <?= e($apo['nombre']) ?>
                <?php if ($apo['es_titular']): ?>
                <span class="badge-titular ms-1">Titular</span>
                <?php endif; ?>
            </div>
            <div style="font-size:.78rem;color:#64748b;">
                <?= e((string)($apo['relacion'] ?? $apo['tipo_relacion'] ?? '')) ?>
                <?php if ($apo['telefono']): ?> · <i class="bi bi-telephone me-1"></i><?= e($apo['telefono']) ?><?php endif; ?>
                <?php if ($apo['email']): ?> · <i class="bi bi-envelope me-1"></i><?= e($apo['email']) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; // datos tab ?>

<!-- ══ TAB: APODERADOS ═══════════════════════════════════ -->
<?php if ($tabActivo === 'apoderados'): ?>

<!-- Casos vinculados -->
<div class="info-card">
    <div class="info-section-title"><i class="bi bi-folder2 me-1"></i>Casos de convivencia vinculados</div>
    <?php if (!$casosVinculados): ?>
    <p style="font-size:.84rem;color:#94a3b8;">Este alumno no está vinculado a ningún caso.</p>
    <?php else: ?>
    <div class="d-flex flex-wrap gap-2">
    <?php foreach ($casosVinculados as $caso):
        $semColor = match($caso['semaforo'] ?? 'verde') {
            'rojo'     => '#ef4444',
            'amarillo' => '#f59e0b',
            default    => '#22c55e'
        };
    ?>
    <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['caso_id'] ?>" class="caso-link">
        <span style="width:8px;height:8px;border-radius:50%;background:<?= $semColor ?>;flex-shrink:0;"></span>
        <?= e($caso['numero_caso']) ?>
        <span style="color:#94a3b8;font-weight:400;">(<?= e($caso['rol_en_caso']) ?>)</span>
    </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; // apoderados tab ?>

<!-- ══ TAB: CASOS ═════════════════════════════════════════ -->
<?php if ($tabActivo === 'casos'): ?>

<style>
.al-caso-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 1.1rem 1.25rem; margin-bottom: .85rem;
    box-shadow: 0 1px 3px rgba(15,23,42,.05); transition: border-color .15s;
    display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;
}
.al-caso-card:hover { border-color: #bfdbfe; }
.al-caso-sem {
    width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(0,0,0,.06);
}
.al-caso-body { flex: 1; min-width: 0; }
.al-caso-num  { font-size: .95rem; font-weight: 700; color: #0f172a; margin-bottom: .2rem; }
.al-caso-meta { font-size: .78rem; color: #64748b; display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; }
.al-caso-badge {
    display: inline-flex; align-items: center; border-radius: 999px;
    padding: .18rem .58rem; font-size: .72rem; font-weight: 600;
    border: 1px solid #e2e8f0; background: #f8fafc; color: #475569;
}
.al-caso-badge.ok     { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.al-caso-badge.warn   { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.al-caso-badge.danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.al-caso-badge.soft   { background:#f8fafc; color:#475569; }
.al-caso-btn {
    display: inline-flex; align-items: center; gap: .38rem;
    background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;
    border-radius: 8px; padding: .5rem 1rem; font-size: .82rem;
    font-weight: 600; text-decoration: none; white-space: nowrap;
    transition: all .15s; flex-shrink: 0;
}
.al-caso-btn:hover { background: #dbeafe; color: #1d4ed8; }
.al-caso-empty { text-align: center; padding: 2.5rem 1rem; color: #94a3b8; }
</style>

<div class="info-card">
    <div class="info-section-title">
        <i class="bi bi-folder2-open me-1"></i>
        Casos de convivencia vinculados
        <?php if ($casosVinculados): ?>
            <span style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;
                border-radius:20px;padding:.1rem .55rem;font-size:.72rem;font-weight:700;margin-left:.4rem;">
                <?= count($casosVinculados) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (!$casosVinculados): ?>
        <div class="al-caso-empty">
            <i class="bi bi-folder2" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
            Este alumno no está vinculado a ningún caso de convivencia.
        </div>
    <?php else: ?>
        <?php foreach ($casosVinculados as $caso):
            $sem   = strtolower((string)($caso['semaforo'] ?? 'verde'));
            $semColor = match($sem) {
                'rojo'     => '#ef4444',
                'amarillo' => '#f59e0b',
                default    => '#22c55e'
            };
            $semClass = match($sem) {
                'rojo'     => 'danger',
                'amarillo' => 'warn',
                'verde'    => 'ok',
                default    => 'soft'
            };
            $estado = (string)($caso['estado'] ?? '');
            $estadoClass = match($estado) {
                'abierto','en_proceso' => 'warn',
                'cerrado'              => 'ok',
                'borrador'             => 'soft',
                default                => 'soft'
            };
            $rol = (string)($caso['rol_en_caso'] ?? '');
            $rolLabel = match($rol) {
                'victima'      => 'Víctima',
                'denunciante'  => 'Denunciante',
                'testigo'      => 'Testigo',
                'denunciado'   => 'Denunciado',
                default        => ucfirst($rol)
            };
            $fecha = $caso['fecha_ingreso']
                ? date('d/m/Y', strtotime((string)$caso['fecha_ingreso']))
                : '';
        ?>
        <div class="al-caso-card">
            <span class="al-caso-sem" style="background:<?= $semColor ?>;"></span>
            <div class="al-caso-body">
                <div class="al-caso-num"><?= e((string)($caso['numero_caso'] ?? '—')) ?></div>
                <div class="al-caso-meta">
                    <span class="al-caso-badge <?= $estadoClass ?>">
                        <?= e(ucfirst($estado)) ?>
                    </span>
                    <?php if ($rolLabel): ?>
                        <span class="al-caso-badge soft">
                            <i class="bi bi-person-fill" style="font-size:.7rem;"></i>
                            <?= e($rolLabel) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($fecha): ?>
                        <span><i class="bi bi-calendar3" style="font-size:.74rem;"></i> <?= e($fecha) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <a class="al-caso-btn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['caso_id'] ?>">
                <i class="bi bi-folder2-open"></i> Ver expediente
            </a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php endif; // casos tab ?>

<!-- ══ TAB: INCLUSIÓN / NEE ══════════════════════════════ -->
<?php if ($tabActivo === 'inclusion'): ?>

<div class="info-card">
<div class="inc-header">
    <div>
        <div class="info-section-title"><i class="bi bi-heart-pulse-fill me-1"></i>Inclusión, NEE y Ley 21.545</div>
        <div style="font-size:.8rem;color:#64748b;">
            Registro de condiciones especiales, diagnósticos, derivaciones a salud y ajustes razonables.
        </div>
    </div>
</div>

<!-- Badges de estado actual -->
<?php if ($al['condicion_especial'] || $al['tiene_pie'] || $al['diagnostico_tea']): ?>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <?php if ($al['condicion_especial']): ?>
        <span class="inc-badge inc-badge-tea">
            <i class="bi bi-heart-pulse-fill"></i>
            <?= e(strtoupper((string)$al['condicion_especial'])) ?>
            <?php if ($al['diagnostico_tea']): ?>
                · <?= e(ucfirst((string)$al['diagnostico_tea'])) ?>
            <?php endif; ?>
            <?php if ($al['nivel_apoyo_tea']): ?>
                · Nivel <?= (int)$al['nivel_apoyo_tea'] ?>
            <?php endif; ?>
        </span>
    <?php endif; ?>
    <?php if ($al['tiene_pie']): ?>
        <span class="inc-badge inc-badge-pie"><i class="bi bi-mortarboard-fill"></i> PIE</span>
    <?php endif; ?>
    <?php if ($al['tiene_certificado_discapacidad']): ?>
        <span class="inc-badge inc-badge-cert"><i class="bi bi-patch-check-fill"></i> Certificado discapacidad</span>
    <?php endif; ?>
    <?php if ($al['derivado_salud_tea']): ?>
        <span class="inc-badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
            <i class="bi bi-hospital"></i> Derivado a salud
            <?= $al['fecha_derivacion_tea'] ? ' · ' . date('d-m-Y', strtotime((string)$al['fecha_derivacion_tea'])) : '' ?>
        </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- KPIs -->
<?php if ($historialCondiciones): ?>
<div class="inc-kpis">
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= count($historialCondiciones) ?></div>
        <div class="inc-kpi-lbl">Registros</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= (int)$al['tiene_pie'] ? 'Sí' : 'No' ?></div>
        <div class="inc-kpi-lbl">PIE activo</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= (int)$al['derivado_salud_tea'] ? 'Sí' : 'No' ?></div>
        <div class="inc-kpi-lbl">Derivado salud</div>
    </div>
    <div class="inc-kpi">
        <div class="inc-kpi-val"><?= (int)$al['requiere_ajustes_razonables'] ? 'Sí' : 'No' ?></div>
        <div class="inc-kpi-lbl">Ajustes razonables</div>
    </div>
</div>
<?php endif; ?>

<?php
// Calcular % protocolo TEA para mostrar en botón
$p_pct = 0;
try {
    $stmtPPct = $pdo->prepare("SELECT deteccion_registrada+comunicacion_familia+derivacion_salud+coordinacion_pie+ajustes_metodologicos+seguimiento_establecido AS pasos FROM caso_protocolo_tea WHERE colegio_id=? AND alumno_condicion_id=? LIMIT 1");
    $stmtPPct->execute([$cid, (int)($condicionActiva['id'] ?? 0)]);
    $rPct = $stmtPPct->fetch();
    if ($rPct) $p_pct = round(((int)$rPct['pasos'] / 6) * 100);
} catch (Throwable $e) {}
?>
<!-- Protocolo derivación TEA -->
<?php if ($al['diagnostico_tea'] === 'sospecha' || $al['diagnostico_tea'] === 'en_proceso'): ?>
<div class="derivacion-card <?= $al['derivado_salud_tea'] ? 'ok' : '' ?>">
    <div style="font-size:.82rem;font-weight:700;color:<?= $al['derivado_salud_tea'] ? '#065f46' : '#92400e' ?>;margin-bottom:.35rem;">
        <i class="bi bi-<?= $al['derivado_salud_tea'] ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
        <?= $al['derivado_salud_tea']
            ? 'Derivación a salud registrada (Art. 12 Ley 21.545)'
            : 'Pendiente: derivación a salud obligatoria (Art. 12 Ley 21.545)' ?>
    </div>
    <div style="font-size:.78rem;color:#64748b;">
        <?php if ($al['derivado_salud_tea']): ?>
            Derivado el <?= date('d-m-Y', strtotime((string)$al['fecha_derivacion_tea'])) ?>
            <?= $al['destino_derivacion_tea'] ? ' a ' . e((string)$al['destino_derivacion_tea']) : '' ?>.
        <?php else: ?>
            El establecimiento debe derivar al establecimiento de salud correspondiente para proceso diagnóstico.
            Registra la derivación en el formulario.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Botón acceso directo al Protocolo TEA -->
<?php if ($condicionActiva && str_starts_with((string)($condicionActiva['tipo_condicion'] ?? ''), 'tea')): ?>
<div style="margin-bottom:1rem;">
    <a href="<?= APP_URL ?>/modules/inclusion/protocolo_tea.php?alumno_id=<?= $id ?>&condicion_id=<?= (int)$condicionActiva['id'] ?>&origen=alumno"
       style="display:inline-flex;align-items:center;gap:.5rem;background:#b45309;color:#fff;
              border-radius:9px;padding:.6rem 1.25rem;font-size:.84rem;font-weight:700;
              text-decoration:none;">
        <i class="bi bi-clipboard2-check-fill"></i>
        Gestionar Protocolo TEA (Ley 21.545)
        <?php if (!empty($p_pct) && $p_pct > 0): ?>
            <span style="background:rgba(255,255,255,.25);border-radius:10px;padding:.1rem .5rem;font-size:.72rem;">
                <?= $p_pct ?>% completado
            </span>
        <?php endif; ?>
    </a>
</div>
<?php endif; ?>

<!-- Formulario nuevo registro -->
<details <?= empty($historialCondiciones) ? 'open' : '' ?> style="margin-bottom:1.25rem;">
    <summary style="cursor:pointer;font-size:.85rem;font-weight:700;color:#059669;padding:.5rem 0;">
        <i class="bi bi-plus-circle"></i>
        <?= empty($historialCondiciones) ? 'Registrar condición especial' : 'Agregar nuevo registro' ?>
    </summary>

    <form method="post" style="margin-top:1rem;">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion" value="guardar_condicion">

        <div class="inc-form-grid">

            <div>
                <label class="inc-label">Tipo de condición *</label>
                <select class="inc-ctrl" name="tipo_condicion" id="tipoCond" required>
                    <option value="">— Seleccione —</option>
                    <?php
                    $cat_actual = '';
                    foreach ($catalogoCondiciones as $cat):
                        if ($cat['categoria'] !== $cat_actual):
                            if ($cat_actual !== '') echo '</optgroup>';
                            $labels = ['tea'=>'TEA (Ley 21.545)','nee'=>'NEE (Decreto 170)','otro'=>'Otros'];
                            echo '<optgroup label="' . e($labels[$cat['categoria']] ?? ucfirst($cat['categoria'])) . '">';
                            $cat_actual = $cat['categoria'];
                        endif;
                    ?>
                        <option value="<?= e($cat['codigo']) ?>"><?= e($cat['nombre']) ?></option>
                    <?php endforeach; if ($cat_actual) echo '</optgroup>'; ?>
                </select>
            </div>

            <div>
                <label class="inc-label">Estado diagnóstico *</label>
                <select class="inc-ctrl" name="estado_diagnostico" required>
                    <option value="sospecha">Sospecha / detección establecimiento</option>
                    <option value="en_proceso">En proceso diagnóstico</option>
                    <option value="confirmado">Confirmado</option>
                    <option value="descartado">Descartado</option>
                </select>
            </div>

            <div id="nivelApoyoDiv" style="display:none;">
                <label class="inc-label">Nivel de apoyo TEA (DSM-5)</label>
                <select class="inc-ctrl" name="nivel_apoyo">
                    <option value="">— No definido —</option>
                    <option value="1">Nivel 1 — Necesita apoyo</option>
                    <option value="2">Nivel 2 — Necesita apoyo sustancial</option>
                    <option value="3">Nivel 3 — Necesita apoyo muy sustancial</option>
                </select>
            </div>

            <div>
                <label class="inc-label">Nombre específico (si es NEE u otro)</label>
                <input class="inc-ctrl" type="text" name="nombre_condicion" placeholder="Ej: Dislexia, TDAH severo...">
            </div>

            <div>
                <label class="inc-label">Fecha de detección por el establecimiento</label>
                <input class="inc-ctrl" type="date" name="fecha_deteccion">
            </div>

            <div>
                <label class="inc-label">Fecha de diagnóstico profesional</label>
                <input class="inc-ctrl" type="date" name="fecha_diagnostico">
            </div>

            <div>
                <label class="inc-label" style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="tiene_pie" value="1">
                    Está en PIE (Programa de Integración Escolar)
                </label>
            </div>

            <div>
                <label class="inc-label" style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="tiene_certificado" value="1">
                    Tiene certificado de discapacidad (Ley 20.422)
                </label>
            </div>

            <div class="full" style="border-top:1px solid #e2e8f0;padding-top:1rem;
                font-size:.78rem;font-weight:700;color:#0369a1;margin-top:.25rem;">
                <i class="bi bi-hospital"></i>
                Derivación a salud — Art. 12 Ley 21.545
            </div>

            <div>
                <label class="inc-label" style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="derivado_salud" value="1" id="chkDerivado">
                    Derivado al sistema de salud
                </label>
            </div>

            <div>
                <label class="inc-label">Estado de la derivación</label>
                <select class="inc-ctrl" name="estado_derivacion">
                    <option value="">— No aplica —</option>
                    <option value="pendiente">Pendiente respuesta</option>
                    <option value="en_proceso">En proceso diagnóstico salud</option>
                    <option value="con_diagnostico">Con diagnóstico recibido</option>
                    <option value="sin_respuesta">Sin respuesta de salud</option>
                </select>
            </div>

            <div>
                <label class="inc-label">Fecha de derivación</label>
                <input class="inc-ctrl" type="date" name="fecha_derivacion">
            </div>

            <div>
                <label class="inc-label">Establecimiento de salud destino</label>
                <input class="inc-ctrl" type="text" name="destino_derivacion"
                       placeholder="CESFAM, Hospital, Centro especialista...">
            </div>

            <div class="full" style="border-top:1px solid #e2e8f0;padding-top:1rem;
                font-size:.78rem;font-weight:700;color:#059669;margin-top:.25rem;">
                <i class="bi bi-tools"></i>
                Ajustes razonables — Art. 18 Ley 21.545
            </div>

            <div>
                <label class="inc-label" style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="requiere_ajustes" value="1">
                    Requiere ajustes razonables
                </label>
            </div>

            <div>
                <label class="inc-label">Fuente de información</label>
                <input class="inc-ctrl" type="text" name="fuente_informacion"
                       placeholder="Familia, docente, profesional externo...">
            </div>

            <div class="full">
                <label class="inc-label">Descripción de ajustes aplicados</label>
                <textarea class="inc-ctrl" name="descripcion_ajustes" rows="2"
                    placeholder="Tiempos extendidos, sala diferencial, apoyos visuales..."></textarea>
            </div>

            <div class="full">
                <label class="inc-label">Observaciones</label>
                <textarea class="inc-ctrl" name="observaciones" rows="2"
                    placeholder="Información relevante adicional..."></textarea>
            </div>

        </div>

        <div style="text-align:right;margin-top:1rem;">
            <button type="submit" class="inc-submit">
                <i class="bi bi-check-circle-fill"></i> Guardar registro
            </button>
        </div>
    </form>
</details>

<!-- Historial de registros -->
<?php if ($historialCondiciones): ?>
<div class="inc-hist">
    <div class="info-section-title">Historial de registros</div>
    <?php foreach ($historialCondiciones as $reg): ?>
    <div class="inc-hist-row">
        <div>
            <strong style="color:#0f172a;"><?= e((string)($reg['nombre_mostrar'] ?? $reg['tipo_condicion'])) ?></strong>
            <div style="color:#64748b;font-size:.78rem;margin-top:.15rem;">
                <?= e(ucfirst((string)($reg['estado_diagnostico'] ?? ''))) ?>
                <?php if ($reg['nivel_apoyo']): ?> · Nivel <?= (int)$reg['nivel_apoyo'] ?><?php endif; ?>
                <?php if ($reg['tiene_pie']): ?> · <span style="color:#0369a1;">PIE</span><?php endif; ?>
                <?php if ($reg['derivado_salud']): ?> · <span style="color:#059669;">Derivado salud</span><?php endif; ?>
            </div>
            <?php if ($reg['destino_derivacion']): ?>
                <div style="font-size:.75rem;color:#64748b;">
                    <i class="bi bi-hospital"></i> <?= e((string)$reg['destino_derivacion']) ?>
                    <?= $reg['fecha_derivacion'] ? ' · ' . date('d-m-Y', strtotime((string)$reg['fecha_derivacion'])) : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($reg['descripcion_ajustes']): ?>
                <div style="font-size:.75rem;color:#059669;">
                    <i class="bi bi-tools"></i> <?= e(mb_strimwidth((string)$reg['descripcion_ajustes'],0,80,'…')) ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;font-size:.72rem;color:#94a3b8;white-space:nowrap;">
            <?= date('d-m-Y', strtotime((string)$reg['created_at'])) ?>
            <?php if ($reg['registrado_por_nombre']): ?>
                <br><?= e((string)$reg['registrado_por_nombre']) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div style="text-align:center;padding:2rem;color:#94a3b8;background:#f8fafd;border-radius:10px;border:1.5px dashed #e2e8f0;">
    <i class="bi bi-heart-pulse" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>
    No hay registros de condición especial para este alumno.<br>
    <small>Usa el formulario para registrar una condición.</small>
</div>
<?php endif; ?>

</div><!-- /info-card inclusión -->

<script>
document.getElementById('tipoCond').addEventListener('change', function(){
    var isTea = this.value.startsWith('tea');
    document.getElementById('nivelApoyoDiv').style.display = isTea ? 'block' : 'none';
});
</script>

<?php endif; // inclusion tab ?>
<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
