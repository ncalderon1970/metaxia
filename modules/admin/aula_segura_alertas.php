<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');
$colegioUsuarioId = (int)($user['colegio_id'] ?? 0);

$puedeVer = Auth::canOperate();

if (!$puedeVer) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Alertas Aula Segura · Metis';
$pageSubtitle = 'Control de plazos, estados y riesgos normativos de procedimientos Aula Segura';

function asa_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function asa_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function asa_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asa_clean(?string $value): string
{
    return trim((string)$value);
}

function asa_fecha(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    if (!$ts) {
        return (string)$value;
    }

    return $withTime ? date('d-m-Y H:i', $ts) : date('d-m-Y', $ts);
}

function asa_estado_texto(string $estado): string
{
    return match ($estado) {
        'no_aplica' => 'No aplica',
        'posible' => 'Posible Aula Segura',
        'en_evaluacion' => 'En evaluación directiva',
        'descartado' => 'Descartado',
        'procedimiento_iniciado' => 'Procedimiento iniciado',
        'suspension_cautelar' => 'Suspensión cautelar',
        'resuelto' => 'Resuelto',
        'reconsideracion' => 'Reconsideración',
        'cerrado' => 'Cerrado',
        'aplica' => 'Aplica / requiere revisión',
        default => mb_strtoupper(str_replace('_', ' ', $estado), 'UTF-8'),
    };
}

function asa_estado_clase(string $estado): string
{
    return match ($estado) {
        'posible', 'aplica' => 'warn',
        'en_evaluacion', 'procedimiento_iniciado', 'reconsideracion' => 'blue',
        'suspension_cautelar' => 'danger',
        'resuelto', 'cerrado' => 'ok',
        'descartado', 'no_aplica' => 'soft',
        default => 'soft',
    };
}

function asa_causal_label(string $codigo): string
{
    return match ($codigo) {
        'agresion_sexual' => 'Agresión de carácter sexual',
        'agresion_fisica_lesiones' => 'Agresión física con lesiones',
        'armas' => 'Armas',
        'artefactos_incendiarios' => 'Artefactos incendiarios',
        'infraestructura_esencial' => 'Infraestructura esencial',
        'grave_reglamento' => 'Falta grave/gravísima del Reglamento Interno',
        default => mb_strtoupper(str_replace('_', ' ', $codigo), 'UTF-8'),
    };
}

function asa_causales_desde_json(?string $value): array
{
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }

    $parts = preg_split('/[,;|]/', $value) ?: [];
    return array_values(array_filter(array_map('trim', $parts)));
}

function asa_causales_row(array $row): array
{
    $causales = asa_causales_desde_json($row['aula_segura_causales_preliminares'] ?? null);

    $map = [
        'causal_agresion_sexual' => 'agresion_sexual',
        'causal_agresion_fisica_lesiones' => 'agresion_fisica_lesiones',
        'causal_armas' => 'armas',
        'causal_artefactos_incendiarios' => 'artefactos_incendiarios',
        'causal_infraestructura_esencial' => 'infraestructura_esencial',
        'causal_grave_reglamento' => 'grave_reglamento',
    ];

    foreach ($map as $field => $codigo) {
        if ((int)($row[$field] ?? 0) === 1) {
            $causales[] = $codigo;
        }
    }

    return array_values(array_unique(array_filter($causales)));
}

function asa_causales_texto(array $row): string
{
    $causales = asa_causales_row($row);
    if (!$causales) {
        return 'Sin causal informada';
    }

    return implode(' · ', array_map('asa_causal_label', $causales));
}

function asa_estado_real(array $row): string
{
    $estadoAula = trim((string)($row['aula_estado'] ?? ''));
    if ($estadoAula !== '') {
        return $estadoAula;
    }

    $estadoCaso = trim((string)($row['caso_aula_estado'] ?? ''));
    if ($estadoCaso !== '') {
        return $estadoCaso;
    }

    return ((int)($row['posible_aula_segura'] ?? 0) === 1) ? 'posible' : 'no_aplica';
}

