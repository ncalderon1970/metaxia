<?php
declare(strict_types=1);
?>
<div class="ge-grid-2">
<section class="ge-section">
    <div class="ge-section-header">
        <h2>Alertas críticas</h2>
        <span class="ge-muted">motor preventivo</span>
    </div>
    <?php if (empty($alertasCriticas)): ?>
        <div class="ge-empty">No hay alertas críticas pendientes.</div>
    <?php else: ?>
        <table class="ge-table">
            <thead>
                <tr><th>Expediente</th><th>Tipo</th><th>Prioridad</th><th>Fecha</th></tr>
            </thead>
            <tbody>
                <?php foreach ($alertasCriticas as $alerta): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)($alerta['caso_id'] ?? 0) ?>"><?= e($alerta['numero_caso'] ?? 'Expediente') ?></a></td>
                        <td><?= e($alerta['tipo'] ?? '') ?><br><span class="ge-muted"><?= e($alerta['mensaje'] ?? '') ?></span></td>
                        <td><span class="badge bg-<?= e(metis_ge_prioridad_badge($alerta['prioridad'] ?? '')) ?>"><?= e($alerta['prioridad'] ?? 'media') ?></span></td>
                        <td><?= e(metis_ge_fecha_corta($alerta['fecha_alerta'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
