<?php
declare(strict_types=1);

// Fase 38K-2H: bootstrap robusto y diagnosticable para crear denuncia.
// Corrige buscador RUN/NOMBRE con búsqueda real por estamento, fallback controlado y diagnóstico JSON.

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';
require_once dirname(__DIR__, 3) . '/core/helpers.php';
require_once dirname(__DIR__, 3) . '/core/CSRF.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigoActual = (string)($user['rol_codigo'] ?? '');
$esAdminCentral = in_array($rolCodigoActual, ['superadmin', 'admin_sistema'], true)
    || (method_exists('Auth', 'can') && Auth::can('admin_sistema'));

$pageTitle = 'Registrar Incidente / Denuncia · Metis';
$pageSubtitle = 'Ingreso inicial del caso, intervinientes, datos de denuncia y marcadores normativos';

function nd_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function nd_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function nd_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function nd_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function nd_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !nd_table_exists($pdo, $table)) {
        return $cache[$key] = [];
    }

    try {
        $rows = $pdo->query('SHOW COLUMNS FROM ' . nd_quote($table))->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $cols[strtolower($field)] = $field;
            }
        }
        return $cache[$key] = $cols;
    } catch (Throwable $e) {
        return $cache[$key] = [];
    }
}

function nd_column_exists(PDO $pdo, string $table, string $column): bool
{
    $cols = nd_columns($pdo, $table);
    return isset($cols[strtolower($column)]);
}

function nd_first_column(PDO $pdo, string $table, array $candidates): ?string
{
    $cols = nd_columns($pdo, $table);
    foreach ($candidates as $candidate) {
        $candidate = strtolower((string)$candidate);
        if (isset($cols[$candidate])) {
            return $cols[$candidate];
        }
    }
    return null;
}

function nd_expr_column(?string $column, string $fallback = "''"): string
{
    return $column ? 'COALESCE(' . nd_quote($column) . ", '')" : $fallback;
}

function nd_run_normalizado(?string $column): string
{
    if (!$column) {
        return "''";
    }

    $expr = 'UPPER(COALESCE(' . nd_quote($column) . ", ''))";
    return "REPLACE(REPLACE(REPLACE(REPLACE({$expr}, '.', ''), '-', ''), ' ', ''), CHAR(9), '')";
}

function nd_normalizar_run_busqueda(string $value): string
{
    $value = nd_upper(trim($value));
    $value = str_replace(['.', '-', ' ', "\t"], '', $value);
    return preg_replace('/[^0-9K]/', '', $value) ?? '';
}

function nd_select_expr_nombre(PDO $pdo, string $table): string
{
    $cols = nd_columns($pdo, $table);

    $preferentes = [];
    foreach (['nombre_completo', 'nombres_completos_cache', 'nombre', 'full_name'] as $candidate) {
        $key = strtolower($candidate);
        if (isset($cols[$key])) {
            $preferentes[] = "NULLIF(TRIM(" . nd_quote($cols[$key]) . "), '')";
        }
    }

    $componentes = [];
    foreach (['nombres', 'primer_nombre', 'segundo_nombre', 'apellido_paterno', 'apellido_materno', 'apellidos'] as $candidate) {
        $key = strtolower($candidate);
        if (isset($cols[$key])) {
            $componentes[] = "NULLIF(TRIM(" . nd_quote($cols[$key]) . "), '')";
        }
    }

    $concat = $componentes
        ? "NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $componentes) . ")), '')"
        : null;

    $exprs = $preferentes;
    if ($concat) {
        $exprs[] = $concat;
    }

    return $exprs ? 'COALESCE(' . implode(', ', $exprs) . ", '')" : "''";
}

function nd_estado_activo_where(PDO $pdo, string $table): ?string
{
    if (nd_column_exists($pdo, $table, 'activo')) {
        return "(" . nd_quote('activo') . " = 1 OR " . nd_quote('activo') . " IS NULL)";
    }

    if (nd_column_exists($pdo, $table, 'vigente')) {
        return "(" . nd_quote('vigente') . " = 1 OR " . nd_quote('vigente') . " IS NULL)";
    }

    if (nd_column_exists($pdo, $table, 'estado')) {
        return "(LOWER(COALESCE(" . nd_quote('estado') . ", 'activo')) IN ('activo','activa','vigente','matriculado','matriculada','1'))";
    }

    return null;
}