function asa_dias_hasta(?string $fecha): ?int
{
    if (!$fecha) {
        return null;
    }

    $limite = DateTimeImmutable::createFromFormat('Y-m-d', substr((string)$fecha, 0, 10));
    if (!$limite) {
        return null;
    }

    $hoy = new DateTimeImmutable('today');
    return (int)$hoy->diff($limite)->format('%r%a');
}

function asa_riesgo(array $row): array
{
    $estado = asa_estado_real($row);
    $cerrados = ['resuelto', 'cerrado', 'descartado', 'no_aplica'];
    $limiteResolucion = $row['fecha_limite_resolucion'] ?? null;
    $diasLimite = asa_dias_hasta($limiteResolucion);
    $suspension = (int)($row['suspension_cautelar'] ?? 0) === 1;

    if ($suspension && $diasLimite !== null && $diasLimite < 0 && !in_array($estado, $cerrados, true)) {
        return ['codigo' => 'vencido', 'texto' => 'Plazo de resolución vencido', 'clase' => 'danger', 'prioridad' => 100];
    }

    if ($suspension && $diasLimite !== null && $diasLimite <= 2 && !in_array($estado, $cerrados, true)) {
        return ['codigo' => 'critico', 'texto' => 'Plazo vence pronto', 'clase' => 'danger', 'prioridad' => 90];
    }

    $requiereSupereduc = in_array($estado, ['resuelto', 'cerrado'], true)
        && in_array((string)($row['resolucion'] ?? ''), ['expulsion', 'cancelacion_matricula'], true)
        && (int)($row['comunicacion_supereduc'] ?? 0) !== 1;

    if ($requiereSupereduc) {
        return ['codigo' => 'supereduc_pendiente', 'texto' => 'Comunicación Supereduc pendiente', 'clase' => 'warn', 'prioridad' => 80];
    }

    if (in_array($estado, ['posible', 'aplica', 'en_evaluacion'], true)) {
        return ['codigo' => 'evaluacion_pendiente', 'texto' => 'Evaluación directiva pendiente', 'clase' => 'warn', 'prioridad' => 70];
    }

    if (in_array($estado, ['procedimiento_iniciado', 'suspension_cautelar', 'reconsideracion'], true)) {
        return ['codigo' => 'seguimiento', 'texto' => 'Requiere seguimiento', 'clase' => 'blue', 'prioridad' => 50];
    }

    if (in_array($estado, ['resuelto', 'cerrado'], true)) {
        return ['codigo' => 'cerrado', 'texto' => 'Sin alerta crítica', 'clase' => 'ok', 'prioridad' => 10];
    }

    return ['codigo' => 'sin_alerta', 'texto' => 'Sin alerta crítica', 'clase' => 'soft', 'prioridad' => 0];
}

function asa_csv_download(array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="aula_segura_alertas_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Caso',
        'Colegio',
        'Estado Aula Segura',
        'Riesgo',
        'Causales',
        'Fecha ingreso',
        'Marcado Aula Segura',
        'Suspension cautelar',
        'Fecha limite resolucion',
        'Resolucion',
        'Comunicacion Supereduc',
    ], ';');

    foreach ($rows as $row) {
        $riesgo = $row['_riesgo'] ?? asa_riesgo($row);
        fputcsv($out, [
            (string)($row['numero_caso'] ?? ('#' . (int)($row['id'] ?? 0))),
            (string)($row['colegio_nombre'] ?? '-'),
            asa_estado_texto(asa_estado_real($row)),
            (string)$riesgo['texto'],
            asa_causales_texto($row),
            asa_fecha($row['fecha_ingreso'] ?? null, true),
            asa_fecha($row['aula_segura_marcado_at'] ?? null, true),
            ((int)($row['suspension_cautelar'] ?? 0) === 1) ? 'Sí' : 'No',
            asa_fecha($row['fecha_limite_resolucion'] ?? null),
            (string)($row['resolucion'] ?? '-'),
            ((int)($row['comunicacion_supereduc'] ?? 0) === 1) ? 'Sí' : 'No',
        ], ';');
    }

    fclose($out);
    exit;
}

