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
    || Auth::can('admin_sistema');

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Checklist preproducción · Metis';
$pageSubtitle = 'Control de preparación técnica antes de instalar o publicar el sistema';

function pp_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function pp_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function pp_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function pp_estado_badge(string $estado): string
{
    return match ($estado) {
        'ok' => 'ok',
        'observado' => 'warn',
        'no_aplica' => 'soft',
        default => 'danger',
    };
}

function pp_prioridad_badge(string $prioridad): string
{
    return match ($prioridad) {
        'alta' => 'danger',
        'media' => 'warn',
        'baja' => 'ok',
        default => 'soft',
    };
}

function pp_redirect(string $status, string $msg, string $categoria = 'todas', string $estado = 'todos'): void
{
    $url = APP_URL . '/modules/admin/preproduccion.php?status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);
    $url .= '&categoria=' . urlencode($categoria);
    $url .= '&estado=' . urlencode($estado);
    header('Location: ' . $url);
    exit;
}

$categorias = [
    'todas' => 'Todas',
    'servidor' => 'Servidor',
    'base_datos' => 'Base de datos',
    'seguridad' => 'Seguridad',
    'archivos' => 'Archivos y storage',
    'funcional' => 'Prueba funcional',
    'datos' => 'Datos y migración',
    'usuarios' => 'Usuarios y permisos',
    'respaldo' => 'Respaldo y recuperación',
    'salida' => 'Salida a producción',
];

$estados = [
    'todos' => 'Todos',
    'pendiente' => 'Pendiente',
    'ok' => 'OK',
    'observado' => 'Observado',
    'no_aplica' => 'No aplica',
];

$error = '';
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));
$categoria = clean((string)($_GET['categoria'] ?? 'todas'));
$estadoFiltro = clean((string)($_GET['estado'] ?? 'todos'));

if (!array_key_exists($categoria, $categorias)) {
    $categoria = 'todas';
}

if (!array_key_exists($estadoFiltro, $estados)) {
    $estadoFiltro = 'todos';
}

$items = [];
$kpis = [
    'total' => 0,
    'pendiente' => 0,
    'ok' => 0,
    'observado' => 0,
    'no_aplica' => 0,
    'avance' => 0,
];

