<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigoActual = (string)($user['rol_codigo'] ?? '');

$puedeVer = in_array($rolCodigoActual, ['superadmin', 'director'], true)
    || Auth::can('admin_sistema');

if (!$puedeVer) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Roles · Metis';
$pageSubtitle = 'Matriz funcional de perfiles, permisos y acceso a módulos';

function roles_table_exists(PDO $pdo, string $table): bool
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

function roles_column_exists(PDO $pdo, string $table, string $column): bool
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

function roles_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function roles_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $default;
}

function roles_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

$error = '';

$roles = [];
$usuariosPorRol = [];

$rolesFallback = [
    [
        'id' => 1,
        'codigo' => 'superadmin',
        'nombre' => 'Superadministrador',
        'descripcion' => 'Acceso completo al sistema, configuración, respaldo, auditoría y operación.',
        'activo' => 1,
    ],
    [
        'id' => 2,
        'codigo' => 'director',
        'nombre' => 'Director',
        'descripcion' => 'Acceso directivo a reportes, auditoría, seguimiento y administración institucional.',
        'activo' => 1,
    ],
    [
        'id' => 3,
        'codigo' => 'convivencia',
        'nombre' => 'Encargado de convivencia',
        'descripcion' => 'Gestión de denuncias, expedientes, seguimiento, alertas y evidencias.',
        'activo' => 1,
    ],
    [
        'id' => 4,
        'codigo' => 'docente',
        'nombre' => 'Docente',
        'descripcion' => 'Acceso operativo limitado a consulta o derivación según configuración institucional.',
        'activo' => 1,
    ],
    [
        'id' => 5,
        'codigo' => 'consulta',
        'nombre' => 'Consulta',
        'descripcion' => 'Perfil de visualización limitada sin administración del sistema.',
        'activo' => 1,
    ],
];

try {
    if (roles_table_exists($pdo, 'roles')) {
        $select = [
            roles_column_exists($pdo, 'roles', 'id') ? 'id' : 'NULL AS id',
            roles_column_exists($pdo, 'roles', 'codigo') ? 'codigo' : "'' AS codigo",
            roles_column_exists($pdo, 'roles', 'nombre') ? 'nombre' : "'' AS nombre",
            roles_column_exists($pdo, 'roles', 'descripcion') ? 'descripcion' : "'' AS descripcion",
            roles_column_exists($pdo, 'roles', 'activo') ? 'activo' : '1 AS activo',
            roles_column_exists($pdo, 'roles', 'created_at') ? 'created_at' : 'NULL AS created_at',
        ];

        $stmt = $pdo->query("
            SELECT " . implode(', ', $select) . "
            FROM roles
            ORDER BY id ASC
        ");

        $roles = $stmt->fetchAll();
    }

    if (!$roles) {
        $roles = $rolesFallback;
    }

    if (
        roles_table_exists($pdo, 'usuarios')
        && roles_table_exists($pdo, 'roles')
        && roles_column_exists($pdo, 'usuarios', 'rol_id')
        && roles_column_exists($pdo, 'roles', 'id')
    ) {
        $stmt = $pdo->query("
            SELECT
                COALESCE(r.codigo, 'sin_rol') AS codigo,
                COUNT(*) AS total
            FROM usuarios u
            LEFT JOIN roles r ON r.id = u.rol_id
            GROUP BY COALESCE(r.codigo, 'sin_rol')
        ");

        foreach ($stmt->fetchAll() as $row) {
            $usuariosPorRol[(string)$row['codigo']] = (int)$row['total'];
        }
    }
} catch (Throwable $e) {
    $error = 'Error al cargar roles: ' . $e->getMessage();
}

$modulos = [
    'dashboard' => [
        'nombre' => 'Dashboard',
        'descripcion' => 'Panel ejecutivo, salud del sistema y actividad reciente.',
    ],
    'denuncias' => [
        'nombre' => 'Denuncias',
        'descripcion' => 'Registro y revisión de expedientes de convivencia escolar.',
    ],
    'seguimiento' => [
        'nombre' => 'Seguimiento',
        'descripcion' => 'Gestión operativa, historial, control y acciones del caso.',
    ],
    'alertas' => [
        'nombre' => 'Alertas',
        'descripcion' => 'Control de alertas pendientes, resueltas y prioritarias.',
    ],
    'evidencias' => [
        'nombre' => 'Evidencias',
        'descripcion' => 'Repositorio y descarga segura de archivos vinculados a casos.',
    ],
    'reportes' => [
        'nombre' => 'Reportes',
        'descripcion' => 'Indicadores, estadísticas y exportaciones CSV.',
    ],
    'importar' => [
        'nombre' => 'Importar datos',
        'descripcion' => 'Carga o revisión de datos institucionales base.',
    ],
    'admin' => [
        'nombre' => 'Administración',
        'descripcion' => 'Panel institucional, usuarios, seguridad y herramientas.',
    ],
    'auditoria' => [
        'nombre' => 'Auditoría',
        'descripcion' => 'Bitácora de eventos, trazabilidad y exportación de logs.',
    ],
    'respaldo' => [
        'nombre' => 'Respaldo SQL',
        'descripcion' => 'Descarga de respaldo completo de la base activa.',
    ],
    'diagnostico' => [
        'nombre' => 'Diagnóstico',
        'descripcion' => 'Verificación técnica de rutas, tablas, columnas y storage.',
    ],
];

$permisosPorRol = [
    'superadmin' => [
        'dashboard',
        'denuncias',
        'seguimiento',
        'alertas',
        'evidencias',
        'reportes',
        'importar',
        'admin',
        'auditoria',
        'respaldo',
        'diagnostico',
    ],
    'director' => [
        'dashboard',
        'denuncias',
        'seguimiento',
        'alertas',
        'evidencias',
        'reportes',
        'admin',
        'auditoria',
        'respaldo',
        'diagnostico',
    ],
    'convivencia' => [
        'dashboard',
        'denuncias',
        'seguimiento',
        'alertas',
        'evidencias',
        'reportes',
    ],
    'encargado_convivencia' => [
        'dashboard',
        'denuncias',
        'seguimiento',
        'alertas',
        'evidencias',
        'reportes',
    ],
    'docente' => [
        'dashboard',
        'denuncias',
        'seguimiento',
    ],
    'consulta' => [
        'dashboard',
        'denuncias',
        'reportes',
    ],
    'usuario' => [
        'dashboard',
        'denuncias',
    ],
];

$totalRoles = count($roles);
$totalModulos = count($modulos);
$totalAccesos = 0;

foreach ($roles as $rol) {
    $codigo = (string)roles_pick($rol, ['codigo'], 'usuario');
    $totalAccesos += count($permisosPorRol[$codigo] ?? ['dashboard']);
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.roles-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #312e81 58%, #4f46e5 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.roles-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.roles-hero p {
    margin: 0;
    color: #ddd6fe;
    max-width: 880px;
    line-height: 1.55;
}

.roles-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.roles-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.roles-btn:hover {
    color: #fff;
}

.roles-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.roles-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.roles-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.roles-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 2rem;
    line-height: 1;
    margin-top: .35rem;
}

.roles-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.roles-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.roles-panel-title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    font-weight: 900;
}

