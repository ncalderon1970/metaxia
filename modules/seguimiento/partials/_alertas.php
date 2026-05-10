<?php if ($kpi['revision_vencida'] > 0): ?>
<div class="alerta-warn">
    <i class="bi bi-calendar-x-fill" style="font-size:1.1rem;flex-shrink:0;"></i>
    <div>
        <strong><?= $kpi['revision_vencida'] ?> caso(s) con revisión vencida.</strong>
        Revisa la columna "Próx. revisión" y actualiza el seguimiento.
    </div>
</div>
<?php endif; ?>

<?php if ($kpi['sin_plan'] > 0): ?>
<div class="alerta-warn" style="background:#fff7ed;border-color:#fed7aa;">
    <i class="bi bi-clipboard2-x-fill" style="font-size:1.1rem;flex-shrink:0;color:#c2410c;"></i>
    <div>
        <strong><?= $kpi['sin_plan'] ?> caso(s) sin plan de acción definido.</strong>
        El plan es obligatorio para documentar las acciones por participante.
    </div>
</div>
<?php endif; ?>

<?php if ($kpi['sin_pauta_riesgo'] > 0): ?>
<div class="alerta-warn" style="background:#fef2f2;border-color:#fecaca;">
    <i class="bi bi-shield-exclamation" style="font-size:1.1rem;flex-shrink:0;color:#b91c1c;"></i>
    <div>
        <strong><?= $kpi['sin_pauta_riesgo'] ?> caso(s) sin pauta de riesgo aplicada.</strong>
        La pauta de riesgo es clave para determinar la gravedad e intervención del caso.
        Ingresa a cada caso → pestaña <em>Clasificación</em>.
    </div>
</div>
<?php endif; ?>
