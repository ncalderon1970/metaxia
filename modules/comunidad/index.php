<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';
require_once __DIR__ . '/_comunidad_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$puedeGestionar = Auth::canOperate();

$tipo = com_safe_tipo((string)($_GET['tipo'] ?? 'alumnos'));
$meta = com_tipo_meta($tipo);
$q = trim((string)($_GET['q'] ?? ''));
$activo = (string)($_GET['activo'] ?? 'activos');
$status = (string)($_GET['status'] ?? '');
$msg = (string)($_GET['msg'] ?? '');

$pageTitle = 'Comunidad Educativa · Metis';
$pageSubtitle = 'Consulta, edición, apoderados y control de estado de comunidad educativa';
$pageHeaderActions = metis_context_actions([
    metis_context_action('Nuevo registro', APP_URL . '/modules/comunidad/crear.php?tipo=' . urlencode($tipo), 'bi-plus-circle', 'primary', $puedeGestionar),
    metis_context_action('Importar CSV', APP_URL . '/modules/importar/index.php', 'bi-upload', 'secondary', $puedeGestionar),
    metis_context_action('Vincular apoderados', APP_URL . '/modules/comunidad/vincular_apoderado.php', 'bi-diagram-3', 'secondary', $puedeGestionar),
]);

$tipos = ['alumnos','apoderados','docentes','asistentes'];
$kpis = [];
foreach ($tipos as $t) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM {$t} WHERE colegio_id = ?");
        $s->execute([$colegioId]);
        $kpis[$t] = (int)$s->fetchColumn();
    } catch (Throwable $e) { $kpis[$t] = 0; }
}

$where = ['colegio_id = ?'];
$params = [$colegioId];
if ($activo === 'activos') { $where[] = 'activo = 1'; }
if ($activo === 'inactivos') { $where[] = 'activo = 0'; }

$searchSql = '';
if ($q !== '') {
    $like = '%' . mb_strtoupper($q, 'UTF-8') . '%';
    $runLike = '%' . str_replace(['.', '-', ' '], '', mb_strtoupper($q, 'UTF-8')) . '%';
    if ($tipo === 'alumnos') {
        $searchSql = " AND (UPPER(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno)) COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci OR UPPER(REPLACE(REPLACE(REPLACE(run,'.',''),'-',''),' ','')) LIKE ? OR UPPER(COALESCE(curso,'')) COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci OR UPPER(COALESCE(email,'')) COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci)";
        array_push($params, $like, $runLike, $like, $like);
    } else {
        $searchSql = " AND (UPPER(CONCAT_WS(' ', COALESCE(nombres,''), COALESCE(apellido_paterno,''), COALESCE(apellido_materno,''), COALESCE(nombre,''))) COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci OR UPPER(REPLACE(REPLACE(REPLACE(run,'.',''),'-',''),' ','')) LIKE ? OR UPPER(COALESCE(email,'')) COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci OR UPPER(COALESCE(telefono,'')) LIKE ?";
        $params[] = $like; $params[] = $runLike; $params[] = $like; $params[] = $like;
        if (in_array($tipo, ['docentes','asistentes'], true)) { $searchSql .= " OR UPPER(COALESCE(cargo,'')) COLLATE utf8mb4_unicode_ci LIKE ? COLLATE utf8mb4_unicode_ci"; $params[] = $like; }
        $searchSql .= ')';
    }
}

