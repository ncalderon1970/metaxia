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

$puedeAdmin = in_array($rolCodigo, ['superadmin', 'director'], true)
    || Auth::can('admin_sistema');

if (!$puedeAdmin) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Administración · Metis';
$pageSubtitle = 'Centro administrativo del sistema, colegios, usuarios, controles y operación técnica';

function adm_table_exists(PDO $pdo, string $table): bool
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

function adm_column_exists(PDO $pdo, string $table, string $column): bool
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

function adm_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!adm_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`';

        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function adm_sum(PDO $pdo, string $table, string $column, ?string $where = null, array $params = []): float
{
    if (!adm_table_exists($pdo, $table) || !adm_column_exists($pdo, $table, $column)) {
        return 0.0;
    }

    try {
        $sql = 'SELECT COALESCE(SUM(`' . str_replace('`', '``', $column) . '`), 0) FROM `' . str_replace('`', '``', $table) . '`';

        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

$totalColegios = adm_count($pdo, 'colegios');
$totalColegiosActivos = adm_count($pdo, 'colegios', 'activo = 1');
$totalColegiosVencidos = adm_count($pdo, 'colegios', 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()');
$totalColegiosPorVencer = adm_count($pdo, 'colegios', 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)');
$mrrUf = adm_sum($pdo, 'colegios', 'precio_uf_mensual', "activo = 1 AND estado_comercial IN ('activo', 'demo')");

$totalUsuarios = adm_count($pdo, 'usuarios');
$totalUsuariosActivos = adm_count($pdo, 'usuarios', 'activo = 1');
$totalRoles = adm_count($pdo, 'roles');
$totalPermisos = adm_count($pdo, 'permisos');
$totalEventosHoy = adm_count($pdo, 'logs_sistema', 'DATE(created_at) = CURDATE()');

$totalPruebas = adm_count($pdo, 'pruebas_integrales');
$pruebasOk = adm_count($pdo, 'pruebas_integrales', "estado = 'ok'");
$pruebasObservadas = adm_count($pdo, 'pruebas_integrales', "estado = 'observado'");

$totalPreprod = adm_count($pdo, 'checklist_preproduccion');
$preprodOk = adm_count($pdo, 'checklist_preproduccion', "estado = 'ok'");
$preprodObservados = adm_count($pdo, 'checklist_preproduccion', "estado = 'observado'");

$avancePruebas = $totalPruebas > 0 ? round(($pruebasOk / $totalPruebas) * 100) : 0;
$avancePreprod = $totalPreprod > 0 ? round(($preprodOk / $totalPreprod) * 100) : 0;

$bloqueadores = $pruebasObservadas + $preprodObservados + $totalColegiosVencidos;

$herramientas = [
    [
        'titulo' => 'Colegios',
        'texto' => 'Administrar establecimientos, planes, vigencias, límites y datos institucionales.',
        'icono' => 'bi-building',
        'url' => APP_URL . '/modules/colegios/index.php',
        'tag' => $totalColegios . ' colegio(s)',
        'class' => 'green',
    ],
    [
        'titulo' => 'Usuarios',
        'texto' => 'Crear usuarios, asignar colegio, rol y estado de acceso.',
        'icono' => 'bi-person-gear',
        'url' => APP_URL . '/modules/admin/usuarios.php',
        'tag' => $totalUsuarios . ' usuario(s)',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Panel financiero',
        'texto' => 'Controlar MRR, ARR, colegios vencidos, planes y riesgo comercial.',
        'icono' => 'bi-cash-coin',
        'url' => APP_URL . '/modules/admin/financiero.php',
        'tag' => 'MRR ' . number_format($mrrUf, 2, ',', '.') . ' UF',
        'class' => $mrrUf > 0 ? 'green' : 'blue',
    ],
    [
        'titulo' => 'Centro de control',
        'texto' => 'Ver avance global, bloqueadores, pruebas integrales y preproducción.',
        'icono' => 'bi-kanban',
        'url' => APP_URL . '/modules/admin/control_proyecto.php',
        'tag' => $bloqueadores . ' alerta(s)',
        'class' => $bloqueadores > 0 ? 'warn' : 'green',
    ],
    [
        'titulo' => 'Pruebas integrales',
        'texto' => 'Controlar validación funcional por área antes de pasar a producción.',
        'icono' => 'bi-clipboard2-check',
        'url' => APP_URL . '/modules/admin/pruebas_integrales.php',
        'tag' => $avancePruebas . '% avance',
        'class' => $avancePruebas >= 80 ? 'green' : 'blue',
    ],
    [
        'titulo' => 'Checklist preproducción',
        'texto' => 'Controlar hosting, base de datos, SSL, respaldos, seguridad y operación.',
        'icono' => 'bi-rocket-takeoff',
        'url' => APP_URL . '/modules/admin/preproduccion.php',
        'tag' => $avancePreprod . '% avance',
        'class' => $avancePreprod >= 80 ? 'green' : 'blue',
    ],
    [
        'titulo' => 'Diagnóstico técnico',
        'texto' => 'Revisar rutas rotas, código antiguo, tablas críticas y salud general.',
        'icono' => 'bi-shield-check',
        'url' => APP_URL . '/modules/admin/diagnostico.php',
        'tag' => 'Control',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Respaldo SQL',
        'texto' => 'Generar respaldo descargable de la base activa antes de cambios críticos.',
        'icono' => 'bi-database-down',
        'url' => APP_URL . '/modules/admin/respaldo.php',
        'tag' => 'Backup',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Manual operativo',
        'texto' => 'Guía interna de uso del sistema para operación, reportes y administración.',
        'icono' => 'bi-journal-text',
        'url' => APP_URL . '/modules/admin/manual_usuario.php',
        'tag' => 'Manual',
        'class' => 'green',
    ],
    [
        'titulo' => 'Roles',
        'texto' => 'Revisar perfiles disponibles y permisos generales del sistema.',
        'icono' => 'bi-person-badge',
        'url' => APP_URL . '/modules/roles/index.php',
        'tag' => $totalRoles . ' rol(es)',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Permisos',
        'texto' => 'Administrar matriz de permisos por rol para controlar acciones críticas.',
        'icono' => 'bi-sliders',
        'url' => APP_URL . '/modules/admin/permisos.php',
        'tag' => $totalPermisos . ' permiso(s)',
        'class' => 'warn',
    ],
    [
        'titulo' => 'Auditoría',
        'texto' => 'Consultar bitácora de eventos, cambios y trazabilidad operacional.',
        'icono' => 'bi-activity',
        'url' => APP_URL . '/modules/auditoria/index.php',
        'tag' => $totalEventosHoy . ' hoy',
        'class' => 'blue',
    ],
];

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.adm-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.adm-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.9rem;
    font-weight: 900;
}

.adm-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 920px;
    line-height: 1.55;
}

.adm-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.adm-btn {
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

.adm-btn:hover {
    color: #fff;
}

.adm-kpis {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.adm-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.adm-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.adm-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.8rem;
    line-height: 1;
    margin-top: .35rem;
}

.adm-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.adm-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.adm-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.adm-panel-body {
    padding: 1.2rem;
}

.adm-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .9rem;
}

.adm-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .9rem;
    align-items: start;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    text-decoration: none;
    color: inherit;
}

.adm-card:hover {
    background: #f1f5f9;
}

.adm-icon {
    width: 46px;
    height: 46px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    font-size: 1.25rem;
}

.adm-icon.green {
    background: #ecfdf5;
    color: #047857;
}

.adm-icon.blue {
    background: #eff6ff;
    color: #1d4ed8;
}

.adm-icon.warn {
    background: #fffbeb;
    color: #92400e;
}

.adm-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .2rem;
}

.adm-card-text {
    color: #64748b;
    font-size: .8rem;
    line-height: 1.4;
}

.adm-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .62rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin-top: .5rem;
}

.adm-badge.green {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.adm-badge.blue {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.adm-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.adm-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 16px;
    padding: 1rem;
    line-height: 1.5;
    font-size: .9rem;
}

@media (max-width: 1200px) {
    .adm-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .adm-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .adm-kpis,
    .adm-grid {
        grid-template-columns: 1fr;
    }

    .adm-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="adm-hero">
    <h2>Hub de administración</h2>
    <p>
        Centro de control administrativo para colegios, usuarios, roles, pruebas integrales,
        preproducción, diagnóstico, respaldos, auditoría y documentación operativa.
    </p>

    <div class="adm-actions">
        <a class="adm-btn" href="<?= APP_URL ?>/modules/colegios/index.php">
            <i class="bi bi-building"></i>
            Colegios
        </a>

        <a class="adm-btn" href="<?= APP_URL ?>/modules/admin/usuarios.php">
            <i class="bi bi-person-gear"></i>
            Usuarios
        </a>


        <a class="adm-btn" href="<?= APP_URL ?>/modules/admin/financiero.php">
            <i class="bi bi-cash-coin"></i>
            Financiero
        </a>

        <a class="adm-btn" href="<?= APP_URL ?>/modules/admin/permisos.php">
            <i class="bi bi-sliders"></i>
            Permisos
        </a>

        <a class="adm-btn" href="<?= APP_URL ?>/modules/admin/control_proyecto.php">
            <i class="bi bi-kanban"></i>
            Centro de control
        </a>

        <a class="adm-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <i class="bi bi-shield-check"></i>
            Diagnóstico
        </a>

        <a class="adm-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>
    </div>
</section>

<section class="adm-kpis">
    <div class="adm-kpi">
        <span>Colegios</span>
        <strong><?= number_format($totalColegios, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Colegios activos</span>
        <strong style="color:#047857;"><?= number_format($totalColegiosActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Vencidos</span>
        <strong style="color:#b91c1c;"><?= number_format($totalColegiosVencidos, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Por vencer</span>
        <strong style="color:#92400e;"><?= number_format($totalColegiosPorVencer, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Usuarios activos</span>
        <strong><?= number_format($totalUsuariosActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>MRR UF</span>
        <strong><?= number_format($mrrUf, 2, ',', '.') ?></strong>
    </div>
</section>

<section class="adm-kpis">
    <div class="adm-kpi">
        <span>Pruebas integrales</span>
        <strong><?= number_format($avancePruebas, 0, ',', '.') ?>%</strong>
    </div>

    <div class="adm-kpi">
        <span>Preproducción</span>
        <strong><?= number_format($avancePreprod, 0, ',', '.') ?>%</strong>
    </div>

    <div class="adm-kpi">
        <span>Observados</span>
        <strong style="color:<?= $bloqueadores > 0 ? '#b91c1c' : '#047857' ?>;">
            <?= number_format($bloqueadores, 0, ',', '.') ?>
        </strong>
    </div>

    <div class="adm-kpi">
        <span>Usuarios</span>
        <strong><?= number_format($totalUsuarios, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Roles</span>
        <strong><?= number_format($totalRoles, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Eventos hoy</span>
        <strong><?= number_format($totalEventosHoy, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="adm-panel">
    <div class="adm-panel-head">
        <h3 class="adm-panel-title">
            <i class="bi bi-grid"></i>
            Herramientas administrativas
        </h3>
    </div>

    <div class="adm-panel-body">
        <div class="adm-grid">
            <?php foreach ($herramientas as $item): ?>
                <a class="adm-card" href="<?= e($item['url']) ?>">
                    <div class="adm-icon <?= e($item['class']) ?>">
                        <i class="bi <?= e($item['icono']) ?>"></i>
                    </div>

                    <div>
                        <div class="adm-card-title"><?= e($item['titulo']) ?></div>
                        <div class="adm-card-text"><?= e($item['texto']) ?></div>
                        <span class="adm-badge <?= e($item['class']) ?>">
                            <?= e($item['tag']) ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="adm-panel">
    <div class="adm-panel-head">
        <h3 class="adm-panel-title">
            <i class="bi bi-info-circle"></i>
            Recomendación de uso
        </h3>
    </div>

    <div class="adm-panel-body">
        <div class="adm-note">
            Antes de pasar a producción, mantén el siguiente orden: colegios configurados,
            usuarios y roles revisados, pruebas integrales ejecutadas, checklist de preproducción
            validado, diagnóstico técnico en cero y respaldo SQL generado.
        </div>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
