<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$user = Auth::user() ?? [];
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = in_array($rolCodigo, ['superadmin', 'director', 'convivencia', 'encargado_convivencia', 'admin_colegio'], true)
    || (method_exists('Auth', 'can') && (Auth::can('admin_sistema') || Auth::can('gestionar_usuarios')));

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Importar datos · Metis';
$pageSubtitle = 'Carga masiva de comunidad educativa con validación de RUN y control de pendientes';

$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$msg = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';
$tipoActual = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : 'apoderados';

$tipos = [
    'alumnos' => 'Alumnos',
    'apoderados' => 'Apoderados',
    'docentes' => 'Docentes',
    'asistentes' => 'Asistentes',
];

if (!isset($tipos[$tipoActual])) {
    $tipoActual = 'apoderados';
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.imp-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.imp-hero h2 { margin: 0 0 .45rem; font-size: 1.85rem; font-weight: 900; }
.imp-hero p { margin: 0; color: #bfdbfe; max-width: 920px; line-height: 1.55; }
.imp-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
.imp-btn {
    display: inline-flex; align-items: center; gap: .42rem; border-radius: 999px;
    padding: .62rem 1rem; font-size: .84rem; font-weight: 900; text-decoration: none;
    border: 1px solid rgba(255,255,255,.28); color: #fff; background: rgba(255,255,255,.12);
}
.imp-btn:hover { color: #fff; }
.imp-panel {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06); overflow: hidden; margin-bottom: 1.2rem;
}
.imp-panel-head {
    padding: 1rem 1.2rem; border-bottom: 1px solid #e2e8f0;
    display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap;
}
.imp-panel-title { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 900; }
.imp-panel-body { padding: 1.2rem; }
.imp-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, .55fr); gap: 1.2rem; align-items: start; }
.imp-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem; }
.imp-field.full { grid-column: 1 / -1; }
.imp-label { display: block; color: #334155; font-size: .76rem; font-weight: 900; margin-bottom: .35rem; }
.imp-control { width: 100%; border: 1px solid #cbd5e1; border-radius: 13px; padding: .72rem .8rem; outline: none; background: #fff; font-size: .92rem; }
.imp-submit, .imp-link {
    display: inline-flex; align-items: center; justify-content: center; gap: .35rem;
    border: 0; background: #0f172a; color: #fff; border-radius: 999px;
    padding: .72rem 1rem; font-weight: 900; font-size: .86rem; text-decoration: none; cursor: pointer;
}
.imp-submit.green, .imp-link.green { background: #059669; color: #fff; border: 1px solid #10b981; }
.imp-link { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.imp-msg { border-radius: 14px; padding: .9rem 1rem; margin-bottom: 1rem; font-weight: 800; }
.imp-msg.ok { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
.imp-msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.imp-note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 16px; padding: 1rem; line-height: 1.5; font-size: .9rem; }
.imp-list { margin: 0; padding-left: 1.15rem; color: #475569; line-height: 1.6; }
.imp-code { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:.75rem; font-family: monospace; font-size:.82rem; color:#334155; overflow:auto; }
@media (max-width: 900px) { .imp-layout, .imp-form { grid-template-columns: 1fr; } .imp-hero { padding: 1.35rem; } }
</style>

<section class="imp-hero">
    <h2>Importar comunidad educativa</h2>
    <p>
        Carga masiva con validación de RUN, lectura de archivos separados por punto y coma,
        actualización de duplicados y envío de errores a pendientes.
    </p>
    <div class="imp-actions">
        <a class="imp-btn" href="<?= APP_URL ?>/modules/comunidad/index.php"><i class="bi bi-people"></i> Comunidad</a>
        <a class="imp-btn" href="<?= APP_URL ?>/modules/importar/pendientes.php"><i class="bi bi-exclamation-triangle"></i> Pendientes</a>
        <a class="imp-btn" href="<?= APP_URL ?>/modules/dashboard/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="imp-msg ok"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="imp-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<div class="imp-layout">
    <section class="imp-panel">
        <div class="imp-panel-head">
            <h3 class="imp-panel-title"><i class="bi bi-file-earmark-arrow-up"></i> Cargar archivo CSV</h3>
            <a class="imp-link" href="<?= APP_URL ?>/modules/importar/plantilla.php?tipo=<?= e($tipoActual) ?>">
                <i class="bi bi-download"></i> Descargar plantilla
            </a>
        </div>
        <div class="imp-panel-body">
            <form class="imp-form" method="post" action="<?= APP_URL ?>/modules/importar/procesar.php" enctype="multipart/form-data">
                <?= CSRF::field() ?>
                <div>
                    <label class="imp-label">Tipo de datos</label>
                    <select class="imp-control" name="tipo" onchange="window.location='<?= APP_URL ?>/modules/importar/index.php?tipo=' + encodeURIComponent(this.value)">
                        <?php foreach ($tipos as $codigo => $nombre): ?>
                            <option value="<?= e($codigo) ?>" <?= $tipoActual === $codigo ? 'selected' : '' ?>><?= e($nombre) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="imp-label">Archivo CSV</label>
                    <input class="imp-control" type="file" name="archivo" accept=".csv,text/csv" required>
                </div>
                <div class="imp-field full">
                    <button class="imp-submit green" type="submit"><i class="bi bi-upload"></i> Importar archivo</button>
                    <a class="imp-link" href="<?= APP_URL ?>/modules/importar/plantilla.php?tipo=<?= e($tipoActual) ?>" style="margin-left:.5rem;">
                        <i class="bi bi-filetype-csv"></i> Plantilla <?= e($tipos[$tipoActual]) ?>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <aside class="imp-panel">
        <div class="imp-panel-head">
            <h3 class="imp-panel-title"><i class="bi bi-info-circle"></i> Formato esperado</h3>
        </div>
        <div class="imp-panel-body">
            <div class="imp-note">
                Para apoderados se aceptan estos encabezados y separador punto y coma.
            </div>
            <div class="imp-code" style="margin-top:.85rem;">run;nombres;apellido_paterno;apellido_materno;parentesco;email;telefono;direccion</div>
            <ul class="imp-list" style="margin-top:.85rem;">
                <li>El RUN se limpia y valida con dígito verificador.</li>
                <li>Los nombres y apellidos se guardan en mayúscula.</li>
                <li>El correo se conserva en minúscula.</li>
                <li>Si el RUN ya existe, se actualizan los datos faltantes o corregidos.</li>
                <li>Si el RUN es inválido, queda en pendientes para revisión.</li>
            </ul>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