$registros = [];
$totalTipo = 0;
$error = '';
try {
    $whereSql = implode(' AND ', $where) . $searchSql;
    $c = $pdo->prepare("SELECT COUNT(*) FROM {$tipo} WHERE {$whereSql}");
    $c->execute($params);
    $totalTipo = (int)$c->fetchColumn();

    $selectName = $tipo === 'alumnos'
        ? "CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno) AS nombre_display"
        : "COALESCE(NULLIF(CONCAT_WS(' ', COALESCE(nombres,''), COALESCE(apellido_paterno,''), COALESCE(apellido_materno,'')), ''), nombre) AS nombre_display";
    $order = $tipo === 'alumnos' ? 'apellido_paterno, apellido_materno, nombres' : 'apellido_paterno, apellido_materno, nombre';
    $s = $pdo->prepare("SELECT *, {$selectName} FROM {$tipo} WHERE {$whereSql} ORDER BY activo DESC, {$order} ASC LIMIT 300");
    $s->execute($params);
    $registros = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error = 'Error al cargar comunidad educativa: ' . $e->getMessage(); }

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.com-hero{background:radial-gradient(circle at 90% 16%,rgba(16,185,129,.22),transparent 28%),linear-gradient(135deg,#0f172a 0%,#1e3a8a 58%,#2563eb 100%);color:#fff;border-radius:22px;padding:2rem;margin-bottom:1.2rem;box-shadow:0 18px 45px rgba(15,23,42,.18)}
.com-hero h2{margin:0 0 .45rem;font-size:1.85rem;font-weight:900}.com-hero p{margin:0;color:#bfdbfe;max-width:900px;line-height:1.55}
.com-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.9rem;margin-bottom:1.2rem}.com-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1rem;box-shadow:0 12px 28px rgba(15,23,42,.06)}.com-kpi span{color:#64748b;display:block;font-size:.68rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.com-kpi strong{display:block;color:#0f172a;font-size:2rem;line-height:1;margin-top:.35rem}
.com-tabs{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.2rem}.com-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem .9rem;border-radius:7px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-weight:900;font-size:.84rem;text-decoration:none}.com-tab.active{background:#0f172a;color:#fff;border-color:#0f172a}
.com-panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 28px rgba(15,23,42,.06);overflow:hidden;margin-bottom:1.2rem}.com-panel-head{padding:1rem 1.2rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.com-panel-title{margin:0;color:#0f172a;font-size:1rem;font-weight:900}.com-panel-body{padding:1.2rem}.com-filter{display:grid;grid-template-columns:1.3fr .75fr auto auto;gap:.8rem;align-items:end}.com-label{display:block;font-size:.76rem;font-weight:900;color:#334155;margin-bottom:.35rem}.com-control{width:100%;border:1px solid #cbd5e1;border-radius:13px;padding:.65rem .78rem;outline:none;background:#fff;font-size:.9rem}.com-submit,.com-link,.com-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:.28rem;border:1px solid #e2e8f0;background:#f8fafc;color:#374151;border-radius:6px;padding:.36rem .7rem;font-weight:700;font-size:.73rem;text-decoration:none;white-space:nowrap;cursor:pointer;line-height:1.4}.com-link{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.com-link.green{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.com-action-btn.red{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.com-action-btn.green{background:#ecfdf5;color:#047857;border-color:#bbf7d0}
.com-table-scroll{width:100%;overflow:auto;max-height:620px;border:1px solid #e2e8f0;border-radius:14px}.com-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem}.com-table th{background:#f8fafc;color:#64748b;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;padding:.75rem;border-bottom:1px solid #e2e8f0;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:2}.com-table td{padding:.85rem .75rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}.com-main{color:#0f172a;font-weight:900}.com-muted{color:#64748b;font-size:.78rem}.com-badge{display:inline-flex;align-items:center;border-radius:99px;padding:.2rem .55rem;font-size:.68rem;font-weight:900}.com-badge.ok{background:#dcfce7;color:#166534}.com-badge.off{background:#fee2e2;color:#991b1b}.com-alert{border-radius:14px;padding:.85rem 1rem;margin-bottom:1rem;font-weight:800}.com-alert.ok{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.com-alert.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:900px){.com-kpis{grid-template-columns:repeat(2,1fr)}.com-filter{grid-template-columns:1fr}.com-table{min-width:980px}}
</style>

<section class="com-hero">
    <h2>Comunidad educativa</h2>
    <p>Gestión centralizada de estudiantes, apoderados, docentes y asistentes del establecimiento activo.</p>
</section>

<div class="com-kpis">
    <?php foreach ($tipos as $t): $m = com_tipo_meta($t); ?>
        <div class="com-kpi"><span><?= com_e($m['label']) ?></span><strong><?= (int)$kpis[$t] ?></strong></div>
    <?php endforeach; ?>
</div>

<?php if ($status === 'ok'): ?><div class="com-alert ok"><?= com_e($msg ?: 'Operación realizada correctamente.') ?></div><?php endif; ?>
<?php if ($status === 'error' || $error): ?><div class="com-alert err"><?= com_e($msg ?: $error) ?></div><?php endif; ?>

<nav class="com-tabs">
    <?php foreach ($tipos as $t): $m = com_tipo_meta($t); ?>
        <a class="com-tab <?= $tipo === $t ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= urlencode($t) ?>"><i class="bi <?= com_e($m['icon']) ?>"></i><?= com_e($m['label']) ?></a>
    <?php endforeach; ?>
</nav>

<section class="com-panel">
    <div class="com-panel-head">
        <div><h3 class="com-panel-title"><i class="bi <?= com_e($meta['icon']) ?>"></i> <?= com_e($meta['label']) ?></h3><div class="com-muted"><?= com_e($meta['desc']) ?> · <?= $totalTipo ?> resultado(s)</div></div>
    </div>
    <div class="com-panel-body">
        <form method="get" class="com-filter">
            <input type="hidden" name="tipo" value="<?= com_e($tipo) ?>">
            <div><label class="com-label">Buscar</label><input class="com-control" type="search" name="q" value="<?= com_e($q) ?>" placeholder="RUN, nombre, curso, cargo, teléfono o email"></div>
            <div><label class="com-label">Estado</label><select class="com-control" name="activo"><option value="activos" <?= $activo==='activos'?'selected':'' ?>>Activos</option><option value="inactivos" <?= $activo==='inactivos'?'selected':'' ?>>Inactivos</option><option value="todos" <?= $activo==='todos'?'selected':'' ?>>Todos</option></select></div>
            <button class="com-submit" type="submit"><i class="bi bi-search"></i> Buscar</button>
            <a class="com-link" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= urlencode($tipo) ?>">Limpiar</a>
        </form>
    </div>
</section>

<section class="com-panel">
    <div class="com-table-scroll">
        <table class="com-table">
            <thead><tr><th>Nombre</th><th>RUN</th><?php if ($tipo==='alumnos'): ?><th>Curso</th><th>Condición</th><?php else: ?><th>Cargo / Relación</th><th>Contacto</th><?php endif; ?><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if (!$registros): ?>
                <tr><td colspan="6" class="com-muted" style="text-align:center;padding:2rem;">No hay registros para los filtros seleccionados.</td></tr>
            <?php endif; ?>
            <?php foreach ($registros as $r): ?>
                <tr>
                    <td><div class="com-main"><?= com_e((string)($r['nombre_display'] ?? com_nombre_persona($r))) ?></div><div class="com-muted">ID <?= (int)$r['id'] ?></div></td>
                    <td><?= com_e((string)$r['run']) ?></td>
                    <?php if ($tipo==='alumnos'): ?>
                        <td><?= com_e((string)($r['curso'] ?? '-')) ?></td><td><?= com_e((string)($r['condicion_especial'] ?? '-')) ?></td>
                    <?php else: ?>
                        <td><?= com_e((string)($r['cargo'] ?? $r['tipo_relacion'] ?? '-')) ?></td><td><div><?= com_e((string)($r['telefono'] ?? '-')) ?></div><div class="com-muted"><?= com_e((string)($r['email'] ?? '-')) ?></div></td>
                    <?php endif; ?>
                    <td><span class="com-badge <?= (int)($r['activo'] ?? 1) === 1 ? 'ok' : 'off' ?>"><?= (int)($r['activo'] ?? 1) === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                    <td>
                        <a class="com-link" href="<?= APP_URL ?>/modules/comunidad/editar.php?tipo=<?= urlencode($tipo) ?>&id=<?= (int)$r['id'] ?>"><i class="bi bi-pencil"></i> Editar</a>
                        <?php if ($tipo === 'alumnos'): ?><a class="com-link green" href="<?= APP_URL ?>/modules/comunidad/vincular_apoderado.php?alumno_id=<?= (int)$r['id'] ?>"><i class="bi bi-people"></i> Apoderados</a><?php endif; ?>
                        <?php if ($puedeGestionar): ?><form method="post" action="<?= APP_URL ?>/modules/comunidad/toggle_estado.php" style="display:inline;"><?= CSRF::field() ?><input type="hidden" name="tipo" value="<?= com_e($tipo) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="com-action-btn <?= (int)($r['activo'] ?? 1) === 1 ? 'red' : 'green' ?>" type="submit"><?= (int)($r['activo'] ?? 1) === 1 ? 'Desactivar' : 'Activar' ?></button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
