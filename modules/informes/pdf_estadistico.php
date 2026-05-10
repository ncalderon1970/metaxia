<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/core/DB.php';
require_once BASE_PATH . '/core/Auth.php';
require_once BASE_PATH . '/core/helpers.php';

use Dompdf\Dompdf;
use Dompdf\Options;

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

// ── Filtros ─────────────────────────────────────────────────
$desde    = clean((string)($_GET['desde'] ?? date('Y-01-01')));
$hasta    = clean((string)($_GET['hasta'] ?? date('Y-m-d')));
$estado   = clean((string)($_GET['estado'] ?? 'todos'));

// ── Helpers ─────────────────────────────────────────────────
function pe(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function pf(?string $v): string { if(!$v) return '—'; $t=strtotime($v); return $t ? date('d/m/Y',$t) : $v; }
function pc(PDO $pdo, string $sql, array $p=[]): int { try { $s=$pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); } catch(Throwable $e){return 0;} }
function col_ok(PDO $pdo, string $t, string $c): bool {
    static $schema = [
        'casos' => ['id','colegio_id','fecha_ingreso','created_at','estado','estado_caso_id','prioridad','relato','involucra_nna_tea'],
        'caso_clasificacion_normativa' => ['caso_id','tipo_conducta','violencia_sexual'],
        'caso_pauta_riesgo' => ['caso_id','nivel_final','puntaje_total'],
    ];
    return in_array($c, $schema[$t] ?? [], true);
}

// ── Datos del establecimiento ───────────────────────────────
$colegio = [];
try { $s=$pdo->prepare("SELECT * FROM colegios WHERE id=? LIMIT 1"); $s->execute([$colegioId]); $colegio=$s->fetch()?:[]; } catch(Throwable $e){}

// ── Parámetros base ─────────────────────────────────────────
$whereBase = ['c.colegio_id = ?', 'DATE(c.fecha_ingreso) BETWEEN ? AND ?'];
$paramsBase = [$colegioId, $desde, $hasta];
if ($estado !== 'todos') { $whereBase[] = 'c.estado = ?'; $paramsBase[] = $estado; }
$whereSql = 'WHERE ' . implode(' AND ', $whereBase);

// ── KPIs ────────────────────────────────────────────────────
$kpi = [];
$kpi['total']    = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql", $paramsBase);
$kpi['abiertos'] = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql AND c.estado='abierto'", $paramsBase);
$kpi['cerrados'] = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql AND c.estado='cerrado'", $paramsBase);
$kpi['investigacion'] = pc($pdo, "SELECT COUNT(*) FROM casos c LEFT JOIN estado_caso ec ON ec.id=c.estado_caso_id $whereSql AND (ec.codigo='investigacion' OR c.estado='en_proceso')", $paramsBase);
$kpi['alta']     = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql AND c.prioridad='alta'", $paramsBase);
$kpi['media']    = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql AND c.prioridad='media'", $paramsBase);
$kpi['baja']     = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql AND c.prioridad='baja'", $paramsBase);

// Participantes, declaraciones, evidencias
$kpi['participantes']  = pc($pdo, "SELECT COUNT(*) FROM caso_participantes p INNER JOIN casos c ON c.id=p.caso_id $whereSql", $paramsBase);
$kpi['declaraciones']  = pc($pdo, "SELECT COUNT(*) FROM caso_declaraciones d INNER JOIN casos c ON c.id=d.caso_id $whereSql", $paramsBase);
$kpi['evidencias']     = pc($pdo, "SELECT COUNT(*) FROM caso_evidencias e INNER JOIN casos c ON c.id=e.caso_id $whereSql", $paramsBase);
$kpi['cierres']        = pc($pdo, "SELECT COUNT(*) FROM caso_cierre cc INNER JOIN casos c ON c.id=cc.caso_id $whereSql", $paramsBase);
$kpi['con_tea']        = pc($pdo, "SELECT COUNT(*) FROM casos c $whereSql AND c.involucra_nna_tea=1", $paramsBase);

// TEA en alumnos
try {
    $sT=$pdo->prepare("SELECT COUNT(*) FROM alumno_condicion_especial WHERE colegio_id=? AND activo=1");
    $sT->execute([$colegioId]);
    $kpi['alumnos_nee'] = (int)$sT->fetchColumn();
} catch(Throwable $e){ $kpi['alumnos_nee']=0; }

