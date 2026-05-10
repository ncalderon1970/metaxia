<h3 class="mb-3">Clasificación de la denuncia</h3>

<form method="post" action="<?= APP_URL ?>/modules/denuncias/guardar_clasificacion.php">
    <?= CSRF::field(); ?>
    <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="border rounded-4 p-4 h-100">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-tags text-primary"></i>
                    <h4 class="m-0">Tipo de denuncia</h4>
                </div>

                <p class="text-muted small">
                    Clasifica la denuncia según el catálogo institucional de materias recepcionadas.
                </p>

                <label class="form-label">Materia recepcionada</label>
                <select name="denuncia_aspecto_id" class="form-select">
                    <option value="">-- Sin clasificar --</option>
                    <?php foreach ($aspectosDenuncia as $a): ?>
                        <option value="<?= (int)$a['aspecto_id'] ?>"
                            <?= ((int)($caso['denuncia_aspecto_id'] ?? 0) === (int)$a['aspecto_id']) ? 'selected' : '' ?>>
                            <?= e((string)$a['area_nombre']) ?> → <?= e((string)$a['aspecto_nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="border rounded-4 p-4 h-100 bg-light">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-shield-exclamation text-danger"></i>
                    <h4 class="m-0">Ley 21.128 Aula Segura</h4>
                </div>

                <p class="text-muted small mb-3">
                    Procedimiento especial aplicable a hechos que afecten gravemente la convivencia escolar.
                </p>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="aplica_aula_segura" value="1"
                        <?= !empty($aulaSegura['aplica']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold">
                        Aplica Aula Segura
                    </label>
                </div>

                <label class="form-label">Estado del procedimiento</label>
                <select name="estado" class="form-select">
                    <?php
                    $estadosAula = [
                        'no_aplica' => 'No aplica',
                        'evaluacion' => 'En evaluación',
                        'procedimiento_iniciado' => 'Procedimiento iniciado',
                        'suspension_notificada' => 'Suspensión notificada',
                        'resuelto' => 'Resuelto',
                        'reconsideracion' => 'En reconsideración',
                        'cerrado' => 'Cerrado'
                    ];
                    ?>
                    <?php foreach ($estadosAula as $codigo => $nombre): ?>
                        <option value="<?= e($codigo) ?>" <?= (($aulaSegura['estado'] ?? 'no_aplica') === $codigo) ? 'selected' : '' ?>>
                            <?= e($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="border rounded-4 p-4 mt-4">
        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-journal-text text-primary"></i>
            <h4 class="m-0">Fundamento y cautela</h4>
        </div>

        <div class="alert alert-warning border">
            Registrar con especial cuidado las fechas de notificación, porque activan plazos legales relevantes.
        </div>

        <label class="form-label">Causal / fundamento preliminar</label>
        <textarea name="causal" rows="3" class="form-control"><?= e((string)($aulaSegura['causal'] ?? '')) ?></textarea>

        <div class="form-check form-switch mt-3">
            <input class="form-check-input" type="checkbox" name="medida_cautelar_suspension" value="1"
                <?= !empty($aulaSegura['medida_cautelar_suspension']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold">
                Se aplicó medida cautelar de suspensión
            </label>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-xl-6">
            <div class="border rounded-4 p-4 h-100">
                <h4 class="mb-3">Plazo de resolución</h4>

                <label class="form-label">Fecha notificación suspensión</label>
                <input type="date" name="fecha_notificacion_suspension" class="form-control mb-3"
                       value="<?= e((string)($aulaSegura['fecha_notificacion_suspension'] ?? '')) ?>">

                <label class="form-label">Fecha límite resolución, 10 días hábiles</label>
                <input type="date" class="form-control bg-light" disabled
                       value="<?= e((string)($aulaSegura['fecha_limite_resolucion'] ?? '')) ?>">
            </div>
        </div>

        <div class="col-xl-6">
            <div class="border rounded-4 p-4 h-100">
                <h4 class="mb-3">Reconsideración</h4>

                <label class="form-label">Fecha notificación resolución</label>
                <input type="date" name="fecha_notificacion_resolucion" class="form-control mb-3"
                       value="<?= e((string)($aulaSegura['fecha_notificacion_resolucion'] ?? '')) ?>">

                <label class="form-label">Fecha límite reconsideración, 5 días hábiles</label>
                <input type="date" class="form-control bg-light mb-3" disabled
                       value="<?= e((string)($aulaSegura['fecha_limite_reconsideracion'] ?? '')) ?>">

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="reconsideracion_presentada" value="1"
                        <?= !empty($aulaSegura['reconsideracion_presentada']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold">
                        Se presentó reconsideración
                    </label>
                </div>

                <label class="form-label mt-3">Fecha reconsideración</label>
                <input type="date" name="fecha_reconsideracion" class="form-control"
                       value="<?= e((string)($aulaSegura['fecha_reconsideracion'] ?? '')) ?>">
            </div>
        </div>
    </div>

    <div class="border rounded-4 p-4 mt-4">
        <label class="form-label">Observaciones</label>
        <textarea name="observaciones" rows="3" class="form-control"><?= e((string)($aulaSegura['observaciones'] ?? '')) ?></textarea>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button class="btn btn-primary-sgce">
            <i class="bi bi-save me-1"></i> Guardar clasificación
        </button>
    </div>
</form>