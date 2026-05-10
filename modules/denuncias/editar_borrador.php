<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) { http_response_code(403); exit('Acceso no autorizado.'); }

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$casoId    = (int)($_GET['id'] ?? 0);

if ($casoId <= 0) { header('Location: ' . APP_URL . '/modules/denuncias/index.php'); exit; }

// ── Cargar el borrador ─────────────────────────────────────
$sc = $pdo->prepare("
    SELECT c.* FROM casos c
    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    WHERE c.id = ? AND c.colegio_id = ?
      AND (c.estado = 'borrador' OR ec.codigo = 'borrador')
    LIMIT 1
");
$sc->execute([$casoId, $colegioId]);
$borrador = $sc->fetch();

if (!$borrador) {
    header('Location: ' . APP_URL . '/modules/denuncias/index.php');
    exit;
}

// ── Cargar participantes del borrador ──────────────────────
$sp = $pdo->prepare("
    SELECT cp.*, cp.nombre_referencial AS nombre, cp.run AS run_participante
    FROM caso_participantes cp
    WHERE cp.caso_id = ?
    ORDER BY cp.id ASC
");
$sp->execute([$casoId]);
$participantes = $sp->fetchAll();

$pageTitle = 'Completar borrador · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.eb-hero {
    background: linear-gradient(135deg,#78350f 0%,#b45309 55%,#d97706 100%);
    color:#fff;border-radius:16px;padding:1.5rem 1.75rem;margin-bottom:1.25rem;
    display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
}
.eb-hero h2 { font-size:1.15rem;font-weight:700;margin:0 0 .25rem; }
.eb-hero p  { font-size:.84rem;color:rgba(255,255,255,.75);margin:0; }
.eb-badge   { background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);
              border-radius:20px;padding:.22rem .75rem;font-size:.76rem;font-weight:700; }
.eb-actions { display:flex;gap:.55rem;flex-wrap:wrap; }
.eb-btn-back { background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);
               color:#fff;border-radius:8px;padding:.48rem 1rem;font-size:.84rem;
               font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem; }
