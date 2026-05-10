<?php
// Helpers locales de color
$semColor = ['verde'=>'#22c55e','amarillo'=>'#f59e0b','rojo'=>'#ef4444'];
$semLabel = ['verde'=>'Verde','amarillo'=>'Amarillo','rojo'=>'Rojo'];
$prioColor = ['baja'=>'#64748b','media'=>'#3b82f6','alta'=>'#ef4444'];
$prioIcon  = ['baja'=>'bi-arrow-down','media'=>'bi-dash','alta'=>'bi-arrow-up'];
?>

<div class="row g-4">

    <!-- ── Columna principal: Resumen ── -->
    <div class="col-xl-7">
        <div class="tab-section-title">
            <span class="icon-box"><i class="bi bi-layout-text-sidebar-reverse"></i></span>
            Resumen del caso
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label">Denunciante</label>
                <div class="form-control bg-light border-0 fs-sm" style="font-size:.875rem; color:#334155;">
                    <?= e((string)($caso['denunciante_nombre'] ?? '—')) ?>
                </div>
            </div>
            <div class="col-sm-6">
                <label class="form-label">Contexto</label>
                <div class="form-control bg-light border-0 fs-sm" style="font-size:.875rem; color:#334155;">
                    <?= e((string)($caso['contexto'] ?? '—')) ?>
                </div>
            </div>
        </div>

        <div class="mb-1">
            <label class="form-label">Relato del caso</label>
            <div class="form-control bg-light border-0"
                 style="min-height:160px; font-size:.875rem; color:#334155; line-height:1.7; height:auto;">
                <?= nl2br(e((string)$caso['relato'])) ?>
            </div>
        </div>
    </div>

    <!-- ── Columna lateral: informes + estado ── -->
    <div class="col-xl-5">

        <!-- Informes -->
        <div class="tab-section-title">
            <span class="icon-box"><i class="bi bi-file-earmark-text"></i></span>
            Informes
        </div>

        <div class="d-grid gap-2 mb-4">
            <a class="btn btn-outline-primary d-flex align-items-center gap-2" target="_blank"
               href="<?= APP_URL ?>/modules/informes/informe_caso_pdf.php?id=<?= (int)$caso['id'] ?>&modo=interno"
               style="border-radius:8px; font-size:.84rem; font-weight:600; border-width:1.5px;">
                <i class="bi bi-file-earmark-text"></i> Informe interno
            </a>

            <?php if (tiene_permiso('ver_informes')): ?>
            <a class="btn btn-outline-secondary d-flex align-items-center gap-2" target="_blank"
               href="<?= APP_URL ?>/modules/informes/informe_caso_pdf.php?id=<?= (int)$caso['id'] ?>&modo=autoridad"
               style="border-radius:8px; font-size:.84rem; font-weight:600; border-width:1.5px;">
                <i class="bi bi-building"></i> Informe para autoridad
            </a>
            <?php endif; ?>

            <a class="btn d-flex align-items-center gap-2"
               href="<?= APP_URL ?>/modules/seguimiento/index.php?caso_id=<?= (int)$caso['id'] ?>"
               style="background:#f0fdf4; color:#15803d; border:1.5px solid #bbf7d0; border-radius:8px; font-size:.84rem; font-weight:600;">
                <i class="bi bi-clipboard2-check"></i> Ir a seguimiento
            </a>
        </div>

        <!-- Estado del caso -->
        <div class="tab-section-title">
            <span class="icon-box"><i class="bi bi-sliders"></i></span>
            Estado del caso
        </div>

        <form method="POST" action="<?= APP_URL ?>/modules/denuncias/actualizar_estado.php"
              class="form-panel">
            <?= CSRF::field(); ?>
            <input type="hidden" name="caso_id" value="<?= (int)$caso['id'] ?>">

            <div class="mb-3">
                <label class="form-label">Estado formal</label>
                <select name="estado_caso_id" class="form-select" required>
                    <?php foreach ($estadosCaso as $ec): ?>
                        <option value="<?= (int)$ec['id'] ?>"
                            <?= $estadoActualId === (int)$ec['id'] ? 'selected' : '' ?>>
                            <?= e((string)$ec['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label">Prioridad</label>
                    <select name="prioridad" class="form-select" required>
                        <option value="baja"  <?= (($caso['prioridad'] ?? '') === 'baja')  ? 'selected' : '' ?>>Baja</option>
                        <option value="media" <?= (($caso['prioridad'] ?? '') === 'media') ? 'selected' : '' ?>>Media</option>
                        <option value="alta"  <?= (($caso['prioridad'] ?? '') === 'alta')  ? 'selected' : '' ?>>Alta</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label">Semáforo</label>
                    <select name="semaforo" class="form-select" required>
                        <option value="verde"    <?= (($caso['semaforo'] ?? '') === 'verde')    ? 'selected' : '' ?>>🟢 Verde</option>
                        <option value="amarillo" <?= (($caso['semaforo'] ?? '') === 'amarillo') ? 'selected' : '' ?>>🟡 Amarillo</option>
                        <option value="rojo"     <?= (($caso['semaforo'] ?? '') === 'rojo')     ? 'selected' : '' ?>>🔴 Rojo</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary-sgce w-100">
                <i class="bi bi-save me-1"></i> Actualizar estado
            </button>
        </form>

    </div>
</div>
