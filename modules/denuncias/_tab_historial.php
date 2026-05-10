<?php
$eventoIcon = [
    'creacion'       => ['icon' => 'bi-plus-circle-fill',   'color' => '#22c55e'],
    'estado'         => ['icon' => 'bi-arrow-repeat',        'color' => '#3b82f6'],
    'participante'   => ['icon' => 'bi-person-plus-fill',    'color' => '#8b5cf6'],
    'declaracion'    => ['icon' => 'bi-chat-left-quote-fill','color' => '#0ea5e9'],
    'evidencia'      => ['icon' => 'bi-paperclip',           'color' => '#f59e0b'],
    'analisis'       => ['icon' => 'bi-stars',               'color' => '#6366f1'],
    'manual'         => ['icon' => 'bi-journal-text',        'color' => '#64748b'],
];
function eventoMeta(array $eventoMap, string $tipo): array {
    $key = strtolower($tipo);
    foreach ($eventoMap as $k => $v) {
        if (str_contains($key, $k)) return $v;
    }
    return $eventoMap['manual'];
}
?>

<!-- ── Historial del expediente ── -->
<div class="tab-section-title">
    <span class="icon-box"><i class="bi bi-clock-history"></i></span>
    Historial del expediente
    <?php if ($historial): ?>
        <span style="font-size:.7rem;font-weight:700;background:#eff6ff;color:#2563eb;
                     padding:.2em .7em;border-radius:20px;margin-left:.25rem;">
            <?= count($historial) ?> eventos
        </span>
    <?php endif; ?>
</div>

<?php if (!$historial): ?>
    <div class="empty-state">
        <i class="bi bi-clock-history"></i>
        <p>Sin movimientos registrados aún.</p>
    </div>
<?php else: ?>
    <div class="timeline">
        <?php foreach ($historial as $h):
            $meta = eventoMeta($eventoIcon, (string)($h['tipo_evento'] ?? 'manual'));
        ?>
        <div class="timeline-item">
            <div class="timeline-dot" style="background:<?= $meta['color'] ?>;box-shadow:0 0 0 3px <?= $meta['color'] ?>22;"></div>

            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.9rem 1.1rem;">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;font-size:.95rem;"></i>
                        <span style="font-weight:700;font-size:.875rem;color:#0f172a;">
                            <?= e((string)$h['titulo']) ?>
                        </span>
                    </div>
                    <span style="font-size:.72rem;color:#94a3b8;white-space:nowrap;">
                        <?= e((string)$h['created_at']) ?>
                    </span>
                </div>

                <div style="font-size:.75rem;color:#64748b;margin-bottom:<?= !empty($h['detalle']) ? '.6rem' : '0' ?>;">
                    <?= e((string)($h['tipo_evento'] ?? '')) ?>
                </div>

                <?php if (!empty($h['detalle'])): ?>
                    <div style="font-size:.84rem;color:#334155;line-height:1.6;
                                border-left:3px solid <?= $meta['color'] ?>44;
                                padding-left:.75rem;">
                        <?= nl2br(e((string)$h['detalle'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ── Agregar registro manual ── -->
<div class="section-divider"><span>Agregar registro manual</span></div>

<div class="form-panel">
    <form method="POST" action="<?= APP_URL ?>/modules/denuncias/guardar_historial.php">
        <?= CSRF::field(); ?>
        <input type="hidden" name="caso_id" value="<?= (int)$caso['id'] ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Título del evento</label>
                <input type="text" name="titulo" class="form-control" maxlength="150" required
                       placeholder="Ej: Reunión con apoderados…">
            </div>
            <div class="col-12">
                <label class="form-label">Detalle <span style="color:#94a3b8;">(opcional)</span></label>
                <textarea name="detalle" rows="4" class="form-control"
                          placeholder="Descripción del evento o acción realizada…"></textarea>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary-sgce">
                <i class="bi bi-journal-plus me-1"></i> Guardar en historial
            </button>
        </div>
    </form>
</div>
