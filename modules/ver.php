<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/includes/ver_helpers.php';
require_once __DIR__ . '/includes/ver_queries.php';

Auth::requireLogin();

$pdo       = DB::conn();

// ── AJAX: buscar participante desde comunidad educativa ──────
if (($_GET['ajax'] ?? '') === 'buscar_participante') {
    header('Content-Type: application/json; charset=utf-8');

    $user      = Auth::user() ?? [];
    $colegioId = (int)($user['colegio_id'] ?? 0);
    $tipo      = trim((string)($_GET['tipo'] ?? 'alumno'));
    $q         = trim((string)($_GET['q']    ?? ''));

    if (!in_array($tipo, ['alumno','apoderado','funcionario','todos','externo'], true)) {
        $tipo = 'alumno';
    }

    if ($tipo === 'externo' || strlen($q) < 2) {
        echo json_encode(['ok'=>true,'items'=>[],'message'=>''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Reusar la función de búsqueda del módulo crear si está disponible
    require_once dirname(__DIR__, 2) . '/core/helpers.php';

    function vp_col_exists(PDO $pdo, string $table, string $col): bool {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $s->execute([$table, $col]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    function vp_table_exists(PDO $pdo, string $table): bool {
        try {
            return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    function vp_search_table(PDO $pdo, string $table, string $tipoPersona, string $tipoLabel, string $q, int $colegioId): array {
        if (!vp_table_exists($pdo, $table)) return [];

        $hasNombres  = vp_col_exists($pdo, $table, 'nombres');
        $hasApPat    = vp_col_exists($pdo, $table, 'apellido_paterno');
        $hasNombre   = vp_col_exists($pdo, $table, 'nombre');
        $hasRun      = vp_col_exists($pdo, $table, 'run');
        $hasColegio  = vp_col_exists($pdo, $table, 'colegio_id');
        $hasCurso    = vp_col_exists($pdo, $table, 'curso');

        $nombreExpr = $hasNombres && $hasApPat
            ? "CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres)"
            : ($hasNombre ? 'nombre' : "'N/N'");
        $runExpr    = $hasRun ? 'run' : "''";
        $cursoExpr  = $hasCurso ? 'curso' : "NULL";

        $where  = [];
        $params = [];

        if ($hasColegio) { $where[] = 'colegio_id = ?'; $params[] = $colegioId; }

        $qUp  = mb_strtoupper(trim($q), 'UTF-8');
        $qRun = preg_replace('/[.\-\s]/', '', $q);

        $where[] = "(UPPER({$nombreExpr}) COLLATE utf8mb4_unicode_ci LIKE ?
                    OR {$runExpr} COLLATE utf8mb4_unicode_ci LIKE ?
                    OR REPLACE(REPLACE({$runExpr},'.',''),'-','') COLLATE utf8mb4_unicode_ci LIKE ?)";
        $params[] = '%' . $qUp . '%';
        $params[] = '%' . $q   . '%';
        $params[] = '%' . $qRun . '%';

        try {
            $stmt = $pdo->prepare("
                SELECT id,
                       ({$nombreExpr}) AS nombre,
                       ({$runExpr})    AS run,
                       ({$cursoExpr})  AS curso
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ({$nombreExpr}) ASC
                LIMIT 10
            ");
            $stmt->execute($params);
            return array_map(function($r) use ($tipoPersona, $tipoLabel) {
                return [
                    'id'        => $r['id'],
                    'nombre'    => mb_strtoupper(trim((string)$r['nombre']), 'UTF-8'),
                    'run'       => (string)($r['run'] ?: '0-0'),
                    'curso'     => (string)($r['curso'] ?? ''),
                    'tipo'      => $tipoPersona,
                    'tipo_label'=> $tipoLabel,
                ];
            }, $stmt->fetchAll());
        } catch (Throwable $e) { return []; }
    }

    $map = [
        'alumno'     => [['alumnos',    'alumno',    'Alumno/a']],
        'apoderado'  => [['apoderados', 'apoderado', 'Apoderado/a']],
        'funcionario'=> [['docentes',   'docente',   'Docente'],
                         ['asistentes', 'asistente', 'Asistente']],
        'todos'      => [['alumnos',    'alumno',    'Alumno/a'],
                         ['apoderados', 'apoderado', 'Apoderado/a'],
                         ['docentes',   'docente',   'Docente'],
                         ['asistentes', 'asistente', 'Asistente']],
    ];

    $targets = $map[$tipo] ?? $map['alumno'];
    $items   = [];
    foreach ($targets as [$table, $tipoP, $tipoL]) {
        $items = array_merge($items, vp_search_table($pdo, $table, $tipoP, $tipoL, $q, $colegioId));
    }

    $message = '';
    if (empty($items) && $tipo !== 'todos' && $q !== '') {
        foreach ($map['todos'] as [$table, $tipoP, $tipoL]) {
            $items = array_merge($items, vp_search_table($pdo, $table, $tipoP, $tipoL, $q, $colegioId));
        }
        if ($items) $message = 'Se muestran coincidencias de otros estamentos.';
        else        $message = 'Sin coincidencias. Usa "Externo" para ingresar manualmente.';
    }

    echo json_encode(['ok'=>true,'items'=>array_slice($items,0,20),'message'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$casoId = (int)($_GET['id'] ?? 0);
$tab = clean((string)($_GET['tab'] ?? 'resumen'));

if ($casoId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

$error = '';
$exito = '';

$caso = ver_cargar_caso($pdo, $casoId, $colegioId);

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado o no pertenece al establecimiento.');
}

$mostrarAulaSegura = (int)($caso['posible_aula_segura'] ?? 0) === 1;

$tabsPermitidos = [
    'resumen', 'gestion', 'seguimiento', 'medidas_preventivas',
    'participantes', 'declaraciones', 'evidencias', 'clasificacion',
    'historial', 'cierre', 'analisis_ia', 'plan_accion', 'pauta_riesgo',
];

if ($mostrarAulaSegura) {
    $tabsPermitidos[] = 'aula_segura';
}

if (!in_array($tab, $tabsPermitidos, true)) {
    $tab = 'resumen';
}

require_once __DIR__ . '/includes/ver_actions.php';

$contexto = ver_cargar_contexto($pdo, $casoId, $colegioId);
extract($contexto, EXTR_OVERWRITE);

$pageTitle = 'Expediente · ' . ($caso['numero_caso'] ?? 'Caso');
$pageSubtitle = 'Revisión integral del caso, intervinientes, declaraciones, evidencias e historial';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
require_once __DIR__ . '/partials/ver_styles.php';
require_once __DIR__ . '/partials/ver_header.php';
require_once __DIR__ . '/partials/ver_messages.php';
require_once __DIR__ . '/partials/ver_tabs.php';

// Validar tabs permitidos para evitar path traversal
$tabsPermitidos = [
    'resumen', 'seguimiento', 'clasificacion', 'participantes',
    'declaraciones', 'evidencias', 'gestion', 'aula_segura',
    'historial', 'cierre', 'analisis_ia', 'plan_accion', 'pauta_riesgo',
];

if (!in_array($tab, $tabsPermitidos, true)) {
    $tab = 'resumen';
}

$tabFile = __DIR__ . '/partials/tab_' . $tab . '.php';

if (is_file($tabFile)) {
    require $tabFile;
} else {
    require __DIR__ . '/partials/tab_resumen.php';
}

require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
