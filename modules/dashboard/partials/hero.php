<?php // Dashboard partial: hero ?>
<section class="dash-hero">
    <h2>Panel ejecutivo Metis</h2>
    <p>
        Vista consolidada del sistema de convivencia escolar: estado técnico,
        casos activos, alertas pendientes, evidencias, comunidad educativa, importaciones y actividad reciente.
    </p>

    <div class="dash-actions">
        <a class="dash-btn green" href="<?= APP_URL ?>/modules/denuncias/crear.php">
            <i class="bi bi-plus-circle"></i>
            Nueva denuncia
        </a>

        <a class="dash-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-clipboard2-check"></i>
            Seguimiento
        </a>

        <a class="dash-btn" href="<?= APP_URL ?>/modules/comunidad/index.php">
            <i class="bi bi-people"></i>
            Comunidad
        </a>

        <a class="dash-btn" href="<?= APP_URL ?>/modules/importar/index.php">
            <i class="bi bi-file-earmark-arrow-up"></i>
            Importar
        </a>

        <?php if ($totalPendientesImportacion > 0): ?>
            <a class="dash-btn warn" href="<?= APP_URL ?>/modules/importar/pendientes.php">
                <i class="bi bi-exclamation-triangle"></i>
                Pendientes <?= number_format($totalPendientesImportacion, 0, ',', '.') ?>
            </a>
        <?php endif; ?>

        <a class="dash-btn" href="<?= APP_URL ?>/modules/reportes/index.php">
            <i class="bi bi-bar-chart"></i>
            Reportes
        </a>
    </div>
</section>
