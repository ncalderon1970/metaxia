<?php
if (!class_exists('Cache')) {
    require_once dirname(__DIR__, 3) . '/core/Cache.php';
}

// TTL del caché del dashboard
// KPIs contables: 5 min (300s) — son estables y costosos de calcular
// Alertas pendientes: 60s — necesitan estar relativamente frescos
// Actividad reciente y casos recientes: sin caché — deben ser en tiempo real
const DASH_KPI_TTL    = 300;
const DASH_ALERT_TTL  = 60;

$cacheKpiKey  = "dash_kpis_{$colegioId}";
$cacheAlertas = "dash_alertas_{$colegioId}";

$totalCasos = 0;
$totalAbiertos = 0;
$totalCerrados = 0;
$totalRojos = 0;
$totalAlta = 0;
$totalAlertasPendientes = 0;
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

$coreOk = true;
$dbOk = true;
$storageOk = dash_storage_ok();

$tablasCriticas = [
    'usuarios',
    'roles',
    'casos',
    'caso_alertas',
    'caso_evidencias',
    'caso_historial',
    'logs_sistema',
];

$tablasFaltantes = [];

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
    // ── KPIs contables — cacheados 5 minutos ─────────────────
    $kpisCache = Cache::get($cacheKpiKey, DASH_KPI_TTL);

    if ($kpisCache !== null) {
        // Restaurar desde caché
        [
            'totalCasos'                => $totalCasos,
            'totalAbiertos'             => $totalAbiertos,
            'totalCerrados'             => $totalCerrados,
            'totalRojos'                => $totalRojos,
            'totalAlta'                 => $totalAlta,
            'totalEvidencias'           => $totalEvidencias,
            'totalUsuarios'             => $totalUsuarios,
            'totalLogsHoy'              => $totalLogsHoy,
            'totalDeclaraciones'        => $totalDeclaraciones,
            'totalParticipantes'        => $totalParticipantes,
            'totalAlumnos'              => $totalAlumnos,
            'totalApoderados'           => $totalApoderados,
            'totalDocentes'             => $totalDocentes,
            'totalAsistentes'           => $totalAsistentes,
            'totalComunidad'            => $totalComunidad,
            'totalPendientesImportacion'=> $totalPendientesImportacion,
        ] = $kpisCache;
    } else {
    // ── Sin caché: ejecutar queries y guardar ─────────────────

    if (dash_table_exists($pdo, 'casos')) {
        $whereColegio = dash_column_exists($pdo, 'casos', 'colegio_id') ? 'colegio_id = ?' : null;

        $totalCasos = dash_count($pdo, 'casos', $whereColegio, $whereColegio ? [$colegioId] : []);

        $totalAbiertos = dash_count(
            $pdo,
            'casos',
            ($whereColegio ? $whereColegio . " AND " : '') . "estado = 'abierto'",
            $whereColegio ? [$colegioId] : []
        );

        $totalCerrados = dash_count(
            $pdo,
            'casos',
            ($whereColegio ? $whereColegio . " AND " : '') . "estado = 'cerrado'",
            $whereColegio ? [$colegioId] : []
        );

        $totalRojos = dash_count(
            $pdo,
            'casos',
            ($whereColegio ? $whereColegio . " AND " : '') . "semaforo = 'rojo'",
            $whereColegio ? [$colegioId] : []
        );

        $totalAlta = dash_count(
            $pdo,
            'casos',
            ($whereColegio ? $whereColegio . " AND " : '') . "prioridad = 'alta'",
            $whereColegio ? [$colegioId] : []
        );
    }

    if (dash_table_exists($pdo, 'caso_alertas') && dash_table_exists($pdo, 'casos')) {
        $joinWhere = dash_column_exists($pdo, 'casos', 'colegio_id')
            ? 'WHERE c.colegio_id = ? AND a.estado = ?'
            : 'WHERE a.estado = ?';

        $params = dash_column_exists($pdo, 'casos', 'colegio_id')
            ? [$colegioId, 'pendiente']
            : ['pendiente'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM caso_alertas a
            INNER JOIN casos c ON c.id = a.caso_id
            {$joinWhere}
        ");
        $stmt->execute($params);
        $totalAlertasPendientes = (int)$stmt->fetchColumn();
    }

    // Evidencias — filtrar por colegio vía casos si no tiene colegio_id propio
    if (dash_column_exists($pdo, 'caso_evidencias', 'colegio_id')) {
        $totalEvidencias = dash_count($pdo, 'caso_evidencias', 'colegio_id = ?', [$colegioId]);
    } elseif (dash_table_exists($pdo, 'casos')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_evidencias e
            INNER JOIN casos c ON c.id = e.caso_id WHERE c.colegio_id = ?");
        $stmt->execute([$colegioId]);
        $totalEvidencias = (int)$stmt->fetchColumn();
    }

    // Usuarios — solo del establecimiento
    $totalUsuarios = $esSuperAdmin
        ? dash_count($pdo, 'usuarios')
        : dash_count($pdo, 'usuarios', 'colegio_id = ? AND activo = 1', [$colegioId]);

    // Logs de hoy — filtrar por colegio
    if (dash_column_exists($pdo, 'logs_sistema', 'colegio_id')) {
        $totalLogsHoy = $esSuperAdmin
            ? dash_count($pdo, 'logs_sistema', 'DATE(created_at) = CURDATE()')
            : dash_count($pdo, 'logs_sistema', 'colegio_id = ? AND DATE(created_at) = CURDATE()', [$colegioId]);
    } else {
        $totalLogsHoy = dash_count($pdo, 'logs_sistema', 'DATE(created_at) = CURDATE()');
    }

    // Declaraciones — filtrar por colegio vía JOIN con casos
    if (dash_column_exists($pdo, 'caso_declaraciones', 'colegio_id')) {
        $totalDeclaraciones = dash_count($pdo, 'caso_declaraciones', 'colegio_id = ?', [$colegioId]);
    } elseif (dash_table_exists($pdo, 'casos')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_declaraciones d
            INNER JOIN casos c ON c.id = d.caso_id WHERE c.colegio_id = ?");
        $stmt->execute([$colegioId]);
        $totalDeclaraciones = (int)$stmt->fetchColumn();
    }

    // Participantes — filtrar por colegio vía JOIN con casos
    if (dash_column_exists($pdo, 'caso_participantes', 'colegio_id')) {
        $totalParticipantes = dash_count($pdo, 'caso_participantes', 'colegio_id = ?', [$colegioId]);
    } elseif (dash_table_exists($pdo, 'casos')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM caso_participantes p
            INNER JOIN casos c ON c.id = p.caso_id WHERE c.colegio_id = ?");
        $stmt->execute([$colegioId]);
        $totalParticipantes = (int)$stmt->fetchColumn();
    }

    $totalAlumnos = dash_count_colegio($pdo, 'alumnos', $colegioId);
    $totalApoderados = dash_count_colegio($pdo, 'apoderados', $colegioId);
    $totalDocentes = dash_count_colegio($pdo, 'docentes', $colegioId);
    $totalAsistentes = dash_count_colegio($pdo, 'asistentes', $colegioId);
    $totalComunidad = $totalAlumnos + $totalApoderados + $totalDocentes + $totalAsistentes;

    if (dash_table_exists($pdo, 'comunidad_importacion_pendientes')) {
        if (dash_column_exists($pdo, 'comunidad_importacion_pendientes', 'colegio_id')) {
            $totalPendientesImportacion = dash_count(
                $pdo,
                'comunidad_importacion_pendientes',
                "colegio_id = ? AND estado = 'pendiente'",
                [$colegioId]
            );
        } else {
            $totalPendientesImportacion = dash_count(
                $pdo,
                'comunidad_importacion_pendientes',
                "estado = 'pendiente'"
            );
        }
    }

    // Guardar KPIs en caché para la próxima carga
    Cache::put($cacheKpiKey, [
        'totalCasos'                => $totalCasos,
        'totalAbiertos'             => $totalAbiertos,
        'totalCerrados'             => $totalCerrados,
        'totalRojos'                => $totalRojos,
        'totalAlta'                 => $totalAlta,
        'totalEvidencias'           => $totalEvidencias,
        'totalUsuarios'             => $totalUsuarios,
        'totalLogsHoy'              => $totalLogsHoy,
        'totalDeclaraciones'        => $totalDeclaraciones,
        'totalParticipantes'        => $totalParticipantes,
        'totalAlumnos'              => $totalAlumnos,
        'totalApoderados'           => $totalApoderados,
        'totalDocentes'             => $totalDocentes,
        'totalAsistentes'           => $totalAsistentes,
        'totalComunidad'            => $totalComunidad,
        'totalPendientesImportacion'=> $totalPendientesImportacion,
    ]);

    } // fin else (sin caché)

    // ── Alertas pendientes — cacheadas 60 segundos ────────────
    $alertasPendientes = Cache::remember($cacheAlertas, DASH_ALERT_TTL, function () use ($pdo, $colegioId): array {
        if (!dash_table_exists($pdo, 'caso_alertas') || !dash_table_exists($pdo, 'casos')) {
            return [];
        }

        $where = dash_column_exists($pdo, 'casos', 'colegio_id')
            ? "WHERE c.colegio_id = ? AND a.estado = 'pendiente'"
            : "WHERE a.estado = 'pendiente'";

        $params = dash_column_exists($pdo, 'casos', 'colegio_id') ? [$colegioId] : [];

        $stmt = $pdo->prepare("
            SELECT a.id, a.caso_id, a.tipo, a.mensaje, a.prioridad, a.estado, a.fecha_alerta, c.numero_caso
            FROM caso_alertas a
            INNER JOIN casos c ON c.id = a.caso_id
            {$where}
            ORDER BY
                CASE a.prioridad WHEN 'alta' THEN 1 WHEN 'media' THEN 2 ELSE 3 END,
                a.fecha_alerta DESC
            LIMIT 6
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    });

    // totalAlertasPendientes se actualiza con el conteo real de la lista
    $totalAlertasPendientes = count($alertasPendientes);

    // ── Casos recientes y actividad — siempre en tiempo real ─
    if (dash_table_exists($pdo, 'casos')) {
        $where = dash_column_exists($pdo, 'casos', 'colegio_id') ? 'WHERE c.colegio_id = ?' : '';
        $params = dash_column_exists($pdo, 'casos', 'colegio_id') ? [$colegioId] : [];

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.numero_caso,
                c.fecha_ingreso,
                c.estado,
                c.semaforo,
                c.prioridad,
                c.relato,
                c.contexto,
                c.updated_at,
                ec.nombre AS estado_formal,
                (
                    SELECT COUNT(*)
                    FROM caso_alertas a
                    WHERE a.caso_id = c.id
                      AND a.estado = 'pendiente'
                ) AS alertas_pendientes
            FROM casos c
            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
            {$where}
            ORDER BY c.id DESC
            LIMIT 6
        ");
        $stmt->execute($params);
        $casosRecientes = $stmt->fetchAll();
    }

    if (dash_table_exists($pdo, 'logs_sistema')) {
        $where = dash_column_exists($pdo, 'logs_sistema', 'colegio_id')
            ? 'WHERE (l.colegio_id = ? OR l.colegio_id IS NULL)'
            : '';

        $params = dash_column_exists($pdo, 'logs_sistema', 'colegio_id') ? [$colegioId] : [];

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
            {$where}
            ORDER BY l.id DESC
            LIMIT 8
        ");
        $stmt->execute($params);
        $actividadReciente = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT
                l.descripcion,
                l.created_at,
                u.nombre AS usuario_nombre
            FROM logs_sistema l
            LEFT JOIN usuarios u ON u.id = l.usuario_id
            WHERE l.accion = 'exportar_respaldo_bd'
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $stmt->execute();
        $ultimoRespaldo = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $error = 'Error al cargar dashboard: ' . $e->getMessage();
}

$saludOk = $coreOk && $dbOk && $storageOk;
