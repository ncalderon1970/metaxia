<?php
$contextoFamiliar = $contextoFamiliar ?? [];
?>

<section class="exp-card exp-family-card">
    <div class="exp-title">Contexto familiar del estudiante</div>

    <div class="exp-help">
        Este bloque cruza los alumnos vinculados al expediente con la base de comunidad educativa.
        Permite identificar rápidamente apoderado principal, contactos de emergencia y personas autorizadas para retiro.
    </div>

    <?php if (!$contextoFamiliar): ?>
        <div class="exp-empty">
            No hay contexto familiar disponible. Para verlo, primero vincula un participante de tipo alumno desde comunidad educativa
            y registra sus apoderados en la ficha del estudiante.

            <div style="margin-top:1rem;">
                <a class="exp-link green" href="<?= APP_URL ?>/modules/denuncias/vincular_comunidad.php?caso_id=<?= (int)$casoId ?>">
                    <i class="bi bi-person-plus"></i>
                    Vincular alumno desde comunidad educativa
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($contextoFamiliar as $bloque): ?>
            <?php
            $alumno = $bloque['alumno'] ?? [];
            $apoderados = $bloque['apoderados'] ?? [];
            $principales = $bloque['principales'] ?? [];
            $emergencia = $bloque['emergencia'] ?? [];
            $retiro = $bloque['retiro'] ?? [];
            $alumnoId = (int)($alumno['id'] ?? 0);
            ?>

            <article class="exp-family-student">
                <div class="exp-family-head">
                    <div>
                        <div class="exp-family-name">
                            <i class="bi bi-mortarboard"></i>
                            <?= e(ver_nombre_persona($alumno)) ?>
                        </div>

                        <div class="exp-item-meta">
                            RUN <?= e(ver_pick($alumno, ['run', 'rut'], '-')) ?> ·
                            Curso <?= e(ver_pick($alumno, ['curso', 'curso_nombre', 'nivel'], 'Sin curso')) ?>
                        </div>
                    </div>

                    <div class="exp-family-actions">
                        <a class="exp-link" href="<?= APP_URL ?>/modules/comunidad/vincular_apoderado.php?alumno_id=<?= $alumnoId ?>">
                            <i class="bi bi-people"></i>
                            Gestionar apoderados
                        </a>
                    </div>
                </div>

                <div class="exp-family-kpis">
                    <div class="exp-family-kpi">
                        <span>Apoderados</span>
                        <strong><?= count($apoderados) ?></strong>
                    </div>

                    <div class="exp-family-kpi">
                        <span>Principal</span>
                        <strong><?= count($principales) > 0 ? 'Sí' : 'No' ?></strong>
                    </div>

                    <div class="exp-family-kpi">
                        <span>Emergencia</span>
                        <strong><?= count($emergencia) ?></strong>
                    </div>

                    <div class="exp-family-kpi">
                        <span>Retiro</span>
                        <strong><?= count($retiro) ?></strong>
                    </div>
                </div>

                <?php if (!$apoderados): ?>
                    <div class="exp-empty" style="padding:1.2rem 1rem;">
                        El alumno está vinculado al expediente, pero aún no tiene apoderados registrados.
                    </div>
                <?php else: ?>
                    <div class="exp-family-list">
                        <?php foreach ($apoderados as $apoderado): ?>
                            <?php $relActivo = (int)($apoderado['relacion_activo'] ?? 1) === 1; ?>

                            <div class="exp-family-person <?= $relActivo ? '' : 'inactive' ?>">
                                <div class="exp-family-person-title">
                                    <?= e(ver_nombre_persona($apoderado)) ?>
                                </div>

                                <div class="exp-item-meta">
                                    RUN <?= e(ver_pick($apoderado, ['run', 'rut'], '-')) ?> ·
                                    <?= e(ver_pick($apoderado, ['relacion_parentesco', 'parentesco'], 'Sin parentesco')) ?>
                                </div>

                                <div style="margin:.45rem 0;">
                                    <?php if ((int)($apoderado['es_principal'] ?? 0) === 1): ?>
                                        <span class="exp-badge ok">Principal</span>
                                    <?php endif; ?>

                                    <?php if ((int)($apoderado['contacto_emergencia'] ?? 0) === 1): ?>
                                        <span class="exp-badge warn">Emergencia</span>
                                    <?php endif; ?>

                                    <?php if ((int)($apoderado['autorizado_retirar'] ?? 0) === 1): ?>
                                        <span class="exp-badge ok">Retiro autorizado</span>
                                    <?php endif; ?>

                                    <?php if ((int)($apoderado['vive_con_estudiante'] ?? 0) === 1): ?>
                                        <span class="exp-badge soft">Vive con estudiante</span>
                                    <?php endif; ?>

                                    <span class="exp-badge <?= $relActivo ? 'ok' : 'danger' ?>">
                                        <?= $relActivo ? 'Relación activa' : 'Relación inactiva' ?>
                                    </span>
                                </div>

                                <div class="exp-family-contact">
                                    <div>
                                        <strong>Teléfono:</strong>
                                        <?= e(ver_pick($apoderado, ['telefono', 'fono', 'celular'], '-')) ?>
                                    </div>

                                    <div>
                                        <strong>Email:</strong>
                                        <?= e(ver_pick($apoderado, ['email', 'correo', 'correo_electronico'], '-')) ?>
                                    </div>

                                    <div>
                                        <strong>Dirección:</strong>
                                        <?= e(ver_pick($apoderado, ['direccion', 'domicilio'], '-')) ?>
                                    </div>
                                </div>

                                <?php if (!empty($apoderado['relacion_observacion'])): ?>
                                    <div class="exp-family-note">
                                        <?= e((string)$apoderado['relacion_observacion']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