.eb-btn-back:hover { background:rgba(255,255,255,.24);color:#fff; }
.eb-card { background:#fff;border:1px solid #e2e8f0;border-radius:14px;
           box-shadow:0 1px 3px rgba(15,23,42,.06);overflow:hidden;margin-bottom:1rem; }
.eb-card-head { padding:.75rem 1.1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
                color:#2563eb;display:flex;align-items:center;gap:.4rem; }
.eb-card-body { padding:1.1rem; }
.eb-field-row { display:grid;grid-template-columns:160px 1fr;gap:.35rem .85rem;
                font-size:.88rem;padding:.35rem 0;border-bottom:1px solid #f1f5f9;
                align-items:baseline; }
.eb-field-row:last-child { border-bottom:none; }
.eb-field-key { font-size:.76rem;font-weight:600;color:#64748b; }
.eb-field-val { color:#0f172a; }
.eb-field-val.empty { color:#94a3b8;font-style:italic; }
.eb-part-item { display:flex;align-items:center;gap:.65rem;padding:.55rem .75rem;
                border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.4rem;background:#f8fafc; }
.eb-part-name { font-size:.88rem;font-weight:600;color:#0f172a;flex:1; }
.eb-part-rol  { font-size:.72rem;font-weight:600;padding:.14rem .55rem;border-radius:20px;
                background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe; }
.eb-cta { background:#1e3a8a;color:#fff;border:none;border-radius:10px;
          padding:.75rem 1.75rem;font-size:.88rem;font-weight:600;cursor:pointer;
          font-family:inherit;display:inline-flex;align-items:center;gap:.5rem;
          transition:background .15s; }
.eb-cta:hover { background:#1e40af; }
.eb-note { font-size:.8rem;color:#64748b;margin-top:.65rem;line-height:1.5; }
</style>

<!-- Hero -->
<div class="eb-hero">
    <div>
        <h2><i class="bi bi-pencil-fill"></i> Completar borrador</h2>
        <p>N° <?= e((string)($borrador['numero_caso'] ?? "CASO-{$casoId}")) ?>
           · Guardado el <?= e(date('d/m/Y H:i', strtotime((string)($borrador['fecha_ingreso'] ?? 'now')))) ?>
        </p>
    </div>
    <div class="eb-actions">
        <span class="eb-badge"><i class="bi bi-floppy-fill"></i> Borrador</span>
        <a class="eb-btn-back" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
    </div>
</div>

<!-- Datos ya guardados -->
<div class="eb-card">
    <div class="eb-card-head"><i class="bi bi-journal-text"></i> Datos guardados en este borrador</div>
    <div class="eb-card-body">
        <div class="eb-field-row">
            <span class="eb-field-key">Relato</span>
            <span class="eb-field-val <?= empty($borrador['relato']) || str_contains((string)$borrador['relato'],'Borrador') ? 'empty' : '' ?>">
                <?= nl2br(e(substr((string)($borrador['relato'] ?? ''), 0, 300))) ?>
                <?= strlen((string)($borrador['relato'] ?? '')) > 300 ? '…' : '' ?>
            </span>
        </div>
        <div class="eb-field-row">
            <span class="eb-field-key">Lugar hechos</span>
            <span class="eb-field-val <?= empty($borrador['lugar_hechos']) ? 'empty' : '' ?>">
                <?= e((string)($borrador['lugar_hechos'] ?? '')) ?: '—' ?>
            </span>
        </div>
        <div class="eb-field-row">
            <span class="eb-field-key">Contexto</span>
            <span class="eb-field-val <?= empty($borrador['contexto']) ? 'empty' : '' ?>">
                <?= e((string)($borrador['contexto'] ?? '')) ?: '—' ?>
            </span>
        </div>
        <div class="eb-field-row">
            <span class="eb-field-key">Fecha del hecho</span>
            <span class="eb-field-val <?= empty($borrador['fecha_hora_incidente']) ? 'empty' : '' ?>">
                <?php
                $fhi = (string)($borrador['fecha_hora_incidente'] ?? '');
                echo $fhi ? e(date('d/m/Y H:i', strtotime($fhi))) : '—';
                ?>
            </span>
        </div>
        <div class="eb-field-row">
            <span class="eb-field-key">Intervinientes</span>
            <span class="eb-field-val">
                <?= count($participantes) ?> registrado(s)
            </span>
        </div>
    </div>
</div>

<?php if ($participantes): ?>
<div class="eb-card">
    <div class="eb-card-head"><i class="bi bi-people"></i> Intervinientes registrados</div>
    <div class="eb-card-body">
        <?php foreach ($participantes as $p): ?>
        <div class="eb-part-item">
            <span class="eb-part-name"><?= e((string)($p['nombre_referencial'] ?? $p['nombre'] ?? '—')) ?></span>
            <span class="eb-part-rol"><?= e(ucfirst((string)($p['rol_en_caso'] ?? $p['tipo_participante'] ?? ''))) ?></span>
            <?php if (!empty($p['run_participante'])): ?>
                <span style="font-size:.76rem;color:#64748b;">RUN <?= e((string)$p['run_participante']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Formulario oculto que redirige a crear con el id del borrador -->
<form method="get" action="<?= APP_URL ?>/modules/denuncias/crear.php">
    <input type="hidden" name="borrador_id" value="<?= $casoId ?>">

    <div style="text-align:center;padding:1.5rem 0 .5rem;">
        <button type="submit" class="eb-cta">
            <i class="bi bi-pencil-fill"></i> Continuar completando la denuncia
        </button>
        <p class="eb-note">
            Al continuar, el formulario se abrirá con los datos ya guardados.<br>
            Una vez completado, haz clic en <strong>"Registrar denuncia"</strong> para cambiar el estado a activo.
        </p>
    </div>
</form>

<?php
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
ob_end_flush();
?>
