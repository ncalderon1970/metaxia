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

// Filtros
$curso  = trim((string)($_GET['curso']  ?? ''));
$buscar = trim((string)($_GET['q']      ?? ''));
$activo = ($_GET['activo'] ?? '1');

$where  = ['a.colegio_id = ?'];
$params = [$cid];

if ($activo !== '') {
    $where[]  = 'a.activo = ?';
    $params[] = (int)$activo;
}
if ($curso !== '') {
    $where[]  = 'a.curso = ?';
    $params[] = $curso;
}
if ($buscar !== '') {
    $where[]  = '(a.run LIKE ? OR a.nombres LIKE ? OR a.apellido_paterno LIKE ? OR a.apellido_materno LIKE ?)';
    $like     = '%' . $buscar . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "SELECT a.*,
               CONCAT_WS(' ', a.nombres, a.apellido_paterno, a.apellido_materno) AS nombre_completo,
               a.condicion_especial, a.tiene_pie, a.diagnostico_tea
        FROM alumnos a
        LEFT JOIN alumno_condicion_especial ace ON ace.alumno_id = a.id AND ace.activo = 1
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.apellido_paterno, a.apellido_materno, a.nombres";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cursos disponibles para filtro
$cursos = $pdo->prepare("SELECT DISTINCT curso FROM alumnos WHERE colegio_id = ? AND curso != '' ORDER BY curso");
$cursos->execute([$cid]);
$listaCursos = $cursos->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Alumnos · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
:root {
    --al-navy:   #0f172a;
    --al-green:  #065f46;
    --al-accent: #10b981;
    --al-border: #e2e8f0;
    --al-muted:  #64748b;
    --al-light:  #f8fafc;
    --al-radius: 12px;
    --al-shadow: 0 1px 3px rgba(0,0,0,.07), 0 4px 14px rgba(0,0,0,.05);
}
.al-hero {
    background: linear-gradient(135deg, #064e3b 0%, #065f46 55%, #059669 100%);
    border-radius: var(--al-radius);
    color:#fff; padding:2.25rem 2.5rem;
    margin-bottom:1.75rem; position:relative; overflow:hidden;
}
.al-hero::before {
    content:'';position:absolute;inset:0;
    background: radial-gradient(ellipse 70% 80% at 95% 50%, rgba(16,185,129,.2) 0%, transparent 65%);
}
.al-hero__kicker { font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#6ee7b7;margin-bottom:.4rem;display:block;position:relative; }
.al-hero__title  { font-size:1.85rem;font-weight:800;letter-spacing:-.025em;margin-bottom:.4rem;color:#fff;position:relative; }
.al-hero__sub    { font-size:.875rem;color:#a7f3d0;margin:0;position:relative; }
.hero-chips { display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.25rem;position:relative; }
.hero-chip {
    display:inline-flex;align-items:center;gap:.45rem;
    background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
    border-radius:7px;padding:.3em .9em;font-size:.75rem;font-weight:600;color:#d1fae5;
}
.hero-chip .chip-val { font-size:.9rem;font-weight:800;color:#fff; }
.panel-card { background:#fff;border:1px solid var(--al-border);border-radius:var(--al-radius);box-shadow:var(--al-shadow); }
.panel-header { padding:1.35rem 1.5rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap; }
.panel-title { font-size:.95rem;font-weight:700;color:var(--al-navy);display:flex;align-items:center;gap:.5rem;margin:0; }
.panel-title-icon { width:30px;height:30px;border-radius:8px;background:#d1fae5;color:#065f46;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0; }
.panel-body { padding:1rem 1.5rem 1.5rem; }
.filter-bar { display:flex;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1.1rem; }
.filter-search { position:relative;flex:1;min-width:200px;max-width:320px; }
.filter-search input { width:100%;font-size:.84rem;padding:.5rem .75rem .5rem 2.25rem;border:1px solid var(--al-border);border-radius:8px;background:var(--al-light);color:var(--al-navy);outline:none;transition:border-color .15s; }
.filter-search input:focus { border-color:var(--al-accent);box-shadow:0 0 0 3px rgba(16,185,129,.12);background:#fff; }
.filter-search i { position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--al-muted);font-size:.85rem;pointer-events:none; }
.filter-select { font-size:.8rem;font-weight:600;padding:.45rem .8rem;border:1px solid var(--al-border);border-radius:8px;background:var(--al-light);color:#374151;cursor:pointer;outline:none;transition:border-color .15s; }
.filter-select:focus { border-color:var(--al-accent); }
.result-count { font-size:.75rem;font-weight:600;color:var(--al-muted);margin-left:auto;white-space:nowrap; }
.al-table { width:100%;border-collapse:separate;border-spacing:0;font-size:.835rem; }
.al-table thead tr th { font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--al-muted);padding:.65rem .85rem;border-bottom:1px solid var(--al-border);white-space:nowrap;background:var(--al-light); }
.al-table tbody tr { transition:background .1s;cursor:pointer; }
.al-table tbody tr:hover { background:#f0fdf4; }
.al-table tbody td { padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #f1f5f9; }
.badge-curso { display:inline-flex;align-items:center;font-size:.72rem;font-weight:700;padding:.25em .75em;border-radius:7px;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;white-space:nowrap; }
.badge-inactivo { background:#fef2f2;color:#b91c1c;border-color:#fecaca; }
.al-nombre { font-weight:600;color:var(--al-navy);font-size:.875rem; }
.al-run { font-size:.78rem;color:var(--al-muted);font-family:monospace; }
.btn-ver { font-size:.74rem;font-weight:600;padding:.32rem .85rem;border-radius:7px;border:1.5px solid #6ee7b7;background:#d1fae5;color:#065f46;text-decoration:none;display:inline-block;transition:all .15s;white-space:nowrap; }
.btn-ver:hover { background:#065f46;color:#fff;border-color:#065f46; }
.al-empty { text-align:center;padding:3rem 1rem;color:#94a3b8; }
.al-empty i { font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4; }
tr.hidden-row { display:none; }
</style>

<!-- Hero -->
<div class="al-hero">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <span class="al-hero__kicker"><i class="bi bi-mortarboard-fill me-1"></i>Metis · Comunidad Educativa</span>
            <h1 class="al-hero__title">Alumnos</h1>
            <p class="al-hero__sub">Registro de estudiantes del establecimiento.</p>
            <div class="hero-chips">
                <span class="hero-chip">
                    <i class="bi bi-people-fill"></i>
                    <span class="chip-val"><?= count($alumnos) ?></span> registrados
                </span>
                <?php
                $activos_count = count(array_filter($alumnos, fn($a) => $a['activo']));
                $cursos_count  = count(array_unique(array_column($alumnos, 'curso')));
                ?>
                <span class="hero-chip"><i class="bi bi-check-circle"></i><span class="chip-val"><?= $activos_count ?></span> activos</span>
                <span class="hero-chip"><i class="bi bi-grid-3x3-gap"></i><span class="chip-val"><?= $cursos_count ?></span> cursos</span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-self-center" style="position:relative;">
            <a href="<?= APP_URL ?>/modules/alumnos/crear.php"
               style="background:#10b981;color:#fff;border:none;border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="bi bi-plus-circle"></i> Nuevo alumno
            </a>
            <a href="<?= APP_URL ?>/modules/dashboard/index.php"
               style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Tabla -->
<div class="panel-card">
    <div class="panel-header">
        <h2 class="panel-title">
            <span class="panel-title-icon"><i class="bi bi-mortarboard"></i></span>
            Listado de alumnos
        </h2>
    </div>
    <div class="panel-body">
        <!-- Filtros -->
        <form method="get" class="filter-bar" id="form-filtros">
            <div class="filter-search">
                <i class="bi bi-search"></i>
                <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Buscar por nombre o RUN…" oninput="this.form.submit()">
            </div>
            <select name="curso" class="filter-select" onchange="this.form.submit()">
                <option value="">📚 Todos los cursos</option>
                <?php foreach ($listaCursos as $c): ?>
                <option value="<?= e($c) ?>" <?= $curso === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="activo" class="filter-select" onchange="this.form.submit()">
                <option value="1" <?= $activo === '1' ? 'selected' : '' ?>>✅ Activos</option>
                <option value="0" <?= $activo === '0' ? 'selected' : '' ?>>❌ Inactivos</option>
                <option value=""  <?= $activo === ''  ? 'selected' : '' ?>>Todos</option>
            </select>
            <a href="?" class="filter-select" style="text-decoration:none;color:#64748b;background:#fff;">
                <i class="bi bi-x-circle me-1"></i>Limpiar
            </a>
            <span class="result-count"><?= count($alumnos) ?> alumno<?= count($alumnos) !== 1 ? 's' : '' ?></span>
        </form>

        <?php if (!$alumnos): ?>
        <div class="al-empty">
            <i class="bi bi-mortarboard"></i>
            <p>No se encontraron alumnos con los filtros aplicados.</p>
            <a href="<?= APP_URL ?>/modules/alumnos/crear.php" class="btn-ver" style="display:inline-flex;align-items:center;gap:.4rem;margin-top:.5rem;"><i class="bi bi-plus-circle"></i> Agregar primer alumno</a>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="al-table">
                <thead>
                    <tr>
                        <th>Nombre completo</th>
                        <th>RUN</th>
                        <th>Curso</th>
                        <th>Nivel</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($alumnos as $al): ?>
                <tr onclick="window.location='<?= APP_URL ?>/modules/alumnos/ver.php?id=<?= (int)$al['id'] ?>'">
                    <td>
                        <div class="al-nombre"><?= e($al['nombre_completo']) ?></div>
                    </td>
                    <td><span class="al-run"><?= e($al['run']) ?></span></td>
                    <td>
                        <?php if ($al['curso']): ?>
                        <span class="badge-curso <?= !$al['activo'] ? 'badge-inactivo' : '' ?>"><?= e($al['curso']) ?></span>
                        <?php else: ?><span class="al-run">—</span><?php endif; ?>
                    </td>
                    <td><span class="al-run"><?= e(($al['nivel'] ?? '') ?: '—') ?></span></td>
                    <td>
                        <?php if ($al['activo']): ?>
                        <span style="font-size:.72rem;font-weight:600;color:#065f46;"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>
                        <?php else: ?>
                        <span style="font-size:.72rem;font-weight:600;color:#b91c1c;"><i class="bi bi-x-circle-fill me-1"></i>Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td onclick="event.stopPropagation()">
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/modules/alumnos/ver.php?id=<?= (int)$al['id'] ?>" class="btn-ver">Ver <i class="bi bi-arrow-right ms-1"></i></a>
                            <a href="<?= APP_URL ?>/modules/alumnos/editar.php?id=<?= (int)$al['id'] ?>" class="btn-ver" style="background:#fff8e1;color:#92400e;border-color:#fde68a;">
                                <i class="bi bi-pencil"></i>
                            </a>
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