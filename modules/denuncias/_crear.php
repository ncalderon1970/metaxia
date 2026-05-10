<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';

Auth::requireLogin();

$pageTitle = 'Nueva denuncia · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<div class="module-hero mb-4">
    <div class="module-hero__content">
        <span class="module-hero__kicker">Metis</span>
        <h1 class="module-hero__title">Nueva denuncia</h1>
        <p class="module-hero__text">
            Ingreso inicial del caso con relato, denunciante y contexto.
        </p>
    </div>
    <div class="module-hero__actions d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-arrow-left me-1"></i> Volver
        </a>
    </div>
</div>

<div class="card-sgce p-4">
    <form method="POST" action="<?= APP_URL ?>/modules/denuncias/guardar.php">
        <?= CSRF::field(); ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Denunciante</label>
                <input type="text" name="denunciante" maxlength="150" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label">Contexto</label>
                <input type="text" name="contexto" maxlength="150" class="form-control">
            </div>

            <div class="col-12">
                <label class="form-label">Relato</label>
                <textarea name="relato" rows="7" class="form-control" required></textarea>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary-sgce">
                <i class="bi bi-save me-1"></i> Guardar denuncia
            </button>
            <a href="<?= APP_URL ?>/modules/denuncias/index.php" class="btn btn-outline-secondary">
                Cancelar
            </a>
        </div>
    </form>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>