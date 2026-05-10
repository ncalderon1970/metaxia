<?php
declare(strict_types=1);

if (!class_exists('Cache')) {
    require_once dirname(__DIR__, 3) . '/core/Cache.php';
}

const DASH_KPI_TTL   = 300;
const DASH_ALERT_TTL = 60;

$cacheKpiKey  = "dash_v17_kpis_{$colegioId}";
$cacheAlertas = "dash_v17_alertas_{$colegioId}";

$totalCasos = 0;
$totalRecepcion = 0;
$totalInvestigacion = 0;
$totalResolucion = 0;
$totalSeguimiento = 0;
$totalCerrados = 0;
$totalAlta = 0;
$totalAlertasPendientes = 0;
$totalSinPlan = 0;
$totalSeguimientoVencido = 0;
$totalRiesgoAltoCritico = 0;
$totalAulaSeguraPendiente = 0;
$totalEvidencias = 0;
$totalUsuarios = 0;
$totalLogsHoy = 0;
$totalDeclaraciones = 0;
$totalParticipantes = 0;

$totalAlumnos = 0;
$totalApoderados = 0;
$totalDocentes = 0;
$totalAsistentes = 0;
$totalComunidad = 0;
$totalPendientesImportacion = 0;

$casosRecientes = [];
$alertasPendientes = [];
$actividadReciente = [];
$ultimoRespaldo = null;
$focosGestion = [];

$coreOk = true;
$dbOk = true;
$storageOk = dash_storage_ok();
$tablasFaltantes = [];

$tablasCriticas = [
    'usuarios',
    'roles',
    'estado_caso',
    'casos',
    'caso_participantes',
    'caso_alertas',
    'caso_evidencias',
    'caso_historial',
    'caso_plan_intervencion',
    'caso_seguimiento_sesion',
    'logs_sistema',
];

foreach ($tablasCriticas as $tabla) {
    if (!dash_table_exists($pdo, $tabla)) {
        $tablasFaltantes[] = $tabla;
    }
}

$dbOk = count($tablasFaltantes) === 0;

$coreFiles = [
    'config/app.php',
    'core/DB.php',
    'core/Auth.php',
    'core/CSRF.php',
    'core/helpers.php',
    'core/layout_header.php',
    'core/layout_footer.php',
];

foreach ($coreFiles as $file) {
    if (!is_file(dirname(__DIR__, 3) . '/' . $file)) {
        $coreOk = false;
        break;
    }
}

