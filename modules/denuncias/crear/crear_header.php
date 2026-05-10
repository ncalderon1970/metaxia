<?php
// Fase 0.5.38G: encabezado, formulario, navegación por pestañas.
?>
<section class="nd-hero">
    <h2>Registrar Incidente / Denuncia</h2>
    <p>
        Ingreso inicial del caso. Primero identifica intervinientes; luego registra fecha, hora, lugar, relato y marcadores normativos preliminares vinculados a convivencia educativa y medidas formativas/disciplinarias.
    </p>
    <div style="margin-top:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
        <a class="nd-link" href="<?= APP_URL ?>/modules/denuncias/index.php"><i class="bi bi-arrow-left"></i> Volver</a>
        <a class="nd-link" href="<?= APP_URL ?>/modules/dashboard/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    </div>
</section>

<?php if (isset($_GET['status'], $_GET['msg']) && $_GET['status'] === 'error'): ?>
    <div class="nd-alert danger"><?= e((string)$_GET['msg']) ?></div>
<?php endif; ?>

<form method="post" action="<?= APP_URL ?>/modules/denuncias/guardar.php" id="formNuevaDenuncia" autocomplete="off">
    <?= CSRF::field(); ?>

    <input type="hidden" name="denunciante" id="denuncianteOculto" value="">

    <div class="nd-form-tabs" role="tablist" aria-label="Registro de denuncia">
                <button type="button" class="nd-tab-button active" data-tab-target="intervinientes">
                    <i class="bi bi-people"></i> 1. Intervinientes
                </button>
                <button type="button" class="nd-tab-button" data-tab-target="datos_denuncia">
                    <i class="bi bi-journal-text"></i> 2. Datos de la denuncia
                </button>
                <button type="button" class="nd-tab-button" data-tab-target="comunicacion_apoderado">
                    <i class="bi bi-person-lines-fill"></i> 3. Comunicación al apoderado
                </button>
                <button type="submit" form="formNuevaDenuncia" name="_submit_mode" value="borrador"
                        class="nd-btn-borrador">
                    <i class="bi bi-floppy-fill"></i> Guardar borrador
                </button>
            </div>
