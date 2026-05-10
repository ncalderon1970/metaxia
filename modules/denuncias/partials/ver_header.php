<section class="exp-hero">
    <h2><?= e($caso['numero_caso']) ?></h2>
    <p>
        Expediente de convivencia escolar · Estado:
        <?= e($caso['estado_formal'] ?: caso_label($caso['estado'])) ?>
    </p>

    <div class="exp-actions">
        <a class="exp-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-arrow-left"></i>
            Volver al listado
        </a>

        <a class="exp-btn green" href="<?= APP_URL ?>/modules/denuncias/vincular_comunidad.php?caso_id=<?= (int)$casoId ?>">
            <i class="bi bi-person-plus"></i>
            Vincular comunidad educativa
        </a>

        <a class="exp-btn" href="<?= APP_URL ?>/modules/seguimiento/abrir.php?caso_id=<?= (int)$casoId ?>">
            <i class="bi bi-clipboard2-check"></i>
            Seguimiento
        </a>


        <a class="exp-btn <?= !empty($cierreCaso) ? 'green' : '' ?>" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&tab=cierre">
            <i class="bi bi-check2-square"></i>
            Cierre formal
        </a>


        <?php if (!empty($resumenAulaSegura['visible'])): ?>
            <a class="exp-btn warn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&tab=aula_segura">
                <i class="bi bi-exclamation-triangle"></i>
                Aula Segura
            </a>
        <?php endif; ?>

        <a class="exp-btn" href="<?= APP_URL ?>/modules/denuncias/reporte_ejecutivo.php?id=<?= (int)$casoId ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer"></i>
            Reporte ejecutivo
        </a>

        <a class="exp-btn warn" href="<?= APP_URL ?>/modules/alertas/index.php">
            <i class="bi bi-bell"></i>
            Alertas
        </a>
    </div>
</section>