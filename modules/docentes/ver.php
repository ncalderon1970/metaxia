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
$id   = cleanInt($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM docentes WHERE id=? AND colegio_id=?");
$stmt->execute([$id,$cid]);
$d = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$d) { http_response_code(404); exit('Docente no encontrado.'); }

$stmtCasos = $pdo->prepare("SELECT c.numero_caso,c.estado,c.semaforo,c.id AS caso_id,cp.rol_en_caso FROM caso_participantes cp JOIN casos c ON c.id=cp.caso_id WHERE cp.persona_id=? AND cp.tipo_persona='docente' AND c.colegio_id=? ORDER BY c.id DESC");
$stmtCasos->execute([$id,$cid]);
$casos = $stmtCasos->fetchAll(PDO::FETCH_ASSOC);

$ok = isset($_GET['ok']);
$pageTitle = e($d['nombre']) . ' · Docentes · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.ver-hero{background:linear-gradient(135deg,#3b0764 0%,#5b21b6 55%,#7c3aed 100%);border-radius:12px;color:#fff;padding:2rem 2.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden;}
.ver-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 70% 80% at 95% 50%,rgba(124,58,237,.2) 0%,transparent 65%);}
.info-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);padding:1.5rem;margin-bottom:1.25rem;}
.info-section-title{font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#7c3aed;border-bottom:1px solid #ede9fe;padding-bottom:.4rem;margin-bottom:1rem;}
.info-row{display:flex;gap:1.5rem;flex-wrap:wrap;row-gap:.65rem;}
.info-item{min-width:140px;flex:1;}
.info-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:.2rem;}
.info-val{font-size:.875rem;color:#1e293b;font-weight:500;}
.breadcrumb-bar{display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#64748b;margin-bottom:1.25rem;}
.breadcrumb-bar a{color:#7c3aed;text-decoration:none;font-weight:600;}
.btn-edit{font-size:.8rem;font-weight:700;padding:.45rem 1.1rem;border-radius:8px;background:#fffbeb;color:#92400e;border:1.5px solid #fde68a;text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:.4rem;}
.btn-edit:hover{background:#f59e0b;color:#fff;border-color:#f59e0b;}
.caso-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;font-weight:600;color:#1d4ed8;text-decoration:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:.2rem .6rem;transition:all .15s;}
.caso-link:hover{background:#1d4ed8;color:#fff;}
</style>
<div class="breadcrumb-bar">
    <a href="<?= APP_URL ?>/modules/docentes/index.php"><i class="bi bi-person-workspace"></i> Docentes</a>
    <i class="bi bi-chevron-right" style="font-size:.65rem;"></i>
    <span><?= e($d['nombre']) ?></span>
</div>
<?php if ($ok): ?>
<div class="alert alert-success alert-dismissible mb-3" style="border-radius:10px;"><i class="bi bi-check-circle-fill me-2"></i>Datos guardados correctamente.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<div class="ver-hero">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3" style="position:relative;">
        <div>
            <div style="font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#c4b5fd;margin-bottom:.3rem;"><i class="bi bi-person-workspace me-1"></i>Ficha de docente</div>
            <div style="font-size:1.6rem;font-weight:800;letter-spacing:-.025em;color:#fff;margin-bottom:.25rem;"><?= e($d['nombre']) ?></div>
            <div style="font-size:.875rem;color:#ddd6fe;">RUN: <?= e($d['run']) ?> · <?= e($d['cargo']) ?> · <?= $d['activo'] ? '<span style="color:#c4b5fd;font-weight:700;">Activo</span>' : '<span style="color:#fca5a5;font-weight:700;">Inactivo</span>' ?></div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;">
            <a href="<?= APP_URL ?>/modules/docentes/editar.php?id=<?= $id ?>" class="btn-edit"><i class="bi bi-pencil-square"></i> Editar</a>
        </div>
    </div>
</div>
<div class="info-card">
    <div class="info-section-title"><i class="bi bi-briefcase me-1"></i>Datos laborales</div>
    <div class="info-row">
        <div class="info-item"><div class="info-label">RUN</div><div class="info-val" style="font-family:monospace;"><?= e($d['run']) ?></div></div>
        <div class="info-item"><div class="info-label">Cargo</div><div class="info-val"><?= e($d['cargo']) ?></div></div>
        <div class="info-item"><div class="info-label">Especialidad</div><div class="info-val"><?= e($d['especialidad'] ?: '—') ?></div></div>
        <div class="info-item"><div class="info-label">Horas contrato</div><div class="info-val"><?= $d['horas_contrato'] ?: '—' ?> hrs</div></div>
        <div class="info-item"><div class="info-label">Email</div><div class="info-val"><?= $d['email'] ? '<a href="mailto:' . e($d['email']) . '">' . e($d['email']) . '</a>' : '—' ?></div></div>
        <div class="info-item"><div class="info-label">Teléfono</div><div class="info-val"><?= e($d['telefono'] ?: '—') ?></div></div>
    </div>
    <?php if ($d['observaciones']): ?><div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #f1f5f9;"><div class="info-label" style="margin-bottom:.35rem;">Observaciones</div><p style="font-size:.875rem;color:#374151;margin:0;"><?= nl2br(e($d['observaciones'])) ?></p></div><?php endif; ?>
</div>
<div class="info-card">
    <div class="info-section-title"><i class="bi bi-folder2 me-1"></i>Casos de convivencia vinculados</div>
    <?php if (!$casos): ?><p style="font-size:.84rem;color:#94a3b8;">Sin casos vinculados.</p>
    <?php else: ?>
    <div class="d-flex flex-wrap gap-2">
    <?php foreach ($casos as $caso):
        $semColor = match($caso['semaforo'] ?? 'verde') {'rojo'=>'#ef4444','amarillo'=>'#f59e0b',default=>'#22c55e'};
    ?>
    <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['caso_id'] ?>" class="caso-link">
        <span style="width:8px;height:8px;border-radius:50%;background:<?= $semColor ?>;flex-shrink:0;"></span>
        <?= e($caso['numero_caso']) ?>
        <span style="color:#94a3b8;font-weight:400;">(<?= e($caso['rol_en_caso']) ?>)</span>
    </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
