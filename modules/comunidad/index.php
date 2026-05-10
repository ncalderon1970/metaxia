<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

$pageTitle = 'Comunidad Educativa · Metis';
$pageSubtitle = 'Consulta, edición, apoderados y control de estado de comunidad educativa';

function com_table_exists(PDO $pdo, string $table): bool
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

function com_column_exists(PDO $pdo, string $table, string $column): bool
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

function com_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function com_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y', $ts) : $value;
}

function com_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $default;
}

function com_nombre_completo(array $row): string
{
    $partes = [];

    foreach (['nombres', 'apellido_paterno', 'apellido_materno'] as $key) {
        if (!empty($row[$key])) {
            $partes[] = trim((string)$row[$key]);
        }
    }

    $nombre = trim(implode(' ', $partes));

    return $nombre !== '' ? $nombre : 'Sin nombre';
}

function com_count(PDO $pdo, string $table, int $colegioId): int
{
    if (!com_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        if (com_column_exists($pdo, $table, 'colegio_id')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . com_quote($table) . " WHERE colegio_id = ?");
            $stmt->execute([$colegioId]);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM " . com_quote($table));
        }

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$tipos = [
    'alumnos' => [
        'label' => 'Alumnos',
        'singular' => 'Alumno',
        'icon' => 'bi-mortarboard',
        'descripcion' => 'Estudiantes registrados en el establecimiento.',
    ],
    'apoderados' => [
        'label' => 'Apoderados',
        'singular' => 'Apoderado',
        'icon' => 'bi-people',
        'descripcion' => 'Adultos responsables y contactos familiares.',
    ],
    'docentes' => [
        'label' => 'Docentes',
        'singular' => 'Docente',
        'icon' => 'bi-person-video3',
        'descripcion' => 'Profesores y profesionales docentes.',
    ],
    'asistentes' => [
        'label' => 'Asistentes',
        'singular' => 'Asistente',
        'icon' => 'bi-person-workspace',
        'descripcion' => 'Asistentes de la educación y funcionarios de apoyo.',
    ],
];

$tipo = clean((string)($_GET['tipo'] ?? 'alumnos'));

if (!array_key_exists($tipo, $tipos)) {
    $tipo = 'alumnos';
}

$q = clean((string)($_GET['q'] ?? ''));
$activo = clean((string)($_GET['activo'] ?? 'todos'));
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));

$error = '';
$registros = [];
$totalTipo = 0;
$tablaExiste = com_table_exists($pdo, $tipo);

$kpis = [];

foreach ($tipos as $tabla => $info) {
    $kpis[$tabla] = [
        'existe' => com_table_exists($pdo, $tabla),
        'total' => com_count($pdo, $tabla, $colegioId),
    ];
}

