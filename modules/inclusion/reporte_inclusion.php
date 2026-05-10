<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo    = DB::conn();
$user   = Auth::user() ?? [];
$cid    = (int)($user['colegio_id'] ?? 0);
if ($cid <= 0 && !Auth::isSuperAdmin()) { http_response_code(403); exit('Colegio no determinado.'); }
$modo   = trim((string)($_GET['modo'] ?? 'vista'));   // vista | csv
$filtroTipo = trim((string)($_GET['tipo'] ?? ''));

// ── Datos del establecimiento ──────────────────────────────
$colegio = [];
try {
    $stmtC = $pdo->prepare("SELECT * FROM colegios WHERE id = ? LIMIT 1");
    $stmtC->execute([$cid]);
    $colegio = $stmtC->fetch() ?: [];
} catch (Throwable $e) {}

// ── Query principal ────────────────────────────────────────
$where  = ['ace.colegio_id = ?', 'ace.activo = 1'];
$params = [$cid];
if ($filtroTipo !== '') { $where[] = 'ace.tipo_condicion = ?'; $params[] = $filtroTipo; }

try {
    $stmtD = $pdo->prepare("
        SELECT
            a.run,
            a.curso,
            CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombres) AS nombre_alumno,
            CASE
                WHEN a.fecha_nacimiento IS NULL THEN NULL
                ELSE TIMESTAMPDIFF(YEAR, a.fecha_nacimiento, CURDATE())
            END AS edad,
            ace.tipo_condicion,
            COALESCE(cat.nombre, ace.tipo_condicion) AS condicion_nombre,
            ace.estado_diagnostico,
            ace.nivel_apoyo,
            ace.tiene_pie,
            ace.tiene_certificado,
            ace.nro_certificado,
            ace.fecha_deteccion,
            ace.fecha_diagnostico,
            ace.derivado_salud,
            ace.fecha_derivacion,
            ace.destino_derivacion,
            ace.estado_derivacion,
            ace.requiere_ajustes,
            ace.descripcion_ajustes,
            ace.observaciones,
            pte.estado_protocolo,
            pte.deteccion_registrada,
            pte.comunicacion_familia,
            pte.derivacion_salud AS prot_derivacion,
            pte.coordinacion_pie,
            pte.ajustes_metodologicos,
            pte.seguimiento_establecido,
            pte.diagnostico_confirmado,
            pte.fecha_proximo_seguimiento,
            apo.apoderados,
            apo.parentescos
        FROM alumno_condicion_especial ace
        INNER JOIN alumnos a
            ON a.id = ace.alumno_id
           AND a.colegio_id = ace.colegio_id
        LEFT JOIN catalogo_condicion_especial cat
            ON cat.codigo = ace.tipo_condicion
           AND cat.activo = 1
        LEFT JOIN caso_protocolo_tea pte
            ON pte.alumno_condicion_id = ace.id
           AND pte.colegio_id = ace.colegio_id
        LEFT JOIN (
            SELECT
                aa.alumno_id,
                GROUP_CONCAT(DISTINCT ap.nombre ORDER BY aa.es_titular DESC SEPARATOR ' / ') AS apoderados,
                GROUP_CONCAT(DISTINCT COALESCE(aa.tipo_relacion, aa.parentesco, '') ORDER BY aa.es_titular DESC SEPARATOR ' / ') AS parentescos
            FROM alumno_apoderado aa
            INNER JOIN apoderados ap ON ap.id = aa.apoderado_id
            WHERE aa.activo = 1
            GROUP BY aa.alumno_id
        ) apo ON apo.alumno_id = a.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (ace.tipo_condicion LIKE 'tea%') DESC, ace.estado_diagnostico, a.apellido_paterno
    ");
    $stmtD->execute($params);
    $datos = $stmtD->fetchAll();
} catch (Throwable $e) {
    $datos = [];
}