try {
    if (!pp_table_exists($pdo, 'checklist_preproduccion')) {
        throw new RuntimeException('La tabla checklist_preproduccion no existe. Ejecuta primero sql/29_checklist_preproduccion.sql.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);
        $categoriaPost = clean((string)($_POST['categoria_actual'] ?? $categoria));
        $estadoPostFiltro = clean((string)($_POST['estado_actual'] ?? $estadoFiltro));

        if ($accion !== 'actualizar') {
            throw new RuntimeException('Acción no válida.');
        }

        if ($id <= 0) {
            throw new RuntimeException('Ítem no válido.');
        }

        $nuevoEstado = clean((string)($_POST['estado'] ?? ''));
        $responsable = trim((string)($_POST['responsable'] ?? ''));
        $observacion = trim((string)($_POST['observacion'] ?? ''));

        if (!array_key_exists($nuevoEstado, $estados) || $nuevoEstado === 'todos') {
            throw new RuntimeException('Estado no válido.');
        }

        $stmt = $pdo->prepare("\n            UPDATE checklist_preproduccion\n            SET estado = ?,\n                responsable = NULLIF(?, ''),\n                observacion = NULLIF(?, ''),\n                revisado_por = ?,\n                revisado_at = NOW(),\n                updated_at = NOW()\n            WHERE id = ?\n            LIMIT 1\n        ");
        $stmt->execute([
            $nuevoEstado,
            $responsable,
            $observacion,
            $userId > 0 ? $userId : null,
            $id,
        ]);

        if (function_exists('registrar_bitacora')) {
            registrar_bitacora(
                'admin',
                'actualizar_checklist_preproduccion',
                'checklist_preproduccion',
                $id,
                'Se actualizó ítem de checklist preproducción a estado: ' . $nuevoEstado
            );
        }

        pp_redirect('ok', 'Ítem actualizado correctamente.', $categoriaPost, $estadoPostFiltro);
    }

    $stmtKpi = $pdo->query("\n        SELECT estado, COUNT(*) AS total\n        FROM checklist_preproduccion\n        GROUP BY estado\n    ");

    foreach ($stmtKpi->fetchAll() as $row) {
        $estadoRow = (string)$row['estado'];
        $totalRow = (int)$row['total'];

        if (isset($kpis[$estadoRow])) {
            $kpis[$estadoRow] = $totalRow;
        }

        $kpis['total'] += $totalRow;
    }

    $kpis['avance'] = $kpis['total'] > 0
        ? (int)round((($kpis['ok'] + $kpis['no_aplica']) / $kpis['total']) * 100)
        : 0;

    $where = [];
    $params = [];

    if ($categoria !== 'todas') {
        $where[] = 'categoria = ?';
        $params[] = $categoria;
    }

    if ($estadoFiltro !== 'todos') {
        $where[] = 'estado = ?';
        $params[] = $estadoFiltro;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("\n        SELECT cp.*, u.nombre AS revisado_por_nombre\n        FROM checklist_preproduccion cp\n        LEFT JOIN usuarios u ON u.id = cp.revisado_por\n        {$whereSql}\n        ORDER BY\n            CASE cp.prioridad\n                WHEN 'alta' THEN 1\n                WHEN 'media' THEN 2\n                WHEN 'baja' THEN 3\n                ELSE 4\n            END,\n            CASE cp.estado\n                WHEN 'observado' THEN 1\n                WHEN 'pendiente' THEN 2\n                WHEN 'ok' THEN 3\n                WHEN 'no_aplica' THEN 4\n                ELSE 5\n            END,\n            cp.orden ASC,\n            cp.id ASC\n    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.pp-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(245,158,11,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #92400e 58%, #f59e0b 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.pp-hero h2 { margin: 0 0 .45rem; font-size: 1.85rem; font-weight: 900; }
.pp-hero p { margin: 0; color: #fef3c7; max-width: 950px; line-height: 1.55; }
.pp-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
.pp-btn {
    display: inline-flex; align-items: center; gap: .42rem; border-radius: 999px;
    padding: .62rem 1rem; font-size: .84rem; font-weight: 900; text-decoration: none;
    border: 1px solid rgba(255,255,255,.28); color: #fff; background: rgba(255,255,255,.12);
}
.pp-kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .9rem; margin-bottom: 1.2rem; }
.pp-kpi {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}
.pp-kpi span { color: #64748b; display: block; font-size: .68rem; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
.pp-kpi strong { display: block; color: #0f172a; font-size: 2rem; line-height: 1; margin-top: .35rem; }
.pp-panel {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06); overflow: hidden; margin-bottom: 1.2rem;
}
.pp-panel-head {
    padding: 1rem 1.2rem; border-bottom: 1px solid #e2e8f0;
    display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap;
}
.pp-panel-title { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 900; }
.pp-panel-body { padding: 1.2rem; }
.pp-filter { display: grid; grid-template-columns: 1fr 1fr auto auto; gap: .8rem; align-items: end; }
.pp-label { display: block; color: #334155; font-size: .76rem; font-weight: 900; margin-bottom: .35rem; }
.pp-control {
    width: 100%; border: 1px solid #cbd5e1; border-radius: 13px;
    padding: .66rem .78rem; outline: none; background: #fff; font-size: .9rem;
}
.pp-submit, .pp-link {
    display: inline-flex; align-items: center; justify-content: center; gap: .35rem;
    border: 0; background: #0f172a; color: #fff; border-radius: 999px;
    padding: .66rem 1rem; font-weight: 900; font-size: .84rem; text-decoration: none;
    white-space: nowrap; cursor: pointer;
}
.pp-link { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.pp-table-scroll { width: 100%; overflow-x: auto; }
.pp-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .86rem; }
.pp-table th {
    background: #f8fafc; color: #64748b; font-size: .68rem; text-transform: uppercase;
    letter-spacing: .08em; padding: .75rem; border-bottom: 1px solid #e2e8f0;
    white-space: nowrap; text-align: left;
}
.pp-table td { padding: .85rem .75rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.pp-main { color: #0f172a; font-weight: 900; }
.pp-muted { color: #64748b; font-size: .76rem; margin-top: .2rem; line-height: 1.35; }
.pp-badge {
    display: inline-flex; align-items: center; border-radius: 999px; padding: .24rem .62rem;
    font-size: .72rem; font-weight: 900; border: 1px solid #e2e8f0; background: #fff;
    color: #475569; white-space: nowrap; margin: .1rem;
}
.pp-badge.ok { background: #ecfdf5; border-color: #bbf7d0; color: #047857; }
.pp-badge.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.pp-badge.danger { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
.pp-badge.soft { background: #f8fafc; color: #475569; }
.pp-update { display: grid; grid-template-columns: 140px 160px minmax(220px, 1fr) auto; gap: .45rem; align-items: start; }
.pp-msg { border-radius: 14px; padding: .9rem 1rem; margin-bottom: 1rem; font-weight: 800; }
.pp-msg.ok { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
.pp-msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.pp-empty { text-align: center; padding: 2.5rem 1rem; color: #94a3b8; }
@media (max-width: 1200px) { .pp-kpis { grid-template-columns: repeat(3, 1fr); } .pp-update { grid-template-columns: 1fr; } }
@media (max-width: 760px) { .pp-kpis, .pp-filter { grid-template-columns: 1fr; } .pp-hero { padding: 1.35rem; } }
</style>

<section class="pp-hero">
    <h2>Checklist preproducción</h2>
    <p>
        Control interno para preparar la instalación real de Metis en servidor. Sirve para ordenar tareas,
        registrar observaciones y confirmar que el sistema está listo antes de pasar a producción.
    </p>

    <div class="pp-actions">
        <a class="pp-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>

        <a class="pp-btn" href="<?= APP_URL ?>/modules/admin/pruebas_integrales.php">
            <i class="bi bi-clipboard-check"></i>
            Pruebas integrales
        </a>

        <a class="pp-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <i class="bi bi-shield-check"></i>
            Diagnóstico
        </a>

        <a class="pp-btn" href="<?= APP_URL ?>/modules/admin/respaldo.php">
            <i class="bi bi-database-down"></i>
            Respaldo SQL
        </a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="pp-msg ok"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="pp-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="pp-msg error"><?= e($error) ?></div>
<?php endif; ?>

<section class="pp-kpis">
    <div class="pp-kpi"><span>Total</span><strong><?= number_format($kpis['total'], 0, ',', '.') ?></strong></div>
    <div class="pp-kpi"><span>Pendientes</span><strong style="color:#b91c1c;"><?= number_format($kpis['pendiente'], 0, ',', '.') ?></strong></div>
    <div class="pp-kpi"><span>OK</span><strong style="color:#047857;"><?= number_format($kpis['ok'], 0, ',', '.') ?></strong></div>
    <div class="pp-kpi"><span>Observados</span><strong style="color:#92400e;"><?= number_format($kpis['observado'], 0, ',', '.') ?></strong></div>
    <div class="pp-kpi"><span>No aplica</span><strong><?= number_format($kpis['no_aplica'], 0, ',', '.') ?></strong></div>
    <div class="pp-kpi"><span>Avance</span><strong><?= number_format($kpis['avance'], 0, ',', '.') ?>%</strong></div>
</section>

<section class="pp-panel">
    <div class="pp-panel-head">
        <h3 class="pp-panel-title"><i class="bi bi-funnel"></i> Filtros</h3>
    </div>

    <div class="pp-panel-body">
        <form method="get" class="pp-filter">
            <div>
                <label class="pp-label">Categoría</label>
                <select class="pp-control" name="categoria">
                    <?php foreach ($categorias as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $categoria === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="pp-label">Estado</label>
                <select class="pp-control" name="estado">
                    <?php foreach ($estados as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $estadoFiltro === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button class="pp-submit" type="submit"><i class="bi bi-search"></i> Filtrar</button>
            </div>

            <div>
                <a class="pp-link" href="<?= APP_URL ?>/modules/admin/preproduccion.php">Limpiar</a>
            </div>
        </form>
    </div>
</section>

<section class="pp-panel">
    <div class="pp-panel-head">
        <h3 class="pp-panel-title">Ítems de preparación</h3>
        <span class="pp-badge"><?= number_format(count($items), 0, ',', '.') ?> ítem(s)</span>
    </div>

    <?php if (!$items): ?>
        <div class="pp-empty">No hay ítems para los filtros seleccionados.</div>
    <?php else: ?>
        <div class="pp-table-scroll">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th>Ítem</th>
                        <th>Categoría</th>
                        <th>Prioridad</th>
                        <th>Estado actual</th>
                        <th>Actualización</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td style="min-width:330px;">
                                <div class="pp-main"><?= e((string)$item['item']) ?></div>
                                <div class="pp-muted"><?= e((string)$item['detalle']) ?></div>
                                <div class="pp-muted">
                                    Última revisión: <?= e(pp_fecha($item['revisado_at'] ?? null)) ?>
                                    <?php if (!empty($item['revisado_por_nombre'])): ?>
                                        · <?= e((string)$item['revisado_por_nombre']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="pp-badge soft"><?= e(pp_label((string)$item['categoria'])) ?></span></td>
                            <td><span class="pp-badge <?= e(pp_prioridad_badge((string)$item['prioridad'])) ?>"><?= e(pp_label((string)$item['prioridad'])) ?></span></td>
                            <td>
                                <span class="pp-badge <?= e(pp_estado_badge((string)$item['estado'])) ?>">
                                    <?= e(pp_label((string)$item['estado'])) ?>
                                </span>
                                <?php if (!empty($item['responsable'])): ?>
                                    <div class="pp-muted">Responsable: <?= e((string)$item['responsable']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['observacion'])): ?>
                                    <div class="pp-muted">Obs.: <?= e((string)$item['observacion']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:560px;">
                                <form method="post" class="pp-update">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="_accion" value="actualizar">
                                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                    <input type="hidden" name="categoria_actual" value="<?= e($categoria) ?>">
                                    <input type="hidden" name="estado_actual" value="<?= e($estadoFiltro) ?>">

                                    <select class="pp-control" name="estado" required>
                                        <option value="pendiente" <?= (string)$item['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                        <option value="ok" <?= (string)$item['estado'] === 'ok' ? 'selected' : '' ?>>OK</option>
                                        <option value="observado" <?= (string)$item['estado'] === 'observado' ? 'selected' : '' ?>>Observado</option>
                                        <option value="no_aplica" <?= (string)$item['estado'] === 'no_aplica' ? 'selected' : '' ?>>No aplica</option>
                                    </select>

                                    <input class="pp-control" type="text" name="responsable" value="<?= e((string)($item['responsable'] ?? '')) ?>" placeholder="Responsable">
                                    <input class="pp-control" type="text" name="observacion" value="<?= e((string)($item['observacion'] ?? '')) ?>" placeholder="Observación">

                                    <button class="pp-submit" type="submit">
                                        <i class="bi bi-save"></i>
                                        Guardar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
