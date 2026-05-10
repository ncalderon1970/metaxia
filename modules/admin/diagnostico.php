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

$puedeVer = Auth::canOperate();

if (!$puedeVer) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Diagnóstico · Metis';
$pageSubtitle = 'Revisión técnica de módulos, rutas, tablas y compatibilidad del sistema';

$basePath = realpath(dirname(__DIR__, 2));

if ($basePath === false) {
    http_response_code(500);
    exit('No fue posible resolver la ruta base del proyecto.');
}

function diag_status(bool $ok): string
{
    return $ok ? 'ok' : 'danger';
}

function diag_label(bool $ok): string
{
    return $ok ? 'Correcto' : 'Revisar';
}

function diag_relative(string $basePath, string $path): string
{
    $path = str_replace('\\', '/', $path);
    $basePath = str_replace('\\', '/', $basePath);

    return ltrim(str_replace($basePath, '', $path), '/');
}

function diag_skip_path(string $path): bool
{
    $normal = str_replace('\\', '/', $path);

    $skips = [
        '/vendor/',
        '/storage/',
        '/modules/dashboard/respaldo/',
        '/modules/dashboard/backup/',
        '/modules/dashboard/old/',
    ];

    foreach ($skips as $skip) {
        if (str_contains($normal, $skip)) {
            return true;
        }
    }

    return false;
}

function diag_php_files(string $basePath): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        if (diag_skip_path($path)) {
            continue;
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

function diag_find_pattern(string $basePath, string $pattern): array
{
    $matches = [];

    foreach (diag_php_files($basePath) as $file) {
        $content = @file_get_contents($file);

        if ($content === false) {
            continue;
        }

        if (str_contains($content, $pattern)) {
            $matches[] = diag_relative($basePath, $file);
        }
    }

    return $matches;
}

function diag_find_static_routes(string $basePath): array
{
    $routes = [];

    foreach (diag_php_files($basePath) as $file) {
        $content = @file_get_contents($file);

        if ($content === false) {
            continue;
        }

        preg_match_all('/modules\/[a-zA-Z0-9_\-\/]+\.php/', $content, $matches);

        foreach ($matches[0] ?? [] as $route) {
            $route = trim($route);

            if ($route === '') {
                continue;
            }

            if (str_contains($route, 'dashboard/respaldo/')) {
                continue;
            }

            $routes[$route][] = diag_relative($basePath, $file);
        }
    }

    ksort($routes);

    return $routes;
}

function diag_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function diag_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$tablasCriticas = [
    'colegios',
    'usuarios',
    'roles',
    'estado_caso',
    'casos',
    'caso_historial',
    'caso_participantes',
    'caso_declaraciones',
    'caso_evidencias',
    'caso_alertas',
    'logs_sistema',
];

$columnasCriticas = [
    'casos' => [
        'id',
        'colegio_id',
        'numero_caso',
        'fecha_ingreso',
        'relato',
        'estado',
        'estado_caso_id',
        'semaforo',
        'prioridad',
        'requiere_reanalisis_ia',
        'created_at',
        'updated_at',
    ],
    'caso_declaraciones' => [
        'id',
        'caso_id',
        'participante_id',
        'nombre_declarante',
        'run_declarante',
        'calidad_procesal',
        'texto_declaracion',
        'requiere_reanalisis_ia',
        'tomada_por',
        'created_at',
    ],
    'caso_evidencias' => [
        'id',
        'caso_id',
        'tipo',
        'nombre_archivo',
        'ruta',
        'mime_type',
        'descripcion',
        'subido_por',
        'created_at',
    ],
    'caso_alertas' => [
        'id',
        'caso_id',
        'tipo',
        'mensaje',
        'prioridad',
        'estado',
        'fecha_alerta',
        'resuelta_por',
        'resuelta_at',
        'created_at',
    ],
    'logs_sistema' => [
        'id',
        'colegio_id',
        'usuario_id',
        'modulo',
        'accion',
        'entidad',
        'entidad_id',
        'descripcion',
        'ip',
        'user_agent',
        'created_at',
    ],
];

$coreCritico = [
    'config/app.php',
    'core/DB.php',
    'core/Auth.php',
    'core/CSRF.php',
    'core/helpers.php',
    'core/layout_header.php',
    'core/layout_footer.php',
];

$modulosCriticos = [
    'modules/dashboard/index.php',
    'modules/denuncias/index.php',
    'modules/denuncias/crear.php',
    'modules/denuncias/guardar.php',
    'modules/denuncias/ver.php',
    'modules/seguimiento/index.php',
    'modules/seguimiento/abrir.php',
    'modules/alertas/index.php',
    'modules/evidencias/index.php',
    'modules/evidencias/descargar.php',
    'modules/reportes/index.php',
    'modules/reportes/exportar_csv.php',
    'modules/importar/index.php',
    'modules/admin/index.php',
    'modules/admin/diagnostico.php',
    'modules/auditoria/index.php',
    'modules/auditoria/exportar_csv.php',
    'modules/roles/index.php',
];

$tablas = [];

foreach ($tablasCriticas as $tabla) {
    $tablas[$tabla] = diag_table_exists($pdo, $tabla);
}

$columnas = [];

foreach ($columnasCriticas as $tabla => $cols) {
    foreach ($cols as $col) {
        $columnas[] = [
            'tabla' => $tabla,
            'columna' => $col,
            'ok' => diag_column_exists($pdo, $tabla, $col),
        ];
    }
}

$core = [];

foreach ($coreCritico as $archivo) {
    $core[] = [
        'ruta' => $archivo,
        'ok' => is_file($basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archivo)),
    ];
}

