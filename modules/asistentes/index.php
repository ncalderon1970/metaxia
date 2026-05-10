<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
Auth::requireLogin();

$pdo  = DB::conn();
$user = Auth::user();
$cid  = (int)$user['colegio_id'];
$buscar = trim((string)($_GET['q'] ?? ''));
$activo = ($_GET['activo'] ?? '1');

$where = ['colegio_id = ?']; $params = [$cid];
if ($activo !== '') { $where[] = 'activo = ?'; $params[] = (int)$activo; }
if ($buscar !== '') {
    $like = '%' . $buscar . '%';
    $where[] = '(run LIKE ? OR nombre LIKE ? OR cargo LIKE ? OR area LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$stmt = $pdo->prepare("SELECT * FROM asistentes WHERE " . implode(' AND ', $where) . " ORDER BY nombre");
$stmt->execute($params);
$lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Asistentes · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.as-hero{background:linear-gradient(135deg,#1e3a5f 0%,#1e40af 55%,#2563eb 100%);border-radius:12px;color:#fff;padding:2.25rem 2.5rem;margin-bottom:1.75rem;position:relative;overflow:hidden;}
.as-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 80% at 95% 50%,rgba(59,130,246,.2) 0%,transparent 65%);}
.hero-chips{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.25rem;position:relative;}
.hero-chip{display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:.3em .9em;font-size:.75rem;font-weight:600;color:#bfdbfe;}
.hero-chip .chip-val{font-size:.9rem;font-weight:800;color:#fff;}
.panel-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);}
.panel-header{padding:1.35rem 1.5rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
.panel-title{font-size:.95rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:.5rem;margin:0;}
.panel-title-icon{width:30px;height:30px;border-radius:8px;background:#dbeafe;color:#1d4ed8;display:flex;align-items:center;justify-content:center;font-size:.85rem;}
.panel-body{padding:1rem 1.5rem 1.5rem;}
.filter-bar{display:flex;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1.1rem;}
.filter-search{position:relative;flex:1;min-width:200px;max-width:320px;}
.filter-search input{width:100%;font-size:.84rem;padding:.5rem .75rem .5rem 2.25rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;color:#0f172a;outline:none;}
.filter-search input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);background:#fff;}
.filter-search i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:#64748b;font-size:.85rem;pointer-events:none;}
.filter-select{font-size:.8rem;font-weight:600;padding:.45rem .8rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;color:#374151;cursor:pointer;outline:none;}
.as-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.835rem;}
.as-table thead tr th{font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;padding:.65rem .85rem;border-bottom:1px solid #e2e8f0;white-space:nowrap;background:#f8fafc;}
.as-table tbody tr{transition:background .1s;cursor:pointer;}
.as-table tbody tr:hover{background:#eff6ff;}
.as-table tbody td{padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #f1f5f9;}
.btn-ver{font-size:.74rem;font-weight:600;padding:.32rem .85rem;border-radius:7px;border:1.5px solid #bfdbfe;background:#dbeafe;color:#1d4ed8;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:all .15s;}
.btn-ver:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8;}
.as-empty{text-align:center;padding:3rem 1rem;color:#94a3b8;}
</style>

<div class="as-hero">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#93c5fd;margin-bottom:.4rem;display:block;position:relative;"><i class="bi bi-shield-person me-1"></i>Metis · Comunidad Educativa</span>
            <h1 style="font-size:1.85rem;font-weight:800;letter-spacing:-.025em;margin-bottom:.4rem;color:#fff;position:relative;">Asistentes de la educación</h1>
            <p style="font-size:.875rem;color:#bfdbfe;margin:0;position:relative;">Inspectores, psicólogos, orientadores, auxiliares y técnicos del establecimiento.</p>
            <div class="hero-chips">
                <span class="hero-chip"><i class="bi bi-people"></i><span class="chip-val"><?= count($lista) ?></span> registrados</span>
                <span class="hero-chip"><i class="bi bi-check-circle"></i><span class="chip-val"><?= count(array_filter($lista, fn($a) => $a['activo'])) ?></span> activos</span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-self-center" style="position:relative;">
            <a href="<?= APP_URL ?>/modules/asistentes/crear.php" style="background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;"><i class="bi bi-plus-circle"></i> Nuevo asistente</a>
            <a href="<?= APP_URL ?>/modules/dashboard/index.php" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="panel-card">
    <div class="panel-header"><h2 class="panel-title"><span class="panel-title-icon"><i class="bi bi-shield-person"></i></span>Listado</h2></div>
    <div class="panel-body">
        <form method="get" class="filter-bar">
            <div class="filter-search"><i class="bi bi-search"></i><input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Buscar por nombre, RUN o cargo…"></div>
            <select name="activo" class="filter-select" onchange="this.form.submit()">
                <option value="1" <?= $activo==='1'?'selected':'' ?>>✅ Activos</option>
                <option value="0" <?= $activo==='0'?'selected':'' ?>>❌ Inactivos</option>
                <option value="" <?= $activo===''?'selected':'' ?>>Todos</option>
            </select>
            <button type="submit" class="filter-select" style="cursor:pointer;background:#fff;color:#64748b;">🔍 Buscar</button>
            <a href="?" class="filter-select" style="text-decoration:none;color:#64748b;background:#fff;"><i class="bi bi-x-circle me-1"></i>Limpiar</a>
            <span style="font-size:.75rem;font-weight:600;color:#64748b;margin-left:auto;"><?= count($lista) ?> resultado<?= count($lista)!==1?'s':'' ?></span>
        </form>
        <?php if (!$lista): ?>
        <div class="as-empty"><i class="bi bi-shield-person" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4;"></i><p>No se encontraron asistentes.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="as-table">
                <thead><tr><th>Nombre</th><th>RUN</th><th>Cargo</th><th>Área</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $a): ?>
                <tr onclick="window.location='<?= APP_URL ?>/modules/asistentes/ver.php?id=<?= (int)$a['id'] ?>'">
                    <td style="font-weight:600;color:#0f172a;"><?= e($a['nombre']) ?></td>
                    <td style="font-size:.78rem;color:#64748b;font-family:monospace;"><?= e($a['run']) ?></td>
                    <td style="font-size:.82rem;color:#374151;"><?= e($a['cargo'] ?: '—') ?></td>
                    <td style="font-size:.82rem;color:#374151;"><?= e($a['area'] ?: '—') ?></td>
                    <td><?= $a['activo'] ? '<span style="font-size:.72rem;font-weight:600;color:#1d4ed8;"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>' : '<span style="font-size:.72rem;font-weight:600;color:#b91c1c;"><i class="bi bi-x-circle-fill me-1"></i>Inactivo</span>' ?></td>
                    <td onclick="event.stopPropagation()">
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/modules/asistentes/ver.php?id=<?= (int)$a['id'] ?>" class="btn-ver">Ver <i class="bi bi-arrow-right ms-1"></i></a>
                            <a href="<?= APP_URL ?>/modules/asistentes/editar.php?id=<?= (int)$a['id'] ?>" class="btn-ver" style="background:#fff8e1;color:#92400e;border-color:#fde68a;"><i class="bi bi-pencil"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