if (!asa_table_exists($pdo, 'casos')) {
    http_response_code(500);
    exit('No existe la tabla casos.');
}

if (!asa_column_exists($pdo, 'casos', 'posible_aula_segura')) {
    http_response_code(500);
    exit('Falta la estructura Aula Segura. Ejecuta la Fase 0.5.36A.');
}

$esAdminCentral = in_array($rolCodigo, ['superadmin', 'admin_sistema'], true) || Auth::can('admin_sistema');
$q = asa_clean($_GET['q'] ?? '');
$filtroEstado = asa_clean($_GET['estado'] ?? 'todos');
$filtroRiesgo = asa_clean($_GET['riesgo'] ?? 'todos');
$filtroColegio = (int)($_GET['colegio_id'] ?? 0);
$exportar = isset($_GET['export']) && (string)$_GET['export'] === 'csv';

$where = ['c.posible_aula_segura = 1'];
$params = [];

if (!$esAdminCentral && $colegioUsuarioId > 0 && asa_column_exists($pdo, 'casos', 'colegio_id')) {
    $where[] = 'c.colegio_id = ?';
    $params[] = $colegioUsuarioId;
} elseif ($filtroColegio > 0 && asa_column_exists($pdo, 'casos', 'colegio_id')) {
    $where[] = 'c.colegio_id = ?';
    $params[] = $filtroColegio;
}

if ($q !== '') {
    $likeParts = ['c.numero_caso LIKE ?', 'c.relato LIKE ?'];
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';

    if (asa_column_exists($pdo, 'casos', 'denunciante_nombre')) {
        $likeParts[] = 'c.denunciante_nombre LIKE ?';
        $params[] = '%' . $q . '%';
    }

    $where[] = '(' . implode(' OR ', $likeParts) . ')';
}

$hasColegios = asa_table_exists($pdo, 'colegios') && asa_column_exists($pdo, 'colegios', 'nombre');
$hasAula = asa_table_exists($pdo, 'caso_aula_segura');

$joinColegios = $hasColegios ? 'LEFT JOIN colegios co ON co.id = c.colegio_id' : '';
$selectColegio = $hasColegios ? 'co.nombre AS colegio_nombre' : 'NULL AS colegio_nombre';

$joinAula = '';
$selectAula = "
    NULL AS aula_id,
    NULL AS aula_estado,
    0 AS causal_agresion_sexual,
    0 AS causal_agresion_fisica_lesiones,
    0 AS causal_armas,
    0 AS causal_artefactos_incendiarios,
    0 AS causal_infraestructura_esencial,
    0 AS causal_grave_reglamento,
    NULL AS fecha_evaluacion_directiva,
    NULL AS fecha_inicio_procedimiento,
    0 AS suspension_cautelar,
    NULL AS fecha_notificacion_suspension,
    NULL AS fecha_limite_resolucion,
    0 AS descargos_recibidos,
    NULL AS resolucion,
    NULL AS fecha_resolucion,
    0 AS reconsideracion_presentada,
    0 AS comunicacion_supereduc,
    NULL AS fecha_comunicacion_supereduc
";