function nd_extra_expr(PDO $pdo, string $table): string
{
    foreach (['curso', 'cargo', 'parentesco', 'tipo_relacion', 'email', 'telefono'] as $candidate) {
        if (nd_column_exists($pdo, $table, $candidate)) {
            return 'COALESCE(' . nd_quote($candidate) . ", '')";
        }
    }

    if ($table === 'alumnos' && nd_column_exists($pdo, $table, 'curso_id') && nd_table_exists($pdo, 'cursos') && nd_column_exists($pdo, 'cursos', 'nombre')) {
        return "COALESCE((SELECT c.nombre FROM cursos c WHERE c.id = " . nd_quote('curso_id') . " LIMIT 1), '')";
    }

    return "''";
}

function nd_table_count(PDO $pdo, string $table, ?int $colegioId = null): int
{
    if (!nd_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        if ($colegioId !== null && $colegioId > 0 && nd_column_exists($pdo, $table, 'colegio_id')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . nd_quote($table) . ' WHERE ' . nd_quote('colegio_id') . ' = ?');
            $stmt->execute([$colegioId]);
            return (int)$stmt->fetchColumn();
        }

        return (int)$pdo->query('SELECT COUNT(*) FROM ' . nd_quote($table))->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function nd_diagnostico_buscador(PDO $pdo, int $colegioId): array
{
    $tablas = ['alumnos', 'apoderados', 'docentes', 'asistentes', 'funcionarios'];
    $out = [
        'database' => '',
        'colegio_id_sesion' => $colegioId,
        'tablas' => [],
    ];

    try {
        $out['database'] = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        $out['database'] = 'no_disponible';
    }

    foreach ($tablas as $tabla) {
        $existe = nd_table_exists($pdo, $tabla);
        $out['tablas'][$tabla] = [
            'existe' => $existe,
            'total' => $existe ? nd_table_count($pdo, $tabla, null) : 0,
            'en_colegio_sesion' => $existe ? nd_table_count($pdo, $tabla, $colegioId) : 0,
            'tiene_colegio_id' => $existe ? nd_column_exists($pdo, $tabla, 'colegio_id') : false,
            'columnas_clave' => $existe ? array_values(array_intersect(
                ['id','colegio_id','run','rut','nombre','nombres','apellido_paterno','apellido_materno','activo','estado'],
                array_map('strtolower', array_values(nd_columns($pdo, $tabla)))
            )) : [],
        ];
    }

    return $out;
}

function nd_search_table_personas(PDO $pdo, string $table, string $tipoPersona, string $tipoLabel, string $q, int $colegioId, bool $esAdminCentral, bool $forzarSinColegio = false): array
{
    if (!nd_table_exists($pdo, $table) || !nd_column_exists($pdo, $table, 'id')) {
        return [];
    }

    $runColumn = nd_first_column($pdo, $table, ['run', 'rut', 'documento', 'numero_documento', 'nro_documento']);
    $runExpr = nd_expr_column($runColumn);
    $runNormExpr = nd_run_normalizado($runColumn);
    $nombreExpr = nd_select_expr_nombre($pdo, $table);
    $extraExpr = nd_extra_expr($pdo, $table);

    $where = [];
    $params = [];

    $activoWhere = nd_estado_activo_where($pdo, $table);
    if ($activoWhere) {
        $where[] = $activoWhere;
    }

    if (!$forzarSinColegio && !$esAdminCentral && $colegioId > 0 && nd_column_exists($pdo, $table, 'colegio_id')) {
        $where[] = nd_quote('colegio_id') . ' = ?';
        $params[] = $colegioId;
    }

    $q = trim($q);
    $qUpper = nd_upper($q);
    $qRun = nd_normalizar_run_busqueda($q);

    $searchParts = [];
    $searchParts[] = "UPPER({$nombreExpr}) LIKE ?";
    $params[] = '%' . $qUpper . '%';

    if ($runColumn) {
        $searchParts[] = "{$runExpr} LIKE ?";
        $params[] = '%' . $q . '%';

        if ($qRun !== '') {
            $searchParts[] = "{$runNormExpr} LIKE ?";
            $params[] = '%' . $qRun . '%';
        }
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    try {
        $sql = "
            SELECT
                " . nd_quote('id') . " AS persona_id,
                {$runExpr} AS run,
                {$nombreExpr} AS nombre_completo,
                {$extraExpr} AS extra
            FROM " . nd_quote($table) . "
            {$whereSql}
            ORDER BY nombre_completo ASC, run ASC
            LIMIT 20
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $nombre = trim((string)($row['nombre_completo'] ?? ''));
        $run = trim((string)($row['run'] ?? ''));
        $extra = trim((string)($row['extra'] ?? ''));

        if ($nombre === '' && $run === '') {
            continue;
        }

        $out[] = [
            'id' => (int)($row['persona_id'] ?? 0),
            'tipo_persona' => $tipoPersona,
            'tipo_label' => $tipoLabel,
            'run' => $run,
            'nombre' => $nombre !== '' ? $nombre : 'SIN NOMBRE REGISTRADO',
            'extra' => $extra,
        ];
    }

    return $out;
}

function nd_buscar_intervinientes(PDO $pdo, string $tipo, string $q, int $colegioId, bool $esAdminCentral): array
{
    $q = trim($q);
    if (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') < 2 : strlen($q) < 2) {
        return [];
    }

    $map = [
        'alumno' => [['alumnos', 'alumno', 'Alumno']],
        'apoderado' => [['apoderados', 'apoderado', 'Apoderado']],
        'funcionario' => [
            ['docentes', 'docente', 'Docente'],
            ['asistentes', 'asistente', 'Asistente'],
            ['funcionarios', 'funcionario', 'Funcionario'],
        ],
        'todos' => [
            ['alumnos', 'alumno', 'Alumno'],
            ['apoderados', 'apoderado', 'Apoderado'],
            ['docentes', 'docente', 'Docente'],
            ['asistentes', 'asistente', 'Asistente'],
            ['funcionarios', 'funcionario', 'Funcionario'],
        ],
    ];

    $targets = $map[$tipo] ?? $map['alumno'];
    $out = [];
    foreach ($targets as [$table, $tipoPersona, $tipoLabel]) {
        $out = array_merge($out, nd_search_table_personas($pdo, $table, $tipoPersona, $tipoLabel, $q, $colegioId, $esAdminCentral));
    }

    return array_slice($out, 0, 20);
}

function nd_ajax_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['ajax'] ?? '') === 'diagnostico_buscador') {
    nd_ajax_json([
        'ok' => true,
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'colegio_id' => $colegioId,
            'rol_codigo' => $rolCodigoActual,
            'colegio_nombre' => (string)($user['colegio_nombre'] ?? ''),
        ],
        'diagnostico' => nd_diagnostico_buscador($pdo, $colegioId),
    ]);
}

