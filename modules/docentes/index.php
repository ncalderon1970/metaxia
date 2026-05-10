<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo  = DB::conn();
$user = Auth::user();
$cid  = (int)$user['colegio_id'];

$buscar = trim((string)($_GET['q'] ?? ''));
$activo = ($_GET['activo'] ?? '1');

$where  = ['colegio_id = ?'];
$params = [$cid];
if ($activo !== '') { $where[] = 'activo = ?'; $params[] = (int)$activo; }
if ($buscar !== '') {
    $like     = '%' . $buscar . '%';
    $where[]  = '(run LIKE ? OR nombre LIKE ? OR nombres LIKE ? OR apellido_paterno LIKE ? OR apellido_materno LIKE ? OR especialidad LIKE ? OR cargo LIKE ?)';
    $like2 = $like;
    $params[] = $like2; $params[] = $like2; $params[] = $like2;
    $params[] = $like; $params[] = $like;
}

$stmt = $pdo->prepare("
    SELECT *,
        CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres) AS nombre_completo,
        COALESCE(NULLIF(apellido_paterno,''), nombre) AS nombre_orden
    FROM docentes
    WHERE " . implode(' AND ', $where) . "
    ORDER BY nombre_orden ASC
");
$stmt->execute($params);
$lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Docentes · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
:root{--cl-accent:#7c3aed;--cl-border:#e2e8f0;--cl-muted:#64748b;--cl-light:#f8fafc;--cl-radius:12px;}
.cl-hero{background:linear-gradient(135deg,#3b0764 0%,#5b21b6 55%,#7c3aed 100%);border-radius:var(--cl-radius);color:#fff;padding:2.25rem 2.5rem;margin-bottom:1.75rem;position:relative;overflow:hidden;}
.cl-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 80% at 95% 50%,rgba(124,58,237,.2) 0%,transparent 65%);}
.hero-chips{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.25rem;position:relative;}
.hero-chip{display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:.3em .9em;font-size:.75rem;font-weight:600;color:#ddd6fe;}
.hero-chip .chip-val{font-size:.9rem;font-weight:800;color:#fff;}
.panel-card{background:#fff;border:1px solid var(--cl-border);border-radius:var(--cl-radius);box-shadow:0 1px 3px rgba(0,0,0,.07);}
.panel-header{padding:1.35rem 1.5rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
.panel-title{font-size:.95rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:.5rem;margin:0;}
.panel-title-icon{width:30px;height:30px;border-radius:8px;background:#ede9fe;color:#7c3aed;display:flex;align-items:center;justify-content:center;font-size:.85rem;}
.panel-body{padding:1rem 1.5rem 1.5rem;}
.filter-bar{display:flex;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1.1rem;}
.filter-search{position:relative;flex:1;min-width:200px;max-width:320px;}
.filter-search input{width:100%;font-size:.84rem;padding:.5rem .75rem .5rem 2.25rem;border:1px solid var(--cl-border);border-radius:8px;background:var(--cl-light);color:#0f172a;outline:none;}
.filter-search input:focus{border-color:var(--cl-accent);box-shadow:0 0 0 3px rgba(124,58,237,.12);background:#fff;}
.filter-search i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--cl-muted);font-size:.85rem;pointer-events:none;}
.filter-select{font-size:.8rem;font-weight:600;padding:.45rem .8rem;border:1px solid var(--cl-border);border-radius:8px;background:var(--cl-light);color:#374151;cursor:pointer;outline:none;}
.al-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.835rem;}
.al-table thead tr th{font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--cl-muted);padding:.65rem .85rem;border-bottom:1px solid var(--cl-border);white-space:nowrap;background:var(--cl-light);}
.al-table tbody tr{transition:background .1s;cursor:pointer;}
.al-table tbody tr:hover{background:#f5f3ff;}
.al-table tbody td{padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #f1f5f9;}
.btn-ver{font-size:.74rem;font-weight:600;padding:.32rem .85rem;border-radius:7px;border:1.5px solid #c4b5fd;background:#ede9fe;color:#5b21b6;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:all .15s;}
.btn-ver:hover{background:#5b21b6;color:#fff;border-color:#5b21b6;}
.al-empty{text-align:center;padding:3rem 1rem;color:#94a3b8;}
</style>

<div class="cl-hero">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#c4b5fd;margin-bottom:.4rem;display:block;position:relative;"><i class="bi bi-person-workspace me-1"></i>Metis · Comunidad Educativa</span>
            <h1 style="font-size:1.85rem;font-weight:800;letter-spacing:-.025em;margin-bottom:.4rem;color:#fff;position:relative;">Docentes</h1>
            <p style="font-size:.875rem;color:#ddd6fe;margin:0;position:relative;">Registro de docentes del establecimiento.</p>
            <div class="hero-chips">
                <span class="hero-chip"><i class="bi bi-person-workspace"></i><span class="chip-val"><?= count($lista) ?></span> docentes</span>
                <span class="hero-chip"><i class="bi bi-check-circle"></i><span class="chip-val"><?= count(array_filter($lista, fn($d) => $d['activo'])) ?></span> activos</span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-self-center" style="position:relative;">
            <a href="<?= APP_URL ?>/modules/docentes/crear.php" style="background:#7c3aed;color:#fff;border:none;border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;"><i class="bi bi-plus-circle"></i> Nuevo docente</a>
            <a href="<?= APP_URL ?>/modules/dashboard/index.php" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="panel-card">
    <div class="panel-header">
        <h2 class="panel-title"><span class="panel-title-icon"><i class="bi bi-person-workspace"></i></span>Listado de docentes</h2>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-bar">
            <div class="filter-search">
                <i class="bi bi-search"></i>
                <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Buscar por nombre, RUN o especialidad…">
            </div>
            <select name="activo" class="filter-select" onchange="this.form.submit()">
                <option value="1" <?= $activo==='1'?'selected':'' ?>>✅ Activos</option>
                <option value="0" <?= $activo==='0'?'selected':'' ?>>❌ Inactivos</option>
                <option value=""  <?= $activo==='' ?'selected':'' ?>>Todos</option>
            </select>
            <button type="submit" class="filter-select" style="cursor:pointer;background:#fff;color:#64748b;">🔍 Buscar</button>
            <a href="?" class="filter-select" style="text-decoration:none;color:#64748b;background:#fff;"><i class="bi bi-x-circle me-1"></i>Limpiar</a>
            <span style="font-size:.75rem;font-weight:600;color:var(--cl-muted);margin-left:auto;"><?= count($lista) ?> docente<?= count($lista) !== 1 ? 's' : '' ?></span>
        </form>

        <?php if (!$lista): ?>
        <div class="al-empty"><i class="bi bi-person-workspace" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4;"></i><p>No se encontraron docentes.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="al-table">
                <thead><tr><th>Nombre</th><th>RUN</th><th>Especialidad</th><th>Cargo</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $d): ?>
                <tr onclick="window.location='<?= APP_URL ?>/modules/docentes/ver.php?id=<?= (int)$d['id'] ?>'">
                    <td style="font-weight:600;color:#0f172a;"><?= e(trim((string)($d['nombre_completo'] ?: $d['nombre']))) ?></td>
                    <td style="font-size:.78rem;color:#64748b;font-family:monospace;"><?= e($d['run']) ?></td>
                    <td style="font-size:.82rem;color:#374151;"><?= e($d['especialidad'] ?: '—') ?></td>
                    <td style="font-size:.82rem;color:#374151;"><?= e($d['cargo'] ?: '—') ?></td>
                    <td><?= $d['activo'] ? '<span style="font-size:.72rem;font-weight:600;color:#5b21b6;"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>' : '<span style="font-size:.72rem;font-weight:600;color:#b91c1c;"><i class="bi bi-x-circle-fill me-1"></i>Inactivo</span>' ?></td>
                    <td onclick="event.stopPropagation()">
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/modules/docentes/ver.php?id=<?= (int)$d['id'] ?>" class="btn-ver">Ver <i class="bi bi-arrow-right ms-1"></i></a>
                            <a href="<?= APP_URL ?>/modules/docentes/editar.php?id=<?= (int)$d['id'] ?>" class="btn-ver" style="background:#fff8e1;color:#92400e;border-color:#fde68a;"><i class="bi bi-pencil"></i></a>
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