if ($hasAula) {
    $joinAula = "\n        LEFT JOIN (\n            SELECT cas.*\n            FROM caso_aula_segura cas\n            INNER JOIN (\n                SELECT caso_id, MAX(id) AS max_id\n                FROM caso_aula_segura\n                GROUP BY caso_id\n            ) ult ON ult.max_id = cas.id\n        ) aula ON aula.caso_id = c.id\n    ";

    $selectAula = "
        aula.id AS aula_id,
        aula.estado AS aula_estado,
        aula.causal_agresion_sexual,
        aula.causal_agresion_fisica_lesiones,
        aula.causal_armas,
        aula.causal_artefactos_incendiarios,
        aula.causal_infraestructura_esencial,
        aula.causal_grave_reglamento,
        aula.fecha_evaluacion_directiva,
        aula.fecha_inicio_procedimiento,
        aula.suspension_cautelar,
        aula.fecha_notificacion_suspension,
        aula.fecha_limite_resolucion,
        aula.descargos_recibidos,
        aula.resolucion,
        aula.fecha_resolucion,
        aula.reconsideracion_presentada,
        aula.comunicacion_supereduc,
        aula.fecha_comunicacion_supereduc
    ";

    if ($filtroEstado !== 'todos') {
        $where[] = 'COALESCE(aula.estado, c.aula_segura_estado) = ?';
        $params[] = $filtroEstado;
    }
} elseif ($filtroEstado !== 'todos') {
    $where[] = 'c.aula_segura_estado = ?';
    $params[] = $filtroEstado;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT
        c.id,
        c.colegio_id,
        c.numero_caso,
        c.fecha_ingreso,
        c.estado AS estado_caso,
        c.semaforo,
        c.prioridad,
        c.relato,
        c.posible_aula_segura,
        c.aula_segura_estado AS caso_aula_estado,
        c.aula_segura_marcado_por,
        c.aula_segura_marcado_at,
        c.aula_segura_causales_preliminares,
        c.aula_segura_observacion_preliminar,
        {$selectColegio},
        {$selectAula}
    FROM casos c
    {$joinColegios}
    {$joinAula}
    {$whereSql}
    ORDER BY c.fecha_ingreso DESC, c.id DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $row['_riesgo'] = asa_riesgo($row);
}
unset($row);

if ($filtroRiesgo !== 'todos') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($filtroRiesgo): bool {
        return (string)($row['_riesgo']['codigo'] ?? '') === $filtroRiesgo;
    }));
}

usort($rows, static function (array $a, array $b): int {
    $pa = (int)($a['_riesgo']['prioridad'] ?? 0);
    $pb = (int)($b['_riesgo']['prioridad'] ?? 0);
    if ($pa === $pb) {
        return strcmp((string)($b['fecha_ingreso'] ?? ''), (string)($a['fecha_ingreso'] ?? ''));
    }
    return $pb <=> $pa;
});

if ($exportar) {
    asa_csv_download($rows);
}

$colegios = [];
if ($esAdminCentral && $hasColegios) {
    $stmtColegios = $pdo->query("SELECT id, nombre FROM colegios ORDER BY nombre ASC");
    $colegios = $stmtColegios->fetchAll();
}

$kpi = [
    'total' => count($rows),
    'vencidos' => 0,
    'criticos' => 0,
    'evaluacion' => 0,
    'seguimiento' => 0,
    'supereduc' => 0,
];

foreach ($rows as $row) {
    $codigo = (string)($row['_riesgo']['codigo'] ?? '');
    if ($codigo === 'vencido') {
        $kpi['vencidos']++;
    } elseif ($codigo === 'critico') {
        $kpi['criticos']++;
    } elseif ($codigo === 'evaluacion_pendiente') {
        $kpi['evaluacion']++;
    } elseif ($codigo === 'seguimiento') {
        $kpi['seguimiento']++;
    } elseif ($codigo === 'supereduc_pendiente') {
        $kpi['supereduc']++;
    }
}