if (($_GET['ajax'] ?? '') === 'buscar_interviniente') {
    $tipo = nd_clean((string)($_GET['tipo'] ?? '')) ?? 'alumno';
    $q = nd_clean((string)($_GET['q'] ?? '')) ?? '';

    if (!in_array($tipo, ['alumno', 'funcionario', 'apoderado', 'externo', 'todos'], true)) {
        $tipo = 'alumno';
    }

    if ($tipo === 'externo') {
        nd_ajax_json([
            'ok' => true,
            'items' => [],
            'message' => 'El tipo “Otro actor civil” se ingresa manualmente; no consulta la base interna.',
            'meta' => ['tipo' => $tipo, 'q' => $q, 'colegio_id' => $colegioId],
        ]);
    }

    try {
        $items = nd_buscar_intervinientes($pdo, $tipo, $q, $colegioId, $esAdminCentral);
        $fallback = false;
        $message = '';

        if (!$items && $tipo !== 'todos' && trim($q) !== '') {
            $todos = nd_buscar_intervinientes($pdo, 'todos', $q, $colegioId, $esAdminCentral);
            if ($todos) {
                $items = $todos;
                $fallback = true;
                $message = 'No hubo coincidencias en el tipo seleccionado; se muestran coincidencias encontradas en otros estamentos del mismo colegio.';
            }
        }

        if (!$items && trim($q) !== '') {
            $message = 'Sin coincidencias para el colegio conectado. Revisa el tipo seleccionado, el RUN/nombre escrito o usa N/N si la persona aún no está identificada.';
        }

        nd_ajax_json([
            'ok' => true,
            'items' => $items,
            'message' => $message,
            'meta' => [
                'tipo' => $tipo,
                'q' => $q,
                'colegio_id' => $colegioId,
                'fallback_todos_estamentos' => $fallback,
                'diagnostico' => nd_diagnostico_buscador($pdo, $colegioId),
            ],
        ]);
    } catch (Throwable $e) {
        nd_ajax_json([
            'ok' => false,
            'items' => [],
            'message' => 'No fue posible ejecutar la búsqueda de intervinientes: ' . $e->getMessage(),
            'meta' => [
                'tipo' => $tipo,
                'q' => $q,
                'colegio_id' => $colegioId,
            ],
        ], 500);
    }
}


