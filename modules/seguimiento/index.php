<?php
declare(strict_types=1);
/**
 * Módulo Seguimiento — Dashboard ejecutivo de casos activos
 * Vista panorámica para director y encargado de convivencia.
 *
 * Fase 15:
 * - elimina dependencia visual/operativa de semáforo legacy;
 * - usa estado formal, prioridad, pauta de riesgo, sesiones, alertas y plan vigente;
 * - refuerza multi-tenancy en subconsultas por colegio_id.
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$cid       = (int)($user['colegio_id'] ?? 0);
$pageTitle = 'Seguimiento · Metis';
$pageSubtitle = 'Bandeja ejecutiva de casos activos, planes, revisiones y alertas.';

function seg_norm_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

// ── Filtro de fecha (afecta sólo la tabla, no los KPIs) ─────────────────────
$filtDesde = seg_norm_date((string)($_GET['desde'] ?? ''));
$filtHasta = seg_norm_date((string)($_GET['hasta'] ?? ''));

$activeWhere = "c.colegio_id = ?
    AND COALESCE(ec.codigo, c.estado, 'abierto') NOT IN ('cerrado','archivado','borrador')
    AND c.estado NOT IN ('archivado','borrador')";
$activeParams = [$cid];

$tablaWhere  = $activeWhere;
$tablaParams = $activeParams;

if ($filtDesde !== '') {
    $tablaWhere  .= ' AND DATE(c.fecha_ingreso) >= ?';
    $tablaParams[] = $filtDesde;
}
if ($filtHasta !== '') {
    $tablaWhere  .= ' AND DATE(c.fecha_ingreso) <= ?';
    $tablaParams[] = $filtHasta;
}

// ── KPIs (siempre sobre todos los casos activos, sin filtro de fecha) ───────
$kpi = [
    'total'             => 0,
    'en_seguimiento'    => 0,
    'riesgo_alto'       => 0,
    'alta'              => 0,
    'sin_plan'          => 0,
    'revision_vencida'  => 0,
    'con_sesion_hoy'    => 0,
    'sin_pauta_riesgo'  => 0,
    'alertas_pendientes'=> 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(c.prioridad = 'alta') AS alta,
            SUM((
                SELECT pr.nivel_final
                FROM caso_pauta_riesgo pr
                WHERE pr.caso_id = c.id
                  AND pr.completada_por IS NOT NULL
                ORDER BY pr.created_at DESC, pr.id DESC
                LIMIT 1
            ) IN ('alto','critico')) AS riesgo_alto,
            SUM((
                SELECT COUNT(*)
                FROM caso_plan_accion pa
                WHERE pa.caso_id = c.id
                  AND pa.colegio_id = c.colegio_id
                  AND pa.vigente = 1
            ) = 0) AS sin_plan,
            SUM((
                SELECT COUNT(*)
                FROM caso_pauta_riesgo pr2
                WHERE pr2.caso_id = c.id
                  AND pr2.completada_por IS NOT NULL
            ) = 0) AS sin_pauta_riesgo,
            SUM((
                SELECT COUNT(*)
                FROM caso_alertas a
                WHERE a.caso_id = c.id
                  AND a.estado = 'pendiente'
            )) AS alertas_pendientes,
            SUM(EXISTS(
                SELECT 1
                FROM caso_seguimiento_sesion css
                WHERE css.caso_id = c.id
                  AND css.colegio_id = c.colegio_id
                  AND DATE(css.created_at) = CURDATE()
            )) AS con_sesion_hoy,
            SUM(
                (
                    SELECT MIN(cssf.proxima_revision)
                    FROM caso_seguimiento_sesion cssf
                    WHERE cssf.caso_id = c.id
                      AND cssf.colegio_id = c.colegio_id
                      AND cssf.proxima_revision >= CURDATE()
                ) IS NULL
                AND
                (
                    SELECT MAX(cssp.proxima_revision)
                    FROM caso_seguimiento_sesion cssp
                    WHERE cssp.caso_id = c.id
                      AND cssp.colegio_id = c.colegio_id
                      AND cssp.proxima_revision < CURDATE()
                      AND cssp.proxima_revision IS NOT NULL
                ) IS NOT NULL
            ) AS revision_vencida
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE {$activeWhere}
    ");
    $stmt->execute($activeParams);
    $row = $stmt->fetch() ?: [];

    $kpi['total']              = (int)($row['total'] ?? 0);
    $kpi['en_seguimiento']     = $kpi['total'];
    $kpi['alta']               = (int)($row['alta'] ?? 0);
    $kpi['riesgo_alto']        = (int)($row['riesgo_alto'] ?? 0);
    $kpi['sin_plan']           = (int)($row['sin_plan'] ?? 0);
    $kpi['sin_pauta_riesgo']   = (int)($row['sin_pauta_riesgo'] ?? 0);
    $kpi['alertas_pendientes'] = (int)($row['alertas_pendientes'] ?? 0);
    $kpi['con_sesion_hoy']     = (int)($row['con_sesion_hoy'] ?? 0);
    $kpi['revision_vencida']   = (int)($row['revision_vencida'] ?? 0);
} catch (Throwable $e) {
    // La vista no debe bloquearse por un KPI; la tabla principal mantiene el intento de carga.
}

// ── Casos activos con estado de seguimiento ─────────────────────────────────
$casos = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.numero_caso,
            c.prioridad,
            c.estado,
            c.updated_at,
            c.fecha_ingreso,
            ec.nombre AS estado_formal,
            ec.codigo AS estado_codigo,
            DATEDIFF(NOW(), COALESCE(c.updated_at, c.fecha_ingreso)) AS dias_sin_mov,
            (
                SELECT MAX(css.created_at)
                FROM caso_seguimiento_sesion css
                WHERE css.caso_id = c.id
                  AND css.colegio_id = c.colegio_id
            ) AS ultima_sesion,
            (
                SELECT MIN(css.proxima_revision)
                FROM caso_seguimiento_sesion css
                WHERE css.caso_id = c.id
                  AND css.colegio_id = c.colegio_id
                  AND css.proxima_revision >= CURDATE()
            ) AS proxima_revision,
            (
                SELECT MAX(css.proxima_revision)
                FROM caso_seguimiento_sesion css
                WHERE css.caso_id = c.id
                  AND css.colegio_id = c.colegio_id
                  AND css.proxima_revision < CURDATE()
                  AND css.proxima_revision IS NOT NULL
            ) AS revision_vencida_fecha,
            (
                SELECT COUNT(*)
                FROM caso_plan_accion pa
                WHERE pa.caso_id = c.id
                  AND pa.colegio_id = c.colegio_id
                  AND pa.vigente = 1
            ) AS tiene_plan,
            (
                SELECT COUNT(*)
                FROM caso_alertas a
                WHERE a.caso_id = c.id
                  AND a.estado = 'pendiente'
            ) AS alertas,
            (
                SELECT pr.nivel_final
                FROM caso_pauta_riesgo pr
                WHERE pr.caso_id = c.id
                  AND pr.completada_por IS NOT NULL
                ORDER BY pr.created_at DESC, pr.id DESC
                LIMIT 1
            ) AS nivel_riesgo,
            (
                SELECT COUNT(*)
                FROM caso_participantes cp
                WHERE cp.caso_id = c.id
            ) AS participantes,
            CASE
                WHEN (
                    SELECT pr.nivel_final
                    FROM caso_pauta_riesgo pr
                    WHERE pr.caso_id = c.id
                      AND pr.completada_por IS NOT NULL
                    ORDER BY pr.created_at DESC, pr.id DESC
                    LIMIT 1
                ) = 'critico' THEN 1
                WHEN (
                    SELECT pr.nivel_final
                    FROM caso_pauta_riesgo pr
                    WHERE pr.caso_id = c.id
                      AND pr.completada_por IS NOT NULL
                    ORDER BY pr.created_at DESC, pr.id DESC
                    LIMIT 1
                ) = 'alto' THEN 2
                WHEN c.prioridad = 'alta' THEN 3
                WHEN (
                    SELECT MAX(css.proxima_revision)
                    FROM caso_seguimiento_sesion css
                    WHERE css.caso_id = c.id
                      AND css.colegio_id = c.colegio_id
                      AND css.proxima_revision < CURDATE()
                      AND css.proxima_revision IS NOT NULL
                ) IS NOT NULL THEN 4
                ELSE 9
            END AS orden_riesgo
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE {$tablaWhere}
        ORDER BY
            orden_riesgo ASC,
            FIELD(c.prioridad,'alta','media','baja') ASC,
            COALESCE(c.updated_at, c.fecha_ingreso) ASC
        LIMIT 100
    ");
    $stmt->execute($tablaParams);
    $casos = $stmt->fetchAll();
} catch (Throwable $e) {
    $casos = [];
}

$mostrarAlertaTopbar = metis_topbar_action_visible($pdo, 'alertas', false);

$pageHeaderActions = metis_context_actions([
    metis_context_action('Denuncias', APP_URL . '/modules/denuncias/index.php', 'bi-folder2-open', 'primary'),
    metis_context_action('Alertas', APP_URL . '/modules/alertas/index.php', 'bi-bell-fill', 'warning', $mostrarAlertaTopbar),
    metis_context_action('Dashboard', APP_URL . '/modules/dashboard/index.php', 'bi-speedometer2', 'secondary'),
    metis_context_action('Reportes', APP_URL . '/modules/reportes/index.php', 'bi-bar-chart', 'secondary'),
]);

// ── Presentación ─────────────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
require_once __DIR__ . '/partials/_style.php';
require_once __DIR__ . '/partials/_hero.php';
require_once __DIR__ . '/partials/_kpis.php';
require_once __DIR__ . '/partials/_alertas.php';
require_once __DIR__ . '/partials/_tabla.php';
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
