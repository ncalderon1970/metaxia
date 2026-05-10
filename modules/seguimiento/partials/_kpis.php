<div class="seg-kpis">
    <div class="seg-kpi">
        <div class="seg-kpi-val"><?= $kpi['en_seguimiento'] ?></div>
        <div class="seg-kpi-lbl">Casos activos</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val <?= $kpi['riesgo_alto'] > 0 ? 'warn' : 'ok' ?>"><?= $kpi['riesgo_alto'] ?></div>
        <div class="seg-kpi-lbl">Riesgo alto/crítico</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val <?= $kpi['revision_vencida'] > 0 ? 'warn' : 'ok' ?>"><?= $kpi['revision_vencida'] ?></div>
        <div class="seg-kpi-lbl">Revisiones vencidas</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val <?= $kpi['sin_plan'] > 0 ? 'amber' : 'ok' ?>"><?= $kpi['sin_plan'] ?></div>
        <div class="seg-kpi-lbl">Sin plan de acción</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val ok"><?= $kpi['con_sesion_hoy'] ?></div>
        <div class="seg-kpi-lbl">Sesiones hoy</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val <?= $kpi['alta'] > 0 ? 'amber' : 'ok' ?>"><?= $kpi['alta'] ?></div>
        <div class="seg-kpi-lbl">Prioridad alta</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val <?= $kpi['alertas_pendientes'] > 0 ? 'warn' : 'ok' ?>"><?= $kpi['alertas_pendientes'] ?></div>
        <div class="seg-kpi-lbl">Alertas pendientes</div>
    </div>
    <div class="seg-kpi">
        <div class="seg-kpi-val <?= $kpi['sin_pauta_riesgo'] > 0 ? 'warn' : 'ok' ?>"><?= $kpi['sin_pauta_riesgo'] ?></div>
        <div class="seg-kpi-lbl">Sin pauta de riesgo</div>
    </div>
</div>