$queryExport = $_GET;
$queryExport['export'] = 'csv';
$exportUrl = APP_URL . '/modules/admin/aula_segura_alertas.php?' . http_build_query($queryExport);

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.asa-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(239,68,68,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #7f1d1d 58%, #dc2626 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.asa-hero h2 { margin:0 0 .45rem; font-size:1.85rem; font-weight:900; }
.asa-hero p { margin:0; color:#fee2e2; max-width:920px; line-height:1.55; }
.asa-actions { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:1rem; }
.asa-btn {
    display:inline-flex; align-items:center; gap:.42rem; border-radius:999px;
    padding:.62rem 1rem; font-size:.84rem; font-weight:900; text-decoration:none;
    border:1px solid rgba(255,255,255,.28); color:#fff; background:rgba(255,255,255,.12);
}
.asa-kpis { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.9rem; margin-bottom:1.2rem; }
.asa-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:1rem; box-shadow:0 12px 28px rgba(15,23,42,.06); }
.asa-kpi span { color:#64748b; display:block; font-size:.68rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
.asa-kpi strong { display:block; color:#0f172a; font-size:1.8rem; line-height:1; margin-top:.35rem; }
.asa-kpi strong.danger { color:#b91c1c; }
.asa-kpi strong.warn { color:#92400e; }
.asa-kpi strong.blue { color:#1d4ed8; }
.asa-panel { background:#fff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 12px 28px rgba(15,23,42,.06); overflow:hidden; margin-bottom:1.2rem; }
.asa-panel-head { padding:1rem 1.2rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
.asa-panel-title { margin:0; color:#0f172a; font-size:1rem; font-weight:900; }
.asa-panel-body { padding:1.2rem; }
.asa-filter { display:grid; grid-template-columns:1.1fr .75fr .75fr .75fr auto auto; gap:.8rem; align-items:end; }
.asa-label { display:block; color:#334155; font-size:.76rem; font-weight:900; margin-bottom:.35rem; }
.asa-control { width:100%; border:1px solid #cbd5e1; border-radius:13px; padding:.66rem .78rem; outline:none; background:#fff; font-size:.9rem; }
.asa-submit,.asa-link { display:inline-flex; align-items:center; justify-content:center; gap:.35rem; border:0; background:#0f172a; color:#fff; border-radius:999px; padding:.66rem 1rem; font-weight:900; font-size:.84rem; text-decoration:none; white-space:nowrap; cursor:pointer; }
.asa-link { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.asa-link.green { background:#ecfdf5; color:#047857; border-color:#bbf7d0; }
.asa-link.red { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.asa-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:18px; padding:1rem; margin-bottom:.85rem; }
.asa-card-head { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap; }
.asa-card-title { color:#0f172a; font-weight:900; margin-bottom:.25rem; }
.asa-meta { color:#64748b; font-size:.78rem; line-height:1.45; margin-top:.25rem; }
.asa-badge { display:inline-flex; align-items:center; border-radius:999px; padding:.24rem .62rem; font-size:.72rem; font-weight:900; border:1px solid #e2e8f0; background:#fff; color:#475569; white-space:nowrap; margin:.12rem; }
.asa-badge.ok { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.asa-badge.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.asa-badge.danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.asa-badge.blue { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
.asa-badge.soft { background:#f8fafc; color:#475569; }
.asa-row-actions { display:flex; flex-wrap:wrap; gap:.45rem; margin-top:.75rem; }
.asa-empty { text-align:center; padding:2rem 1rem; color:#94a3b8; }
.asa-note { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:16px; padding:1rem; line-height:1.5; font-size:.9rem; margin-bottom:1rem; }
@media (max-width:1250px) { .asa-kpis { grid-template-columns:repeat(3,minmax(0,1fr)); } .asa-filter { grid-template-columns:1fr 1fr; } }
@media (max-width:720px) { .asa-kpis,.asa-filter { grid-template-columns:1fr; } .asa-hero { padding:1.35rem; } }
</style>

<section class="asa-hero">
    <h2>Alertas Aula Segura</h2>
    <p>
        Control ejecutivo de casos marcados como posible Aula Segura, con énfasis en evaluación directiva,
        suspensión cautelar, plazos de resolución, reconsideración y comunicación a Supereduc.
    </p>

    <div class="asa-actions">
        <a class="asa-btn" href="<?= APP_URL ?>/modules/admin/index.php"><i class="bi bi-gear"></i> Administración</a>
        <a class="asa-btn" href="<?= APP_URL ?>/modules/denuncias/index.php"><i class="bi bi-megaphone"></i> Denuncias</a>
        <a class="asa-btn" href="<?= asa_e($exportUrl) ?>"><i class="bi bi-filetype-csv"></i> Exportar CSV</a>
    </div>
</section>

<section class="asa-kpis">
    <div class="asa-kpi"><span>Total monitoreado</span><strong><?= number_format($kpi['total'], 0, ',', '.') ?></strong></div>
    <div class="asa-kpi"><span>Plazo vencido</span><strong class="danger"><?= number_format($kpi['vencidos'], 0, ',', '.') ?></strong></div>
    <div class="asa-kpi"><span>Vence pronto</span><strong class="danger"><?= number_format($kpi['criticos'], 0, ',', '.') ?></strong></div>
    <div class="asa-kpi"><span>Evaluación pendiente</span><strong class="warn"><?= number_format($kpi['evaluacion'], 0, ',', '.') ?></strong></div>
    <div class="asa-kpi"><span>Seguimiento</span><strong class="blue"><?= number_format($kpi['seguimiento'], 0, ',', '.') ?></strong></div>
    <div class="asa-kpi"><span>Supereduc pendiente</span><strong class="warn"><?= number_format($kpi['supereduc'], 0, ',', '.') ?></strong></div>
</section>

<section class="asa-panel">
    <div class="asa-panel-head">
        <h3 class="asa-panel-title"><i class="bi bi-funnel"></i> Filtros</h3>
    </div>

    <div class="asa-panel-body">
        <form method="get" class="asa-filter">
            <div>
                <label class="asa-label">Buscar</label>
                <input class="asa-control" type="text" name="q" value="<?= asa_e($q) ?>" placeholder="Número de caso, relato o denunciante">
            </div>

            <?php if ($esAdminCentral && $colegios): ?>
                <div>
                    <label class="asa-label">Colegio</label>
                    <select class="asa-control" name="colegio_id">
                        <option value="0">Todos</option>
                        <?php foreach ($colegios as $colegio): ?>
                            <option value="<?= (int)$colegio['id'] ?>" <?= $filtroColegio === (int)$colegio['id'] ? 'selected' : '' ?>>
                                <?= asa_e((string)$colegio['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div>
                <label class="asa-label">Estado</label>
                <select class="asa-control" name="estado">
                    <?php foreach ([
                        'todos' => 'Todos',
                        'posible' => 'Posible',
                        'en_evaluacion' => 'En evaluación',
                        'procedimiento_iniciado' => 'Procedimiento iniciado',
                        'suspension_cautelar' => 'Suspensión cautelar',
                        'resuelto' => 'Resuelto',
                        'reconsideracion' => 'Reconsideración',
                        'cerrado' => 'Cerrado',
                        'descartado' => 'Descartado',
                    ] as $valor => $texto): ?>
                        <option value="<?= asa_e($valor) ?>" <?= $filtroEstado === $valor ? 'selected' : '' ?>><?= asa_e($texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="asa-label">Riesgo</label>
                <select class="asa-control" name="riesgo">
                    <?php foreach ([
                        'todos' => 'Todos',
                        'vencido' => 'Plazo vencido',
                        'critico' => 'Vence pronto',
                        'evaluacion_pendiente' => 'Evaluación pendiente',
                        'seguimiento' => 'Seguimiento',
                        'supereduc_pendiente' => 'Supereduc pendiente',
                        'cerrado' => 'Cerrado sin alerta',
                        'sin_alerta' => 'Sin alerta',
                    ] as $valor => $texto): ?>
                        <option value="<?= asa_e($valor) ?>" <?= $filtroRiesgo === $valor ? 'selected' : '' ?>><?= asa_e($texto) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div><button class="asa-submit" type="submit"><i class="bi bi-search"></i> Filtrar</button></div>
            <div><a class="asa-link" href="<?= APP_URL ?>/modules/admin/aula_segura_alertas.php">Limpiar</a></div>
        </form>
    </div>
</section>

<section class="asa-panel">
    <div class="asa-panel-head">
        <h3 class="asa-panel-title"><i class="bi bi-exclamation-triangle"></i> Casos con posible Aula Segura</h3>
        <span class="asa-badge"><?= number_format(count($rows), 0, ',', '.') ?> resultado(s)</span>
    </div>

    <div class="asa-panel-body">
        <div class="asa-note">
            Esta bandeja no inicia procedimientos. Solo prioriza expedientes marcados como posible Aula Segura
            para que Dirección revise causales, plazos y comunicaciones críticas.
        </div>

        <?php if (!$rows): ?>
            <div class="asa-empty">No hay casos Aula Segura con los filtros actuales.</div>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                    $estado = asa_estado_real($row);
                    $riesgo = $row['_riesgo'];
                    $diasLimite = asa_dias_hasta($row['fecha_limite_resolucion'] ?? null);
                    $casoId = (int)$row['id'];
                ?>
                <article class="asa-card">
                    <div class="asa-card-head">
                        <div>
                            <div class="asa-card-title">
                                <?= asa_e((string)($row['numero_caso'] ?? ('Caso #' . $casoId))) ?>
                            </div>
                            <div class="asa-meta">
                                Colegio: <?= asa_e((string)($row['colegio_nombre'] ?? '-')) ?> ·
                                Ingreso: <?= asa_e(asa_fecha($row['fecha_ingreso'] ?? null, true)) ?>
                            </div>
                        </div>

                        <div>
                            <span class="asa-badge <?= asa_e((string)$riesgo['clase']) ?>">
                                <?= asa_e((string)$riesgo['texto']) ?>
                            </span>
                            <span class="asa-badge <?= asa_e(asa_estado_clase($estado)) ?>">
                                <?= asa_e(asa_estado_texto($estado)) ?>
                            </span>
                        </div>
                    </div>

                    <div style="margin-top:.65rem;">
                        <span class="asa-badge blue">Causales: <?= asa_e(asa_causales_texto($row)) ?></span>
                        <span class="asa-badge <?= ((int)($row['suspension_cautelar'] ?? 0) === 1) ? 'danger' : 'soft' ?>">
                            Suspensión cautelar: <?= ((int)($row['suspension_cautelar'] ?? 0) === 1) ? 'Sí' : 'No' ?>
                        </span>
                        <span class="asa-badge <?= ($diasLimite !== null && $diasLimite <= 2 && !in_array($estado, ['resuelto','cerrado','descartado'], true)) ? 'danger' : 'soft' ?>">
                            Límite resolución: <?= asa_e(asa_fecha($row['fecha_limite_resolucion'] ?? null)) ?>
                            <?= $diasLimite !== null ? ' · ' . (string)$diasLimite . ' día(s)' : '' ?>
                        </span>
                        <span class="asa-badge <?= ((int)($row['comunicacion_supereduc'] ?? 0) === 1) ? 'ok' : 'warn' ?>">
                            Supereduc: <?= ((int)($row['comunicacion_supereduc'] ?? 0) === 1) ? 'Comunicada' : 'Pendiente/no aplica' ?>
                        </span>
                    </div>

                    <?php if (!empty($row['aula_segura_observacion_preliminar'])): ?>
                        <div class="asa-meta">
                            Observación preliminar: <?= asa_e((string)$row['aula_segura_observacion_preliminar']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="asa-row-actions">
                        <a class="asa-link red" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= $casoId ?>&tab=aula_segura">
                            <i class="bi bi-shield-exclamation"></i> Abrir Aula Segura
                        </a>
                        <a class="asa-link" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= $casoId ?>&tab=resumen">
                            <i class="bi bi-folder2-open"></i> Expediente
                        </a>
                        <a class="asa-link green" href="<?= APP_URL ?>/modules/denuncias/reporte_aula_segura.php?id=<?= $casoId ?>" target="_blank">
                            <i class="bi bi-printer"></i> Reporte
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
