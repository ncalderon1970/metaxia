<?php
declare(strict_types=1);
?>
<section class="ge-section">
    <div class="ge-section-header">
        <h2>Expedientes prioritarios</h2>
        <span class="ge-muted">alertas, acciones abiertas o inactividad</span>
    </div>
    <?php if (empty($casosPrioritarios)): ?>
        <div class="ge-empty">No hay expedientes prioritarios para el colegio activo.</div>
    <?php else: ?>
        <table class="ge-table">
            <thead>
                <tr>
                    <th>Expediente</th>
                    <th>Alertas</th>
                    <th>Acciones</th>
                    <th>Días sin movimiento</th>
                    <th>Acceso</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($casosPrioritarios as $caso): ?>
                    <tr>
                        <td>
                            <strong><?= e($caso['numero_caso'] ?? ('Caso #' . (int)($caso['id'] ?? 0))) ?></strong><br>
                            <span class="ge-muted"><?= e($caso['contexto'] ?? 'Sin contexto registrado') ?></span>
                        </td>
                        <td>
                            <span class="badge bg-danger"><?= (int)($caso['alertas_criticas'] ?? 0) ?> críticas</span>
                            <span class="badge bg-secondary"><?= (int)($caso['alertas_pendientes'] ?? 0) ?> total</span>
                        </td>
                        <td><?= (int)($caso['acciones_abiertas'] ?? 0) ?></td>
                        <td><?= (int)($caso['dias_sin_movimiento'] ?? 0) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)($caso['id'] ?? 0) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