.roles-panel-body {
    padding: 1.2rem;
}

.roles-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .9rem;
}

.roles-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
}

.roles-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .25rem;
}

.roles-card-text {
    color: #475569;
    line-height: 1.45;
    font-size: .86rem;
}

.roles-meta {
    color: #64748b;
    font-size: .76rem;
    margin-top: .45rem;
}

.roles-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .65rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .15rem;
}

.roles-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.roles-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.roles-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.roles-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.roles-table-scroll {
    width: 100%;
    overflow-x: auto;
}

.roles-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: .84rem;
}

.roles-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    padding: .75rem;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
    text-align: center;
}

.roles-table th:first-child {
    text-align: left;
    position: sticky;
    left: 0;
    background: #f8fafc;
    z-index: 2;
}

.roles-table td {
    padding: .75rem;
    border-bottom: 1px solid #f1f5f9;
    text-align: center;
    vertical-align: middle;
}

.roles-table td:first-child {
    text-align: left;
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 1;
    min-width: 230px;
}

.roles-access-ok {
    display: inline-grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #bbf7d0;
    font-weight: 900;
}

.roles-access-no {
    display: inline-grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    background: #f8fafc;
    color: #94a3b8;
    border: 1px solid #e2e8f0;
    font-weight: 900;
}

.roles-error {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 800;
}

.roles-note {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    margin-bottom: 1rem;
    font-size: .88rem;
}

