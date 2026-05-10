<section class="exp-card">
    <div class="exp-card-headline">
        <h3><i class="bi bi-clock-history"></i> Historial del expediente</h3>
        <?php if ($historial): ?>
            <span class="exp-badge soft"><?= count($historial) ?> registro(s)</span>
        <?php endif; ?>
    </div>

    <?php if (!$historial): ?>
        <div class="exp-empty">
            <i class="bi bi-clock" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
            No hay registros de historial para este expediente.
        </div>
    <?php else: ?>
        <div class="exp-timeline">
            <?php
            $iconosTipo = [
                'creacion'                => ['bi-file-earmark-plus-fill', '#2563eb', '#dbeafe'],
                'cambio_estado'           => ['bi-arrow-repeat',           '#7c3aed', '#ede9fe'],
                'declaracion'             => ['bi-chat-square-text-fill',  '#0891b2', '#cffafe'],
                'participante'            => ['bi-person-plus-fill',       '#059669', '#d1fae5'],
                'alerta'                  => ['bi-bell-fill',              '#dc2626', '#fee2e2'],
                'evidencia'               => ['bi-paperclip',              '#d97706', '#fef3c7'],
                'plan_accion'             => ['bi-list-check',             '#7c3aed', '#ede9fe'],
                'gestion'                 => ['bi-briefcase-fill',         '#0369a1', '#dbeafe'],
                'comunicacion_apoderado'  => ['bi-person-lines-fill',      '#065f46', '#d1fae5'],
                'cierre'                  => ['bi-lock-fill',              '#1e3a8a', '#dbeafe'],
                'reapertura'              => ['bi-lock-open',              '#92400e', '#fef3c7'],
                'aula_segura'             => ['bi-shield-fill-check',      '#0d9488', '#ccfbf1'],
                'analisis_ia'             => ['bi-robot',                  '#7c3aed', '#ede9fe'],
                'borrador'                => ['bi-floppy-fill',            '#64748b', '#f1f5f9'],
                'actualizacion'           => ['bi-pencil-fill',            '#475569', '#f1f5f9'],
                'registro_desde_borrador' => ['bi-save-fill',              '#059669', '#d1fae5'],
                'actualizacion_borrador'  => ['bi-floppy2-fill',           '#64748b', '#f1f5f9'],
            ];
            $defaultIcono = ['bi-circle-fill', '#94a3b8', '#f1f5f9'];
            ?>
            <?php foreach ($historial as $idx => $h):
                $tipo = (string)($h['tipo_evento'] ?? 'actualizacion');
                [$ico, $color, $bg] = $iconosTipo[$tipo] ?? $defaultIcono;
                $esUltimo = $idx === count($historial) - 1;
            ?>
            <div class="exp-timeline-item<?= $esUltimo ? ' last' : '' ?>">
                <div class="exp-timeline-left">
                    <div class="exp-timeline-icon" style="background:<?= $bg ?>;color:<?= $color ?>;">
                        <i class="bi <?= $ico ?>"></i>
                    </div>
                    <?php if (!$esUltimo): ?>
                        <div class="exp-timeline-line"></div>
                    <?php endif; ?>
                </div>
                <div class="exp-timeline-body">
                    <div class="exp-timeline-title"><?= e((string)($h['titulo'] ?? 'Sin título')) ?></div>
                    <div class="exp-timeline-meta">
                        <span class="exp-timeline-badge" style="background:<?= $bg ?>;color:<?= $color ?>;">
                            <?= e(caso_label($tipo)) ?>
                        </span>
                        <span><?= e(caso_fecha((string)$h['created_at'])) ?></span>
                        <?php if (!empty($h['usuario_nombre'])): ?>
                            · <span><?= e((string)$h['usuario_nombre']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($h['detalle'])): ?>
                        <div class="exp-timeline-text"><?= nl2br(e((string)$h['detalle'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