$modulos = [];

foreach ($modulosCriticos as $archivo) {
    $modulos[] = [
        'ruta' => $archivo,
        'ok' => is_file($basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archivo)),
    ];
}

$tokenDbAntiguo = 'get' . 'DB(';
$tokenCsrfAntiguo = 'CSRF' . '::start(';

$archivosGetDb = diag_find_pattern($basePath, $tokenDbAntiguo);
$archivosCsrfStart = diag_find_pattern($basePath, $tokenCsrfAntiguo);

$staticRoutes = diag_find_static_routes($basePath);
$rutasRotas = [];

foreach ($staticRoutes as $route => $origenes) {
    $target = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route);

    if (!is_file($target)) {
        $rutasRotas[] = [
            'ruta' => $route,
            'origenes' => $origenes,
        ];
    }
}

$storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'evidencias';

if (!is_dir($storagePath)) {
    @mkdir($storagePath, 0775, true);
}

$storageExiste = is_dir($storagePath);
$storageEscribible = is_writable($storagePath);

$totalProblemas = 0;

foreach ($tablas as $ok) {
    if (!$ok) {
        $totalProblemas++;
    }
}

foreach ($columnas as $col) {
    if (!$col['ok']) {
        $totalProblemas++;
    }
}

foreach ($core as $c) {
    if (!$c['ok']) {
        $totalProblemas++;
    }
}

foreach ($modulos as $m) {
    if (!$m['ok']) {
        $totalProblemas++;
    }
}

$totalProblemas += count($archivosGetDb);
$totalProblemas += count($archivosCsrfStart);
$totalProblemas += count($rutasRotas);

if (!$storageExiste || !$storageEscribible) {
    $totalProblemas++;
}

$phpFilesCount = count(diag_php_files($basePath));

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.diag-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e293b 58%, #334155 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.diag-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.8rem;
    font-weight: 900;
}

.diag-hero p {
    margin: 0;
    color: #cbd5e1;
    max-width: 850px;
    line-height: 1.55;
}

.diag-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.diag-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.diag-btn:hover {
    color: #fff;
}

.diag-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.diag-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.diag-kpi span {
    color: #64748b;
    display: block;
    font-size: .7rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.diag-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 2rem;
    line-height: 1;
    margin-top: .35rem;
}

.diag-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.diag-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
}

.diag-panel-title {
    margin: 0;
    color: #0f172a;
    font-weight: 900;
    font-size: 1rem;
}

.diag-panel-body {
    padding: 1.2rem;
}

.diag-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.diag-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: .85rem 1rem;
    margin-bottom: .6rem;
}

.diag-name {
    color: #0f172a;
    font-weight: 900;
    word-break: break-word;
}

.diag-meta {
    color: #64748b;
    font-size: .76rem;
    margin-top: .2rem;
    line-height: 1.35;
}

.diag-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .65rem;
    font-size: .72rem;
    font-weight: 900;
    white-space: nowrap;
    border: 1px solid #e2e8f0;
}

.diag-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.diag-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.diag-empty {
    text-align: center;
    color: #94a3b8;
    padding: 2rem 1rem;
}

.diag-warning {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .9rem 1rem;
    font-weight: 800;
    margin-bottom: 1rem;
}

