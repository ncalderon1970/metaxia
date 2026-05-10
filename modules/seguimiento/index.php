<?php
declare(strict_types=1);
/**
 * Módulo Seguimiento — Dashboard ejecutivo de casos activos
 * Vista panorámica para director y encargado de convivencia
 * El trabajo operativo vive en denuncias/ver.php → tabs Plan de Acción y Seguimiento
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$cid       = (int)($user['colegio_id'] ?? 0);
$pageTitle = 'Seguimiento · Metis';

// ── KPIs (siempre sobre todos los casos, sin filtro de fecha) ────────────────
$kpi = ['total'=>0,'en_seguimiento'=>0,'rojo'=>0,'alta'=>0,
        'sin_plan'=>0,'revision_vencida'=>0,'con_sesion_hoy'=>0,'sin_pauta_riesgo'=>0];
try {
    $s = $pdo->prepare("SELECT
        COUNT(*)                                                         AS total,
        SUM(c.estado NOT IN ('cerrado','archivado','borrador'))          AS en_seguimiento,
        SUM(c.semaforo IN ('rojo','negro'))                             AS rojo,
        SUM(c.prioridad = 'alta')                                       AS alta
        FROM casos c WHERE c.colegio_id = ?");
    $s->execute([$cid]);
    $r = $s->fetch();
    foreach (['total','en_seguimiento','rojo','alta'] as $k)
        $kpi[$k] = (int)($r[$k] ?? 0);

    try {
        $s2 = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM casos c
            LEFT JOIN caso_plan_accion pa ON pa.caso_id=c.id AND pa.vigente=1
            WHERE c.colegio_id=? AND c.estado NOT IN('cerrado','archivado','borrador')
            AND pa.id IS NULL");
        $s2->execute([$cid]);
        $kpi['sin_plan'] = (int)$s2->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $s3 = $pdo->prepare("SELECT COUNT(DISTINCT caso_id) FROM caso_seguimiento_sesion
            WHERE colegio_id=? AND proxima_revision<CURDATE() AND proxima_revision IS NOT NULL");
        $s3->execute([$cid]);
        $kpi['revision_vencida'] = (int)$s3->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $s4 = $pdo->prepare("SELECT COUNT(DISTINCT caso_id) FROM caso_seguimiento_sesion
            WHERE colegio_id=? AND DATE(created_at)=CURDATE()");
        $s4->execute([$cid]);
        $kpi['con_sesion_hoy'] = (int)$s4->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $s5 = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM casos c
            LEFT JOIN caso_pauta_riesgo pr ON pr.caso_id = c.id AND pr.completada_por IS NOT NULL
            WHERE c.colegio_id = ? AND c.estado NOT IN('cerrado','archivado','borrador')
            AND pr.id IS NULL");
        $s5->execute([$cid]);
        $kpi['sin_pauta_riesgo'] = (int)$s5->fetchColumn();
    } catch (Throwable $e) {}

} catch (Throwable $e) {}

// ── Filtro de fecha (afecta sólo la tabla, no los KPIs) ─────────────────────
$filtDesde = clean((string)($_GET['desde'] ?? ''));
$filtHasta = clean((string)($_GET['hasta'] ?? ''));

$tablaWhere  = 'c.colegio_id = ? AND c.estado NOT IN(\'cerrado\',\'archivado\',\'borrador\')';
$tablaParams = [$cid];
if ($filtDesde !== '') {
    $tablaWhere  .= ' AND DATE(c.fecha_ingreso) >= ?';
    $tablaParams[] = $filtDesde;
}
if ($filtHasta !== '') {
    $tablaWhere  .= ' AND DATE(c.fecha_ingreso) <= ?';
    $tablaParams[] = $filtHasta;
}

// ── Casos activos con estado de seguimiento ──────────────────────────────────
$casos = [];
try {
    $s = $pdo->prepare("
        SELECT c.id, c.numero_caso, c.semaforo, c.prioridad,
               c.estado, c.updated_at,
               ec.nombre AS estado_formal, ec.codigo AS estado_codigo,
               DATEDIFF(NOW(), c.updated_at)        AS dias_sin_mov,
               (SELECT MAX(css.created_at) FROM caso_seguimiento_sesion css
                WHERE css.caso_id=c.id) AS ultima_sesion,
               (SELECT MIN(css.proxima_revision) FROM caso_seguimiento_sesion css
                WHERE css.caso_id=c.id AND css.proxima_revision>=CURDATE()
               ) AS proxima_revision,
               (SELECT MAX(css.proxima_revision) FROM caso_seguimiento_sesion css
                WHERE css.caso_id=c.id AND css.proxima_revision<CURDATE()
                  AND css.proxima_revision IS NOT NULL
               ) AS revision_vencida_fecha,
               (SELECT COUNT(*) FROM caso_plan_accion pa
                WHERE pa.caso_id=c.id AND pa.vigente=1) AS tiene_plan,
               (SELECT COUNT(*) FROM caso_alertas a
                WHERE a.caso_id=c.id AND a.estado='pendiente') AS alertas,
               (SELECT pr.nivel_final FROM caso_pauta_riesgo pr
                WHERE pr.caso_id=c.id AND pr.completada_por IS NOT NULL
                ORDER BY pr.created_at DESC LIMIT 1) AS nivel_riesgo,
               (SELECT COUNT(*) FROM caso_participantes cp
                WHERE cp.caso_id=c.id) AS participantes
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id=c.estado_caso_id
        WHERE {$tablaWhere}
        ORDER BY
            FIELD(c.semaforo,'negro','rojo','amarillo','verde') ASC,
            FIELD(c.prioridad,'alta','media','baja') ASC,
            c.updated_at ASC
        LIMIT 100
    ");
    $s->execute($tablaParams);
    $casos = $s->fetchAll();
} catch (Throwable $e) {}

// ── Presentación ─────────────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
require_once __DIR__ . '/partials/_style.php';
require_once __DIR__ . '/partials/_hero.php';
require_once __DIR__ . '/partials/_kpis.php';
require_once __DIR__ . '/partials/_alertas.php';
require_once __DIR__ . '/partials/_tabla.php';
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