try {
    $kpisCache = Cache::get($cacheKpiKey, DASH_KPI_TTL);

    if (is_array($kpisCache)) {
        $totalCasos = (int)($kpisCache['totalCasos'] ?? 0);
        $totalRecepcion = (int)($kpisCache['totalRecepcion'] ?? 0);
        $totalInvestigacion = (int)($kpisCache['totalInvestigacion'] ?? 0);
        $totalResolucion = (int)($kpisCache['totalResolucion'] ?? 0);
        $totalSeguimiento = (int)($kpisCache['totalSeguimiento'] ?? 0);
        $totalCerrados = (int)($kpisCache['totalCerrados'] ?? 0);
        $totalAlta = (int)($kpisCache['totalAlta'] ?? 0);
        $totalSinPlan = (int)($kpisCache['totalSinPlan'] ?? 0);
        $totalSeguimientoVencido = (int)($kpisCache['totalSeguimientoVencido'] ?? 0);
        $totalRiesgoAltoCritico = (int)($kpisCache['totalRiesgoAltoCritico'] ?? 0);
        $totalAulaSeguraPendiente = (int)($kpisCache['totalAulaSeguraPendiente'] ?? 0);
        $totalEvidencias = (int)($kpisCache['totalEvidencias'] ?? 0);
        $totalUsuarios = (int)($kpisCache['totalUsuarios'] ?? 0);
        $totalLogsHoy = (int)($kpisCache['totalLogsHoy'] ?? 0);
        $totalDeclaraciones = (int)($kpisCache['totalDeclaraciones'] ?? 0);
        $totalParticipantes = (int)($kpisCache['totalParticipantes'] ?? 0);
        $totalAlumnos = (int)($kpisCache['totalAlumnos'] ?? 0);
        $totalApoderados = (int)($kpisCache['totalApoderados'] ?? 0);
        $totalDocentes = (int)($kpisCache['totalDocentes'] ?? 0);
        $totalAsistentes = (int)($kpisCache['totalAsistentes'] ?? 0);
        $totalComunidad = (int)($kpisCache['totalComunidad'] ?? 0);
        $totalPendientesImportacion = (int)($kpisCache['totalPendientesImportacion'] ?? 0);
    } else {
        $totalCasos = dash_count($pdo, 'casos', 'colegio_id = ?', [$colegioId]);
        $totalRecepcion = dash_estado_count($pdo, $colegioId, 1, 'recepcion');
        $totalInvestigacion = dash_estado_count($pdo, $colegioId, 2, 'investigacion');
        $totalResolucion = dash_estado_count($pdo, $colegioId, 3, 'resolucion');
        $totalSeguimiento = dash_estado_count($pdo, $colegioId, 4, 'seguimiento');
        $totalCerrados = dash_estado_count($pdo, $colegioId, 5, 'cerrado');

        $totalAlta = dash_count($pdo, 'casos', "colegio_id = ? AND prioridad = 'alta' AND COALESCE(estado_caso_id, 0) <> 5", [$colegioId]);

        $totalAlertasPendientes = dash_scalar($pdo, "
            SELECT COUNT(*)
            FROM caso_alertas a
            INNER JOIN casos c ON c.id = a.caso_id
            WHERE c.colegio_id = ?
              AND a.estado = 'pendiente'
        ", [$colegioId]);

        $totalSinPlan = dash_scalar($pdo, "
            SELECT COUNT(*)
            FROM casos c
            WHERE c.colegio_id = ?
              AND COALESCE(c.estado_caso_id, 0) NOT IN (5)
              AND NOT EXISTS (
                    SELECT 1
                    FROM caso_plan_accion pa
                    WHERE pa.caso_id = c.id
                      AND pa.colegio_id = c.colegio_id
                      AND pa.vigente = 1
              )
              AND NOT EXISTS (
                    SELECT 1
                    FROM caso_plan_intervencion pi
                    WHERE pi.caso_id = c.id
                      AND pi.colegio_id = c.colegio_id
              )
        ", [$colegioId]);

        $totalSeguimientoVencido = dash_scalar($pdo, "
            SELECT COUNT(*)
            FROM caso_seguimiento s
            INNER JOIN casos c ON c.id = s.caso_id AND c.colegio_id = s.colegio_id
            WHERE s.colegio_id = ?
              AND COALESCE(c.estado_caso_id, 0) <> 5
              AND s.proxima_revision IS NOT NULL
              AND s.proxima_revision < CURDATE()
              AND s.estado <> 'cerrado'
        ", [$colegioId]);

        $totalRiesgoAltoCritico = dash_scalar($pdo, "
            SELECT COUNT(DISTINCT pr.caso_id)
            FROM caso_pauta_riesgo pr
            INNER JOIN casos c ON c.id = pr.caso_id
            WHERE c.colegio_id = ?
              AND COALESCE(c.estado_caso_id, 0) <> 5
              AND pr.nivel_final IN ('alto', 'critico')
              AND pr.derivado = 0
        ", [$colegioId]);

        $totalAulaSeguraPendiente = dash_scalar($pdo, "
            SELECT COUNT(DISTINCT c.id)
            FROM casos c
            LEFT JOIN caso_aula_segura a
                   ON a.caso_id = c.id
                  AND (a.colegio_id = c.colegio_id OR a.colegio_id IS NULL)
            WHERE c.colegio_id = ?
              AND COALESCE(c.estado_caso_id, 0) <> 5
              AND (
                    c.posible_aula_segura = 1
                    OR c.aula_segura_estado IN ('posible', 'pendiente', 'en_evaluacion')
                    OR a.estado IN ('posible', 'pendiente', 'en_evaluacion')
                  )
              AND COALESCE(a.estado, c.aula_segura_estado, 'no_aplica') NOT IN ('descartado', 'cerrado', 'resuelto', 'no_aplica')
        ", [$colegioId]);

        $totalEvidencias = dash_scalar($pdo, "
            SELECT COUNT(*)
            FROM caso_evidencias e
            INNER JOIN casos c ON c.id = e.caso_id
            WHERE c.colegio_id = ?
        ", [$colegioId]);

        $totalUsuarios = $esSuperAdmin
            ? dash_count($pdo, 'usuarios', 'activo = 1')
            : dash_count($pdo, 'usuarios', 'colegio_id = ? AND activo = 1', [$colegioId]);

        $totalLogsHoy = $esSuperAdmin
            ? dash_count($pdo, 'logs_sistema', 'DATE(created_at) = CURDATE()')
            : dash_count($pdo, 'logs_sistema', 'colegio_id = ? AND DATE(created_at) = CURDATE()', [$colegioId]);

        $totalDeclaraciones = dash_scalar($pdo, "
            SELECT COUNT(*)
            FROM caso_declaraciones d
            INNER JOIN casos c ON c.id = d.caso_id
            WHERE c.colegio_id = ?
        ", [$colegioId]);

        $totalParticipantes = dash_scalar($pdo, "
            SELECT COUNT(*)
            FROM caso_participantes p
            INNER JOIN casos c ON c.id = p.caso_id
            WHERE c.colegio_id = ?
        ", [$colegioId]);

        $totalAlumnos = dash_count_colegio($pdo, 'alumnos', $colegioId);
        $totalApoderados = dash_count_colegio($pdo, 'apoderados', $colegioId);
        $totalDocentes = dash_count_colegio($pdo, 'docentes', $colegioId);
        $totalAsistentes = dash_count_colegio($pdo, 'asistentes', $colegioId);
        $totalComunidad = $totalAlumnos + $totalApoderados + $totalDocentes + $totalAsistentes;

        $totalPendientesImportacion = dash_count(
            $pdo,
            'comunidad_importacion_pendientes',
            "colegio_id = ? AND estado = 'pendiente'",
            [$colegioId]
        );

        Cache::put($cacheKpiKey, [
            'totalCasos' => $totalCasos,
            'totalRecepcion' => $totalRecepcion,
            'totalInvestigacion' => $totalInvestigacion,
            'totalResolucion' => $totalResolucion,
            'totalSeguimiento' => $totalSeguimiento,
            'totalCerrados' => $totalCerrados,
            'totalAlta' => $totalAlta,
            'totalSinPlan' => $totalSinPlan,
            'totalSeguimientoVencido' => $totalSeguimientoVencido,
            'totalRiesgoAltoCritico' => $totalRiesgoAltoCritico,
            'totalAulaSeguraPendiente' => $totalAulaSeguraPendiente,
            'totalEvidencias' => $totalEvidencias,
            'totalUsuarios' => $totalUsuarios,
            'totalLogsHoy' => $totalLogsHoy,
            'totalDeclaraciones' => $totalDeclaraciones,
            'totalParticipantes' => $totalParticipantes,
            'totalAlumnos' => $totalAlumnos,
            'totalApoderados' => $totalApoderados,
            'totalDocentes' => $totalDocentes,
            'totalAsistentes' => $totalAsistentes,
            'totalComunidad' => $totalComunidad,
            'totalPendientesImportacion' => $totalPendientesImportacion,
        ]);
    }

    $alertasPendientes = Cache::remember($cacheAlertas, DASH_ALERT_TTL, function () use ($pdo, $colegioId): array {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    a.id,
                    a.caso_id,
                    a.tipo,
                    a.mensaje,
                    a.prioridad,
                    a.estado,
                    a.fecha_alerta,
                    c.numero_caso
                FROM caso_alertas a
                INNER JOIN casos c ON c.id = a.caso_id
                WHERE c.colegio_id = ?
                  AND a.estado = 'pendiente'
                ORDER BY
                    CASE a.prioridad WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END,
                    a.fecha_alerta DESC,
                    a.id DESC
                LIMIT 6
            ");
            $stmt->execute([$colegioId]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    });

    $totalAlertasPendientes = max($totalAlertasPendientes, count($alertasPendientes));

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.numero_caso,
            c.fecha_ingreso,
            c.estado,
            c.estado_caso_id,
            c.prioridad,
            c.relato,
            c.contexto,
            c.updated_at,
            c.posible_aula_segura,
            c.aula_segura_estado,
            ec.codigo AS estado_codigo,
            ec.nombre AS estado_formal,
            (
                SELECT COUNT(*)
                FROM caso_alertas a
                WHERE a.caso_id = c.id
                  AND a.estado = 'pendiente'
            ) AS alertas_pendientes,
            (
                SELECT COUNT(*)
                FROM caso_plan_accion pa
                WHERE pa.caso_id = c.id
                  AND pa.colegio_id = c.colegio_id
                  AND pa.vigente = 1
            ) + (
                SELECT COUNT(*)
                FROM caso_plan_intervencion pi
                WHERE pi.caso_id = c.id
                  AND pi.colegio_id = c.colegio_id
            ) AS planes_count,
            (
                SELECT MAX(pr.nivel_final)
                FROM caso_pauta_riesgo pr
                WHERE pr.caso_id = c.id
            ) AS riesgo_final
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE c.colegio_id = ?
        ORDER BY c.id DESC
        LIMIT 6
    ");
    $stmt->execute([$colegioId]);
    $casosRecientes = $stmt->fetchAll() ?: [];

    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.modulo,
            l.accion,
            l.entidad,
            l.entidad_id,
            l.descripcion,
            l.created_at,
            u.nombre AS usuario_nombre
        FROM logs_sistema l
        LEFT JOIN usuarios u ON u.id = l.usuario_id
        WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)
        ORDER BY l.id DESC
        LIMIT 8
    ");
    $stmt->execute([$colegioId]);
    $actividadReciente = $stmt->fetchAll() ?: [];

    $stmt = $pdo->prepare("
        SELECT
            l.descripcion,
            l.created_at,
            u.nombre AS usuario_nombre
        FROM logs_sistema l
        LEFT JOIN usuarios u ON u.id = l.usuario_id
        WHERE l.accion = 'exportar_respaldo_bd'
          AND (l.colegio_id = ? OR l.colegio_id IS NULL)
        ORDER BY l.id DESC
        LIMIT 1
    ");
    $stmt->execute([$colegioId]);
    $ultimoRespaldo = $stmt->fetch() ?: null;

    $focosGestion = [
        [
            'titulo' => 'Casos sin plan de acción',
            'valor' => $totalSinPlan,
            'texto' => 'Expedientes activos que aún no tienen plan registrado.',
            'url' => APP_URL . '/modules/seguimiento/index.php',
            'badge' => $totalSinPlan > 0 ? 'warn' : 'ok',
        ],
        [
            'titulo' => 'Seguimientos vencidos',
            'valor' => $totalSeguimientoVencido,
            'texto' => 'Casos con próxima revisión anterior a la fecha actual.',
            'url' => APP_URL . '/modules/seguimiento/index.php?filtro=vencidos',
            'badge' => $totalSeguimientoVencido > 0 ? 'danger' : 'ok',
        ],
        [
            'titulo' => 'Riesgo alto/crítico sin derivación',
            'valor' => $totalRiesgoAltoCritico,
            'texto' => 'Pautas de riesgo alto o crítico que requieren gestión prioritaria.',
            'url' => APP_URL . '/modules/seguimiento/index.php?filtro=riesgo',
            'badge' => $totalRiesgoAltoCritico > 0 ? 'danger' : 'ok',
        ],
        [
            'titulo' => 'Aula Segura pendiente',
            'valor' => $totalAulaSeguraPendiente,
            'texto' => 'Casos marcados o posibles que requieren evaluación directiva.',
            'url' => APP_URL . '/modules/alertas/index.php?tipo=aula_segura',
            'badge' => $totalAulaSeguraPendiente > 0 ? 'warn' : 'ok',
        ],
    ];
} catch (Throwable $e) {
    $error = 'Error al cargar dashboard: ' . $e->getMessage();
}

$saludOk = $coreOk && $dbOk && $storageOk;
