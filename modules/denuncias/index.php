<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

$pageTitle = 'Denuncias · Metis';
$pageSubtitle = 'Registro, búsqueda y control de expedientes de convivencia escolar';

function den_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function den_corto(?string $value, int $length = 140): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    return mb_strlen($value) > $length
        ? mb_substr($value, 0, $length) . '...'
        : $value;
}

function den_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function den_badge_class(string $value): string
{
    return match (strtolower($value)) {
        'rojo', 'alta' => 'danger',
        'amarillo', 'media', 'pendiente' => 'warn',
        'verde', 'baja', 'cerrado' => 'ok',
        default => 'soft',
    };
}

$error = '';

$q = clean((string)($_GET['q'] ?? ''));
$estadoFiltro = clean((string)($_GET['estado'] ?? ''));
$prioridadFiltro = clean((string)($_GET['prioridad'] ?? ''));
$desde = clean((string)($_GET['desde'] ?? ''));
$hasta = clean((string)($_GET['hasta'] ?? ''));

$where = ['c.colegio_id = ?'];
$params = [$colegioId];

if ($q !== '') {
    $where[] = '(c.numero_caso LIKE ? OR c.relato LIKE ? OR c.denunciante_nombre LIKE ? OR c.contexto LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($estadoFiltro !== '') {
    $where[] = 'c.estado = ?';
    $params[] = $estadoFiltro;
}

if ($prioridadFiltro !== '') {
    $where[] = 'c.prioridad = ?';
    $params[] = $prioridadFiltro;
}

if ($desde !== '') {
    $where[] = 'c.fecha_ingreso >= ?';
    $params[] = $desde . ' 00:00:00';
}

if ($hasta !== '') {
    $where[] = 'c.fecha_ingreso <= ?';
    $params[] = $hasta . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalCasos = 0;
$totalAbiertos = 0;
$totalRojo = 0;
$totalAlta = 0;
$totalReanalisis = 0;
$totalFiltrado = 0;
$casos = [];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM casos c
        WHERE c.colegio_id = ?
    ");
    $stmt->execute([$colegioId]);
    $totalCasos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM casos c
        WHERE c.colegio_id = ?
          AND c.estado <> 'cerrado'
    ");
    $stmt->execute([$colegioId]);
    $totalAbiertos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM casos c
        WHERE c.colegio_id = ?
          AND c.prioridad = 'alta'
    ");
    $stmt->execute([$colegioId]);
    $totalAlta = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM casos c
        WHERE c.colegio_id = ?
          AND c.requiere_reanalisis_ia = 1
    ");
    $stmt->execute([$colegioId]);
    $totalReanalisis = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM casos c
        {$whereSql}
    ");
    $stmt->execute($params);
    $totalFiltrado = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.colegio_id,
            c.numero_caso,
            c.fecha_ingreso,
            c.denunciante_nombre,
            c.denunciante_run,
            c.es_anonimo,
            c.relato,
            c.contexto,
            c.lugar_hechos,
            c.fecha_hechos,
            c.involucra_moviles,
            c.clasificacion_ia,
            c.estado,
            c.estado_caso_id,
            c.requiere_reanalisis_ia,
            c.prioridad,
            c.created_at,
            c.updated_at,
            ec.nombre AS estado_formal,

            (
                SELECT COUNT(*)
                FROM caso_participantes cp
                WHERE cp.caso_id = c.id
            ) AS total_participantes,

            (
                SELECT COUNT(*)
                FROM caso_declaraciones cd
                WHERE cd.caso_id = c.id
            ) AS total_declaraciones,

            (
                SELECT COUNT(*)
                FROM caso_evidencias ce
                WHERE ce.caso_id = c.id
            ) AS total_evidencias,

            (
                SELECT COUNT(*)
                FROM caso_alertas ca
                WHERE ca.caso_id = c.id
                  AND ca.estado = 'pendiente'
            ) AS total_alertas,

            (
                SELECT pr.nivel_final
                FROM caso_pauta_riesgo pr
                WHERE pr.caso_id = c.id
                ORDER BY FIELD(pr.nivel_final,'critico','alto','medio','bajo') ASC, pr.id DESC
                LIMIT 1
            ) AS nivel_riesgo_max,

            (
                SELECT COUNT(*)
                FROM caso_pauta_riesgo pr
                WHERE pr.caso_id = c.id
                  AND pr.nivel_final IN ('alto','critico')
                  AND pr.derivado = 0
            ) AS riesgo_sin_derivar

        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        {$whereSql}
        ORDER BY c.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $casos = $stmt->fetchAll();

} catch (Throwable $e) {
    $error = 'Error al cargar denuncias: ' . $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.den-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.24), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.den-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.8rem;
    font-weight: 900;
}

