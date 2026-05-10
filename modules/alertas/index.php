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

$pageTitle = 'Alertas · Metis';
$pageSubtitle = 'Control de alertas institucionales y vencimientos';

function alerta_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function alerta_clase_prioridad(string $prioridad): string
{
    return match (strtolower($prioridad)) {
        'alta' => 'danger',
        'media' => 'warn',
        'baja' => 'ok',
        default => 'soft',
    };
}

$error = '';
$exito = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'resolver') {
            $alertaId = (int)($_POST['alerta_id'] ?? 0);

            if ($alertaId <= 0) {
                $error = 'Alerta no válida.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE caso_alertas
                    SET estado = 'resuelta',
                        resuelta_por = ?,
                        resuelta_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    (int)($user['id'] ?? 0),
                    $alertaId,
                ]);

                registrar_bitacora(
                    'alertas',
                    'resolver_alerta',
                    'caso_alertas',
                    $alertaId,
                    'Alerta marcada como resuelta.'
                );

                $exito = 'Alerta marcada como resuelta correctamente.';
            }
        }
    }
} catch (Throwable $e) {
    $error = 'Error al procesar acción: ' . $e->getMessage();
}

$estadoFiltro = clean((string)($_GET['estado'] ?? 'pendiente'));
$prioridadFiltro = clean((string)($_GET['prioridad'] ?? ''));
$q = clean((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

$where[] = 'c.colegio_id = ?';
$params[] = $colegioId;

if ($estadoFiltro !== '' && $estadoFiltro !== 'todas') {
    $where[] = 'a.estado = ?';
    $params[] = $estadoFiltro;
}

if ($prioridadFiltro !== '') {
    $where[] = 'a.prioridad = ?';
    $params[] = $prioridadFiltro;
}

if ($q !== '') {
    $where[] = '(a.mensaje LIKE ? OR a.tipo LIKE ? OR c.numero_caso LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalAlertas = 0;
$totalPendientes = 0;
$totalResueltas = 0;
$totalAlta = 0;
$alertas = [];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        {$whereSql}
    ");
    $stmt->execute($params);
    $totalAlertas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        WHERE c.colegio_id = ?
          AND a.estado = 'pendiente'
    ");
    $stmt->execute([$colegioId]);
    $totalPendientes = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        WHERE c.colegio_id = ?
          AND a.estado = 'resuelta'
    ");
    $stmt->execute([$colegioId]);
    $totalResueltas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        WHERE c.colegio_id = ?
          AND a.prioridad = 'alta'
    ");
    $stmt->execute([$colegioId]);
    $totalAlta = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.caso_id,
            a.tipo,
            a.mensaje,
            a.prioridad,
            a.estado,
            a.fecha_alerta,
            a.resuelta_at,
            c.numero_caso,
            c.semaforo AS caso_semaforo,
            c.prioridad AS caso_prioridad
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        {$whereSql}
        ORDER BY
            CASE a.estado
                WHEN 'pendiente' THEN 1
                WHEN 'resuelta' THEN 2
                ELSE 3
            END,
            CASE a.prioridad
                WHEN 'alta' THEN 1
                WHEN 'media' THEN 2
                WHEN 'baja' THEN 3
                ELSE 4
            END,
            a.fecha_alerta DESC,
            a.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $alertas = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = 'Error al cargar alertas: ' . $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.alertas-hero {
    background: linear-gradient(135deg, #0f172a 0%, #7f1d1d 58%, var(--c-metis-danger) 100%);
    color: #fff;
    border-radius: 14px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 12px 32px rgba(15,23,42,.12);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.25rem;
    flex-wrap: wrap;
}

.alertas-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.45rem;
    font-weight: 600;
}

.alertas-hero p {
    margin: 0;
    color: #fee2e2;
}

.alertas-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.alertas-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 7px;
    padding: .62rem 1rem;
    font-weight: 600;
    font-size: .84rem;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.alertas-btn:hover {
    color: #fff;
}

.alertas-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.alertas-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.alertas-kpi span {
    color: #64748b;
    display: block;
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.alertas-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.75rem;
    line-height: 1;
    margin-top: .35rem;
}

.alertas-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.alertas-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.alertas-panel-title {
    margin: 0;
    font-size: .72rem;
    font-weight: 700;
    color: #2563eb;
    text-transform: uppercase;
    letter-spacing: .09em;
    display: flex;
    align-items: center;
    gap: .4rem;
}

.alertas-panel-body {
    padding: 1.2rem;
}

.alertas-filter {
    display: grid;
    grid-template-columns: 1.4fr .8fr .8fr auto;
    gap: .8rem;
    align-items: end;
}

.alertas-label {
    display: block;
    font-size: .76rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: .35rem;
}

.alertas-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: .62rem .75rem;
    font-size: .88rem;
    outline: none;
}

.alertas-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

.alertas-submit {
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 7px;
    padding: .64rem 1rem;
    font-weight: 600;
}

.alerta-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: .8rem;
}

.alerta-caso {
    color: #0f172a;
    font-weight: 600;
    text-decoration: none;
}

.alerta-caso:hover {
    color: var(--c-metis-danger);
}

.alerta-text {
    color: #334155;
    margin-top: .45rem;
    line-height: 1.45;
}

.alerta-meta {
    color: #64748b;
    font-size: .76rem;
    margin-top: .35rem;
}

.badge-alerta {
    display: inline-flex;
    align-items: center;
    border-radius: 7px;
    padding: .24rem .6rem;
    font-size: .72rem;
    font-weight: 600;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    margin: .25rem .25rem 0 0;
}

.badge-alerta.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.badge-alerta.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.badge-alerta.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.alerta-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border-radius: 7px;
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
    padding: .44rem .78rem;
    font-size: .76rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}