// ── Listado de casos del período ────────────────────────────
$casos = [];
try {
    $sc = $pdo->prepare("
        SELECT c.id, c.numero_caso, c.fecha_ingreso, COALESCE(ec.nombre, c.estado) AS estado_nombre, c.estado, c.prioridad,
               SUBSTR(c.relato,1,200) AS relato_corto,
               (SELECT COUNT(*) FROM caso_participantes WHERE caso_id=c.id) AS n_part,
               (SELECT COUNT(*) FROM caso_declaraciones WHERE caso_id=c.id) AS n_decl,
               (SELECT COUNT(*) FROM caso_evidencias WHERE caso_id=c.id) AS n_evid
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        $whereSql
        ORDER BY c.fecha_ingreso DESC
        LIMIT 200
    ");
    $sc->execute($paramsBase);
    $casos = $sc->fetchAll();
} catch(Throwable $e){ $casos=[]; }

// ── Distribución por estado ─────────────────────────────────
$porEstado = [];
try {
    $se = $pdo->prepare("SELECT COALESCE(ec.nombre, c.estado, 'Sin estado') AS estado, COUNT(*) AS n FROM casos c LEFT JOIN estado_caso ec ON ec.id=c.estado_caso_id $whereSql GROUP BY estado ORDER BY n DESC");
    $se->execute($paramsBase);
    $porEstado = $se->fetchAll();
} catch(Throwable $e){}

// ── Distribución por mes ────────────────────────────────────
$porMes = [];
try {
    $sm = $pdo->prepare("SELECT DATE_FORMAT(c.fecha_ingreso,'%Y-%m') AS mes, COUNT(*) AS n FROM casos c $whereSql GROUP BY mes ORDER BY mes ASC");
    $sm->execute($paramsBase);
    $porMes = $sm->fetchAll();
} catch(Throwable $e){}

// ── Folio ───────────────────────────────────────────────────
$fechaDoc  = date('d/m/Y H:i');
$folio     = 'METIS-EST-' . date('Ymd') . '-' . str_pad((string)$colegioId, 4,'0',STR_PAD_LEFT);
$colegioNm = pe((string)($colegio['nombre'] ?? 'Establecimiento'));
$director  = pe((string)($colegio['director_nombre'] ?? ''));
$periodoLbl= pf($desde) . ' — ' . pf($hasta);

// ── CSS ─────────────────────────────────────────────────────
$css = '
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:"DejaVu Sans",Arial,sans-serif; font-size:10pt; color:#1e293b; line-height:1.45; }
.doc-header { width:100%; border-bottom:2.5pt solid #1e3a8a; padding-bottom:8pt; margin-bottom:14pt; }
.doc-header table { width:100%; }
.doc-title-cell h1 { font-size:14pt; color:#1e3a8a; font-weight:bold; }
.doc-title-cell p  { font-size:8.5pt; color:#64748b; }
.doc-folio-cell { width:140pt; text-align:right; vertical-align:middle; font-size:8pt; color:#475569; }
.doc-folio-cell strong { display:block; font-size:9pt; color:#1e3a8a; }
.section { margin-bottom:16pt; }
.section-title { font-size:11pt; font-weight:bold; color:#1e3a8a; border-bottom:1pt solid #bfdbfe; padding-bottom:4pt; margin-bottom:8pt; }
.kpi-table { width:100%; border-collapse:collapse; margin-bottom:12pt; }
.kpi-table td { text-align:center; padding:6pt 4pt; border:0.5pt solid #e2e8f0; vertical-align:middle; }
.kpi-num { font-size:18pt; font-weight:bold; color:#1e3a8a; display:block; }
.kpi-lbl { font-size:7.5pt; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; display:block; margin-top:2pt; }
.kpi-red  { color:#b91c1c; }
.kpi-warn { color:#92400e; }
.kpi-ok   { color:#047857; }
.data-table { width:100%; border-collapse:collapse; font-size:9pt; }
.data-table th { background:#f0f4ff; color:#334155; font-size:8pt; text-transform:uppercase; letter-spacing:0.05em; padding:4pt 6pt; border:0.5pt solid #c7d7f9; text-align:left; }
.data-table td { padding:4pt 6pt; border:0.5pt solid #e2e8f0; vertical-align:top; }
.data-table tr:nth-child(even) td { background:#fafbff; }
.badge { border-radius:999pt; padding:1pt 6pt; font-size:8pt; font-weight:bold; border:0.5pt solid #e2e8f0; }
.ok   { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
.warn { background:#fffbeb; color:#92400e; border-color:#fde68a; }
.risk { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.soft { background:#f8fafc; color:#475569; }
.dist-bar { background:#e2e8f0; border-radius:4pt; height:10pt; width:100%; overflow:hidden; }
.dist-fill { background:#3b82f6; height:10pt; border-radius:4pt; }
.doc-footer { position:fixed; bottom:0; left:0; right:0; border-top:1pt solid #e2e8f0; font-size:7.5pt; color:#94a3b8; padding-top:5pt; text-align:center; }
.page-break { page-break-before:always; }
.firma-line { border-top:1pt solid #334155; padding-top:4pt; font-size:8.5pt; color:#334155; text-align:center; }
.nota-legal { font-size:8pt; color:#64748b; border-left:2pt solid #cbd5e1; padding-left:8pt; margin-top:8pt; line-height:1.5; }
';

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style><?= $css ?></style></head>
<body>

<div class="doc-footer">
    Metis · Informe Estadístico Institucional · <?= $colegioNm ?> · <?= pe($folio) ?> · <?= pe($fechaDoc) ?>
</div>

<!-- ENCABEZADO -->
<div class="doc-header">
    <table>
        <tr>
            <td class="doc-title-cell">
                <h1>INFORME ESTADÍSTICO DE CONVIVENCIA ESCOLAR</h1>
                <p><?= $colegioNm ?> · Sistema Metis · Período: <?= pe($periodoLbl) ?></p>
            </td>
            <td class="doc-folio-cell">
                <strong>Folio: <?= pe($folio) ?></strong>
                <?= pe($fechaDoc) ?><br>
                <span style="color:#1d4ed8;">DOCUMENTO INSTITUCIONAL</span>
            </td>
        </tr>
    </table>
</div>

<!-- IDENTIFICACIÓN -->
<div class="section">
    <div class="section-title">Datos del establecimiento</div>
    <table class="data-table">
        <tr>
            <td style="width:120pt;color:#475569;font-weight:bold;">Nombre</td>
            <td><?= $colegioNm ?></td>
            <td style="width:120pt;color:#475569;font-weight:bold;">Director/a</td>
            <td><?= $director ?: '—' ?></td>
        </tr>
        <tr>
            <td style="color:#475569;font-weight:bold;">RBD</td>
            <td><?= pe((string)($colegio['rbd'] ?? '—')) ?></td>
            <td style="color:#475569;font-weight:bold;">Período del informe</td>
            <td><?= pe($periodoLbl) ?></td>
        </tr>
        <tr>
            <td style="color:#475569;font-weight:bold;">Dependencia</td>
            <td><?= pe(ucfirst((string)($colegio['dependencia'] ?? '—'))) ?></td>
            <td style="color:#475569;font-weight:bold;">Fecha de emisión</td>
            <td><?= pe($fechaDoc) ?></td>
        </tr>
    </table>
</div>

<!-- KPIs PRINCIPALES -->
<div class="section">
    <div class="section-title">Resumen ejecutivo del período</div>
    <table class="kpi-table">
        <tr>
            <td><span class="kpi-num"><?= number_format($kpi['total'],0,',','.') ?></span><span class="kpi-lbl">Total casos</span></td>
            <td><span class="kpi-num kpi-warn"><?= number_format($kpi['abiertos'],0,',','.') ?></span><span class="kpi-lbl">Abiertos</span></td>
            <td><span class="kpi-num kpi-ok"><?= number_format($kpi['cerrados'],0,',','.') ?></span><span class="kpi-lbl">Cerrados</span></td>
            <td><span class="kpi-num kpi-ok"><?= number_format($kpi['cierres'],0,',','.') ?></span><span class="kpi-lbl">Cierres formales</span></td>
            <td><span class="kpi-num kpi-warn"><?= number_format($kpi['investigacion'],0,',','.') ?></span><span class="kpi-lbl">En investigación</span></td>
            <td><span class="kpi-num kpi-warn"><?= number_format($kpi['alta'],0,',','.') ?></span><span class="kpi-lbl">Prioridad alta</span></td>
        </tr>
    </table>
    <table class="kpi-table">
        <tr>
            <td><span class="kpi-num"><?= number_format($kpi['participantes'],0,',','.') ?></span><span class="kpi-lbl">Intervinientes</span></td>
            <td><span class="kpi-num"><?= number_format($kpi['declaraciones'],0,',','.') ?></span><span class="kpi-lbl">Declaraciones</span></td>
            <td><span class="kpi-num"><?= number_format($kpi['evidencias'],0,',','.') ?></span><span class="kpi-lbl">Evidencias</span></td>
            <td><span class="kpi-num kpi-warn"><?= number_format($kpi['con_tea'],0,',','.') ?></span><span class="kpi-lbl">Casos con TEA/NEE</span></td>
            <td><span class="kpi-num"><?= number_format($kpi['alumnos_nee'],0,',','.') ?></span><span class="kpi-lbl">Alumnos c/ condición registrada</span></td>
            <td>
                <?php $pct_cierre = $kpi['total']>0 ? round(($kpi['cerrados']/$kpi['total'])*100) : 0; ?>
                <span class="kpi-num <?= $pct_cierre>=70?'kpi-ok':($pct_cierre>=40?'kpi-warn':'kpi-red') ?>"><?= $pct_cierre ?>%</span>
                <span class="kpi-lbl">Tasa de cierre</span>
            </td>
        </tr>
    </table>
</div>

<!-- DISTRIBUCIÓN POR ESTADO Y MES -->
<?php if ($porEstado || $porMes): ?>
<div class="section">
    <div class="section-title">Distribución del período</div>
    <table style="width:100%;">
        <tr>
            <?php if ($porEstado): ?>
            <td style="vertical-align:top; padding-right:12pt; width:45%;">
                <div style="font-size:9pt; font-weight:bold; color:#334155; margin-bottom:6pt;">Por estado</div>
                <table class="data-table">
                    <thead><tr><th>Estado</th><th>Cantidad</th><th style="width:80pt;">Proporción</th></tr></thead>
                    <tbody>
                    <?php foreach ($porEstado as $r):
                        $pct = $kpi['total']>0 ? round(((int)$r['n']/$kpi['total'])*100) : 0;
                    ?>
                    <tr>
                        <td><?= pe(ucfirst(str_replace('_',' ',(string)$r['estado']))) ?></td>
                        <td style="text-align:center; font-weight:bold;"><?= (int)$r['n'] ?></td>
                        <td>
                            <div class="dist-bar"><div class="dist-fill" style="width:<?= $pct ?>%;"></div></div>
                            <span style="font-size:7.5pt; color:#64748b;"><?= $pct ?>%</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </td>
            <?php endif; ?>
            <?php if ($porMes): ?>
            <td style="vertical-align:top; width:55%;">
                <div style="font-size:9pt; font-weight:bold; color:#334155; margin-bottom:6pt;">Por mes de ingreso</div>
                <table class="data-table">
                    <thead><tr><th>Mes</th><th>Casos</th><th style="width:100pt;">Gráfico</th></tr></thead>
                    <tbody>
                    <?php
                    $maxMes = $porMes ? max(array_column($porMes,'n')) : 1;
                    foreach ($porMes as $r):
                        $pct = $maxMes>0 ? round(((int)$r['n']/$maxMes)*100) : 0;
                        [$y,$m] = explode('-',(string)$r['mes']);
                        $meses=['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
                        $mesLbl = ($meses[$m] ?? $m) . ' ' . $y;
                    ?>
                    <tr>
                        <td><?= pe($mesLbl) ?></td>
                        <td style="text-align:center; font-weight:bold;"><?= (int)$r['n'] ?></td>
                        <td>
                            <div class="dist-bar"><div class="dist-fill" style="width:<?= $pct ?>%; background:#6366f1;"></div></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </td>
            <?php endif; ?>
        </tr>
    </table>
</div>
<?php endif; ?>

<!-- DISTRIBUCIÓN POR PRIORIDAD -->
<div class="section">
    <div class="section-title">Prioridad de atención</div>
    <table class="data-table">
        <thead><tr><th>Nivel</th><th>Cantidad</th><th style="width:70pt;">%</th></tr></thead>
        <tbody>
        <?php foreach ([['alta','risk','⬆'],['media','warn','➡'],['baja','ok','⬇']] as [$k,$cls,$ico]):
            $n = $kpi[$k];
            $pct = $kpi['total']>0 ? round(($n/$kpi['total'])*100) : 0;
        ?>
        <tr>
            <td><?= $ico ?> <?= ucfirst($k) ?></td>
            <td style="text-align:center; font-weight:bold;"><span class="badge <?= $cls ?>"><?= $n ?></span></td>
            <td><?= $pct ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- LISTADO DE CASOS -->
<div class="section page-break">
    <div class="section-title">Detalle de casos del período <span style="font-size:9pt;color:#64748b;font-weight:normal;">(<?= count($casos) ?> casos)</span></div>
    <?php if (!$casos): ?>
        <p style="color:#94a3b8; font-style:italic; font-size:9pt;">No hay casos para el período y filtros seleccionados.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:85pt;">N° Caso</th>
                <th style="width:60pt;">Ingreso</th>
                <th style="width:70pt;">Estado</th>
                <th style="width:50pt;">Prioridad</th>
                <th style="width:38pt;">Part.</th>
                <th style="width:38pt;">Decl.</th>
                <th>Síntesis del hecho</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($casos as $c):
            $est = (string)($c['estado_nombre'] ?? $c['estado'] ?? '');
            $pri = (string)($c['prioridad'] ?? '');
        ?>
        <tr>
            <td style="font-weight:bold; font-size:8.5pt;"><?= pe((string)($c['numero_caso'] ?? "CASO-{$c['id']}")) ?></td>
            <td style="font-size:8.5pt;"><?= pf((string)($c['fecha_ingreso'] ?? '')) ?></td>
            <td><span class="badge <?= stripos($est,'cerr')!==false?'ok':(stripos($est,'invest')!==false?'warn':'soft') ?>"><?= pe(ucfirst($est)) ?></span></td>
            <td><span class="badge <?= $pri==='alta'?'risk':($pri==='media'?'warn':'ok') ?>"><?= pe(ucfirst($pri)) ?></span></td>
            <td style="text-align:center;"><?= (int)$c['n_part'] ?></td>
            <td style="text-align:center;"><?= (int)$c['n_decl'] ?></td>
            <td style="font-size:8.5pt; color:#334155;"><?= pe(substr((string)($c['relato_corto'] ?? ''), 0, 130)) ?><?= strlen((string)($c['relato_corto'] ?? ''))>130?'…':'' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- NOTA LEGAL -->
<div class="nota-legal">
    Este informe ha sido generado por el Sistema Metis de Gestión de Convivencia Escolar.
    La información contenida es de carácter institucional y debe ser tratada con la debida reserva,
    conforme a la Ley N° 19.628 sobre protección de datos personales y la Ley N° 21.809 sobre
    convivencia escolar. El establecimiento es responsable del resguardo y uso apropiado de este documento.
</div>

<!-- FIRMA -->
<div style="margin-top:30pt;">
    <table style="width:100%;">
        <tr>
            <td style="width:40%; text-align:center; padding:0 12pt;">
                <div style="height:32pt;"></div>
                <div class="firma-line">
                    <?= $director ?: '________________________' ?><br>
                    Director(a) — <?= $colegioNm ?>
                </div>
            </td>
            <td style="width:20%;"></td>
            <td style="width:40%; text-align:center; padding:0 12pt;">
                <div style="height:32pt;"></div>
                <div class="firma-line">
                    Encargado/a de Convivencia Escolar<br>
                    <?= $colegioNm ?>
                </div>
            </td>
        </tr>
    </table>
    <div style="text-align:center; margin-top:14pt; font-size:7.5pt; color:#94a3b8;">
        Folio <?= pe($folio) ?> · Generado por Metis el <?= pe($fechaDoc) ?> · Uso institucional exclusivo
    </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('chroot', BASE_PATH);

$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('letter', 'portrait');
$pdf->render();

$filename = 'Metis_Estadistico_' . date('Ymd') . '.pdf';
$pdf->stream($filename, ['Attachment' => false]);
exit;
