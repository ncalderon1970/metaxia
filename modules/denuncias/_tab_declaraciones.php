<?php
$calidadBadge = [
    'victima'     => 'victima',
    'denunciante' => 'denunciante',
    'testigo'     => 'testigo',
    'denunciado'  => 'denunciado',
];
$tipoIcon = [
    'alumno'    => 'bi-mortarboard',
    'docente'   => 'bi-person-workspace',
    'asistente' => 'bi-person-gear',
    'apoderado' => 'bi-house-heart',
    'externo'   => 'bi-person-badge',
];
?>

<!-- ── Listado de declaraciones ── -->
<div class="tab-section-title">
    <span class="icon-box"><i class="bi bi-chat-left-quote"></i></span>
    Declaraciones registradas
    <?php if ($declaraciones): ?>
        <span style="font-size:.7rem;font-weight:700;background:#eff6ff;color:#2563eb;
                     padding:.2em .7em;border-radius:20px;margin-left:.25rem;">
            <?= count($declaraciones) ?>
        </span>
    <?php endif; ?>
</div>

<?php if (!$declaraciones): ?>
    <div class="empty-state">
        <i class="bi bi-chat-left-quote"></i>
        <p>Sin declaraciones registradas para este caso.</p>
    </div>
<?php else: ?>
    <?php foreach ($declaraciones as $d):
        $calidad = strtolower((string)$d['calidad_procesal']);
        $tipo    = strtolower((string)$d['tipo_declarante']);
        $icon    = $tipoIcon[$tipo] ?? 'bi-person';
        $badge   = $calidadBadge[$calidad] ?? 'externo';
    ?>
    <div class="item-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
                <div style="width:36px;height:36px;background:#f1f5f9;border-radius:8px;
                            display:flex;align-items:center;justify-content:center;
                            color:#475569;font-size:1rem;flex-shrink:0;">
                    <i class="bi <?= $icon ?>"></i>
                </div>
                <div>
                    <div class="item-card__title"><?= e((string)$d['nombre_declarante']) ?></div>
                    <div class="item-card__meta">
                        <span class="badge-role <?= $badge ?>"><?= ucfirst($calidad) ?></span>
                        <span class="sep">·</span>
                        <span><?= ucfirst($tipo) ?></span>
                    </div>
                </div>
            </div>
            <div style="font-size:.75rem;color:#64748b;text-align:right;">
                <i class="bi bi-calendar3 me-1"></i>
                <?= e((string)$d['fecha_declaracion']) ?>
            </div>
        </div>

        <div class="item-card__body"
             style="border-left:3px solid #e2e8f0;padding-left:.85rem;margin-left:.5rem;">
            <?= nl2br(e((string)$d['texto_declaracion'])) ?>
        </div>

        <?php if (!empty($d['observaciones'])): ?>
            <div class="mt-2"
                 style="font-size:.78rem;color:#64748b;background:#f8fafc;
                        border-radius:8px;padding:.5rem .75rem;">
                <i class="bi bi-info-circle me-1"></i>
                <?= nl2br(e((string)$d['observaciones'])) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ── Agregar declaración ── -->
<div class="section-divider"><span>Nueva declaración</span></div>

<div class="form-panel">
    <form method="POST" action="<?= APP_URL ?>/modules/denuncias/guardar_declaracion.php">
        <?= CSRF::field(); ?>
        <input type="hidden" name="caso_id" value="<?= (int)$caso['id'] ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Interviniente asociado <span style="color:#94a3b8;">(opcional)</span></label>
                <select name="participante_id" class="form-select">
                    <option value="">— Sin vincular —</option>
                    <?php foreach ($participantes as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">
                            <?= e((string)$p['rol_en_caso']) ?> – <?= e((string)($p['nombre_referencial'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tipo declarante</label>
                <select name="tipo_declarante" class="form-select" required>
                    <option value="alumno">Alumno</option>
                    <option value="docente">Docente</option>
                    <option value="asistente">Asistente</option>
                    <option value="apoderado">Apoderado</option>
                    <option value="externo">Externo</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Nombre declarante</label>
                <input type="text" name="nombre_declarante" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">RUN declarante</label>
                <input type="text" name="run_declarante" class="form-control" placeholder="Opcional">
            </div>
            <div class="col-md-3">
                <label class="form-label">Calidad procesal</label>
                <select name="calidad_procesal" class="form-select" required>
                    <option value="victima">Víctima</option>
                    <option value="denunciante">Denunciante</option>
                    <option value="testigo">Testigo</option>
                    <option value="denunciado">Denunciado</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Fecha declaración</label>
                <input type="datetime-local" name="fecha_declaracion" class="form-control" required>
            </div>

            <div class="col-12">
                <label class="form-label">Texto de la declaración</label>
                <textarea name="texto_declaracion" rows="6" class="form-control" required
                          placeholder="Registre el relato completo…"></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Observaciones internas <span style="color:#94a3b8;">(opcional)</span></label>
                <textarea name="observaciones" rows="3" class="form-control"
                          placeholder="Notas internas del equipo de convivencia…"></textarea>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary-sgce">
                <i class="bi bi-chat-left-text me-1"></i> Guardar declaración
            </button>
        </div>
    </form>
</div>
