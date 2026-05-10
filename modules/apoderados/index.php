<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo    = DB::conn();
$user   = Auth::user();
$cid    = (int)($user['colegio_id'] ?? 0);
$buscar = trim((string)($_GET['q'] ?? ''));
$activo = (string)($_GET['activo'] ?? '1');
$msgOk  = (string)($_GET['msg_ok']  ?? '');
$msgErr = (string)($_GET['msg_err'] ?? '');

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);
    $accion = (string)($_POST['_accion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    if ($accion === 'toggle_activo' && $id > 0) {
        $r = $pdo->prepare('SELECT activo FROM apoderados WHERE id=? AND colegio_id=? LIMIT 1');
        $r->execute([$id, $cid]);
        $row = $r->fetch();
        if ($row) {
            $nuevo = (int)$row['activo'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE apoderados SET activo=?, updated_at=NOW() WHERE id=? AND colegio_id=?')
                ->execute([$nuevo, $id, $cid]);
            $msg = $nuevo ? 'Apoderado+activado.' : 'Apoderado+inactivado.';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?activo=' . $activo . '&msg_ok=' . $msg);
            exit;
        }
    }
}

// Query principal — nombre simple directo desde columna 'nombre'
$where  = ['a.colegio_id = ?'];
$params = [$cid];
if ($activo !== '') { $where[] = 'a.activo = ?'; $params[] = (int)$activo; }
if ($buscar !== '') {
    $like = '%' . $buscar . '%';
    $where[] = '(a.run LIKE ? OR a.nombre LIKE ? OR a.nombres LIKE ? OR a.apellido_paterno LIKE ? OR a.apellido_materno LIKE ? OR a.email LIKE ?)';
    $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like;
}

$sql = "
    SELECT a.*,
        CONCAT_WS(' ', a.apellido_paterno, a.apellido_materno, a.nombres) AS nombre_completo,
        COALESCE(NULLIF(a.apellido_paterno,''), a.nombre) AS nombre_orden,
        GROUP_CONCAT(DISTINCT COALESCE(aa.parentesco, aa.tipo_relacion, '') ORDER BY aa.id SEPARATOR ', ') AS parentescos,
        GROUP_CONCAT(DISTINCT CONCAT_WS(' ', al.apellido_paterno, al.apellido_materno, al.nombres) ORDER BY al.apellido_paterno SEPARATOR ', ') AS alumnos_vinculados
    FROM apoderados a
    LEFT JOIN alumno_apoderado aa ON aa.apoderado_id = a.id
    LEFT JOIN alumnos al ON al.id = aa.alumno_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY a.id
    ORDER BY nombre_orden ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apoderados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contadores
$stC = $pdo->prepare('SELECT activo, COUNT(*) n FROM apoderados WHERE colegio_id=? GROUP BY activo');
$stC->execute([$cid]);
$cnt = ['total'=>0,'activos'=>0,'inactivos'=>0];
foreach ($stC->fetchAll() as $r) {
    $cnt['total'] += (int)$r['n'];
    if ((int)$r['activo']===1) $cnt['activos']=(int)$r['n']; else $cnt['inactivos']=(int)$r['n'];
}

$pageTitle = 'Apoderados · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
:root{--ap:#0369a1;--ap-border:#e2e8f0;--ap-muted:#64748b;--ap-light:#f8fafc;--ap-r:12px;}
.ap-hero{background:linear-gradient(135deg,#0c4a6e,#0369a1,#0ea5e9);border-radius:var(--ap-r);color:#fff;padding:2.25rem 2.5rem;margin-bottom:1.75rem;position:relative;overflow:hidden;}
.ap-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 80% at 95% 50%,rgba(14,165,233,.2),transparent 65%);}
.hero-chips{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.25rem;position:relative;}
.hero-chip{display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:.3em .9em;font-size:.75rem;font-weight:600;color:#bae6fd;}
.hero-chip .chip-val{font-size:.9rem;font-weight:800;color:#fff;}
.panel-card{background:#fff;border:1px solid var(--ap-border);border-radius:var(--ap-r);box-shadow:0 1px 3px rgba(0,0,0,.07);}
.panel-header{padding:1.35rem 1.5rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
.panel-title{font-size:.95rem;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:.5rem;margin:0;}
.panel-body{padding:1rem 1.5rem 1.5rem;}
.filter-bar{display:flex;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1.1rem;}
.filter-search{position:relative;flex:1;min-width:200px;max-width:320px;}
.filter-search input{width:100%;font-size:.84rem;padding:.5rem .75rem .5rem 2.25rem;border:1px solid var(--ap-border);border-radius:8px;background:var(--ap-light);color:#0f172a;outline:none;}
.filter-search input:focus{border-color:var(--ap);box-shadow:0 0 0 3px rgba(3,105,161,.12);background:#fff;}
.filter-search i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--ap-muted);font-size:.85rem;pointer-events:none;}
.filter-sel{font-size:.8rem;font-weight:600;padding:.45rem .8rem;border:1px solid var(--ap-border);border-radius:8px;background:var(--ap-light);color:#374151;cursor:pointer;outline:none;}
.ap-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.835rem;}
.ap-table thead th{font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ap-muted);padding:.65rem .85rem;border-bottom:1px solid var(--ap-border);white-space:nowrap;background:var(--ap-light);}
.ap-table tbody tr:hover td{background:#f0f9ff;}
.ap-table tbody td{padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #f1f5f9;}
.badge-par{font-size:.72rem;background:#e0f2fe;color:#0369a1;border-radius:12px;padding:.12rem .5rem;font-weight:600;display:inline-block;margin:.1rem;}
.badge-al{font-size:.7rem;background:#f0fdf4;color:#166534;border-radius:12px;padding:.1rem .45rem;display:inline-block;margin:.1rem .1rem 0 0;}
.btn-a{font-size:.74rem;font-weight:600;padding:.32rem .85rem;border-radius:7px;border:1.5px solid;background:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:all .15s;cursor:pointer;}
.btn-edit{color:var(--ap);border-color:#bae6fd;} .btn-edit:hover{background:var(--ap);color:#fff;}
.btn-tog{color:#64748b;border-color:var(--ap-border);background:var(--ap-light);}
.ap-empty{text-align:center;padding:3rem 1rem;color:#94a3b8;}
.alert-ok{background:#d1fae5;color:#065f46;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.85rem;}
.alert-err{background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.85rem;}
</style>

<div class="ap-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#bae6fd;margin-bottom:.4rem;display:block;position:relative;"><i class="bi bi-people-fill"></i> Metis · Comunidad Educativa</span>
            <h1 style="font-size:1.85rem;font-weight:800;margin-bottom:.4rem;color:#fff;position:relative;">Apoderados</h1>
            <p style="font-size:.875rem;color:#bae6fd;margin:0;position:relative;">Registro de apoderados, parentesco y vinculación con alumnos.</p>
            <div class="hero-chips">
                <span class="hero-chip"><i class="bi bi-people-fill"></i><span class="chip-val"><?= $cnt['total'] ?></span> total</span>
                <span class="hero-chip"><i class="bi bi-check-circle"></i><span class="chip-val"><?= $cnt['activos'] ?></span> activos</span>
                <span class="hero-chip"><i class="bi bi-x-circle"></i><span class="chip-val"><?= $cnt['inactivos'] ?></span> inactivos</span>
            </div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-self:center;position:relative;">
            <a href="<?= APP_URL ?>/modules/importar/index.php" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;"><i class="bi bi-file-earmark-arrow-up"></i> Importar CSV</a>
            <a href="<?= APP_URL ?>/modules/dashboard/index.php" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;font-weight:600;font-size:.84rem;padding:.45rem 1rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;"><i class="bi bi-speedometer2"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="panel-card">
    <div class="panel-header">
        <h2 class="panel-title"><span style="width:30px;height:30px;border-radius:8px;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-size:.85rem;"><i class="bi bi-people-fill"></i></span> Listado de apoderados</h2>
    </div>
    <div class="panel-body">
        <?php if ($msgOk  !== ''): ?><div class="alert-ok"><i class="bi bi-check-circle-fill"></i> <?= e($msgOk) ?></div><?php endif; ?>
        <?php if ($msgErr !== ''): ?><div class="alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($msgErr) ?></div><?php endif; ?>

        <form method="get" class="filter-bar">
            <div class="filter-search">
                <i class="bi bi-search"></i>
                <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Buscar por nombre, RUN o email…">
            </div>
            <select name="activo" class="filter-sel" onchange="this.form.submit()">
                <option value="1" <?= $activo==='1'?'selected':'' ?>>✅ Activos</option>
                <option value="0" <?= $activo==='0'?'selected':'' ?>>❌ Inactivos</option>
                <option value=""  <?= $activo==='' ?'selected':'' ?>>Todos</option>
            </select>
            <button type="submit" class="filter-sel" style="cursor:pointer;background:#fff;"><i class="bi bi-search"></i> Buscar</button>
            <a href="?" class="filter-sel" style="text-decoration:none;color:#64748b;background:#fff;"><i class="bi bi-x-circle"></i> Limpiar</a>
            <span style="font-size:.75rem;font-weight:600;color:var(--ap-muted);margin-left:auto;"><?= count($apoderados) ?> registro(s)</span>
        </form>

        <?php if (!$apoderados): ?>
            <div class="ap-empty"><i class="bi bi-people" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4;"></i><p>No se encontraron apoderados.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="ap-table">
                <thead>
                    <tr>
                        <th>Nombre</th><th>RUN</th><th>Parentesco</th><th>Alumno(s)</th><th>Contacto</th><th>Estado</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($apoderados as $ap):
                    $nombre     = trim((string)($ap['nombre_completo'] ?? (string)($ap['nombre'] ?? '')));
                    $run        = (string)($ap['run']    ?? '—');
                    $email      = (string)($ap['email']  ?? '');
                    $telefono   = (string)($ap['telefono'] ?? '');
                    $parentesco = trim((string)($ap['parentescos'] ?? ''));
                    $alumnos    = trim((string)($ap['alumnos_vinculados'] ?? ''));
                    $actv       = (int)($ap['activo'] ?? 1);
                    $apId       = (int)$ap['id'];
                ?>
                    <tr>
                        <td><strong style="color:#0f172a;"><?= e($nombre) ?: '<em style="color:#94a3b8;">Sin nombre</em>' ?></strong>
                            <?php if (!empty($ap['created_at'])): ?><br><span style="font-size:.7rem;color:#94a3b8;">Creado: <?= date('d-m-Y', strtotime((string)$ap['created_at'])) ?></span><?php endif; ?></td>
                        <td style="font-family:monospace;font-size:.8rem;color:#64748b;"><?= e($run) ?></td>
                        <td><?php
                            $pars = array_filter(array_map('trim', explode(',', $parentesco)));
                            if ($pars) { foreach ($pars as $p) echo '<span class="badge-par">' . e($p) . '</span>'; }
                            else echo '<span style="color:#94a3b8;font-size:.78rem;">—</span>';
                        ?></td>
                        <td><?php
                            $als = array_filter(array_map('trim', explode(',', $alumnos)));
                            if ($als) { foreach ($als as $al) echo '<span class="badge-al">' . e($al) . '</span>'; }
                            else echo '<span style="color:#94a3b8;font-size:.78rem;">Sin vinculación</span>';
                        ?></td>
                        <td style="font-size:.8rem;">
                            <?php if ($email !== ''): ?><div><i class="bi bi-envelope" style="color:var(--ap);"></i> <?= e($email) ?></div><?php endif; ?>
                            <?php if ($telefono !== ''): ?><div><i class="bi bi-telephone" style="color:var(--ap);"></i> <?= e($telefono) ?></div><?php endif; ?>
                        </td>
                        <td><?= $actv===1 ? '<span style="font-size:.72rem;font-weight:600;color:#0369a1;"><i class="bi bi-check-circle-fill"></i> Activo</span>' : '<span style="font-size:.72rem;font-weight:600;color:#b91c1c;"><i class="bi bi-x-circle-fill"></i> Inactivo</span>' ?></td>
                        <td>
                            <div style="display:flex;gap:.3rem;flex-wrap:nowrap;">
                                <a href="<?= APP_URL ?>/modules/comunidad/editar.php?tipo=apoderado&id=<?= $apId ?>" class="btn-a btn-edit"><i class="bi bi-pencil"></i> Editar</a>
                                <form method="post" style="display:inline;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="_accion" value="toggle_activo">
                                    <input type="hidden" name="id" value="<?= $apId ?>">
                                    <button type="submit" class="btn-a btn-tog"><?= $actv===1 ? 'Inactivar' : 'Activar' ?></button>
                                </form>
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
