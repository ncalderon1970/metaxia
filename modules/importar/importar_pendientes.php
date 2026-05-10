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
$colegioId = (int)($user['colegio_id'] ?? 0);

$puedeGestionar = in_array($rolCodigo, ['superadmin', 'director', 'convivencia', 'encargado_convivencia', 'admin_colegio'], true)
    || (method_exists('Auth', 'can') && (Auth::can('admin_sistema') || Auth::can('gestionar_usuarios')));

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Pendientes de importación · Metis';
$pageSubtitle = 'Registros rechazados por validación para revisión manual';

function pen_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (!pen_table_exists($pdo, 'importacion_pendientes')) {
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
}

$tipo = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : '';
$estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : 'pendiente';

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

if ($colegioId > 0 && !in_array($rolCodigo, ['superadmin'], true)) {
    $where[] = '(colegio_id = ? OR colegio_id IS NULL)';
    $params[] = $colegioId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT * FROM importacion_pendientes {$whereSql} ORDER BY id DESC LIMIT 300");
$stmt->execute($params);
$pendientes = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.pen-panel { background:#fff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 12px 28px rgba(15,23,42,.06); overflow:hidden; }
.pen-head { padding:1rem 1.2rem; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:1rem; align-items:center; flex-wrap:wrap; }
.pen-title { margin:0; font-size:1rem; font-weight:900; color:#0f172a; }
.pen-body { padding:1.2rem; }
.pen-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; padding:1rem; margin-bottom:.75rem; }
.pen-card strong { color:#0f172a; }
.pen-meta { color:#64748b; font-size:.8rem; margin-top:.25rem; line-height:1.45; }
.pen-badge { display:inline-flex; border-radius:999px; padding:.24rem .62rem; font-size:.72rem; font-weight:900; background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.pen-link { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; padding:.62rem 1rem; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; font-weight:900; text-decoration:none; }
.pen-json { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.7rem; font-size:.78rem; overflow:auto; margin-top:.65rem; color:#334155; }
</style>

<section class="pen-panel">
    <div class="pen-head">
        <h3 class="pen-title"><i class="bi bi-exclamation-triangle"></i> Pendientes de importación</h3>
        <a class="pen-link" href="<?= APP_URL ?>/modules/importar/index.php"><i class="bi bi-arrow-left"></i> Volver a importar</a>
    </div>
    <div class="pen-body">
        <?php if (!$pendientes): ?>
            <p style="color:#64748b;margin:0;">No hay registros pendientes con los filtros actuales.</p>
        <?php else: ?>
            <?php foreach ($pendientes as $p): ?>
                <article class="pen-card">
                    <div>
                        <strong><?= e(mb_strtoupper((string)$p['tipo'], 'UTF-8')) ?></strong>
                        <span class="pen-badge"><?= e((string)$p['estado']) ?></span>
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
