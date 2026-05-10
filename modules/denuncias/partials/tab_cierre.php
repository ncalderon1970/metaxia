<?php
declare(strict_types=1);

// ── VALIDACIONES DE CIERRE — Metis 2.0 ──────────────────────
$_bloqueos = [];
$_advertencias = [];
$_pautasAltoNoDeriv = [];
$_puedeOperarCierre = Auth::canOperate();

// 0. Participantes — al menos 1 interviniente registrado
try {
    $s = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_participantes cp\n        INNER JOIN casos c ON c.id = cp.caso_id\n        WHERE cp.caso_id = ?\n          AND c.colegio_id = ?\n    ");
    $s->execute([$casoId, $colegioId]);
    if ((int)$s->fetchColumn() === 0) {
        $_bloqueos[] = [
            'icono' => 'bi-people-fill',
            'titulo'=> 'Sin participantes registrados',
            'msg'   => 'Debe existir al menos un interviniente registrado antes de cerrar el expediente.',
            'link'  => '?id=' . $casoId . '&tab=participantes',
            'link_txt'=> 'Ir a Participantes',
        ];
    }
} catch (Throwable $e) {
    $_bloqueos[] = [
        'icono' => 'bi-exclamation-triangle-fill',
        'titulo'=> 'No fue posible validar participantes',
        'msg'   => 'Revise la carga de participantes antes de intentar el cierre.',
        'link'  => '?id=' . $casoId . '&tab=participantes',
        'link_txt'=> 'Ir a Participantes',
    ];
}

// 1. Plan de acción — al menos 1 participante con plan vigente
try {
    $s = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_plan_accion\n        WHERE caso_id = ?\n          AND colegio_id = ?\n          AND vigente = 1\n    ");
    $s->execute([$casoId, $colegioId]);
    if ((int)$s->fetchColumn() === 0) {
        $_bloqueos[] = [
            'icono' => 'bi-clipboard2-x-fill',
            'titulo'=> 'Sin plan de acción',
            'msg'   => 'No se ha definido ningún plan de acción vigente para los intervinientes.',
            'link'  => '?id=' . $casoId . '&tab=plan_accion',
            'link_txt'=> 'Ir al Plan de Acción',
        ];
    }
} catch (Throwable $e) {
    $_bloqueos[] = [
        'icono' => 'bi-clipboard2-x-fill',
        'titulo'=> 'No fue posible validar plan de acción',
        'msg'   => 'Revise el plan de acción antes de intentar el cierre.',
        'link'  => '?id=' . $casoId . '&tab=plan_accion',
        'link_txt'=> 'Ir al Plan de Acción',
    ];
}

// 2. Sesiones de seguimiento — al menos 1 registrada
try {
    $s = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_seguimiento_sesion\n        WHERE caso_id = ?\n          AND colegio_id = ?\n    ");
    $s->execute([$casoId, $colegioId]);
    if ((int)$s->fetchColumn() === 0) {
        $_bloqueos[] = [
            'icono' => 'bi-journal-x',
            'titulo'=> 'Sin sesiones de seguimiento',
            'msg'   => 'Debe registrar al menos una sesión de seguimiento antes de cerrar.',
            'link'  => '?id=' . $casoId . '&tab=seguimiento',
            'link_txt'=> 'Ir a Seguimiento',
        ];
    }
} catch (Throwable $e) {
    $_bloqueos[] = [
        'icono' => 'bi-journal-x',
        'titulo'=> 'No fue posible validar seguimiento',
        'msg'   => 'Revise las sesiones de seguimiento antes de intentar el cierre.',
        'link'  => '?id=' . $casoId . '&tab=seguimiento',
        'link_txt'=> 'Ir a Seguimiento',
    ];
}

// 3. Comunicación apoderado — advertencia si hay alumnos y no consta comunicación general del caso
try {
    $s = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_participantes cp\n        INNER JOIN casos c ON c.id = cp.caso_id\n        WHERE cp.caso_id = ?\n          AND c.colegio_id = ?\n          AND cp.tipo_persona = 'alumno'\n    ");
    $s->execute([$casoId, $colegioId]);
    $totalAlumnos = (int)$s->fetchColumn();

    if ($totalAlumnos > 0 && (int)($caso['comunicacion_apoderado_realizada'] ?? 0) !== 1) {
        $_advertencias[] = [
            'icono' => 'bi-person-exclamation',
            'titulo'=> 'Comunicación apoderado no registrada en el resumen',
            'msg'   => 'Hay estudiantes vinculados y no consta comunicación general al apoderado en el expediente.',
        ];
    }
} catch (Throwable $e) {}