@media (max-width: 1100px) {
    .roles-kpis,
    .roles-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .roles-kpis,
    .roles-grid {
        grid-template-columns: 1fr;
    }

    .roles-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="roles-hero">
    <h2>Roles y permisos</h2>
    <p>
        Matriz funcional de acceso por perfil. Esta vista permite revisar qué módulos
        puede utilizar cada rol dentro del sistema Metis y mantener trazabilidad del diseño de seguridad.
    </p>

    <div class="roles-actions">
        <a class="roles-btn" href="<?= APP_URL ?>/modules/admin/index.php">
            <i class="bi bi-arrow-left"></i>
            Administración
        </a>

        <a class="roles-btn" href="<?= APP_URL ?>/modules/auditoria/index.php">
            <i class="bi bi-list-check"></i>
            Auditoría
        </a>

        <a class="roles-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <i class="bi bi-shield-check"></i>
            Diagnóstico
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="roles-error">
        <i class="bi bi-exclamation-triangle"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="roles-kpis">
    <div class="roles-kpi">
        <span>Roles definidos</span>
        <strong><?= number_format($totalRoles, 0, ',', '.') ?></strong>
    </div>

    <div class="roles-kpi">
        <span>Módulos evaluados</span>
        <strong><?= number_format($totalModulos, 0, ',', '.') ?></strong>
    </div>

    <div class="roles-kpi">
        <span>Accesos habilitados</span>
        <strong><?= number_format($totalAccesos, 0, ',', '.') ?></strong>
    </div>

    <div class="roles-kpi">
        <span>Perfil actual</span>
        <strong style="font-size:1.25rem;"><?= e(roles_label($rolCodigoActual)) ?></strong>
    </div>
</section>

<section class="roles-panel">
    <div class="roles-panel-head">
        <h3 class="roles-panel-title">
            <i class="bi bi-person-badge"></i>
            Roles registrados
        </h3>
    </div>

    <div class="roles-panel-body">
        <div class="roles-grid">
            <?php foreach ($roles as $rol): ?>
                <?php
                $codigo = roles_pick($rol, ['codigo'], 'usuario');
                $nombre = roles_pick($rol, ['nombre'], roles_label($codigo));
                $descripcion = roles_pick($rol, ['descripcion'], 'Perfil funcional del sistema.');
                $activo = (int)($rol['activo'] ?? 1) === 1;
                $usuariosRol = $usuariosPorRol[$codigo] ?? 0;
                ?>

                <article class="roles-card">
                    <div class="roles-card-title">
                        <?= e($nombre) ?>
                    </div>

                    <div>
                        <span class="roles-badge soft">
                            Código: <?= e($codigo) ?>
                        </span>

                        <span class="roles-badge <?= $activo ? 'ok' : 'warn' ?>">
                            <?= $activo ? 'Activo' : 'Inactivo' ?>
                        </span>

                        <span class="roles-badge soft">
                            <?= number_format($usuariosRol, 0, ',', '.') ?> usuario(s)
                        </span>
                    </div>

                    <div class="roles-card-text" style="margin-top:.55rem;">
                        <?= e($descripcion) ?>
                    </div>

                    <?php if (!empty($rol['created_at'])): ?>
                        <div class="roles-meta">
                            Creado: <?= e(roles_fecha((string)$rol['created_at'])) ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="roles-panel">
    <div class="roles-panel-head">
        <h3 class="roles-panel-title">
            <i class="bi bi-grid-3x3-gap"></i>
            Matriz funcional de acceso
        </h3>
    </div>

    <div class="roles-panel-body">
        <div class="roles-note">
            Esta matriz documenta el comportamiento esperado de acceso por perfil.
            Si más adelante se implementa una tabla específica de permisos, esta vista podrá conectarse
            directamente a esa configuración sin cambiar el diseño visual.
        </div>

        <div class="roles-table-scroll">
            <table class="roles-table">
                <thead>
                    <tr>
                        <th>Módulo</th>

                        <?php foreach ($roles as $rol): ?>
                            <?php $codigo = roles_pick($rol, ['codigo'], 'usuario'); ?>
                            <th><?= e(roles_label($codigo)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($modulos as $moduloCodigo => $modulo): ?>
                        <tr>
                            <td>
                                <div style="font-weight:900;color:#0f172a;">
                                    <?= e($modulo['nombre']) ?>
                                </div>
                                <div class="roles-meta">
                                    <?= e($modulo['descripcion']) ?>
                                </div>
                            </td>

                            <?php foreach ($roles as $rol): ?>
                                <?php
                                $codigoRol = roles_pick($rol, ['codigo'], 'usuario');
                                $permitidos = $permisosPorRol[$codigoRol] ?? ['dashboard'];
                                $tieneAcceso = in_array($moduloCodigo, $permitidos, true);
                                ?>

                                <td>
                                    <?php if ($tieneAcceso): ?>
                                        <span class="roles-access-ok" title="Permitido">
                                            <i class="bi bi-check2"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="roles-access-no" title="No permitido">
                                            —
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="roles-panel">
    <div class="roles-panel-head">
        <h3 class="roles-panel-title">
            <i class="bi bi-shield-lock"></i>
            Criterio de seguridad recomendado
        </h3>
    </div>

    <div class="roles-panel-body">
        <div class="roles-grid">
            <article class="roles-card">
                <div class="roles-card-title">Superadministrador</div>
                <div class="roles-card-text">
                    Debe quedar reservado para soporte técnico o administración superior.
                    Tiene acceso a diagnóstico, respaldo, auditoría y módulos críticos.
                </div>
            </article>

            <article class="roles-card">
                <div class="roles-card-title">Director</div>
                <div class="roles-card-text">
                    Puede revisar reportes, auditoría y respaldos institucionales, además de acceder
                    a expedientes relevantes para la gestión escolar.
                </div>
            </article>

            <article class="roles-card">
                <div class="roles-card-title">Convivencia escolar</div>
                <div class="roles-card-text">
                    Perfil operativo principal para registrar denuncias, gestionar seguimiento,
                    evidencias, alertas y revisión del expediente.
                </div>
            </article>

            <article class="roles-card">
                <div class="roles-card-title">Consulta o docente</div>
                <div class="roles-card-text">
                    Debe tener acceso limitado y solo a funciones expresamente autorizadas
                    por el establecimiento.
                </div>
            </article>
        </div>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>