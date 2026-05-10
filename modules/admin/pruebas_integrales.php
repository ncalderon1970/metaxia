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
$userId = (int)($user['id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = in_array($rolCodigo, ['superadmin', 'director'], true)
    || (method_exists('Auth', 'can') && Auth::can('admin_sistema'));

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Pruebas integrales · Metis';
$pageSubtitle = 'Control de avance, validación funcional y preparación para producción';

function pi_table_exists(PDO $pdo, string $table): bool
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

function pi_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function pi_redirect(string $status, string $msg): void
{
    $url = APP_URL . '/modules/admin/pruebas_integrales.php?status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

function pi_estado_label(string $estado): string
{
    return match ($estado) {
        'ok' => 'OK',
        'observado' => 'Observado',
        'no_aplica' => 'No aplica',
        default => 'Pendiente',
    };
}

function pi_badge(string $value): string
{
    return match ($value) {
        'ok', 'baja' => 'ok',
        'observado', 'alta' => 'danger',
        'no_aplica' => 'soft',
        default => 'warn',
    };
}

function pi_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function pi_stats(array $pruebas): array
{
    $stats = [
        'total' => 0,
        'pendiente' => 0,
        'ok' => 0,
        'observado' => 0,
        'no_aplica' => 0,
        'avance' => 0,
    ];

    foreach ($pruebas as $row) {
        $estado = (string)($row['resultado'] ?? 'pendiente');
        $stats['total']++;

        if (isset($stats[$estado])) {
            $stats[$estado]++;
        }
    }

    if ($stats['total'] > 0) {
        $completadas = $stats['ok'] + $stats['no_aplica'];
        $stats['avance'] = (int)round(($completadas / $stats['total']) * 100);
    }

    return $stats;
}

$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));
$filtroArea = clean((string)($_GET['area'] ?? 'todas'));
$filtroResultado = clean((string)($_GET['resultado'] ?? 'todos'));
$filtroPrioridad = clean((string)($_GET['prioridad'] ?? 'todas'));

$error = '';
$pruebas = [];
$areas = [];

try {
    if (!pi_table_exists($pdo, 'pruebas_integrales')) {
        throw new RuntimeException('Falta la tabla pruebas_integrales. Ejecuta primero sql/28_pruebas_integrales.sql.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);

        if ($accion !== 'actualizar') {
            throw new RuntimeException('Acción no válida.');
        }

        if ($id <= 0) {
            throw new RuntimeException('Prueba no válida.');
        }

        $resultado = clean((string)($_POST['resultado'] ?? 'pendiente')) ?? 'pendiente';
        $prioridad = clean((string)($_POST['prioridad'] ?? 'media')) ?? 'media';

        if (!in_array($resultado, ['pendiente', 'ok', 'observado', 'no_aplica'], true)) {
            throw new RuntimeException('Resultado no válido.');
        }

        if (!in_array($prioridad, ['alta', 'media', 'baja'], true)) {
            throw new RuntimeException('Prioridad no válida.');
        }

        $observacion = pi_clean((string)($_POST['observacion'] ?? ''));
        $responsable = pi_clean((string)($_POST['responsable'] ?? ''));
        $fechaRevision = $resultado === 'pendiente' ? null : date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(" 
            UPDATE pruebas_integrales
            SET resultado = ?,
                prioridad = ?,
                observacion = ?,
                responsable = ?,
                fecha_revision = ?,
                revisado_por = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $resultado,
            $prioridad,
            $observacion,
            $responsable,
            $fechaRevision,
            $userId > 0 ? $userId : null,
            $id,
        ]);

        registrar_bitacora(
            'admin',
            'actualizar_prueba_integral',
            'pruebas_integrales',
            $id,
            'Prueba integral actualizada a estado: ' . $resultado
        );

        pi_redirect('ok', 'Prueba integral actualizada correctamente.');
    }

    $stmtAreas = $pdo->query(" 
        SELECT DISTINCT area
        FROM pruebas_integrales
        WHERE activo = 1
        ORDER BY area ASC
    ");
    $areas = array_map(static fn(array $row): string => (string)$row['area'], $stmtAreas->fetchAll());

    $where = ['activo = 1'];
    $params = [];

    if ($filtroArea !== 'todas') {
        $where[] = 'area = ?';
        $params[] = $filtroArea;
    }

    if ($filtroResultado !== 'todos') {
        $where[] = 'resultado = ?';
        $params[] = $filtroResultado;
    }

    if ($filtroPrioridad !== 'todas') {
        $where[] = 'prioridad = ?';
        $params[] = $filtroPrioridad;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare(" 
        SELECT *
        FROM pruebas_integrales
        {$whereSql}
        ORDER BY
            CASE prioridad
                WHEN 'alta' THEN 1
                WHEN 'media' THEN 2
                WHEN 'baja' THEN 3
                ELSE 4
            END,
            area ASC,
            codigo ASC
    ");
    $stmt->execute($params);
    $pruebas = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$stats = pi_stats($pruebas);
$porArea = [];

foreach ($pruebas as $row) {
    $area = (string)$row['area'];
    if (!isset($porArea[$area])) {
        $porArea[$area] = [];
    }
    $porArea[$area][] = $row;
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.pi-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.pi-hero h2 { margin: 0 0 .45rem; font-size: 1.85rem; font-weight: 900; }
.pi-hero p { margin: 0; color: #bfdbfe; max-width: 920px; line-height: 1.55; }
.pi-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
.pi-btn {
    display: inline-flex; align-items: center; gap: .42rem; border-radius: 999px;
    padding: .62rem 1rem; font-size: .84rem; font-weight: 900; text-decoration: none;
    border: 1px solid rgba(255,255,255,.28); color: #fff; background: rgba(255,255,255,.12);
}
.pi-kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .9rem; margin-bottom: 1.2rem; }
.pi-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:1rem; box-shadow:0 12px 28px rgba(15,23,42,.06); }
.pi-kpi span { color:#64748b; display:block; font-size:.68rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
.pi-kpi strong { display:block; color:#0f172a; font-size:1.8rem; line-height:1; margin-top:.35rem; }
.pi-panel { background:#fff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 12px 28px rgba(15,23,42,.06); overflow:hidden; margin-bottom:1.2rem; }
.pi-panel-head { padding:1rem 1.2rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.pi-panel-title { margin:0; color:#0f172a; font-size:1rem; font-weight:900; }
.pi-panel-body { padding:1.2rem; }
.pi-filter { display:grid; grid-template-columns: 1fr .6fr .6fr auto auto; gap:.8rem; align-items:end; }
.pi-label { display:block; color:#334155; font-size:.76rem; font-weight:900; margin-bottom:.35rem; }
.pi-control { width:100%; border:1px solid #cbd5e1; border-radius:13px; padding:.66rem .78rem; outline:none; background:#fff; font-size:.9rem; }
.pi-submit, .pi-link {
    display:inline-flex; align-items:center; justify-content:center; gap:.35rem; border:0; background:#0f172a; color:#fff;
    border-radius:999px; padding:.66rem 1rem; font-weight:900; font-size:.84rem; text-decoration:none; white-space:nowrap; cursor:pointer;
}
.pi-link { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
.pi-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:18px; padding:1rem; margin-bottom:.8rem; }
.pi-card-head { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:flex-start; }
.pi-title { color:#0f172a; font-weight:900; margin-bottom:.22rem; }
.pi-desc { color:#475569; font-size:.86rem; line-height:1.45; margin-top:.45rem; }
.pi-meta { color:#64748b; font-size:.76rem; line-height:1.35; margin-top:.25rem; }
.pi-form { display:grid; grid-template-columns: .65fr .65fr 1fr 1fr auto; gap:.7rem; align-items:end; margin-top:.85rem; }
.pi-badge { display:inline-flex; align-items:center; border-radius:999px; padding:.24rem .62rem; font-size:.72rem; font-weight:900; border:1px solid #e2e8f0; background:#fff; color:#475569; white-space:nowrap; margin:.12rem; }
.pi-badge.ok { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.pi-badge.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.pi-badge.danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.pi-badge.soft { background:#f8fafc; color:#475569; }
.pi-msg { border-radius:14px; padding:.9rem 1rem; margin-bottom:1rem; font-weight:800; }
.pi-msg.ok { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
.pi-msg.error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.pi-empty { text-align:center; color:#94a3b8; padding:2rem 1rem; }
.pi-progress { width:100%; height:12px; background:#e2e8f0; border-radius:999px; overflow:hidden; margin-top:.75rem; }
.pi-progress span { display:block; height:100%; background:#059669; border-radius:999px; }
@media (max-width: 1280px) { .pi-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } .pi-filter, .pi-form { grid-template-columns:1fr 1fr; } }
@media (max-width: 760px) { .pi-kpis, .pi-filter, .pi-form { grid-template-columns:1fr; } .pi-hero { padding:1.35rem; } }
</style>

<section class="pi-hero">
    <h2>Pruebas integrales del sistema</h2>
    <p>
        Controla el avance de validación antes de pasar a producción. Esta bandeja permite marcar pruebas como OK,
        observadas, pendientes o no aplicables, dejando responsable y observaciones para programar actividades.
    </p>

    <div class="pi-actions">
        <a class="pi-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>
        <a class="pi-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <i class="bi bi-shield-check"></i>
            Diagnóstico
        </a>
        <a class="pi-btn" href="<?= APP_URL ?>/modules/reportes/index.php">
            <i class="bi bi-bar-chart-line"></i>
            Reportes
        </a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="pi-msg ok"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="pi-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="pi-msg error"><?= e($error) ?></div>
<?php endif; ?>

<section class="pi-kpis">
    <div class="pi-kpi"><span>Total</span><strong><?= number_format($stats['total'], 0, ',', '.') ?></strong></div>
    <div class="pi-kpi"><span>OK</span><strong style="color:#047857;"><?= number_format($stats['ok'], 0, ',', '.') ?></strong></div>
    <div class="pi-kpi"><span>Pendientes</span><strong style="color:#92400e;"><?= number_format($stats['pendiente'], 0, ',', '.') ?></strong></div>
    <div class="pi-kpi"><span>Observadas</span><strong style="color:#b91c1c;"><?= number_format($stats['observado'], 0, ',', '.') ?></strong></div>
    <div class="pi-kpi"><span>No aplica</span><strong><?= number_format($stats['no_aplica'], 0, ',', '.') ?></strong></div>
    <div class="pi-kpi"><span>Avance</span><strong style="color:#047857;"><?= (int)$stats['avance'] ?>%</strong></div>
</section>

<section class="pi-panel">
    <div class="pi-panel-head">
        <h3 class="pi-panel-title"><i class="bi bi-funnel"></i> Filtros de control</h3>
    </div>
    <div class="pi-panel-body">
        <form method="get" class="pi-filter">
            <div>
                <label class="pi-label">Área</label>
                <select class="pi-control" name="area">
                    <option value="todas" <?= $filtroArea === 'todas' ? 'selected' : '' ?>>Todas</option>
                    <?php foreach ($areas as $area): ?>
                        <option value="<?= e($area) ?>" <?= $filtroArea === $area ? 'selected' : '' ?>><?= e($area) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="pi-label">Resultado</label>
                <select class="pi-control" name="resultado">
                    <?php foreach (['todos' => 'Todos', 'pendiente' => 'Pendiente', 'ok' => 'OK', 'observado' => 'Observado', 'no_aplica' => 'No aplica'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filtroResultado === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="pi-label">Prioridad</label>
                <select class="pi-control" name="prioridad">
                    <?php foreach (['todas' => 'Todas', 'alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filtroPrioridad === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button class="pi-submit" type="submit"><i class="bi bi-search"></i> Filtrar</button></div>
            <div><a class="pi-link" href="<?= APP_URL ?>/modules/admin/pruebas_integrales.php">Limpiar</a></div>
        </form>

        <div class="pi-progress"><span style="width:<?= max(0, min(100, (int)$stats['avance'])) ?>%;"></span></div>
    </div>
</section>

<?php if (!$pruebas): ?>
    <section class="pi-panel"><div class="pi-empty">No hay pruebas para los filtros seleccionados.</div></section>
<?php else: ?>
    <?php foreach ($porArea as $area => $items): ?>
        <section class="pi-panel">
            <div class="pi-panel-head">
                <h3 class="pi-panel-title"><?= e($area) ?></h3>
                <span class="pi-badge"><?= number_format(count($items), 0, ',', '.') ?> prueba(s)</span>
            </div>
            <div class="pi-panel-body">
                <?php foreach ($items as $item): ?>
                    <?php $resultado = (string)$item['resultado']; $prioridad = (string)$item['prioridad']; ?>
                    <article class="pi-card">
                        <div class="pi-card-head">
                            <div>
                                <div class="pi-title"><?= e((string)$item['codigo']) ?> · <?= e((string)$item['prueba']) ?></div>
                                <div>
                                    <span class="pi-badge <?= e(pi_badge($resultado)) ?>"><?= e(pi_estado_label($resultado)) ?></span>
                                    <span class="pi-badge <?= e(pi_badge($prioridad)) ?>">Prioridad <?= e(ucfirst($prioridad)) ?></span>
                                </div>
                            </div>
                            <div class="pi-meta">Última revisión: <?= e(pi_fecha((string)($item['fecha_revision'] ?? ''))) ?></div>
                        </div>

                        <?php if (!empty($item['descripcion'])): ?>
                            <div class="pi-desc"><?= e((string)$item['descripcion']) ?></div>
                        <?php endif; ?>

                        <form method="post" class="pi-form">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="_accion" value="actualizar">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">

                            <div>
                                <label class="pi-label">Resultado</label>
                                <select class="pi-control" name="resultado">
                                    <?php foreach (['pendiente' => 'Pendiente', 'ok' => 'OK', 'observado' => 'Observado', 'no_aplica' => 'No aplica'] as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $resultado === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="pi-label">Prioridad</label>
                                <select class="pi-control" name="prioridad">
                                    <?php foreach (['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'] as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $prioridad === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="pi-label">Responsable</label>
                                <input class="pi-control" type="text" name="responsable" value="<?= e((string)($item['responsable'] ?? '')) ?>" placeholder="Responsable">
                            </div>

                            <div>
                                <label class="pi-label">Observación</label>
                                <input class="pi-control" type="text" name="observacion" value="<?= e((string)($item['observacion'] ?? '')) ?>" placeholder="Observaciones o pendiente específico">
                            </div>

                            <div>
                                <button class="pi-submit" type="submit">
                                    <i class="bi bi-save"></i>
                                    Guardar
                                </button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