function nd_estados(PDO $pdo): array
{
    if (!nd_table_exists($pdo, 'estado_caso')) {
        return [];
    }

    try {
        $hasActivo      = nd_column_exists($pdo, 'estado_caso', 'activo');
        $hasOrdenVisual = nd_column_exists($pdo, 'estado_caso', 'orden_visual');
        $where  = $hasActivo ? "WHERE activo = 1 AND codigo != 'borrador'" : "WHERE codigo != 'borrador'";
        $order  = $hasOrdenVisual ? 'ORDER BY orden_visual ASC, id ASC' : 'ORDER BY id ASC';
        return $pdo->query("SELECT id, codigo, nombre FROM estado_caso {$where} {$order}")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function nd_causales_aula_segura(PDO $pdo): array
{
    $fallback = [
        ['codigo' => 'agresion_sexual', 'nombre' => 'Agresión de carácter sexual', 'tipo' => 'legal'],
        ['codigo' => 'agresion_fisica_lesiones', 'nombre' => 'Agresión física que produce lesiones', 'tipo' => 'legal'],
        ['codigo' => 'armas', 'nombre' => 'Uso, porte, posesión o tenencia de armas', 'tipo' => 'legal'],
        ['codigo' => 'artefactos_incendiarios', 'nombre' => 'Uso, porte, posesión o tenencia de artefactos incendiarios', 'tipo' => 'legal'],
        ['codigo' => 'infraestructura_esencial', 'nombre' => 'Actos contra infraestructura esencial para la prestación del servicio educativo', 'tipo' => 'legal'],
        ['codigo' => 'grave_reglamento', 'nombre' => 'Conducta grave o gravísima del Reglamento Interno', 'tipo' => 'reglamento'],
    ];

    if (!nd_table_exists($pdo, 'aula_segura_causales')) {
        return $fallback;
    }

    try {
        $where = nd_column_exists($pdo, 'aula_segura_causales', 'activo') ? 'WHERE activo = 1' : '';
        $order = nd_column_exists($pdo, 'aula_segura_causales', 'orden') ? 'ORDER BY orden ASC, id ASC' : 'ORDER BY id ASC';
        $rows = $pdo->query("SELECT codigo, nombre, tipo FROM aula_segura_causales {$where} {$order}")->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

$estadosCaso = nd_estados($pdo);
$causalesAulaSegura = nd_causales_aula_segura($pdo);
$estructuraAulaSeguraOk = nd_table_exists($pdo, 'casos')
    && nd_column_exists($pdo, 'casos', 'posible_aula_segura')
    && nd_column_exists($pdo, 'casos', 'aula_segura_estado');