.alerta-action.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.alerta-action:hover {
    filter: brightness(.97);
}

.alertas-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 600;
}

.alertas-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.alertas-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.alertas-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: #94a3b8;
}

@media (max-width: 980px) {
    .alertas-kpis,
    .alertas-filter {
        grid-template-columns: 1fr 1fr;
    }

    .alerta-item {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 620px) {
    .alertas-kpis,
    .alertas-filter {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="alertas-hero">
    <div>
        <h2><i class="bi bi-bell-fill" style="opacity:.8;margin-right:.35rem;"></i>Alertas institucionales</h2>
        <p>Control de alertas pendientes, resueltas y situaciones que requieren gestión del equipo de convivencia.</p>
    </div>
    <div class="alertas-actions">
        <a class="alertas-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-megaphone"></i> Denuncias
        </a>
        <a class="alertas-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
    </div>
</section>

<?php if ($exito !== ''): ?>
    <div class="alertas-msg ok">
        <i class="bi bi-check-circle"></i>
        <?= e($exito) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alertas-msg error">
        <i class="bi bi-exclamation-triangle"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="alertas-kpis">
    <div class="alertas-kpi">
        <span>Total filtrado</span>
        <strong><?= number_format($totalAlertas, 0, ',', '.') ?></strong>
    </div>

    <div class="alertas-kpi">
        <span>Pendientes</span>
        <strong><?= number_format($totalPendientes, 0, ',', '.') ?></strong>
    </div>

    <div class="alertas-kpi">
        <span>Resueltas</span>
        <strong><?= number_format($totalResueltas, 0, ',', '.') ?></strong>
    </div>

    <div class="alertas-kpi">
        <span>Prioridad alta</span>
        <strong><?= number_format($totalAlta, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="alertas-panel">
    <div class="alertas-panel-head">
        <h3 class="alertas-panel-title">
            <i class="bi bi-funnel"></i>
            Filtros
        </h3>

        <a href="<?= APP_URL ?>/modules/alertas/index.php" class="alerta-action">
            Limpiar
        </a>
    </div>

    <div class="alertas-panel-body">
        <form method="get" class="alertas-filter">
            <div>
                <label class="alertas-label">Buscar</label>
                <input
                    class="alertas-control"
                    type="text"
                    name="q"
                    value="<?= e($q) ?>"
                    placeholder="Mensaje, tipo o número de caso"
                >
            </div>

            <div>
                <label class="alertas-label">Estado</label>
                <select class="alertas-control" name="estado">
                    <option value="todas" <?= $estadoFiltro === 'todas' ? 'selected' : '' ?>>Todas</option>
                    <option value="pendiente" <?= $estadoFiltro === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                    <option value="resuelta" <?= $estadoFiltro === 'resuelta' ? 'selected' : '' ?>>Resueltas</option>
                    <option value="descartada" <?= $estadoFiltro === 'descartada' ? 'selected' : '' ?>>Descartadas</option>
                </select>
            </div>

            <div>
                <label class="alertas-label">Prioridad</label>
                <select class="alertas-control" name="prioridad">
                    <option value="">Todas</option>
                    <option value="alta" <?= $prioridadFiltro === 'alta' ? 'selected' : '' ?>>Alta</option>
                    <option value="media" <?= $prioridadFiltro === 'media' ? 'selected' : '' ?>>Media</option>
                    <option value="baja" <?= $prioridadFiltro === 'baja' ? 'selected' : '' ?>>Baja</option>
                </select>
            </div>

            <div>
                <button class="alertas-submit" type="submit">
                    <i class="bi bi-search"></i>
                    Filtrar
                </button>
            </div>
        </form>
    </div>
</section>

<section class="alertas-panel">
    <div class="alertas-panel-head">
        <h3 class="alertas-panel-title">
            <i class="bi bi-bell"></i>
            Listado de alertas
        </h3>

        <span style="color:#64748b;font-size:.82rem;font-weight:800;">
            <?= number_format(count($alertas), 0, ',', '.') ?> registro(s)
        </span>
    </div>

    <div class="alertas-panel-body">
        <?php if (!$alertas): ?>
            <div class="alertas-empty">
                No hay alertas con los criterios actuales.
            </div>
        <?php else: ?>
            <?php foreach ($alertas as $a): ?>
                <?php
                $estado = strtolower((string)($a['estado'] ?? 'pendiente'));
                $prioridad = strtolower((string)($a['prioridad'] ?? 'media'));
                $estadoClass = $estado === 'resuelta' ? 'ok' : ($estado === 'pendiente' ? 'warn' : '');
                $prioridadClass = alerta_clase_prioridad($prioridad);
                ?>

                <article class="alerta-item">
                    <div>
                        <a class="alerta-caso" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$a['caso_id'] ?>">
                            <?= e($a['numero_caso']) ?>
                        </a>

                        <div>
                            <span class="badge-alerta <?= e($estadoClass) ?>">
                                Estado: <?= e(ucfirst($estado)) ?>
                            </span>

                            <span class="badge-alerta <?= e($prioridadClass) ?>">
                                Prioridad: <?= e(ucfirst($prioridad)) ?>
                            </span>

                            <span class="badge-alerta">
                                Tipo: <?= e($a['tipo']) ?>
                            </span>
                        </div>

                        <div class="alerta-text">
                            <?= nl2br(e($a['mensaje'])) ?>
                        </div>

                        <div class="alerta-meta">
                            Fecha alerta: <?= e(alerta_fecha((string)($a['fecha_alerta'] ?? ''))) ?>

                            <?php if (!empty($a['resuelta_at'])): ?>
                                · Resuelta: <?= e(alerta_fecha((string)$a['resuelta_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <a class="alerta-action" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$a['caso_id'] ?>">
                            Abrir caso
                        </a>

                        <?php if ($estado === 'pendiente'): ?>
                            <form method="post" style="margin-top:.5rem;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="_accion" value="resolver">
                                <input type="hidden" name="alerta_id" value="<?= (int)$a['id'] ?>">

                                <button class="alerta-action ok" type="submit">
                                    Marcar resuelta
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>