// ── Exportar CSV ───────────────────────────────────────────
if ($modo === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    $nombreColegio = preg_replace('/[^a-zA-Z0-9]/', '_', (string)($colegio['nombre'] ?? 'establecimiento'));
    header('Content-Disposition: attachment; filename="inclusion_nee_' . $nombreColegio . '_' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fwrite($out, "sep=;\r\n");

    // Encabezados
    fputcsv($out, [
        'RUN', 'Nombre', 'Curso', 'Edad',
        'Condición', 'Estado diagnóstico', 'Nivel apoyo',
        'PIE', 'Certificado discapacidad', 'N° certificado',
        'Fecha detección', 'Fecha diagnóstico',
        'Derivado salud', 'Fecha derivación', 'Destino derivación', 'Estado derivación',
        'Ajustes razonables', 'Descripción ajustes',
        'Protocolo: pasos cumplidos', 'Próximo seguimiento',
        'Diagnóstico confirmado', 'Apoderado(s)',
        'Observaciones',
    ], ';');

    foreach ($datos as $r) {
        $pasos = (int)($r['deteccion_registrada']??0) + (int)($r['comunicacion_familia']??0)
               + (int)($r['prot_derivacion']??0) + (int)($r['coordinacion_pie']??0)
               + (int)($r['ajustes_metodologicos']??0) + (int)($r['seguimiento_establecido']??0);

        fputcsv($out, [
            $r['run'] ?? '',
            $r['nombre_alumno'] ?? '',
            $r['curso'] ?? '',
            $r['edad'] ?? '',
            $r['condicion_nombre'] ?? '',
            $r['estado_diagnostico'] ?? '',
            $r['nivel_apoyo'] ? 'Nivel ' . $r['nivel_apoyo'] : '',
            $r['tiene_pie'] ? 'Sí' : 'No',
            $r['tiene_certificado'] ? 'Sí' : 'No',
            $r['nro_certificado'] ?? '',
            $r['fecha_deteccion'] ? date('d-m-Y', strtotime((string)$r['fecha_deteccion'])) : '',
            $r['fecha_diagnostico'] ? date('d-m-Y', strtotime((string)$r['fecha_diagnostico'])) : '',
            $r['derivado_salud'] ? 'Sí' : 'No',
            $r['fecha_derivacion'] ? date('d-m-Y', strtotime((string)$r['fecha_derivacion'])) : '',
            $r['destino_derivacion'] ?? '',
            $r['estado_derivacion'] ?? '',
            $r['requiere_ajustes'] ? 'Sí' : 'No',
            $r['descripcion_ajustes'] ?? '',
            $r['estado_protocolo'] ? "{$pasos}/6 — " . ucfirst((string)$r['estado_protocolo']) : 'Sin protocolo',
            $r['fecha_proximo_seguimiento'] ? date('d-m-Y', strtotime((string)$r['fecha_proximo_seguimiento'])) : '',
            $r['diagnostico_confirmado'] ? 'Sí' : 'No',
            $r['apoderados'] ?? '',
            $r['observaciones'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// ── Vista imprimible ───────────────────────────────────────
$pageTitle = 'Reporte Inclusión y NEE';
$countTea  = count(array_filter($datos, fn($r) => str_starts_with((string)($r['tipo_condicion']??''), 'tea')));
$countPie  = count(array_filter($datos, fn($r) => (int)($r['tiene_pie']??0)));
$sinDerivar= count(array_filter($datos, fn($r) =>
    str_starts_with((string)($r['tipo_condicion']??''), 'tea') && !(int)($r['derivado_salud']??0)
    && in_array($r['estado_diagnostico'], ['sospecha','en_proceso'], true)));

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Metis</title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size:11px; color:#1e293b; background:#fff; }
        @media print { .no-print { display:none !important; } body { font-size:10px; } }

        /* Encabezado */
        .rep-header { background:#0c4a6e;color:#fff;padding:1.25rem 1.5rem;display:flex;justify-content:space-between;align-items:flex-start; }
        .rep-header h1 { font-size:1.1rem;font-weight:800;margin-bottom:.2rem; }
        .rep-header p  { font-size:.78rem;color:#bae6fd; }
        .rep-logo-area { text-align:right;font-size:.72rem;color:#bae6fd; }

        /* Resumen */
        .rep-resumen { display:flex;gap:1rem;padding:1rem 1.5rem;background:#f8fafc;border-bottom:1px solid #e2e8f0; }
        .rep-kpi { background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.65rem 1rem;text-align:center;flex:1; }
        .rep-kpi-val { font-size:1.4rem;font-weight:800;color:#0369a1;line-height:1; }
        .rep-kpi-val.warn { color:#dc2626; }
        .rep-kpi-val.ok   { color:#059669; }
        .rep-kpi-val.tea  { color:#f59e0b; }
        .rep-kpi-lbl { font-size:.65rem;color:#64748b;margin-top:.15rem; }

        /* Controles */
        .rep-controles { padding:.75rem 1.5rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;border-bottom:1px solid #e2e8f0; }
        .btn-rep { font-size:.78rem;font-weight:600;padding:.35rem .85rem;border-radius:7px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;text-decoration:none;color:#374151;display:inline-flex;align-items:center;gap:.35rem; }
        .btn-rep:hover { background:#f0f9ff;border-color:#bae6fd;color:#0369a1; }
        .btn-rep.primary { background:#0369a1;color:#fff;border-color:#0369a1; }
        .btn-rep.primary:hover { background:#075985; }

        /* Tabla */
        .rep-table-wrap { padding:0 1.5rem 2rem; }
        table { width:100%;border-collapse:collapse;margin-top:1rem;font-size:10.5px; }
        th { background:#e0f2fe;color:#0c4a6e;font-size:.65rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:.5rem .6rem;border:1px solid #bae6fd;text-align:left; }
        td { padding:.45rem .6rem;border:1px solid #e2e8f0;vertical-align:top; }
        tr:nth-child(even) td { background:#f8fafc; }
        tr.tea-row td { background:#fffbeb; }
        tr.tea-row:nth-child(even) td { background:#fef3c7; }
        .badge-s { border-radius:10px;padding:.1rem .4rem;font-size:.65rem;font-weight:700;display:inline-block; }
        .badge-tea  { background:#fef3c7;color:#92400e; }
        .badge-nee  { background:#ede9fe;color:#5b21b6; }
        .badge-ok   { background:#d1fae5;color:#065f46; }
        .badge-warn { background:#fee2e2;color:#991b1b; }
        .badge-pie  { background:#e0f2fe;color:#0369a1; }
        .badge-pend { background:#fff3cd;color:#856404; }
        .prot-bar   { display:flex;gap:2px;margin-top:3px; }
        .prot-dot   { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
        .prot-dot.done { background:#059669; }
        .prot-dot.pend { background:#e2e8f0; }
        .alerta-tea { background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:.4rem .75rem;margin:1rem 1.5rem;font-size:.78rem;color:#92400e; }
        .footer-rep { padding:.75rem 1.5rem;font-size:.68rem;color:#94a3b8;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between; }
    </style>
</head>
<body>

<!-- Encabezado -->
<div class="rep-header">
    <div>
        <h1><i>🫀</i> Reporte de Inclusión y NEE</h1>
        <p><?= e((string)($colegio['nombre'] ?? 'Establecimiento')) ?>
           <?= $colegio['rbd'] ? ' · RBD ' . e((string)$colegio['rbd']) : '' ?></p>
        <p>Generado: <?= date('d-m-Y H:i') ?> · Ley 21.545 y Decreto 170/2009</p>
    </div>
    <div class="rep-logo-area">
        <strong>Metis</strong><br>Sistema de Convivencia Escolar<br>
        <?= count($datos) ?> registro(s)
    </div>
</div>

<!-- Resumen KPIs -->
<div class="rep-resumen">
    <div class="rep-kpi"><div class="rep-kpi-val"><?= count($datos) ?></div><div class="rep-kpi-lbl">Total registros</div></div>
    <div class="rep-kpi"><div class="rep-kpi-val tea"><?= $countTea ?></div><div class="rep-kpi-lbl">Con TEA</div></div>
    <div class="rep-kpi"><div class="rep-kpi-val ok"><?= $countPie ?></div><div class="rep-kpi-lbl">En PIE</div></div>
    <div class="rep-kpi"><div class="rep-kpi-val <?= $sinDerivar > 0 ? 'warn' : 'ok' ?>"><?= $sinDerivar ?></div><div class="rep-kpi-lbl">TEA sin derivar ⚠️</div></div>
</div>

<!-- Alerta derivaciones pendientes -->
<?php if ($sinDerivar > 0): ?>
<div class="alerta-tea">
    ⚠️ <strong><?= $sinDerivar ?> alumno(s) con sospecha/diagnóstico TEA sin derivación a salud registrada.</strong>
    El Art. 12 de la Ley 21.545 establece la obligación de derivación por parte del establecimiento.
</div>
<?php endif; ?>

<!-- Controles -->
<div class="rep-controles no-print">
    <a href="?modo=csv<?= $filtroTipo ? '&tipo=' . e($filtroTipo) : '' ?>" class="btn-rep primary">
        ⬇ Exportar CSV (Excel)
    </a>
    <button onclick="window.print()" class="btn-rep">🖨 Imprimir</button>
    <a href="<?= APP_URL ?>/modules/inclusion/index.php" class="btn-rep">← Volver al módulo</a>
    <span style="margin-left:auto;font-size:.75rem;color:#94a3b8;">
        Filtrar por tipo:
        <a href="?" class="btn-rep" style="<?= !$filtroTipo ? 'background:#e0f2fe;' : '' ?>">Todos</a>
        <a href="?tipo=tea" class="btn-rep" style="<?= $filtroTipo==='tea' ? 'background:#fef3c7;' : '' ?>">Solo TEA</a>
    </span>
</div>

<!-- Tabla principal -->
<div class="rep-table-wrap">
<?php if (!$datos): ?>
    <p style="padding:2rem;text-align:center;color:#94a3b8;">No hay registros de condición especial para este establecimiento.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Alumno/a</th>
                <th>RUN</th>
                <th>Curso</th>
                <th>Condición</th>
                <th>Dx</th>
                <th>PIE</th>
                <th>Derivación salud</th>
                <th>Protocolo TEA</th>
                <th>Ajustes</th>
                <th>Apoderado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($datos as $i => $r):
            $esTea = str_starts_with((string)($r['tipo_condicion']??''), 'tea');
            $sinDer = $esTea && !(int)$r['derivado_salud']
                      && in_array($r['estado_diagnostico'], ['sospecha','en_proceso'], true);

            $pasos = [
                (int)($r['deteccion_registrada']??0),
                (int)($r['comunicacion_familia']??0),
                (int)($r['prot_derivacion']??0),
                (int)($r['coordinacion_pie']??0),
                (int)($r['ajustes_metodologicos']??0),
                (int)($r['seguimiento_establecido']??0),
            ];
            $sumPasos = array_sum($pasos);
        ?>
            <tr <?= $esTea ? 'class="tea-row"' : '' ?>>
                <td style="color:#94a3b8;"><?= $i + 1 ?></td>
                <td><strong><?= e((string)$r['nombre_alumno']) ?></strong></td>
                <td style="font-family:monospace;"><?= e((string)$r['run']) ?></td>
                <td><?= e((string)($r['curso']??'—')) ?></td>
                <td>
                    <span class="badge-s <?= $esTea ? 'badge-tea' : 'badge-nee' ?>">
                        <?= e((string)$r['condicion_nombre']) ?>
                    </span>
                    <?php if ($r['nivel_apoyo']): ?>
                        <div style="font-size:.65rem;color:#92400e;">N<?= (int)$r['nivel_apoyo'] ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    echo match($r['estado_diagnostico'] ?? '') {
                        'confirmado' => '<span class="badge-s badge-ok">Confirmado</span>',
                        'en_proceso' => '<span class="badge-s badge-pend">En proceso</span>',
                        'sospecha'   => '<span class="badge-s badge-warn">Sospecha</span>',
                        'descartado' => '<span style="color:#94a3b8;">Descartado</span>',
                        default      => '—',
                    };
                    ?>
                </td>
                <td><?= (int)$r['tiene_pie'] ? '<span class="badge-s badge-pie">PIE</span>' : '—' ?></td>
                <td>
                    <?php if ((int)$r['derivado_salud']): ?>
                        <span class="badge-s badge-ok">✓ Derivado</span>
                        <?php if ($r['fecha_derivacion']): ?>
                            <div style="font-size:.65rem;"><?= date('d-m-Y', strtotime((string)$r['fecha_derivacion'])) ?></div>
                            <div style="font-size:.65rem;color:#64748b;"><?= e((string)($r['destino_derivacion']??'')) ?></div>
                        <?php endif; ?>
                    <?php elseif ($sinDer): ?>
                        <span class="badge-s badge-warn">⚠ Pendiente</span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($esTea && $r['estado_protocolo']): ?>
                        <span class="badge-s <?= $r['estado_protocolo']==='completado' ? 'badge-ok' : 'badge-pend' ?>">
                            <?= $sumPasos ?>/6 <?= e(ucfirst((string)$r['estado_protocolo'])) ?>
                        </span>
                        <div class="prot-bar">
                            <?php foreach ($pasos as $paso): ?>
                                <div class="prot-dot <?= $paso ? 'done' : 'pend' ?>"></div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($esTea): ?>
                        <span style="color:#94a3b8;font-size:.7rem;">Sin protocolo</span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?= (int)$r['requiere_ajustes'] ? '<span class="badge-s badge-pie">Sí</span>' : '—' ?>
                    <?php if ($r['descripcion_ajustes']): ?>
                        <div style="font-size:.65rem;color:#64748b;"><?= e(mb_strimwidth((string)$r['descripcion_ajustes'],0,40,'…')) ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.72rem;"><?= e(mb_strimwidth((string)($r['apoderados']??''),0,35,'…')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<!-- Pie de reporte -->
<div class="footer-rep">
    <span>Metis · Sistema de Convivencia Escolar · <?= e((string)($colegio['nombre'] ?? '')) ?></span>
    <span>Generado: <?= date('d-m-Y H:i') ?> · Ley 21.545 · Decreto 170/2009</span>
</div>

</body>
</html>
