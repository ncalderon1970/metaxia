<?php
declare(strict_types=1);
?>
<section class="ge-section">
    <div class="ge-section-header">
        <h2>Acciones ejecutivas pendientes</h2>
        <span class="ge-muted">instrucciones y compromisos</span>
    </div>
    <?php if (empty($accionesPendientes)): ?>
        <div class="ge-empty">No hay acciones ejecutivas abiertas.</div>
    <?php else: ?>
        <table class="ge-table">
            <thead>
                <tr><th>Expediente</th><th>Acción</th><th>Responsable</th><th>Compromiso</th><th>Estado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($accionesPendientes as $accion): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)($accion['caso_id'] ?? 0) ?>"><?= e($accion['numero_caso'] ?? 'Expediente') ?></a></td>
                        <td><strong><?= e($accion['titulo'] ?? 'Acción ejecutiva') ?></strong><br><span class="badge bg-<?= e(metis_ge_prioridad_badge($accion['prioridad'] ?? '')) ?>"><?= e($accion['prioridad'] ?? 'media') ?></span></td>
                        <td><?= e($accion['responsable_nombre'] ?? 'Sin responsable') ?><br><span class="ge-muted"><?= e($accion['responsable_rol'] ?? '') ?></span></td>
                        <td><?= e(metis_ge_fecha_corta($accion['fecha_compromiso'] ?? null)) ?></td>
                        <td><span class="badge bg-<?= e(metis_ge_estado_badge($accion['estado'] ?? '')) ?>"><?= e($accion['estado'] ?? 'pendiente') ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</div>