// 4. Pauta de riesgo — advertencia si no hay evaluaciones aplicadas
try {
    $s = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_pauta_riesgo pr\n        INNER JOIN casos c ON c.id = pr.caso_id\n        WHERE pr.caso_id = ?\n          AND c.colegio_id = ?\n          AND pr.puntaje_total > 0\n    ");
    $s->execute([$casoId, $colegioId]);
    if ((int)$s->fetchColumn() === 0) {
        $_advertencias[] = [
            'icono' => 'bi-shield-exclamation',
            'titulo'=> 'Sin evaluación de riesgo',
            'msg'   => 'No se aplicó pauta de evaluación de riesgo. Se recomienda completarla si corresponde a la naturaleza del caso.',
        ];
    }
} catch (Throwable $e) {}

// 5. Pauta riesgo alto/crítico sin derivar — bloqueo
$_bloqueoRiesgo = false;
try {
    $s = $pdo->prepare("\n        SELECT pr.nombre_alumno, pr.rol_en_caso, pr.puntaje_total, pr.nivel_final\n        FROM caso_pauta_riesgo pr\n        INNER JOIN casos c ON c.id = pr.caso_id\n        WHERE pr.caso_id = ?\n          AND c.colegio_id = ?\n          AND pr.nivel_final IN ('alto','critico')\n          AND (pr.derivado = 0 OR pr.derivado IS NULL)\n        ORDER BY pr.id DESC\n    ");
    $s->execute([$casoId, $colegioId]);
    $_pautasAltoNoDeriv = $s->fetchAll();
    if ($_pautasAltoNoDeriv) {
        $_bloqueoRiesgo = true;
        $_bloqueos[] = [
            'icono' => 'bi-lock-fill',
            'titulo'=> 'Riesgo alto/crítico sin derivar',
            'msg'   => 'Existen intervinientes con riesgo alto o crítico sin derivación registrada.',
            'link'  => '?id=' . $casoId . '&tab=pauta_riesgo',
            'link_txt'=> 'Ir a Pauta de Riesgo',
        ];
    }
} catch (Throwable $e) {}

// Otras verificaciones del expediente
$alertasPendientesCierre = array_values(array_filter($alertas ?? [], static fn($a) =>
    (string)($a['estado'] ?? '') === 'pendiente'
));

$gestionAbiertaCierre = array_values(array_filter($gestionEjecutiva ?? [], static fn($a) =>
    !in_array((string)($a['estado'] ?? ''), ['cumplida', 'descartada'], true)
));

$_hayBloqueos = !empty($_bloqueos);
?>