@media (max-width: 1000px) {
    .diag-kpis,
    .diag-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="diag-hero">
    <h2>Diagnóstico técnico del sistema</h2>
    <p>
        Revisión de archivos, rutas, tablas, columnas críticas, código antiguo y permisos de almacenamiento.
        Las carpetas de respaldo del dashboard se excluyen del análisis para evitar falsos positivos.
    </p>

    <div class="diag-actions">
        <a class="diag-btn" href="<?= APP_URL ?>/modules/admin/index.php">
            <i class="bi bi-arrow-left"></i>
            Volver a administración
        </a>

        <a class="diag-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <i class="bi bi-arrow-clockwise"></i>
            Ejecutar nuevamente
        </a>
    </div>
</section>

<?php if ($totalProblemas > 0): ?>
    <div class="diag-warning">
        Se detectaron <?= number_format($totalProblemas, 0, ',', '.') ?> punto(s) que requieren revisión.
    </div>
<?php endif; ?>

<section class="diag-kpis">
    <div class="diag-kpi">
        <span>Problemas detectados</span>
        <strong><?= number_format($totalProblemas, 0, ',', '.') ?></strong>
    </div>

    <div class="diag-kpi">
        <span>Archivos PHP revisados</span>
        <strong><?= number_format($phpFilesCount, 0, ',', '.') ?></strong>
    </div>

    <div class="diag-kpi">
        <span>Rutas rotas</span>
        <strong><?= number_format(count($rutasRotas), 0, ',', '.') ?></strong>
    </div>

    <div class="diag-kpi">
        <span>Código antiguo</span>
        <strong><?= number_format(count($archivosGetDb) + count($archivosCsrfStart), 0, ',', '.') ?></strong>
    </div>
</section>

<div class="diag-grid">
    <section class="diag-panel">
        <div class="diag-panel-head">
            <h3 class="diag-panel-title">Core del sistema</h3>
        </div>

        <div class="diag-panel-body">
            <?php foreach ($core as $item): ?>
                <div class="diag-item">
                    <div class="diag-name"><?= e($item['ruta']) ?></div>
                    <span class="diag-badge <?= e(diag_status($item['ok'])) ?>">
                        <?= e(diag_label($item['ok'])) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="diag-panel">
        <div class="diag-panel-head">
            <h3 class="diag-panel-title">Módulos principales</h3>
        </div>

        <div class="diag-panel-body">
            <?php foreach ($modulos as $item): ?>
                <div class="diag-item">
                    <div class="diag-name"><?= e($item['ruta']) ?></div>
                    <span class="diag-badge <?= e(diag_status($item['ok'])) ?>">
                        <?= e(diag_label($item['ok'])) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="diag-grid">
    <section class="diag-panel">
        <div class="diag-panel-head">
            <h3 class="diag-panel-title">Tablas críticas</h3>
        </div>

        <div class="diag-panel-body">
            <?php foreach ($tablas as $tabla => $ok): ?>
                <div class="diag-item">
                    <div class="diag-name"><?= e($tabla) ?></div>
                    <span class="diag-badge <?= e(diag_status($ok)) ?>">
                        <?= e(diag_label($ok)) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="diag-panel">
        <div class="diag-panel-head">
            <h3 class="diag-panel-title">Storage evidencias</h3>
        </div>

        <div class="diag-panel-body">
            <div class="diag-item">
                <div>
                    <div class="diag-name">storage/evidencias</div>
                    <div class="diag-meta"><?= e($storagePath) ?></div>
                </div>

                <span class="diag-badge <?= e(diag_status($storageExiste && $storageEscribible)) ?>">
                    <?= e(($storageExiste && $storageEscribible) ? 'Escribible' : 'Revisar') ?>
                </span>
            </div>
        </div>
    </section>
</div>

<section class="diag-panel">
    <div class="diag-panel-head">
        <h3 class="diag-panel-title">Columnas críticas</h3>
    </div>

    <div class="diag-panel-body">
        <div class="diag-grid">
            <?php foreach ($columnas as $col): ?>
                <div class="diag-item">
                    <div class="diag-name"><?= e($col['tabla']) ?>.<?= e($col['columna']) ?></div>
                    <span class="diag-badge <?= e(diag_status($col['ok'])) ?>">
                        <?= e(diag_label($col['ok'])) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="diag-panel">
    <div class="diag-panel-head">
        <h3 class="diag-panel-title">Código antiguo detectado</h3>
    </div>

    <div class="diag-panel-body">
        <?php if (!$archivosGetDb && !$archivosCsrfStart): ?>
            <div class="diag-empty">
                No se detectó código antiguo.
            </div>
        <?php else: ?>
            <?php foreach ($archivosGetDb as $file): ?>
                <div class="diag-item">
                    <div>
                        <div class="diag-name"><?= e($file) ?></div>
                        <div class="diag-meta">Contiene llamada antigua de base de datos. Debe migrarse a DB::conn().</div>
                    </div>
                    <span class="diag-badge danger">Revisar</span>
                </div>
            <?php endforeach; ?>

            <?php foreach ($archivosCsrfStart as $file): ?>
                <div class="diag-item">
                    <div>
                        <div class="diag-name"><?= e($file) ?></div>
                        <div class="diag-meta">Contiene llamada antigua CSRF. Debe migrarse a CSRF::field() / CSRF::requireValid().</div>
                    </div>
                    <span class="diag-badge danger">Revisar</span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="diag-panel">
    <div class="diag-panel-head">
        <h3 class="diag-panel-title">Rutas internas rotas</h3>
    </div>

    <div class="diag-panel-body">
        <?php if (!$rutasRotas): ?>
            <div class="diag-empty">
                No se detectaron rutas internas rotas.
            </div>
        <?php else: ?>
            <?php foreach ($rutasRotas as $ruta): ?>
                <div class="diag-item">
                    <div>
                        <div class="diag-name"><?= e($ruta['ruta']) ?></div>
                        <div class="diag-meta">
                            Referenciada desde: <?= e(implode(', ', array_slice($ruta['origenes'], 0, 5))) ?>
                        </div>
                    </div>
                    <span class="diag-badge danger">Revisar</span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>