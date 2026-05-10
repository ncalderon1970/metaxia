<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();
$colegioId = (int)$user['colegio_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['_token'] ?? null)) {
        exit('CSRF inválido');
    }

    $modulo = clean($_POST['modulo_codigo'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $plan = clean($_POST['plan'] ?? '');

    if ($modulo !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO colegio_modulos (colegio_id, modulo_codigo, activo, fecha_activacion, plan)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                activo = VALUES(activo),
                fecha_activacion = NOW(),
                plan = VALUES(plan)
        ");
        $stmt->execute([$colegioId, $modulo, $activo, $plan ?: null]);
    }

    header('Location: index.php');
    exit;
}

$catalogo = $pdo->query("
    SELECT *
    FROM modulos_catalogo
    WHERE activo = 1
    ORDER BY es_premium ASC, nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT *
    FROM colegio_modulos
    WHERE colegio_id = ?
");
$stmt->execute([$colegioId]);
$activos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$estado = [];
foreach ($activos as $a) {
    $estado[$a['modulo_codigo']] = $a;
}

$pageTitle = 'Panel financiero · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<div class="module-hero mb-4">
    <div class="module-hero__content">
        <span class="module-hero__kicker">Metis</span>
        <h1 class="module-hero__title">Panel financiero</h1>
        <p class="module-hero__text">
            Activación de módulos, control de servicios y gestión del plan del establecimiento.
        </p>
    </div>
    <div class="module-hero__actions d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card-sgce p-4">
            <div class="sgce-kpi__label">Módulos totales</div>
            <div class="sgce-kpi__value"><?= count($catalogo) ?></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card-sgce p-4">
            <div class="sgce-kpi__label">Activos</div>
            <div class="sgce-kpi__value">
                <?= count(array_filter($activos, fn($m) => $m['activo'] == 1)) ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card-sgce p-4">
            <div class="sgce-kpi__label">Premium activos</div>
            <div class="sgce-kpi__value">
                <?= count(array_filter($activos, fn($m) => $m['activo'] == 1 && str_contains($m['modulo_codigo'], 'IA'))) ?>
            </div>
        </div>
    </div>
</div>

<div class="card-sgce p-4">
    <h3 class="mb-3">Módulos disponibles</h3>

    <?php foreach ($catalogo as $m):
        $e = $estado[$m['codigo']] ?? null;
        $activo = $e && $e['activo'] == 1;
    ?>
        <div class="border rounded-4 p-3 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="fw-semibold"><?= e($m['nombre']) ?></div>
                    <div class="text-muted small"><?= e((string)$m['descripcion']) ?></div>
                </div>

                <span class="badge <?= $m['es_premium'] ? 'bg-warning text-dark' : 'bg-light text-dark border' ?>">
                    <?= $m['es_premium'] ? 'Premium' : 'Base' ?>
                </span>
            </div>

            <form method="POST" class="mt-3">
                <?= CSRF::field(); ?>
                <input type="hidden" name="modulo_codigo" value="<?= e($m['codigo']) ?>">

                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Activo</label><br>
                        <input type="checkbox" name="activo" value="1" <?= $activo ? 'checked' : '' ?>>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">Plan</label>
                        <input type="text" name="plan" class="form-control"
                               value="<?= e((string)($e['plan'] ?? '')) ?>">
                    </div>

                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary-sgce w-100">
                            Guardar configuración
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>