<section class="exp-card">
    <div class="exp-title">Cierre formal del expediente</div>

    <?php if ($_bloqueos): ?>
    <div style="margin-bottom:1rem;">
        <?php foreach ($_bloqueos as $bl): ?>
        <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;
             padding:.85rem 1rem;margin-bottom:.6rem;display:flex;align-items:flex-start;gap:.7rem;">
            <i class="bi <?= e($bl['icono']) ?>" style="color:#dc2626;font-size:1.3rem;flex-shrink:0;margin-top:.1rem;"></i>
            <div style="flex:1;">
                <div style="font-weight:700;color:#991b1b;font-size:.87rem;margin-bottom:.2rem;">
                    🔒 <?= e($bl['titulo']) ?>
                </div>
                <div style="font-size:.81rem;color:#7f1d1d;"><?= e($bl['msg']) ?></div>
                <?php if (!empty($bl['link'])): ?>
                <a href="<?= e($bl['link']) ?>"
                   style="font-size:.78rem;font-weight:600;color:#dc2626;margin-top:.3rem;display:inline-block;">
                    → <?= e($bl['link_txt']) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($_advertencias): ?>
    <div style="margin-bottom:1rem;">
        <?php foreach ($_advertencias as $adv): ?>
        <div style="background:#fefce8;border:1.5px solid #fde68a;border-radius:10px;
             padding:.75rem 1rem;margin-bottom:.5rem;display:flex;align-items:flex-start;gap:.6rem;">
            <i class="bi <?= e($adv['icono']) ?>" style="color:#d97706;font-size:1.2rem;flex-shrink:0;margin-top:.1rem;"></i>
            <div>
                <div style="font-weight:700;color:#92400e;font-size:.85rem;margin-bottom:.15rem;">
                    ⚠️ <?= e($adv['titulo']) ?>
                </div>
                <div style="font-size:.8rem;color:#78350f;"><?= e($adv['msg']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($_bloqueoRiesgo && $_pautasAltoNoDeriv): ?>
    <div style="background:#fef2f2;border:1.5px solid #ef4444;border-radius:12px;
        padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.75rem;">
        <i class="bi bi-lock-fill" style="color:#dc2626;font-size:1.4rem;flex-shrink:0;margin-top:.1rem;"></i>
        <div>
            <div style="font-size:.9rem;font-weight:700;color:#b91c1c;margin-bottom:.35rem;">
                Cierre bloqueado — Riesgo alto/crítico sin derivar
            </div>
            <div style="font-size:.82rem;color:#991b1b;line-height:1.5;">
                Los siguientes intervinientes tienen valoración de riesgo alto o crítico y no han sido derivados.
                Debes completar la derivación en la pestaña
                <a href="?id=<?= $casoId ?>&tab=pauta_riesgo"
                   style="color:#dc2626;font-weight:600;">Valoración de riesgo</a>
                antes de cerrar el caso.
            </div>
            <ul style="margin:.6rem 0 0 1rem;font-size:.82rem;color:#991b1b;">
                <?php foreach ($_pautasAltoNoDeriv as $pa): ?>
                <li><strong><?= e((string)($pa['nombre_alumno'] ?? '')) ?></strong>
                    (<?= e(caso_label((string)($pa['rol_en_caso'] ?? ''))) ?>) —
                    <?= e(caso_label((string)($pa['nivel_final'] ?? ''))) ?>,
                    puntaje <?= (int)($pa['puntaje_total'] ?? 0) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="exp-close-grid">
        <div class="exp-close-state <?= !empty($cierreCaso) ? 'ok' : 'warn' ?>">
            <strong>Estado de cierre</strong>
            <span><?= !empty($cierreCaso) ? 'Cierre formal vigente' : 'Sin cierre formal' ?></span>
            <small>Estado operativo actual: <?= e((string)($caso['estado_formal'] ?? caso_label((string)($caso['estado'] ?? '')))) ?></small>
        </div>

        <div class="exp-close-state <?= count($alertasPendientesCierre) > 0 ? 'danger' : 'ok' ?>">
            <strong>Alertas pendientes</strong>
            <span><?= number_format(count($alertasPendientesCierre), 0, ',', '.') ?></span>
            <small><?= count($alertasPendientesCierre) > 0 ? 'Revisar antes o justificar cierre.' : 'Sin alertas pendientes.' ?></small>
        </div>

        <div class="exp-close-state <?= count($gestionAbiertaCierre) > 0 ? 'warn' : 'ok' ?>">
            <strong>Acciones ejecutivas abiertas</strong>
            <span><?= number_format(count($gestionAbiertaCierre), 0, ',', '.') ?></span>
            <small><?= count($gestionAbiertaCierre) > 0 ? 'Existen compromisos no cerrados.' : 'Sin compromisos abiertos.' ?></small>
        </div>
    </div>

    <?php if (!empty($cierreCaso)): ?>
        <section class="exp-close-current">
            <div class="exp-title">Cierre vigente</div>

            <div class="exp-data">
                <strong>Fecha cierre</strong>
                <span><?= e(caso_fecha_corta((string)($cierreCaso['fecha_cierre'] ?? ''))) ?></span>

                <strong>Tipo cierre</strong>
                <span><?= e(caso_label((string)($cierreCaso['tipo_cierre'] ?? ''))) ?></span>

                <strong>Registrado por</strong>
                <span><?= e((string)($cierreCaso['cerrado_por_nombre'] ?? 'No informado')) ?></span>

                <strong>Fundamento</strong>
                <span><?= nl2br(e((string)($cierreCaso['fundamento'] ?? ''))) ?></span>

                <strong>Medidas finales</strong>
                <span><?= nl2br(e((string)($cierreCaso['medidas_finales'] ?? 'No informado'))) ?></span>

                <strong>Acuerdos</strong>
                <span><?= nl2br(e((string)($cierreCaso['acuerdos'] ?? 'No informado'))) ?></span>

                <strong>Derivaciones</strong>
                <span><?= nl2br(e((string)($cierreCaso['derivaciones'] ?? 'No informado'))) ?></span>

                <strong>Observaciones</strong>
                <span><?= nl2br(e((string)($cierreCaso['observaciones'] ?? 'No informado'))) ?></span>
            </div>
        </section>

        <section class="exp-card-soft">
            <div class="exp-title">Reapertura controlada</div>
            <p class="exp-muted-text">
                Usa esta opción solo si aparecen nuevos antecedentes, se detecta un error relevante o se requiere continuar la gestión del caso.
            </p>

            <?php if ($_puedeOperarCierre): ?>
            <form method="post" class="exp-form-gap">
                <?= CSRF::field() ?>
                <input type="hidden" name="_accion" value="reabrir_caso">

                <label class="exp-label">Motivo de reapertura *</label>
                <textarea class="exp-control" name="motivo_reapertura" rows="4" required></textarea>

                <div style="margin-top:1rem;">
                    <button class="exp-submit danger" type="submit" onclick="return confirm('¿Confirmas reabrir este expediente?');">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reabrir expediente
                    </button>
                </div>
            </form>
            <?php else: ?>
                <div class="exp-empty">No tienes permisos para reabrir este expediente.</div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php if (count($alertasPendientesCierre) > 0 || count($gestionAbiertaCierre) > 0): ?>
            <div class="exp-close-warning">
                Existen elementos pendientes. El sistema permite cerrar solo si no hay bloqueos formales; el fundamento debe justificar la decisión o dejar constancia de las acciones derivadas.
            </div>
        <?php endif; ?>

        <?php if (!$_puedeOperarCierre): ?>
            <div class="exp-empty">No tienes permisos para registrar el cierre formal del expediente.</div>
        <?php else: ?>
            <form method="post" class="exp-form-gap">
                <?= CSRF::field() ?>
                <input type="hidden" name="_accion" value="registrar_cierre_formal">

                <div class="exp-grid-2">
                    <div>
                        <label class="exp-label">Fecha de cierre *</label>
                        <input class="exp-control" type="date" name="fecha_cierre" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                    </div>

                    <div>
                        <label class="exp-label">Tipo de cierre *</label>
                        <select class="exp-control" name="tipo_cierre" required>
                            <option value="resuelto">Resuelto</option>
                            <option value="derivado">Derivado</option>
                            <option value="desestimado">Desestimado</option>
                            <option value="acuerdo">Cierre por acuerdo / medidas cumplidas</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                </div>

                <label class="exp-label">Fundamento / síntesis del cierre *</label>
                <textarea class="exp-control" name="fundamento" rows="5" required placeholder="Resumen ejecutivo de antecedentes, análisis, decisión y fundamento del cierre."></textarea>

                <label class="exp-label">Medidas finales</label>
                <textarea class="exp-control" name="medidas_finales" rows="4" placeholder="Medidas formativas, disciplinarias, de apoyo, resguardo o seguimiento final."></textarea>

                <label class="exp-label">Acuerdos</label>
                <textarea class="exp-control" name="acuerdos" rows="4" placeholder="Acuerdos con estudiantes, apoderados, docentes o equipo de convivencia."></textarea>

                <label class="exp-label">Derivaciones</label>
                <textarea class="exp-control" name="derivaciones" rows="4" placeholder="Derivaciones internas o externas, redes de apoyo, orientación, PIE, dupla psicosocial, etc."></textarea>

                <label class="exp-label">Observaciones finales</label>
                <textarea class="exp-control" name="observaciones" rows="4"></textarea>

                <div style="margin-top:1rem;">
                    <button class="exp-submit green" type="submit"
                            <?= $_hayBloqueos ? 'disabled title="Debes resolver los bloqueos formales antes de cerrar"' : '' ?>
                            onclick="return confirm('¿Confirmas registrar cierre formal del expediente?');">
                        <i class="bi bi-check2-square"></i>
                        <?= $_hayBloqueos ? 'Cierre bloqueado' : 'Registrar cierre formal' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="exp-card">
    <div class="exp-title">Historial de cierres</div>

    <?php if (empty($historialCierres)): ?>
        <div class="exp-empty">No hay cierres registrados.</div>
    <?php else: ?>
        <?php foreach ($historialCierres as $cierre): ?>
            <article class="exp-item">
                <div class="exp-item-title">
                    <?= e(caso_label((string)($cierre['tipo_cierre'] ?? 'Cierre'))) ?> ·
                    <?= e(caso_fecha_corta((string)($cierre['fecha_cierre'] ?? ''))) ?>
                </div>

                <div>
                    <span class="exp-badge <?= (string)($cierre['estado_cierre'] ?? '') === 'vigente' ? 'ok' : 'soft' ?>">
                        <?= e(caso_label((string)($cierre['estado_cierre'] ?? ''))) ?>
                    </span>
                    <span class="exp-badge soft">
                        <?= e((string)($cierre['cerrado_por_nombre'] ?? 'Sin usuario')) ?>
                    </span>
                </div>

                <div class="exp-item-text"><?= nl2br(e((string)($cierre['fundamento'] ?? ''))) ?></div>

                <?php if (!empty($cierre['motivo_anulacion'])): ?>
                    <div class="exp-muted-text">
                        Motivo anulación/reapertura: <?= e((string)$cierre['motivo_anulacion']) ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
