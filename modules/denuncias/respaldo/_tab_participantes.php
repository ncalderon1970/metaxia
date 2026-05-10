<?php
$rolBadgeClass = [
    'victima'     => 'victima',
    'denunciante' => 'denunciante',
    'testigo'     => 'testigo',
    'denunciado'  => 'denunciado',
    'externo'     => 'externo',
];
$rolIcon = [
    'victima'     => 'bi-person-exclamation',
    'denunciante' => 'bi-megaphone',
    'testigo'     => 'bi-eye',
    'denunciado'  => 'bi-person-dash',
    'externo'     => 'bi-person-badge',
];
?>

<!-- ── Listado de participantes ── -->
<div class="tab-section-title">
    <span class="icon-box"><i class="bi bi-people"></i></span>
    Participantes del caso
    <?php if ($participantes): ?>
        <span style="font-size:.7rem;font-weight:700;background:#eff6ff;color:#2563eb;
                     padding:.2em .7em;border-radius:20px;margin-left:.25rem;">
            <?= count($participantes) ?>
        </span>
    <?php endif; ?>
</div>

<?php if (!$participantes): ?>
    <div class="empty-state">
        <i class="bi bi-people"></i>
        <p>No hay participantes registrados en este caso.</p>
    </div>
<?php else: ?>
    <?php foreach ($participantes as $p):
        $rol        = strtolower((string)$p['rol_en_caso']);
        $badgeClass = $rolBadgeClass[$rol] ?? 'externo';
        $icon       = $rolIcon[$rol] ?? 'bi-person';
        $iniciales  = strtoupper(substr(trim((string)($p['nombre_referencial'] ?? 'NN')), 0, 1));
        $confirmado = !empty($p['identidad_confirmada']);
    ?>
    <div class="participante-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div class="d-flex gap-3 align-items-start">
                <div class="participante-avatar"><?= $iniciales ?></div>
                <div>
                    <div class="item-card__title"><?= e((string)($p['nombre_referencial'] ?? 'NN')) ?></div>
                    <div class="item-card__meta">
                        <span class="badge-role <?= $badgeClass ?>">
                            <i class="bi <?= $icon ?>"></i>
                            <?= ucfirst($rol) ?>
                        </span>
                        <span class="sep">·</span>
                        <span>RUN: <?= e((string)($p['run'] ?? '0-0')) ?></span>
                    </div>
                </div>
            </div>
            <span style="font-size:.72rem;font-weight:600;padding:.3em .75em;border-radius:20px;
                         <?= $confirmado
                             ? 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;'
                             : 'background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;' ?>">
                <i class="bi <?= $confirmado ? 'bi-patch-check-fill' : 'bi-hourglass-split' ?> me-1"></i>
                <?= $confirmado ? 'Identificado' : 'Pendiente de identificación' ?>
            </span>
        </div>

        <!-- Formulario de actualización colapsable -->
        <details class="update-form mt-3">
            <summary>Actualizar identificación</summary>
            <div class="form-inner">
                <form method="POST" action="<?= APP_URL ?>/modules/denuncias/actualizar_interviniente.php">
                    <?= CSRF::field(); ?>
                    <input type="hidden" name="caso_id"       value="<?= (int)$caso['id'] ?>">
                    <input type="hidden" name="participante_id" value="<?= (int)$p['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label">Buscar persona en el sistema</label>
                        <input type="text"
                               class="form-control busqueda-persona"
                               data-target="<?= (int)$p['id'] ?>"
                               placeholder="Nombre o RUN…">
                        <div class="resultados-persona border rounded-3 mt-1"
                             id="resultados-<?= (int)$p['id'] ?>"></div>
                    </div>

                    <input type="hidden" name="persona_id" id="persona_id_<?= (int)$p['id'] ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Tipo persona</label>
                            <select name="tipo_persona" id="tipo_persona_<?= (int)$p['id'] ?>" class="form-select" required>
                                <?php foreach (['alumno','docente','asistente','apoderado','externo'] as $tp): ?>
                                    <option value="<?= $tp ?>" <?= (($p['tipo_persona'] ?? '') === $tp) ? 'selected' : '' ?>>
                                        <?= ucfirst($tp) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nombre referencial</label>
                            <input type="text"
                                   name="nombre_referencial"
                                   id="nombre_ref_<?= (int)$p['id'] ?>"
                                   class="form-control"
                                   value="<?= e((string)($p['nombre_referencial'] ?? 'NN')) ?>"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">RUN</label>
                            <input type="text"
                                   name="run"
                                   id="run_<?= (int)$p['id'] ?>"
                                   class="form-control"
                                   value="<?= e((string)($p['run'] ?? '0-0')) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Observación</label>
                        <input type="text"
                               name="observacion_identificacion"
                               class="form-control"
                               maxlength="255"
                               value="<?= e((string)($p['observacion_identificacion'] ?? '')) ?>">
                    </div>

                    <button type="submit" class="btn btn-primary-sgce">
                        <i class="bi bi-pencil-square me-1"></i> Actualizar interviniente
                    </button>
                </form>
            </div>
        </details>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ── Agregar participante ── -->
<div class="section-divider"><span>Agregar participante</span></div>

<div class="form-panel">
    <form method="POST" action="<?= APP_URL ?>/modules/denuncias/guardar_participante.php">
        <?= CSRF::field(); ?>
        <input type="hidden" name="caso_id" value="<?= (int)$caso['id'] ?>">

        <div class="row g-3">
            <div class="col-sm-6 col-md-3">
                <label class="form-label">Tipo persona</label>
                <select name="tipo_persona" class="form-select" required>
                    <?php foreach (['alumno','docente','asistente','apoderado','externo'] as $tp): ?>
                        <option value="<?= $tp ?>"><?= ucfirst($tp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label">Nombre referencial</label>
                <input type="text" name="nombre_referencial" class="form-control" maxlength="150" value="NN" required>
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label">RUN</label>
                <input type="text" name="run" class="form-control" maxlength="20" value="0-0" placeholder="0-0 si desconoce">
            </div>
            <div class="col-sm-6 col-md-3">
                <label class="form-label">Rol en el caso</label>
                <select name="rol_en_caso" class="form-select" required>
                    <option value="victima">Víctima</option>
                    <option value="denunciante">Denunciante</option>
                    <option value="testigo">Testigo</option>
                    <option value="denunciado">Denunciado</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Observación</label>
                <input type="text" name="observacion" class="form-control" maxlength="255">
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary-sgce">
                <i class="bi bi-person-plus me-1"></i> Guardar participante
            </button>
        </div>
    </form>
</div>