try {
    if ($tablaExiste) {
        $where = [];
        $params = [];

        if (com_column_exists($pdo, $tipo, 'colegio_id')) {
            $where[] = 'colegio_id = ?';
            $params[] = $colegioId;
        }

        if ($activo !== 'todos' && com_column_exists($pdo, $tipo, 'activo')) {
            $where[] = 'activo = ?';
            $params[] = $activo === 'activos' ? 1 : 0;
        }

        if ($q !== '') {
            $searchParts = [];

            foreach (['run', 'nombres', 'apellido_paterno', 'apellido_materno', 'email', 'telefono', 'curso', 'especialidad', 'cargo'] as $col) {
                if (com_column_exists($pdo, $tipo, $col)) {
                    $searchParts[] = com_quote($col) . ' LIKE ?';
                    $params[] = '%' . $q . '%';
                }
            }

            if ($searchParts) {
                $where[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("
            SELECT COUNT(*)
            FROM " . com_quote($tipo) . "
            {$whereSql}
        ");
        $stmtCount->execute($params);
        $totalTipo = (int)$stmtCount->fetchColumn();

        $orderParts = [];

        if (com_column_exists($pdo, $tipo, 'activo')) {
            $orderParts[] = 'activo DESC';
        }

        if (com_column_exists($pdo, $tipo, 'apellido_paterno')) {
            $orderParts[] = 'apellido_paterno ASC';
        }

        if (com_column_exists($pdo, $tipo, 'apellido_materno')) {
            $orderParts[] = 'apellido_materno ASC';
        }

        if (com_column_exists($pdo, $tipo, 'nombres')) {
            $orderParts[] = 'nombres ASC';
        }

        if (!$orderParts && com_column_exists($pdo, $tipo, 'id')) {
            $orderParts[] = 'id DESC';
        }

        $orderSql = $orderParts ? 'ORDER BY ' . implode(', ', $orderParts) : '';

        $stmt = $pdo->prepare("
            SELECT *
            FROM " . com_quote($tipo) . "
            {$whereSql}
            {$orderSql}
            LIMIT 300
        ");
        $stmt->execute($params);
        $registros = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = 'Error al cargar comunidad educativa: ' . $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.com-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.com-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.com-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 900px;
    line-height: 1.55;
}

.com-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.com-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius:7px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.com-btn.green {
    background: #059669;
    border-color: #10b981;
}

.com-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.com-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.com-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.com-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 2rem;
    line-height: 1;
    margin-top: .35rem;
}

.com-tabs {
    display: flex;
    gap: .35rem;
    flex-wrap: wrap;
    margin-bottom: 1.2rem;
}

.com-tab {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .65rem .9rem;
    border-radius:7px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    font-weight: 900;
    font-size: .84rem;
    text-decoration: none;
}

.com-tab.active {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
}

.com-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius:7px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.com-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.com-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.com-panel-body {
    padding: 1.2rem;
}

.com-filter {
    display: grid;
    grid-template-columns: 1.3fr .75fr auto auto;
    gap: .8rem;
    align-items: end;
}

.com-label {
    display: block;
    font-size: .76rem;
    font-weight: 900;
    color: #334155;
    margin-bottom: .35rem;
}

.com-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .65rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.com-submit,
.com-link,
.com-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .28rem;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #374151;
    border-radius: 6px;
    padding: .28rem .65rem;
    font-weight: 600;
    font-size: .73rem;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
    line-height: 1.4;
    transition: all .12s;
}

.com-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.com-link.green {
    background: #ecfdf5;
    color: #047857;
    border-color: #bbf7d0;
}

.com-link.dark {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
}

.com-link.blue {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}

.com-action-btn.red {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.com-action-btn.green {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #bbf7d0;
}

.com-table-scroll {
    width: 100%;
    overflow-x: auto;
    overflow-y: auto;
    max-height: 620px; /* ~10 filas a ~62px cada una */
    border: 1px solid #e2e8f0;
    border-radius: 14px;
}

/* Scrollbar estilizada */
.com-table-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
.com-table-scroll::-webkit-scrollbar-track { background: #f8fafc; border-radius: 99px; }
.com-table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
.com-table-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.com-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: .86rem;
}

.com-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    padding: .75rem;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 2;
    box-shadow: 0 1px 0 #e2e8f0;
}

.com-table td {
    padding: .85rem .75rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.com-main {
    color: #0f172a;
    font-weight: 900;
}

.com-muted {
    color: #64748b;
    font-size: .76rem;
    margin-top: .15rem;
}

.com-badge {
    display: inline-flex;
    align-items: center;
    border-radius:7px;
    padding: .24rem .62rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
}

.com-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.com-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.com-actions-row {
    display: flex;
    gap: .3rem;
    flex-wrap: nowrap;
    align-items: center;
}

.com-actions-row form {
    margin: 0;
}

.com-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}

.com-error {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 800;
}

.com-ok {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
    font-weight: 800;
}

.com-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
}

@media (max-width: 1100px) {
    .com-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .com-filter {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 720px) {
    .com-kpis,
    .com-filter {
        grid-template-columns: 1fr;
    }

    .com-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="com-hero">
    <h2>Comunidad educativa</h2>
    <p>
        Consulta central de estudiantes, apoderados, docentes y asistentes registrados.
        En alumnos, puedes gestionar sus apoderados vinculados, contactos de emergencia y autorizaciones de retiro.
    </p>

    <div class="com-actions">
        <?php if ($puedeGestionar): ?>
            <a class="com-btn green" href="<?= APP_URL ?>/modules/comunidad/crear.php?tipo=<?= e($tipo) ?>">
                <i class="bi bi-plus-circle"></i>
                Nuevo <?= e($tipos[$tipo]['singular']) ?>
            </a>
        <?php endif; ?>

        <a class="com-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>

        <a class="com-btn" href="<?= APP_URL ?>/modules/importar/index.php">
            <i class="bi bi-file-earmark-arrow-up"></i>
            Importar datos
        </a>

        <a class="com-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-megaphone"></i>
            Denuncias
        </a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="com-ok">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="com-error">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="com-error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="com-kpis">
    <?php foreach ($tipos as $key => $info): ?>
        <div class="com-kpi">
            <span><?= e($info['label']) ?></span>
            <strong><?= number_format((int)$kpis[$key]['total'], 0, ',', '.') ?></strong>
        </div>
    <?php endforeach; ?>
</section>

<nav class="com-tabs">
    <?php foreach ($tipos as $key => $info): ?>
        <a
            class="com-tab <?= $tipo === $key ? 'active' : '' ?>"
            href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= e($key) ?>"
        >
            <i class="bi <?= e($info['icon']) ?>"></i>
            <?= e($info['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="com-panel">
    <div class="com-panel-head">
        <h3 class="com-panel-title">
            <i class="bi <?= e($tipos[$tipo]['icon']) ?>"></i>
            <?= e($tipos[$tipo]['label']) ?>
        </h3>

        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <?php if ($puedeGestionar): ?>
                <a class="com-link green" href="<?= APP_URL ?>/modules/comunidad/crear.php?tipo=<?= e($tipo) ?>">
                    <i class="bi bi-plus-circle"></i>
                    Nuevo <?= e($tipos[$tipo]['singular']) ?>
                </a>
            <?php endif; ?>

            <a class="com-link" href="<?= APP_URL ?>/modules/importar/index.php?plantilla=<?= e($tipo) ?>">
                Descargar plantilla
            </a>
        </div>
    </div>

    <div class="com-panel-body">
        <form method="get" class="com-filter">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">

            <div>
                <label class="com-label">Buscar</label>
                <input
                    class="com-control"
                    type="text"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="RUN, nombre, email, teléfono, curso, cargo o especialidad"
                >
            </div>

            <div>
                <label class="com-label">Estado</label>
                <select class="com-control" name="activo">
                    <option value="todos" <?= $activo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="activos" <?= $activo === 'activos' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivos" <?= $activo === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                </select>
            </div>

            <div>
                <button class="com-submit" type="submit">
                    <i class="bi bi-search"></i>
                    Filtrar
                </button>
            </div>

            <div>
                <a class="com-link" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= e($tipo) ?>">
                    Limpiar
                </a>
            </div>
        </form>
    </div>
</section>

<section class="com-panel">
    <div class="com-panel-head">
        <h3 class="com-panel-title">
            Registros encontrados
        </h3>

        <span class="com-badge">
            <?= number_format($totalTipo, 0, ',', '.') ?> registro(s)
        </span>
    </div>

    <?php if (!$tablaExiste): ?>
        <div class="com-panel-body">
            <div class="com-note">
                La tabla <strong><?= e($tipo) ?></strong> aún no existe.
                Puedes habilitarla ejecutando el SQL opcional de comunidad educativa.
            </div>
        </div>
    <?php elseif (!$registros): ?>
        <div class="com-empty">
            No hay registros con los criterios actuales.
        </div>
    <?php else: ?>
        <div class="com-table-scroll">
            <table class="com-table">
                <thead>
                    <tr>
                        <th>Persona</th>
                        <th>RUN</th>
                        <th>Contacto</th>

                        <?php if ($tipo === 'alumnos'): ?>
                            <th>Curso</th>
                            <th>Fecha nacimiento</th>
                        <?php elseif ($tipo === 'docentes'): ?>
                            <th>Especialidad</th>
                            <th>Dirección</th>
                        <?php elseif ($tipo === 'asistentes'): ?>
                            <th>Cargo</th>
                            <th>Dirección</th>
                        <?php else: ?>
                            <th>Parentesco</th>
                            <th>Dirección</th>
                        <?php endif; ?>

                        <th>Estado</th>

                        <?php if ($puedeGestionar): ?>
                            <th>Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($registros as $row): ?>
                        <?php
                        $estaActivo = (int)($row['activo'] ?? 1) === 1;
                        $nuevoActivo = $estaActivo ? 0 : 1;
                        ?>

                        <tr>
                            <td>
                                <div class="com-main">
                                    <?= e(com_nombre_completo($row)) ?>
                                </div>

                                <?php if (!empty($row['created_at'])): ?>
                                    <div class="com-muted">
                                        Creado: <?= e(com_fecha((string)$row['created_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(com_pick($row, ['run'], '-')) ?>
                            </td>

                            <td>
                                <div><?= e(com_pick($row, ['email'], '-')) ?></div>
                                <div class="com-muted"><?= e(com_pick($row, ['telefono'], '')) ?></div>
                            </td>

                            <?php if ($tipo === 'alumnos'): ?>
                                <td><?= e(com_pick($row, ['curso'], '-')) ?></td>
                                <td><?= e(com_fecha($row['fecha_nacimiento'] ?? null)) ?></td>
                            <?php elseif ($tipo === 'docentes'): ?>
                                <td><?= e(com_pick($row, ['especialidad'], '-')) ?></td>
                                <td><?= e(com_pick($row, ['direccion'], '-')) ?></td>
                            <?php elseif ($tipo === 'asistentes'): ?>
                                <td><?= e(com_pick($row, ['cargo'], '-')) ?></td>
                                <td><?= e(com_pick($row, ['direccion'], '-')) ?></td>
                            <?php else: ?>
                                <td><?= e(com_pick($row, ['parentesco'], '-')) ?></td>
                                <td><?= e(com_pick($row, ['direccion'], '-')) ?></td>
                            <?php endif; ?>

                            <td>
                                <span class="com-badge <?= $estaActivo ? 'ok' : 'warn' ?>">
                                    <?= $estaActivo ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>

                            <?php if ($puedeGestionar): ?>
                                <td>
                                    <div class="com-actions-row">
                                        <?php if ($tipo === 'alumnos'): ?>
                                            <a
                                                class="com-link"
                                                style="background:#f0fdf4;color:#065f46;border-color:#6ee7b7;"
                                                href="<?= APP_URL ?>/modules/alumnos/ver.php?id=<?= (int)$row['id'] ?>"
                                            >
                                                <i class="bi bi-person-badge"></i>
                                                Ver ficha
                                            </a>
                                            <a
                                                class="com-link blue"
                                                href="<?= APP_URL ?>/modules/comunidad/vincular_apoderado.php?alumno_id=<?= (int)$row['id'] ?>"
                                            >
                                                <i class="bi bi-people"></i>
                                                Apoderados
                                            </a>
                                        <?php endif; ?>

                                        <a
                                            class="com-link dark"
                                            href="<?= APP_URL ?>/modules/comunidad/editar.php?tipo=<?= e($tipo) ?>&id=<?= (int)$row['id'] ?>"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                            Editar
                                        </a>

                                        <form method="post" action="<?= APP_URL ?>/modules/comunidad/toggle_estado.php">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="nuevo_activo" value="<?= (int)$nuevoActivo ?>">

                                            <button
                                                class="com-action-btn <?= $estaActivo ? 'red' : 'green' ?>"
                                                type="submit"
                                                onclick="return confirm('¿Confirmas <?= $estaActivo ? 'inactivar' : 'activar' ?> este registro?');"
                                            >
                                                <i class="bi <?= $estaActivo ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                                <?= $estaActivo ? 'Inactivar' : 'Activar' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:.55rem .85rem;font-size:.76rem;color:#94a3b8;border-top:1px solid #f1f5f9;background:#f8fafc;border-radius:0 0 14px 14px;">
            <?= number_format(count($registros), 0, ',', '.') ?> registro(s) cargado(s)
            <?php if (count($registros) >= 500): ?>
                · <span style="color:#d97706;">Para mejores resultados usa los filtros de búsqueda</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>