.den-hero p {
    margin: 0;
    color: #bfdbfe;
}

.den-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.den-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 7px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.den-btn.primary {
    background: #10b981;
    border-color: #10b981;
}

.den-btn:hover {
    color: #fff;
}

.den-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.den-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.den-kpi span {
    color: #64748b;
    display: block;
    font-size: .7rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.den-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 2rem;
    line-height: 1;
    margin-top: .35rem;
}

.den-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.den-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.den-panel-title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    font-weight: 900;
}

.den-panel-body {
    padding: 1.2rem;
}

.den-filter {
    display: grid;
    grid-template-columns: 1.4fr .8fr .8fr .8fr .75fr .75fr auto;
    gap: .8rem;
    align-items: end;
}

.den-label {
    display: block;
    font-size: .76rem;
    font-weight: 900;
    color: #334155;
    margin-bottom: .35rem;
}

.den-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .62rem .75rem;
    font-size: .88rem;
    outline: none;
}

.den-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37,99,235,.12);
}

.den-submit {
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 7px;
    padding: .64rem 1rem;
    font-weight: 900;
}

.den-table-scroll {
    width: 100%;
    overflow-x: auto;
}

.den-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: .86rem;
}

.den-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    padding: .75rem;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.den-table td {
    padding: .85rem .75rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.den-case-number {
    font-weight: 900;
    color: #0f172a;
}

.den-case-text {
    color: #64748b;
    font-size: .76rem;
    line-height: 1.35;
    margin-top: .25rem;
    max-width: 480px;
}

.den-muted {
    color: #64748b;
    font-size: .76rem;
}

.badge-den {
    display: inline-flex;
    align-items: center;
    border-radius: 7px;
    padding: .25rem .62rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .12rem;
}

.badge-den.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.badge-den.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.badge-den.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.badge-den.soft {
    background: #f8fafc;
    color: #475569;
}

.den-mini {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    border-radius: 7px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    padding: .22rem .52rem;
    font-size: .7rem;
    font-weight: 800;
    margin: .12rem;
}

.den-action {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    border-radius: 7px;
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
    padding: .42rem .75rem;
    font-size: .76rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.den-action:hover {
    background: #1d4ed8;
    color: #fff;
}

.den-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}

.den-error {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 800;
}

@media (max-width: 1180px) {
    .den-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .den-filter {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 680px) {
    .den-kpis,
    .den-filter {
        grid-template-columns: 1fr;
    }

    .den-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="den-hero">
    <h2>Denuncias y expedientes</h2>
    <p>
        Control central de denuncias, casos de convivencia, intervinientes,
        evidencias, declaraciones, alertas y seguimiento institucional.
    </p>

    <div class="den-actions">
        <a class="den-btn primary" href="<?= APP_URL ?>/modules/denuncias/crear.php">
            <i class="bi bi-plus-circle"></i>
            Nueva denuncia
        </a>

        <a class="den-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>

        <a class="den-btn" href="<?= APP_URL ?>/modules/alertas/index.php">
            <i class="bi bi-bell"></i>
            Alertas
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="den-error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="den-kpis">
    <div class="den-kpi">
        <span>Total casos</span>
        <strong><?= number_format($totalCasos, 0, ',', '.') ?></strong>
    </div>

    <div class="den-kpi">
        <span>Abiertos</span>
        <strong><?= number_format($totalAbiertos, 0, ',', '.') ?></strong>
    </div>

    <div class="den-kpi">
        <span>Prioridad alta</span>
        <strong><?= number_format($totalAlta, 0, ',', '.') ?></strong>
    </div>

    <div class="den-kpi">
        <span>Reanálisis IA</span>
        <strong><?= number_format($totalReanalisis, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="den-panel">
    <div class="den-panel-head">
        <h3 class="den-panel-title">
            <i class="bi bi-funnel"></i>
            Filtros
        </h3>

        <a class="den-action" href="<?= APP_URL ?>/modules/denuncias/index.php">
            Limpiar
        </a>
    </div>

    <div class="den-panel-body">
        <form method="get" class="den-filter">
            <div>
                <label class="den-label">Buscar</label>
                <input
                    class="den-control"
                    type="text"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="N° caso, relato, denunciante o contexto"
                >
            </div>

            <div>
                <label class="den-label">Estado</label>
                <select class="den-control" name="estado">
                    <option value="">Todos</option>
                    <option value="abierto" <?= $estadoFiltro === 'abierto' ? 'selected' : '' ?>>Abierto</option>
                    <option value="cerrado" <?= $estadoFiltro === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                </select>
            </div>

            <div>
                <label class="den-label">Prioridad</label>
                <select class="den-control" name="prioridad">
                    <option value="">Todas</option>
                    <option value="baja" <?= $prioridadFiltro === 'baja' ? 'selected' : '' ?>>Baja</option>
                    <option value="media" <?= $prioridadFiltro === 'media' ? 'selected' : '' ?>>Media</option>
                    <option value="alta" <?= $prioridadFiltro === 'alta' ? 'selected' : '' ?>>Alta</option>
                </select>
            </div>

            <div>
                <label class="den-label">Desde</label>
                <input class="den-control" type="date" name="desde" value="<?= e($desde) ?>">
            </div>

            <div>
                <label class="den-label">Hasta</label>
                <input class="den-control" type="date" name="hasta" value="<?= e($hasta) ?>">
            </div>

            <div>
                <button class="den-submit" type="submit">
                    <i class="bi bi-search"></i>
                    Filtrar
                </button>
            </div>
        </form>
    </div>
</section>

<section class="den-panel">
    <div class="den-panel-head">
        <h3 class="den-panel-title">
            <i class="bi bi-list-check"></i>
            Listado de denuncias
        </h3>

        <span class="den-muted">
            <?= number_format($totalFiltrado, 0, ',', '.') ?> resultado(s)
        </span>
    </div>

    <?php if (!$casos): ?>
        <div class="den-empty">
            No hay denuncias con los criterios actuales.
        </div>
    <?php else: ?>
        <div class="den-table-scroll">
            <table class="den-table">
                <thead>
                    <tr>
                        <th>Caso</th>
                        <th>Estado</th>
                        <th>Prioridad</th>
                        <th>Fecha</th>
                        <th>Expediente</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($casos as $caso): ?>
                        <?php
                        $estadoVisible = $caso['estado_formal'] ?: den_label((string)$caso['estado']);
                        $prioridad = strtolower((string)$caso['prioridad']);
                        $esAnonimo = (int)$caso['es_anonimo'] === 1;
                        ?>

                        <tr>
                            <td>
                                <div class="den-case-number">
                                    <?= e($caso['numero_caso']) ?>

                                    <?php if ($esAnonimo): ?>
                                        <span class="badge-den warn">Reserva identidad</span>
                                    <?php endif; ?>

                                    <?php if ((int)$caso['involucra_moviles'] === 1): ?>
                                        <span class="badge-den soft">Móviles</span>
                                    <?php endif; ?>

                                    <?php if ((int)$caso['requiere_reanalisis_ia'] === 1): ?>
                                        <span class="badge-den danger">Reanálisis IA</span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($caso['denunciante_nombre'])): ?>
                                    <div class="den-muted">
                                        Denunciante:
                                        <?= $esAnonimo ? 'Identidad reservada' : e($caso['denunciante_nombre']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="den-case-text">
                                    <?= e(den_corto((string)$caso['relato'], 160)) ?>
                                </div>

                                <?php if (!empty($caso['contexto'])): ?>
                                    <div class="den-muted">
                                        Contexto: <?= e($caso['contexto']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge-den soft">
                                    <?= e($estadoVisible) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge-den <?= e(den_badge_class($prioridad)) ?>">
                                    <?= e(den_label($prioridad)) ?>
                                </span>
                            </td>

                            <td>
                                <?= e(den_fecha((string)$caso['fecha_ingreso'])) ?>
                            </td>

                            <td>
                                <span class="den-mini">
                                    <i class="bi bi-people"></i>
                                    <?= (int)$caso['total_participantes'] ?>
                                </span>

                                <span class="den-mini">
                                    <i class="bi bi-chat-square-text"></i>
                                    <?= (int)$caso['total_declaraciones'] ?>
                                </span>

                                <span class="den-mini">
                                    <i class="bi bi-paperclip"></i>
                                    <?= (int)$caso['total_evidencias'] ?>
                                </span>

                                <?php if ((int)$caso['total_alertas'] > 0): ?>
                                    <span class="den-mini" style="background:#fef2f2;color:#b91c1c;border-color:#fecaca;">
                                        <i class="bi bi-bell-fill"></i>
                                        <?= (int)$caso['total_alertas'] ?>
                                    </span>
                                <?php endif; ?>

                                <?php
                                $nivelR = (string)($caso['nivel_riesgo_max'] ?? '');
                                $sinDer = (int)($caso['riesgo_sin_derivar'] ?? 0);
                                if ($nivelR !== ''):
                                    $rBg  = $nivelR === 'critico' ? '#f1f5f9' : ($nivelR === 'alto' ? '#fef2f2' : ($nivelR === 'medio' ? '#fffbeb' : '#ecfdf5'));
                                    $rClr = $nivelR === 'critico' ? '#0f172a' : ($nivelR === 'alto' ? '#b91c1c' : ($nivelR === 'medio' ? '#92400e' : '#047857'));
                                    $rBrd = $nivelR === 'critico' ? '#334155' : ($nivelR === 'alto' ? '#fecaca' : ($nivelR === 'medio' ? '#fde68a' : '#bbf7d0'));
                                    $rEmoji = $nivelR === 'critico' ? '⚫' : ($nivelR === 'alto' ? '🔴' : ($nivelR === 'medio' ? '🟡' : '🟢'));
                                ?>
                                <a class="den-mini" title="Pauta de riesgo: <?= strtoupper($nivelR) ?><?= $sinDer > 0 ? ' — derivación pendiente' : '' ?>"
                                   href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['id'] ?>&tab=pauta_riesgo"
                                   style="background:<?= $rBg ?>;color:<?= $rClr ?>;border-color:<?= $rBrd ?>;text-decoration:none;">
                                    <?= $rEmoji ?> <?= $sinDer > 0 ? '⚑' : '' ?>
                                </a>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ((string)$caso['estado'] === 'borrador'): ?>
                                    <a class="den-action" href="<?= APP_URL ?>/modules/denuncias/completar_borrador.php?id=<?= (int)$caso['id'] ?>"
                                       style="background:#fef3c7;color:#92400e;border-color:#fde68a;">
                                        <i class="bi bi-pencil-fill"></i> Completar
                                    </a>
                                <?php else: ?>
                                    <a class="den-action" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['id'] ?>">
                                        <i class="bi bi-folder2-open"></i> Expediente
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>