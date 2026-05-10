<?php
declare(strict_types=1);

// Dashboard partial: layout inferior, salud técnica, focos, casos, alertas y actividad.
?>
<div class="dash-layout">
    <section>
        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-shield-check"></i>
                    Salud técnica del sistema
                </h3>

                <a class="dash-link" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
                    Diagnóstico
                </a>
            </div>

            <div class="dash-panel-body">
                <div class="dash-health">
                    <div class="dash-health-item">
                        <div class="dash-health-icon <?= $coreOk ? 'ok' : 'warn' ?>">
                            <i class="bi <?= $coreOk ? 'bi-check2-circle' : 'bi-exclamation-triangle' ?>"></i>
                        </div>

                        <div>
                            <div class="dash-health-title">Core del sistema</div>
                            <div class="dash-health-text">
                                Archivos principales de configuración, autenticación, base de datos y layout.
                            </div>
                        </div>

                        <span class="dash-badge <?= $coreOk ? 'ok' : 'danger' ?>">
                            <?= $coreOk ? 'Correcto' : 'Revisar' ?>
                        </span>
                    </div>

                    <div class="dash-health-item">
                        <div class="dash-health-icon <?= $dbOk ? 'ok' : 'warn' ?>">
                            <i class="bi <?= $dbOk ? 'bi-check2-circle' : 'bi-exclamation-triangle' ?>"></i>
                        </div>

                        <div>
                            <div class="dash-health-title">Base de datos</div>
                            <div class="dash-health-text">
                                Tablas críticas para operación, trazabilidad, alertas, evidencias y seguimiento.
                            </div>
                        </div>

                        <span class="dash-badge <?= $dbOk ? 'ok' : 'danger' ?>">
                            <?= $dbOk ? 'Correcto' : 'Revisar' ?>
                        </span>
                    </div>

                    <div class="dash-health-item">
                        <div class="dash-health-icon <?= $storageOk ? 'ok' : 'warn' ?>">
                            <i class="bi <?= $storageOk ? 'bi-check2-circle' : 'bi-exclamation-triangle' ?>"></i>
                        </div>

                        <div>
                            <div class="dash-health-title">Storage de evidencias</div>
                            <div class="dash-health-text">
                                Carpeta protegida y escribible para documentos asociados a expedientes.
                            </div>
                        </div>

                        <span class="dash-badge <?= $storageOk ? 'ok' : 'danger' ?>">
                            <?= $storageOk ? 'Correcto' : 'Revisar' ?>
                        </span>
                    </div>
                </div>

                <?php if (!$dbOk && $tablasFaltantes): ?>
                    <div class="dash-empty" style="margin-top:1rem;">
                        Revisar tablas críticas: <?= e(implode(', ', $tablasFaltantes)) ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-compass"></i>
                    Focos de gestión
                </h3>

                <a class="dash-link" href="<?= APP_URL ?>/modules/seguimiento/index.php">
                    Ver seguimiento
                </a>
            </div>

            <div class="dash-panel-body">
                <?php foreach ($focosGestion as $foco): ?>
                    <article class="dash-item">
                        <div class="dash-item-title">
                            <a href="<?= e((string)$foco['url']) ?>">
                                <?= e((string)$foco['titulo']) ?>
                            </a>
                        </div>

                        <div>
                            <span class="dash-badge <?= e((string)$foco['badge']) ?>">
                                <?= number_format((int)$foco['valor'], 0, ',', '.') ?> caso(s)
                            </span>
                        </div>

                        <div class="dash-text">
                            <?= e((string)$foco['texto']) ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-clock-history"></i>
                    Casos recientes
                </h3>

                <a class="dash-link" href="<?= APP_URL ?>/modules/denuncias/index.php">
                    Ver denuncias
                </a>
            </div>

            <div class="dash-panel-body">
                <?php if (!$casosRecientes): ?>
                    <div class="dash-empty">
                        No hay casos recientes para mostrar.
                    </div>
                <?php else: ?>
                    <?php foreach ($casosRecientes as $caso): ?>
                        <?php
                            $estadoVisible = (string)($caso['estado_formal'] ?: dash_label((string)$caso['estado']));
                            $riesgoVisible = (string)($caso['riesgo_final'] ?? '');
                            $planesCount = (int)($caso['planes_count'] ?? 0);
                        ?>
                        <article class="dash-item">
                            <div class="dash-item-title">
                                <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$caso['id'] ?>">
                                    <?= e($caso['numero_caso']) ?>
                                </a>
                            </div>

                            <div>
                                <span class="dash-badge soft">
                                    <?= e($estadoVisible) ?>
                                </span>

                                <span class="dash-badge <?= e(dash_badge((string)$caso['prioridad'])) ?>">
                                    Prioridad <?= e(dash_label((string)$caso['prioridad'])) ?>
                                </span>

                                <?php if ($riesgoVisible !== ''): ?>
                                    <span class="dash-badge <?= e(dash_badge($riesgoVisible)) ?>">
                                        Riesgo <?= e(dash_label($riesgoVisible)) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($planesCount === 0 && (int)($caso['estado_caso_id'] ?? 0) !== 5): ?>
                                    <span class="dash-badge warn">
                                        Sin plan
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)($caso['alertas_pendientes'] ?? 0) > 0): ?>
                                    <span class="dash-badge danger">
                                        <?= (int)$caso['alertas_pendientes'] ?> alerta(s)
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)($caso['posible_aula_segura'] ?? 0) === 1): ?>
                                    <span class="dash-badge warn">
                                        Aula Segura
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="dash-meta">
                                Ingreso: <?= e(dash_fecha((string)$caso['fecha_ingreso'])) ?>
                            </div>

                            <?php if (!empty($caso['relato'])): ?>
                                <div class="dash-text">
                                    <?= e(dash_corto((string)$caso['relato'], 160)) ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-tools"></i>
                    Accesos rápidos
                </h3>
            </div>

            <div class="dash-panel-body">
                <div class="dash-tools">
                    <a class="dash-tool" href="<?= APP_URL ?>/modules/denuncias/crear.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-plus-circle"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Nueva denuncia</div>
                            <div class="dash-tool-text">Registrar un nuevo expediente.</div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/seguimiento/index.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-clipboard2-check"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Seguimiento</div>
                            <div class="dash-tool-text">Gestionar casos activos y planes.</div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/alertas/index.php">
                        <div class="dash-tool-icon warn">
                            <i class="bi bi-bell"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Alertas</div>
                            <div class="dash-tool-text">
                                <?= number_format($totalAlertasPendientes, 0, ',', '.') ?> alerta(s) pendiente(s).
                            </div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/comunidad/index.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Comunidad</div>
                            <div class="dash-tool-text">Alumnos, apoderados y funcionarios.</div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/inclusion/index.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-universal-access"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Inclusión y TEA</div>
                            <div class="dash-tool-text">Protocolos y reportes de inclusión.</div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/importar/index.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-file-earmark-arrow-up"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Importar datos</div>
                            <div class="dash-tool-text">Plantillas oficiales y carga CSV.</div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/importar/pendientes.php">
                        <div class="dash-tool-icon warn">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Pendientes CSV</div>
                            <div class="dash-tool-text">
                                <?= number_format($totalPendientesImportacion, 0, ',', '.') ?> registro(s) por corregir.
                            </div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/reportes/index.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-bar-chart-line"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Reportes</div>
                            <div class="dash-tool-text">Indicadores y CSV.</div>
                        </div>
                    </a>

                    <a class="dash-tool" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
                        <div class="dash-tool-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div>
                            <div class="dash-tool-title">Diagnóstico</div>
                            <div class="dash-tool-text">Salud técnica.</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($totalPendientesImportacion > 0): ?>
            <div class="dash-panel">
                <div class="dash-panel-head">
                    <h3 class="dash-panel-title">
                        <i class="bi bi-exclamation-triangle"></i>
                        Pendientes de importación
                    </h3>

                    <a class="dash-link warn" href="<?= APP_URL ?>/modules/importar/pendientes.php">
                        Corregir
                    </a>
                </div>

                <div class="dash-panel-body">
                    <article class="dash-item">
                        <div class="dash-item-title">
                            <?= number_format($totalPendientesImportacion, 0, ',', '.') ?> registro(s) CSV pendiente(s)
                        </div>

                        <div class="dash-text">
                            Existen registros de comunidad educativa que no fueron cargados por RUN inválido,
                            vacío, duplicado o datos insuficientes. No contaminan las tablas finales y pueden
                            corregirse desde la bandeja de pendientes.
                        </div>

                        <div style="margin-top:.8rem;">
                            <a class="dash-link warn" href="<?= APP_URL ?>/modules/importar/pendientes.php">
                                <i class="bi bi-pencil-square"></i>
                                Abrir bandeja de corrección
                            </a>
                        </div>
                    </article>
                </div>
            </div>
        <?php endif; ?>

        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-bell"></i>
                    Alertas pendientes
                </h3>

                <a class="dash-link red" href="<?= APP_URL ?>/modules/alertas/index.php">
                    Ver alertas
                </a>
            </div>

            <div class="dash-panel-body">
                <?php if (!$alertasPendientes): ?>
                    <div class="dash-empty">
                        No hay alertas pendientes.
                    </div>
                <?php else: ?>
                    <?php foreach ($alertasPendientes as $alerta): ?>
                        <article class="dash-item">
                            <div class="dash-item-title">
                                <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$alerta['caso_id'] ?>">
                                    <?= e($alerta['numero_caso']) ?>
                                </a>
                            </div>

                            <div>
                                <span class="dash-badge <?= e(dash_badge((string)$alerta['prioridad'])) ?>">
                                    <?= e(dash_label((string)$alerta['prioridad'])) ?>
                                </span>

                                <span class="dash-badge danger">
                                    Pendiente
                                </span>
                            </div>

                            <div class="dash-meta">
                                <?= e(dash_fecha((string)$alerta['fecha_alerta'])) ?>
                            </div>

                            <div class="dash-text">
                                <?= e(dash_corto((string)$alerta['mensaje'], 140)) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-database-down"></i>
                    Último respaldo
                </h3>

                <a class="dash-link green" href="<?= APP_URL ?>/modules/admin/respaldo.php">
                    Respaldar
                </a>
            </div>

            <div class="dash-panel-body">
                <?php if (!$ultimoRespaldo): ?>
                    <div class="dash-empty">
                        No hay respaldos registrados en la bitácora.
                    </div>
                <?php else: ?>
                    <article class="dash-item">
                        <div class="dash-item-title">
                            Respaldo SQL generado
                        </div>

                        <div class="dash-meta">
                            <?= e(dash_fecha((string)$ultimoRespaldo['created_at'])) ?>
                            <?php if (!empty($ultimoRespaldo['usuario_nombre'])): ?>
                                · <?= e($ultimoRespaldo['usuario_nombre']) ?>
                            <?php endif; ?>
                        </div>

                        <div class="dash-text">
                            <?= e($ultimoRespaldo['descripcion'] ?? 'Se generó un respaldo SQL de la base de datos.') ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3 class="dash-panel-title">
                    <i class="bi bi-activity"></i>
                    Actividad reciente
                </h3>

                <a class="dash-link" href="<?= APP_URL ?>/modules/auditoria/index.php">
                    Auditoría
                </a>
            </div>

            <div class="dash-panel-body">
                <?php if (!$actividadReciente): ?>
                    <div class="dash-empty">
                        No hay actividad reciente.
                    </div>
                <?php else: ?>
                    <?php foreach ($actividadReciente as $log): ?>
                        <article class="dash-item">
                            <div class="dash-item-title">
                                <?= e(dash_label((string)$log['accion'])) ?>
                            </div>

                            <div class="dash-meta">
                                <?= e(dash_label((string)$log['modulo'])) ?> ·
                                <?= e(dash_fecha((string)$log['created_at'])) ?>

                                <?php if (!empty($log['usuario_nombre'])): ?>
                                    · <?= e($log['usuario_nombre']) ?>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($log['descripcion'])): ?>
                                <div class="dash-text">
                                    <?= e(dash_corto((string)$log['descripcion'], 130)) ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>
