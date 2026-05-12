<?php
declare(strict_types=1);
$items = [
    ['label' => 'Alertas pendientes', 'value' => (int)($kpis['alertas_pendientes'] ?? 0)],
    ['label' => 'Alertas críticas', 'value' => (int)($kpis['alertas_criticas'] ?? 0)],
    ['label' => 'Acciones abiertas', 'value' => (int)($kpis['acciones_abiertas'] ?? 0)],
    ['label' => 'Acciones vencidas', 'value' => (int)($kpis['acciones_vencidas'] ?? 0)],
    ['label' => 'Casos abiertos', 'value' => (int)($kpis['casos_abiertos'] ?? 0)],
    ['label' => 'Sin movimiento', 'value' => (int)($kpis['casos_sin_movimiento'] ?? 0)],
];
?>
<div class="ge-kpi-grid">
    <?php foreach ($items as $item): ?>
        <article class="ge-kpi-card">
            <div class="ge-kpi-label"><?= e($item['label']) ?></div>
            <div class="ge-kpi-value"><?= number_format((int)$item['value'], 0, ',', '.') ?></div>
        </article>
    <?php endforeach; ?>
</div>
