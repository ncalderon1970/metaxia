<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/core/DB.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/modules/denuncias/includes/ver_helpers.php';
require_once BASE_PATH . '/modules/denuncias/includes/ver_queries.php';

use Dompdf\Dompdf;
use Dompdf\Options;

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$casoId    = (int)($_GET['id'] ?? 0);
$modo      = in_array($_GET['modo'] ?? '', ['interno','externo','autoridad'], true)
             ? ($_GET['modo']) : 'interno';

if ($casoId <= 0) { http_response_code(400); exit('Caso no válido.'); }

// ── Cargar datos completos ──────────────────────────────────
$caso = ver_cargar_caso($pdo, $casoId, $colegioId);
if (!$caso) { http_response_code(404); exit('Caso no encontrado.'); }

$contexto = ver_cargar_contexto($pdo, $casoId, $colegioId);
extract($contexto, EXTR_OVERWRITE);

// ── Colegio ────────────────────────────────────────────────
$colegio = [];
try {
    $sc = $pdo->prepare("SELECT * FROM colegios WHERE id = ? LIMIT 1");
    $sc->execute([$colegioId]);
    $colegio = $sc->fetch() ?: [];
} catch (Throwable $e) {}

// ── Plan de acción versionado ──────────────────────────────
$planes = [];
try {
    $sp = $pdo->prepare("
        SELECT pa.*, cp.nombre_referencial AS participante_nombre, cp.rol_en_caso
        FROM caso_plan_accion pa
        LEFT JOIN caso_participantes cp ON cp.id = pa.participante_id
        WHERE pa.caso_id = ?
        ORDER BY pa.participante_id ASC, pa.version DESC
    ");
    $sp->execute([$casoId]);
    $planes = $sp->fetchAll();
} catch (Throwable $e) {}

// ── Sesiones de seguimiento ─────────────────────────────────
$sesiones = [];
try {
    $ss = $pdo->prepare("
        SELECT s.*, cp.nombre_referencial AS participante_nombre, u.nombre AS registrado_nombre
        FROM caso_seguimiento_sesion s
        LEFT JOIN caso_participantes cp ON cp.id = s.participante_id
        LEFT JOIN usuarios u ON u.id = s.registrado_por
        WHERE s.caso_id = ?
        ORDER BY s.fecha_sesion DESC, s.id DESC
    ");
    $ss->execute([$casoId]);
    $sesiones = $ss->fetchAll();
} catch (Throwable $e) {}

// ── Cierre ─────────────────────────────────────────────────
$cierre = ver_cargar_cierre_caso($pdo, $casoId, $colegioId);

// ── Clasificación normativa ─────────────────────────────────
$clasificacion = ver_cargar_clasificacion_normativa($pdo, $casoId, $colegioId);

// ── Folio y hash de autenticidad ───────────────────────────
$fechaDoc  = date('d/m/Y H:i');
$folio     = 'METIS-' . date('Ymd') . '-' . str_pad((string)$casoId, 6, '0', STR_PAD_LEFT);
$hash      = strtoupper(substr(hash('sha256', $folio . ($caso['numero_caso'] ?? '') . $colegioId), 0, 16));

// ── Helpers locales ────────────────────────────────────────
function pdf_e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pdf_fecha(?string $v): string { if (!$v) return '—'; $t = strtotime($v); return $t ? date('d/m/Y', $t) : $v; }
function pdf_fechahora(?string $v): string { if (!$v) return '—'; $t = strtotime($v); return $t ? date('d/m/Y H:i', $t) : $v; }
function pdf_nl(?string $v): string { return nl2br(pdf_e($v)); }
function pdf_rol(string $rol): string {
    return match($rol) {
        'victima','víctima'  => 'Víctima',
        'denunciado'         => 'Denunciado/a',
        'denunciante'        => 'Denunciante',
        'testigo'            => 'Testigo',
        'involucrado'        => 'Otro interviniente',
        default              => 'Otro interviniente',
    };
}
function pdf_estado(string $s): string {
    return match($s) {
        'abierto'            => 'Abierto',
        'cerrado'            => 'Cerrado',
        'en_proceso','en proceso' => 'En proceso',
        default              => ucfirst(str_replace('_',' ',$s)),
    };
}
function pdf_prioridad(string $p): string {
    return match($p) { 'alta'=>'Alta ⬆', 'media'=>'Media ➡', 'baja'=>'Baja ⬇', default=>ucfirst($p) };
}
// Anonimato
function pdf_nombre_participante(array $p, string $modo): string {
    $reserva = !empty($p['solicita_reserva_identidad']) || !empty($p['anonimo']);
    if ($reserva && $p['rol_en_caso'] === 'denunciante' && $modo !== 'interno') {
        return 'Identidad reservada (Art. 9 Ley 21.809)';
    }
    return pdf_e((string)($p['nombre_referencial'] ?? $p['nombre'] ?? '—'));
}

$colegioNombre = pdf_e((string)($colegio['nombre'] ?? 'Establecimiento'));
$director      = pdf_e((string)($colegio['director_nombre'] ?? $caso['director_nombre'] ?? ''));
$numeroCaso    = pdf_e((string)($caso['numero_caso'] ?? "CASO-{$casoId}"));
$estadoCaso    = pdf_estado((string)($caso['estado_nombre'] ?? $caso['estado_codigo'] ?? $caso['estado'] ?? ''));

// ── ESTILOS PDF (Dompdf compatibles — sin flex ni grid) ────
$css = '
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10.5pt; color: #1e293b; line-height: 1.45; margin: 8pt; border: 1.5pt solid #1e3a8a; border-radius: 0; padding: 12pt; min-height: 96%; }

/* HEADER */
.doc-header { width:100%; border-bottom: 2.5pt solid #1e3a8a; padding-bottom: 8pt; margin-bottom: 14pt; }
.doc-header table { width:100%; }
.doc-logo-cell { width: 80pt; vertical-align: middle; }
.doc-title-cell { vertical-align: middle; padding-left: 12pt; }
.doc-title-cell h1 { font-size: 14pt; color: #1e3a8a; font-weight: bold; margin-bottom: 2pt; }
.doc-title-cell p { font-size: 8.5pt; color: #64748b; }
.doc-folio-cell { width: 130pt; vertical-align: middle; text-align: right; font-size: 8pt; color: #475569; }
.doc-folio-cell strong { display: block; font-size: 9pt; color: #1e3a8a; }

/* FICHA RESUMEN */
.ficha { width:100%; background:#f0f4ff; border: 1pt solid #c7d7f9; border-radius: 6pt; padding: 10pt; margin-bottom: 14pt; }
.ficha table { width:100%; }
.ficha td { font-size: 9.5pt; padding: 3pt 6pt; }
.ficha .lbl { color: #475569; font-size: 8.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; width: 90pt; }
.ficha .val { color: #0f172a; font-weight: bold; }
.badge { display:inline; border-radius:999pt; padding: 1pt 7pt; font-size: 8.5pt; font-weight: bold; border: 0.5pt solid #ccc; }
.badge-ok    { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
.badge-warn  { background:#fffbeb; color:#92400e; border-color:#fde68a; }
.badge-risk  { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.badge-blue  { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.badge-gray  { background:#f8fafc; color:#475569; border-color:#e2e8f0; }

/* SECCIONES */
.section { margin-bottom: 16pt; }
.section-title { font-size: 11pt; font-weight: bold; color: #1e3a8a; border-bottom: 1pt solid #bfdbfe; padding-bottom: 4pt; margin-bottom: 8pt; }
.section-title span { font-size: 9pt; color: #64748b; font-weight: normal; margin-left: 6pt; }

/* TABLA DATOS */
.data-table { width:100%; border-collapse:collapse; font-size: 9.5pt; margin-bottom: 8pt; }
.data-table th { background: #f0f4ff; color: #334155; font-size: 8.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; padding: 5pt 7pt; border: 0.5pt solid #c7d7f9; text-align: left; }
.data-table td { padding: 5pt 7pt; border: 0.5pt solid #e2e8f0; vertical-align: top; }
.data-table tr:nth-child(even) td { background: #fafbff; }
.data-table .main { font-weight: bold; color: #0f172a; }
.data-table .sub { font-size: 8.5pt; color: #64748b; margin-top: 2pt; }

/* CAJA DE TEXTO */
.text-box { background: #fafbff; border: 0.5pt solid #e2e8f0; border-left: 3pt solid #3b82f6; padding: 8pt 10pt; font-size: 9.5pt; line-height: 1.55; margin-bottom: 8pt; }
.text-box.warn { border-left-color: #f59e0b; background: #fffdf5; }
.text-box.red  { border-left-color: #ef4444; background: #fff5f5; }
.text-box.green{ border-left-color: #10b981; background: #f0fdf4; }
.text-box.gray { border-left-color: #94a3b8; background: #f8fafc; }

/* PARTICIPANTE CARD */
.p-card { border: 0.5pt solid #e2e8f0; border-radius: 5pt; padding: 8pt 10pt; margin-bottom: 7pt; page-break-inside: avoid; }
.p-card-name { font-size: 10.5pt; font-weight: bold; color: #0f172a; }
.p-card-role { font-size: 8.5pt; color: #64748b; margin-top: 2pt; }
.p-card-detail { font-size: 9pt; color: #334155; margin-top: 5pt; }

/* DECLARACIÓN */
.decl-card { border: 0.5pt solid #e2e8f0; border-radius: 5pt; padding: 9pt 11pt; margin-bottom: 9pt; page-break-inside: avoid; }
.decl-meta { font-size: 8.5pt; color: #64748b; margin-bottom: 5pt; }
.decl-meta strong { color: #0f172a; font-size: 9.5pt; }
.decl-text { font-size: 9.5pt; line-height: 1.55; text-align: justify; }

/* SESIÓN */
.ses-card { border: 0.5pt solid #e2e8f0; border-left: 3pt solid #6366f1; border-radius: 5pt; padding: 8pt 10pt; margin-bottom: 7pt; page-break-inside: avoid; }
.ses-header { font-size: 9pt; color: #64748b; margin-bottom: 5pt; }
.ses-header strong { color: #0f172a; font-size: 10pt; }

/* NORMATIVO */
.norm-grid table { width:100%; }
.norm-cell { padding: 3pt 4pt; }
.norm-check { font-size: 10pt; }
.norm-lbl { font-size: 8.5pt; color: #1e293b; }

/* CIERRE */
.cierre-box { background: #ecfdf5; border: 1pt solid #bbf7d0; border-radius: 6pt; padding: 10pt; margin-bottom: 8pt; }
.cierre-box h4 { color: #047857; font-size: 10pt; margin-bottom: 5pt; }

/* FIRMA */
.firma-section { margin-top: 28pt; }
.firma-table { width:100%; }
.firma-cell { width: 40%; text-align: center; vertical-align: bottom; padding: 0 10pt; }
.firma-line { border-top: 1pt solid #334155; padding-top: 4pt; font-size: 9pt; color: #334155; }

/* PIE DE PÁGINA */
.doc-footer { position: fixed; bottom: 0; left: 0; right: 0; border-top: 1pt solid #e2e8f0; font-size: 7.5pt; color: #94a3b8; padding-top: 5pt; text-align: center; }

/* MARCA CONFIDENCIAL */
.confidencial-mark { text-align: center; font-size: 8pt; color: #94a3b8; border: 0.5pt solid #e2e8f0; border-radius: 4pt; padding: 3pt 8pt; display: inline-block; margin-bottom: 10pt; }

/* SALTO DE PÁGINA */
.page-break { page-break-before: always; }

/* EMPTY */
.empty-note { font-size: 9pt; color: #94a3b8; font-style: italic; padding: 5pt 0; }

/* Marcadores normativos */
.marcador-grid td { padding: 2pt 5pt; font-size: 9pt; }
.marcador-check { color: #047857; font-weight: bold; }
.marcador-empty { color: #cbd5e1; }
';

// ── BODY HTML ─────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style><?= $css ?></style>
</head>
<body>

<!-- PIE FIJO -->
<div class="doc-footer">
    Metis · Sistema de Gestión de Convivencia Escolar · <?= pdf_e($colegioNombre) ?> ·
    Folio <?= pdf_e($folio) ?> · Generado el <?= pdf_e($fechaDoc) ?> ·
    Código de autenticidad: <?= pdf_e($hash) ?>
</div>

<!-- ENCABEZADO -->
<div class="doc-header">
    <table>
        <tr>
            <td class="doc-title-cell">
                <h1>INFORME DE CONVIVENCIA ESCOLAR</h1>
                <p><?= pdf_e($colegioNombre) ?> · Sistema Metis</p>
            </td>
            <td class="doc-folio-cell">
                <strong>Folio: <?= pdf_e($folio) ?></strong>
                <?= pdf_e($fechaDoc) ?><br>
                <?= $modo === 'externo' ? '<span style="color:#b91c1c;">USO EXTERNO</span>' : ($modo === 'autoridad' ? '<span style="color:#92400e;">AUTORIDAD COMPETENTE</span>' : '<span style="color:#1d4ed8;">USO INTERNO</span>') ?>
            </td>
        </tr>
    </table>
</div>

<!-- FICHA RESUMEN -->
<div class="ficha">
    <table>
        <tr>
            <td class="lbl">N° de Caso</td>
            <td class="val"><?= pdf_e($numeroCaso) ?></td>
            <td class="lbl">Estado</td>
            <td class="val">
                <?php $est = (string)($caso['estado'] ?? ''); ?>
                <span class="badge <?= $est==='cerrado' ? 'badge-ok' : ($est==='abierto'?'badge-warn':'badge-blue') ?>">
                    <?= pdf_e($estadoCaso) ?>
                </span>
            </td>
            <td class="lbl">Cierre formal</td>
            <td class="val"><?= !empty($cierre) ? 'Registrado' : 'Pendiente' ?></td>
        </tr>
        <tr>
            <td class="lbl">Fecha ingreso</td>
            <td class="val"><?= pdf_fecha((string)($caso['fecha_ingreso'] ?? '')) ?></td>
            <td class="lbl">Prioridad</td>
            <td class="val"><?= pdf_prioridad((string)($caso['prioridad'] ?? '')) ?></td>
            <td class="lbl">Responsable</td>
            <td class="val"><?= pdf_e((string)($caso['responsable_nombre'] ?? '—')) ?></td>
        </tr>
        <?php if (!empty($caso['fecha_hechos'])): ?>
        <tr>
            <td class="lbl">Fecha de hechos</td>
            <td class="val"><?= pdf_fecha((string)($caso['fecha_hechos'] ?? '')) ?></td>
            <td class="lbl">Lugar</td>
            <td class="val" colspan="3"><?= pdf_e((string)($caso['lugar_hechos'] ?? '—')) ?></td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<!-- 1. RELATO DE HECHOS -->
<div class="section">
    <div class="section-title">1. Relato de los hechos</div>
    <?php if (!empty($caso['relato'])): ?>
        <div class="text-box"><?= pdf_nl((string)$caso['relato']) ?></div>
    <?php else: ?>
        <p class="empty-note">No se registró relato de hechos.</p>
    <?php endif; ?>

    <?php if (!empty($caso['contexto'])): ?>
        <div style="font-size:8.5pt; color:#475569; margin-top:4pt; margin-bottom:2pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.05em;">Contexto adicional</div>
        <div class="text-box gray"><?= pdf_nl((string)$caso['contexto']) ?></div>
    <?php endif; ?>
</div>

<!-- 2. PARTICIPANTES -->
<div class="section">
    <div class="section-title">2. Intervinientes <span>(<?= count($participantes ?? []) ?>)</span></div>
    <?php if (empty($participantes)): ?>
        <p class="empty-note">No hay participantes registrados.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>RUN</th>
                    <th>Condición</th>
                    <th>Tipo</th>
                    <th>Curso / Nivel</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($participantes as $p): ?>
                <tr>
                    <td>
                        <div class="main"><?= pdf_nombre_participante($p, $modo) ?></div>
                    </td>
                    <td><?= pdf_e((string)($p['run'] ?? '—')) ?></td>
                    <td>
                        <?php $rol = (string)($p['rol_en_caso'] ?? ''); ?>
                        <span class="badge <?= in_array($rol,['victima','víctima'], true)?'badge-risk':($rol==='denunciado'?'badge-warn':($rol==='denunciante'?'badge-blue':'badge-gray')) ?>">
                            <?= pdf_e(pdf_rol($rol)) ?>
                        </span>
                    </td>
                    <td><?= pdf_e(ucfirst((string)($p['tipo_participante'] ?? ''))) ?></td>
                    <td><?= pdf_e((string)($p['curso'] ?? '—')) ?></td>
                    <td><div class="sub"><?= pdf_e(substr((string)($p['observaciones'] ?? ''), 0, 120)) ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- 3. DECLARACIONES -->
<?php if (empty($declaraciones)): ?>
    <!-- Omitir sección vacía silenciosamente -->
<?php else: ?>
<div class="section page-break">
    <div class="section-title">3. Declaraciones registradas <span>(<?= count($declaraciones) ?>)</span></div>
    <?php foreach ($declaraciones as $i => $d): ?>
    <div class="decl-card">
        <div class="decl-meta">
            <strong><?= pdf_e((string)($d['participante_nombre'] ?? $d['nombre_declarante'] ?? 'Declarante')) ?></strong>
            &nbsp;·&nbsp; <?= pdf_fechahora((string)($d['fecha_declaracion'] ?? '')) ?>
            <?php if (!empty($d['tipo_declaracion'])): ?>
                &nbsp;·&nbsp; <span class="badge badge-gray"><?= pdf_e(ucfirst((string)$d['tipo_declaracion'])) ?></span>
            <?php endif; ?>
        </div>
        <div class="decl-text"><?= pdf_nl((string)($d['texto_declaracion'] ?? '—')) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 4. PLAN DE ACCIÓN -->
<?php if (!empty($planes)): ?>
<div class="section">
    <div class="section-title">4. Plan de acción
        <span>(<?= count($planes) ?> versión(es))</span>
    </div>
    <?php
    $planesPorPart = [];
    foreach ($planes as $pl) {
        $pid = (int)($pl['participante_id'] ?? 0);
        $planesPorPart[$pid][] = $pl;
    }
    foreach ($planesPorPart as $pid => $versiones):
        $vigente = null;
        $historico = [];
        foreach ($versiones as $v) {
            if ($v['vigente'] ?? false) $vigente = $v;
            else $historico[] = $v;
        }
        if (!$vigente && $versiones) $vigente = $versiones[0];
    ?>
    <div class="p-card">
        <div class="p-card-name"><?= pdf_e((string)($vigente['participante_nombre'] ?? '—')) ?></div>
        <div class="p-card-role">
            <span class="badge badge-blue">Versión <?= (int)($vigente['version'] ?? 1) ?> — Vigente</span>
            &nbsp; Registrado: <?= pdf_fecha((string)($vigente['created_at'] ?? '')) ?>
        </div>
        <div class="p-card-detail">
            <strong>Plan:</strong><br>
            <?= pdf_nl((string)($vigente['plan_accion'] ?? '—')) ?>
            <?php if (!empty($vigente['medidas_preventivas'])): ?>
                <br><strong>Medidas preventivas:</strong><br>
                <?= pdf_nl((string)$vigente['medidas_preventivas']) ?>
            <?php endif; ?>
        </div>
        <?php if ($historico): ?>
        <div style="margin-top:6pt;">
            <?php foreach ($historico as $h): ?>
            <div style="font-size:8.5pt; color:#64748b; border-top:0.5pt dashed #e2e8f0; padding-top:5pt; margin-top:5pt;">
                <strong>Versión <?= (int)($h['version'] ?? '') ?></strong> · <?= pdf_fecha((string)($h['created_at'] ?? '')) ?>
                <?php if (!empty($h['motivo_version'])): ?>
                    &nbsp;·&nbsp; <?= pdf_e((string)$h['motivo_version']) ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 5. SEGUIMIENTO POR SESIONES -->
<?php if (!empty($sesiones)): ?>
<div class="section">
    <div class="section-title">5. Registro de sesiones de seguimiento <span>(<?= count($sesiones) ?>)</span></div>
    <?php foreach ($sesiones as $ses): ?>
    <div class="ses-card">
        <div class="ses-header">
            <strong><?= pdf_e((string)($ses['participante_nombre'] ?? 'Participante')) ?></strong>
            &nbsp;·&nbsp; <?= pdf_fechahora((string)($ses['fecha_sesion'] ?? '')) ?>
            <?php if (!empty($ses['estado_caso'])): ?>
                &nbsp;·&nbsp;
                <span class="badge badge-gray"><?= pdf_e(pdf_estado((string)$ses['estado_caso'])) ?></span>
            <?php endif; ?>
            <?php if (!empty($ses['cumplimiento'])): ?>
                &nbsp;·&nbsp;
                <span class="badge <?= $ses['cumplimiento']==='cumplido'?'badge-ok':($ses['cumplimiento']==='en_proceso'?'badge-warn':'badge-gray') ?>">
                    <?= pdf_e(ucfirst(str_replace('_',' ',(string)$ses['cumplimiento']))) ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if (!empty($ses['observacion_avance'])): ?>
            <div class="decl-text"><?= pdf_nl((string)$ses['observacion_avance']) ?></div>
        <?php endif; ?>
        <?php if (!empty($ses['comunicacion_apoderado_modalidad'])): ?>
            <div style="font-size:8.5pt; color:#64748b; margin-top:4pt;">
                Comunicación apoderado: <?= pdf_e(ucfirst((string)$ses['comunicacion_apoderado_modalidad'])) ?>
                <?php if (!empty($ses['comunicacion_apoderado_fecha'])): ?>
                    · <?= pdf_fecha((string)$ses['comunicacion_apoderado_fecha']) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($ses['proxima_revision'])): ?>
            <div style="font-size:8.5pt; color:#64748b; margin-top:2pt;">
                Próxima revisión: <?= pdf_fecha((string)$ses['proxima_revision']) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 6. CLASIFICACIÓN NORMATIVA -->
<?php
$tieneClasificacion = !empty($clasificacion['area_mineduc']) || !empty($clasificacion['tipo_conducta']);
?>
<?php if ($tieneClasificacion): ?>
<div class="section">
    <div class="section-title">6. Clasificación normativa</div>
    <table class="data-table" style="margin-bottom:8pt;">
        <tr>
            <td class="lbl" style="width:130pt;">Área MINEDUC</td>
            <td><?= pdf_e(ucfirst(str_replace('_',' ',(string)($clasificacion['area_mineduc'] ?? '—')))) ?></td>
            <td class="lbl" style="width:130pt;">Ámbito</td>
            <td><?= pdf_e(ucfirst(str_replace('_',' ',(string)($clasificacion['ambito'] ?? '—')))) ?></td>
        </tr>
        <tr>
            <td class="lbl">Tipo de conducta</td>
            <td><?= pdf_e(ucfirst(str_replace('_',' ',(string)($clasificacion['tipo_conducta'] ?? '—')))) ?></td>
            <td class="lbl">Categoría</td>
            <td><?= pdf_e(ucfirst(str_replace('_',' ',(string)($clasificacion['categoria'] ?? '—')))) ?></td>
        </tr>
    </table>

    <?php
    // Marcadores Ley 21.809
    $marcadores809 = [];
    $marcadores782 = [];
    try {
        $sm = $pdo->prepare("SELECT * FROM caso_clasificacion_normativa WHERE caso_id = ? LIMIT 1");
        $sm->execute([$casoId]);
        $marcRow = $sm->fetch();
        if ($marcRow) {
            foreach ($marcRow as $k => $v) {
                if (str_starts_with($k, 'ley21809_') && $v) $marcadores809[] = str_replace(['ley21809_','_'], ['',''], $k);
                if (str_starts_with($k, 'rex782_') && $v) $marcadores782[] = str_replace(['rex782_','_'], ['',''], $k);
            }
        }
    } catch (Throwable $e) {}

    if ($marcadores809 || $marcadores782):
    ?>
    <table style="width:100%;">
        <tr>
            <?php if ($marcadores809): ?>
            <td style="vertical-align:top; padding-right:10pt; width:50%;">
                <div style="font-size:8.5pt; font-weight:bold; color:#1e3a8a; margin-bottom:4pt;">Ley 21.809 — Buen trato</div>
                <?php foreach ($marcadores809 as $m): ?>
                    <div style="font-size:9pt; padding:1pt 0;"><span class="marcador-check">✓</span> <?= pdf_e(ucfirst(str_replace('_',' ',$m))) ?></div>
                <?php endforeach; ?>
            </td>
            <?php endif; ?>
            <?php if ($marcadores782): ?>
            <td style="vertical-align:top; width:50%;">
                <div style="font-size:8.5pt; font-weight:bold; color:#1e3a8a; margin-bottom:4pt;">REX 782 — Procedimiento</div>
                <?php foreach ($marcadores782 as $m): ?>
                    <div style="font-size:9pt; padding:1pt 0;"><span class="marcador-check">✓</span> <?= pdf_e(ucfirst(str_replace('_',' ',$m))) ?></div>
                <?php endforeach; ?>
            </td>
            <?php endif; ?>
        </tr>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 7. CIERRE FORMAL -->
<?php if (!empty($cierre)): ?>
<div class="section page-break">
    <div class="section-title">7. Cierre del expediente</div>
    <div class="cierre-box">
        <h4>✅ Caso cerrado formalmente</h4>
        <table style="width:100%; font-size:9.5pt;">
            <tr>
                <td style="padding:2pt 0; width:130pt; color:#475569;">Tipo de cierre:</td>
                <td style="font-weight:bold;"><?= pdf_e(ucfirst(str_replace('_',' ',(string)($cierre['tipo_cierre'] ?? '—')))) ?></td>
                <td style="padding:2pt 0; width:130pt; color:#475569;">Fecha cierre:</td>
                <td style="font-weight:bold;"><?= pdf_fecha((string)($cierre['fecha_cierre'] ?? '')) ?></td>
            </tr>
        </table>
        <?php if (!empty($cierre['resolucion'])): ?>
            <div style="margin-top:6pt; font-size:9.5pt;"><?= pdf_nl((string)$cierre['resolucion']) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- MARCO LEGAL TEA / Ley 21.430 -->
<?php
$involucraT  = (int)($caso['involucra_nna_tea'] ?? 0);
$marcoLegal  = (string)($caso['marco_legal'] ?? '');
$coordSenape = (int)($caso['requiere_coordinacion_senape'] ?? 0);
if ($involucraT || $coordSenape || $marcoLegal):
?>
<div class="section">
    <div class="section-title">Contexto normativo especial</div>
    <div class="text-box warn">
        <?php if ($marcoLegal && $marcoLegal !== 'ley21809'): ?>
            <strong>Marco legal:</strong> <?= pdf_e(str_replace(['ley','rex','_'],[' Ley ',' REX ',' '], $marcoLegal)) ?><br>
        <?php endif; ?>
        <?php if ($involucraT): ?>
            ⚠ <strong>Involucra NNA con condición especial (TEA u otra).</strong>
            Aplica protocolo Art. 12 Ley 21.545 y ajustes razonables Art. 18.<br>
        <?php endif; ?>
        <?php if ($coordSenape): ?>
            📋 <strong>Requiere coordinación con SENAPE</strong> (Ley 21.430 Art. 75-77).<br>
        <?php endif; ?>
        <?php if ((int)($caso['interés_superior_aplicado'] ?? 0) || (int)($caso['interes_superior_aplicado'] ?? 0)): ?>
            ✓ Principio de interés superior del NNA aplicado (Ley 21.430 Art. 9).
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- FIRMA -->
<div class="firma-section">
    <table class="firma-table">
        <tr>
            <td class="firma-cell">
                <div style="height:35pt;"></div>
                <div class="firma-line">
                    <?= pdf_e($director) ?><br>
                    Director(a) — <?= pdf_e($colegioNombre) ?>
                </div>
            </td>
            <td style="width:20%;"></td>
            <td class="firma-cell">
                <div style="height:35pt;"></div>
                <div class="firma-line">
                    Encargado/a de Convivencia Escolar<br>
                    <?= pdf_e($colegioNombre) ?>
                </div>
            </td>
        </tr>
    </table>

    <div style="text-align:center; margin-top:18pt; font-size:8pt; color:#94a3b8;">
        Documento generado por Metis · Código de autenticidad: <strong><?= pdf_e($hash) ?></strong> ·
        <?php if ($modo !== 'interno'): ?>
            Versión <?= strtoupper(pdf_e($modo)) ?> — sujeto a normativa de confidencialidad.
        <?php else: ?>
            Uso interno exclusivo del establecimiento.
        <?php endif; ?>
    </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ── Generar PDF con Dompdf ──────────────────────────────────
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('isFontSubsettingEnabled', true);
$options->set('chroot', BASE_PATH);

$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('letter', 'portrait');
$pdf->render();

$filename = 'Metis_' . preg_replace('/[^A-Za-z0-9\-]/', '_', (string)($caso['numero_caso'] ?? $casoId)) . '_' . date('Ymd') . '.pdf';
$pdf->stream($filename, ['Attachment' => false]);
exit;
