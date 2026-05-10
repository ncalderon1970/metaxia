<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$pageTitle = 'Pendientes de importación · Metis';
$pageSubtitle = 'Registros rechazados por validación para revisión manual';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS importacion_pendientes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        colegio_id INT UNSIGNED DEFAULT NULL,
        tipo VARCHAR(40) NOT NULL,
        fila INT UNSIGNED DEFAULT NULL,
        run VARCHAR(30) DEFAULT NULL,
        motivo TEXT NOT NULL,
        datos_json LONGTEXT DEFAULT NULL,
        estado VARCHAR(40) NOT NULL DEFAULT 'pendiente',
        creado_por INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_importacion_pendientes_tipo (tipo),
        INDEX idx_importacion_pendientes_estado (estado),
        INDEX idx_importacion_pendientes_colegio (colegio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$tipo = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : '';
$estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : 'pendiente';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$msg = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';

$tiposPermitidos = ['', 'alumnos', 'apoderados', 'docentes', 'asistentes'];
$estadosPermitidos = ['pendiente', 'corregido', 'descartado', 'todos'];

if (!in_array($tipo, $tiposPermitidos, true)) {
    $tipo = '';
}
if (!in_array($estado, $estadosPermitidos, true)) {
    $estado = 'pendiente';
}

$where = [];
$params = [];

if ($tipo !== '') {
    $where[] = 'tipo = ?';
    $params[] = $tipo;
}

if ($estado !== 'todos') {
    $where[] = 'estado = ?';
    $params[] = $estado;
}

if ($rolCodigo !== 'superadmin') {
    $where[] = 'colegio_id = ?';
    $params[] = $colegioId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT * FROM importacion_pendientes {$whereSql} ORDER BY id DESC LIMIT 300");
$stmt->execute($params);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageHeaderActions = [
    metis_context_action('Volver a importar', APP_URL . '/modules/importar/index.php', 'bi-arrow-left', 'secondary'),
    metis_context_action('Comunidad', APP_URL . '/modules/comunidad/index.php', 'bi-people', 'soft'),
];

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.pen-panel { background:#fff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 12px 28px rgba(15,23,42,.06); overflow:hidden; }
.pen-head { padding:1rem 1.2rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
.pen-title { margin:0; font-size:1rem; font-weight:900; color:#0f172a; }
.pen-body { padding:1.2rem; }
.pen-filter { display:flex; gap:.55rem; flex-wrap:wrap; align-items:end; margin-bottom:1rem; }
.pen-filter label { display:block; font-size:.72rem; font-weight:900; color:#475569; margin-bottom:.25rem; }
.pen-filter select { border:1px solid #cbd5e1; border-radius:10px; padding:.55rem .7rem; min-width:160px; }
.pen-filter button { border:0; border-radius:7px; background:#1e3a8a; color:#fff; font-weight:900; padding:.62rem 1rem; }
.pen-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; padding:1rem; margin-bottom:.75rem; }
.pen-card strong { color:#0f172a; }
.pen-meta { color:#64748b; font-size:.8rem; margin-top:.25rem; line-height:1.45; }
.pen-badge { display:inline-flex; border-radius:999px; padding:.24rem .62rem; font-size:.72rem; font-weight:900; background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.pen-badge.corregido { background:#ecfdf5; color:#166534; border-color:#bbf7d0; }
.pen-badge.descartado { background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
.pen-json { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.7rem; font-size:.78rem; overflow:auto; margin-top:.65rem; color:#334155; }
.pen-msg { border-radius:14px; padding:.85rem 1rem; margin-bottom:1rem; font-weight:800; }
.pen-msg.ok { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
.pen-msg.error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
</style>

<?php if ($status === 'ok' && $msg !== ''): ?><div class="pen-msg ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($status === 'error' && $msg !== ''): ?><div class="pen-msg error"><?= e($msg) ?></div><?php endif; ?>

<section class="pen-panel">
    <div class="pen-head">
        <h3 class="pen-title"><i class="bi bi-exclamation-triangle"></i> Pendientes de importación</h3>
    </div>
    <div class="pen-body">
        <form class="pen-filter" method="get">
            <div>
                <label>Tipo</label>
                <select name="tipo">
                    <option value="" <?= $tipo === '' ? 'selected' : '' ?>>Todos</option>
                    <option value="alumnos" <?= $tipo === 'alumnos' ? 'selected' : '' ?>>Alumnos</option>
                    <option value="apoderados" <?= $tipo === 'apoderados' ? 'selected' : '' ?>>Apoderados</option>
                    <option value="docentes" <?= $tipo === 'docentes' ? 'selected' : '' ?>>Docentes</option>
                    <option value="asistentes" <?= $tipo === 'asistentes' ? 'selected' : '' ?>>Asistentes</option>
                </select>
            </div>
            <div>
                <label>Estado</label>
                <select name="estado">
                    <option value="pendiente" <?= $estado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="corregido" <?= $estado === 'corregido' ? 'selected' : '' ?>>Corregido</option>
                    <option value="descartado" <?= $estado === 'descartado' ? 'selected' : '' ?>>Descartado</option>
                    <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <button type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
        </form>

        <?php if (!$pendientes): ?>
            <p style="color:#64748b;margin:0;">No hay registros pendientes con los filtros actuales.</p>
        <?php else: ?>
            <?php foreach ($pendientes as $p): ?>
                <?php $badgeClass = in_array((string)$p['estado'], ['corregido','descartado'], true) ? ' ' . (string)$p['estado'] : ''; ?>
                <article class="pen-card">
                    <div>
                        <strong><?= e(mb_strtoupper((string)$p['tipo'], 'UTF-8')) ?></strong>
                        <span class="pen-badge<?= e($badgeClass) ?>"><?= e((string)$p['estado']) ?></span>
                    </div>
                    <div class="pen-meta">
                        Fila: <?= e((string)($p['fila'] ?? '-')) ?> · RUN: <?= e((string)($p['run'] ?? '-')) ?> · Fecha: <?= e((string)$p['created_at']) ?>
                    </div>
                    <div class="pen-meta"><strong>Motivo:</strong> <?= e((string)$p['motivo']) ?></div>
                    <pre class="pen-json"><?= e((string)($p['datos_json'] ?? '')) ?></pre>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
