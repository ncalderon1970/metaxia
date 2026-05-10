<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

$pageTitle = 'Importar comunidad educativa · Metis';
$pageSubtitle = 'Carga masiva de alumnos, apoderados, docentes y asistentes con validación de RUN';

$tipoActual = isset($_GET['tipo']) ? trim((string)$_GET['tipo']) : 'alumnos';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$msg = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';

$tipos = [
    'alumnos' => 'Alumnos',
    'apoderados' => 'Apoderados',
    'docentes' => 'Docentes',
    'asistentes' => 'Asistentes',
];

if (!isset($tipos[$tipoActual])) {
    $tipoActual = 'alumnos';
}

$kpis = [
    'alumnos' => 0,
    'apoderados' => 0,
    'docentes' => 0,
    'asistentes' => 0,
    'pendientes' => 0,
];

try {
    foreach (['alumnos', 'apoderados', 'docentes', 'asistentes'] as $tabla) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE colegio_id = ?");
        $s->execute([$colegioId]);
        $kpis[$tabla] = (int)$s->fetchColumn();
    }

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

    $sp = $pdo->prepare("SELECT COUNT(*) FROM importacion_pendientes WHERE colegio_id = ? AND estado = 'pendiente'");
    $sp->execute([$colegioId]);
    $kpis['pendientes'] = (int)$sp->fetchColumn();
} catch (Throwable $e) {
    // El tablero de carga no debe romper por un KPI secundario.
}

$pageHeaderActions = [
    metis_context_action('Comunidad', APP_URL . '/modules/comunidad/index.php', 'bi-people', 'secondary'),
    metis_context_action('Pendientes', APP_URL . '/modules/importar/pendientes.php', 'bi-exclamation-triangle', 'warning'),
    metis_context_action('Plantilla', APP_URL . '/modules/importar/plantilla.php?tipo=' . urlencode($tipoActual), 'bi-download', 'primary'),
    metis_context_action('Vincular apoderados', APP_URL . '/modules/importar/vincular_apoderados.php', 'bi-diagram-2', 'soft'),
];

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
.imp-hero p { margin: 0; color: #bfdbfe; max-width: 980px; line-height: 1.55; }
.imp-kpis { display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap:.75rem; margin-bottom: 1.1rem; }
.imp-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1rem; box-shadow:0 8px 20px rgba(15,23,42,.05); }
.imp-kpi strong { display:block; color:#0f172a; font-size:1.45rem; line-height:1; margin-bottom:.35rem; }
.imp-kpi span { color:#64748b; font-size:.78rem; font-weight:800; }
.imp-panel {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
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
    border: 0; background: #0f172a; color: #fff; border-radius: 7px;
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
@media (max-width: 1000px) { .imp-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 900px) { .imp-layout, .imp-form { grid-template-columns: 1fr; } .imp-hero { padding: 1.35rem; } }
</style>

<section class="imp-hero">
    <h2>Importar comunidad educativa</h2>
    <p>
        Carga masiva con validación de RUN, actualización de registros existentes y envío de errores a pendientes.
        El procesamiento trabaja sobre el esquema estable de Metis y respeta el colegio activo del usuario.
    </p>
</section>

<section class="imp-kpis" aria-label="Indicadores de comunidad educativa">
    <div class="imp-kpi"><strong><?= (int)$kpis['alumnos'] ?></strong><span>Alumnos</span></div>
    <div class="imp-kpi"><strong><?= (int)$kpis['apoderados'] ?></strong><span>Apoderados</span></div>
    <div class="imp-kpi"><strong><?= (int)$kpis['docentes'] ?></strong><span>Docentes</span></div>
    <div class="imp-kpi"><strong><?= (int)$kpis['asistentes'] ?></strong><span>Asistentes</span></div>
    <div class="imp-kpi"><strong><?= (int)$kpis['pendientes'] ?></strong><span>Pendientes</span></div>
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
                El archivo puede estar separado por punto y coma, coma o tabulación. Para Excel se recomienda usar punto y coma.
            </div>
            <div class="imp-code" style="margin-top:.85rem;">run;nombres;apellido_paterno;apellido_materno;curso;email;telefono;direccion</div>
            <ul class="imp-list" style="margin-top:.85rem;">
                <li>El RUN se limpia y valida con dígito verificador.</li>
                <li>Nombres, apellidos, curso, cargo y dirección se guardan en mayúscula.</li>
                <li>El correo se conserva en minúscula.</li>
                <li>Si el RUN ya existe en el colegio, se actualiza el registro.</li>
                <li>Si el RUN es inválido o faltan datos obligatorios, queda en pendientes.</li>
            </ul>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
