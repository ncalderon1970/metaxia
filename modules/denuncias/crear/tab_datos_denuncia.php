<?php
// Fase 0.5.38G-2: pestaña 2 - datos de la denuncia, orden visual y marcadores compactos.
?>
<section class="nd-tab-panel" data-tab-panel="datos_denuncia">
                <div id="alertaCondicionEspecialAnchor" style="margin-bottom:.5rem;"></div>
                <section class="nd-panel">
                    <div class="nd-head"><span class="nd-step">2</span><h3>Datos de la denuncia</h3></div>
                    <div class="nd-body">

                        <div class="nd-grid">
                            <div>
                                <label class="nd-label">Fecha y hora del incidente <span class="nd-required">*</span></label>
                                <input type="datetime-local" name="fecha_hora_incidente" id="fechaHoraIncidente" class="nd-control" required>
                                <div class="nd-help">Debe corresponder al momento estimado u observado del hecho. Campo obligatorio.</div>
                            </div>

                            <div>
                                <label class="nd-label">Lugar de los hechos</label>
                                <input type="text" name="lugar_hechos" maxlength="180" class="nd-control" placeholder="Ej: PATIO CENTRAL, BAÑO, SALA 4B">
                                <div class="nd-help">Espacio físico o digital donde ocurrió la situación.</div>
                            </div>

                            <div>
                                <label class="nd-label">Contexto</label>
                                <input type="text" name="contexto" maxlength="150" class="nd-control" placeholder="Ej: SALA DE CLASES, RECREO, PATIO, EXTERNO">
                                <div class="nd-help">Ámbito en que se produjo o se informó la situación.</div>
                            </div>

                            <div>
                                <label class="nd-label">Canal de ingreso</label>
                                <select name="canal_ingreso" class="nd-control">
                                    <option value="presencial">Presencial</option>
                                    <option value="correo">Correo electrónico</option>
                                    <option value="telefono">Teléfono</option>
                                    <option value="formulario">Formulario interno</option>
                                    <option value="derivacion_funcionario">Derivación de funcionario</option>
                                    <option value="otro">Otro</option>
                                </select>
                                <div class="nd-help">Se guardará si la tabla casos tiene columna compatible.</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="nd-panel">
                    <div class="nd-head"><span class="nd-step">2A</span><h3>Relato de los hechos</h3></div>
                    <div class="nd-body">
                        <label class="nd-label">Relato <span class="nd-required">*</span></label>
                        <textarea name="relato" id="relato" rows="9" maxlength="5000" class="nd-control" required placeholder="Describa con el mayor detalle posible los hechos denunciados: qué ocurrió, cuándo, dónde, quiénes participaron, cómo se tomó conocimiento y qué antecedentes existen."></textarea>
                        <div style="display:flex;justify-content:space-between;gap:1rem;margin-top:.35rem;">
                            <div class="nd-help">Campo obligatorio. Evita conclusiones anticipadas; registra hechos observados o relatados.</div>
                            <div class="nd-help" id="relatoContador">0 / 5000 caracteres</div>
                        </div>
                    </div>
                </section>


                <section class="nd-panel">
                    <div class="nd-head"><span class="nd-step" style="background:#dc2626;">2B</span><h3>Evaluación preliminar de Aula Segura</h3></div>
                    <div class="nd-body">
                        <?php if (!$estructuraAulaSeguraOk): ?>
                            <div class="nd-alert danger">La estructura de Aula Segura aún no está disponible. Ejecuta primero la Fase 0.5.36A.</div>
                        <?php else: ?>
                            <div class="nd-aula">
                                <label class="nd-main-check">
                                    <input type="checkbox" name="posible_aula_segura" id="posibleAulaSegura" value="1">
                                    <span>
                                        El hecho podría configurar causal de Aula Segura
                                        <span style="display:block;font-weight:700;color:#92400e;font-size:.78rem;margin-top:.18rem;">Esta marca solo genera una alerta preliminar. No inicia formalmente el procedimiento.</span>
                                    </span>
                                </label>
                                <div class="nd-causales" id="bloqueCausalesAulaSegura">
                                    <div class="nd-alert warn">Seleccione la causal preliminar observada. La evaluación formal corresponde a Dirección.</div>
                                    <label class="nd-label">Causales preliminares</label>
                                    <?php foreach ($causalesAulaSegura as $causal): ?>
                                        <label class="nd-causal">
                                            <input type="checkbox" name="aula_segura_causales[]" value="<?= e((string)$causal['codigo']) ?>">
                                            <span>
                                                <?= e((string)$causal['nombre']) ?>
                                                <?php if (($causal['tipo'] ?? '') === 'reglamento'): ?>
                                                    <span style="display:block;color:#92400e;font-size:.74rem;margin-top:.12rem;">Requiere fundamentar proporcionalidad y afectación grave a la convivencia escolar.</span>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                    <label class="nd-label" style="margin-top:1rem;">Observación preliminar del receptor</label>
                                    <textarea name="aula_segura_observacion_preliminar" class="nd-control" rows="4" maxlength="1500" placeholder="Explique brevemente por qué se marca esta alerta preliminar."></textarea>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>


                <div class="nd-mini-nav">
                    <button type="button" class="nd-link" data-tab-target="intervinientes"><i class="bi bi-arrow-left"></i> Volver a intervinientes</button>
                    <button type="button" class="nd-submit" data-tab-target="comunicacion_apoderado"><i class="bi bi-arrow-right"></i> Continuar a comunicación</button>
                </div>

<script>
(function(){
    // Impedir fechas futuras en fecha/hora del incidente
    var fhInc = document.getElementById('fechaHoraIncidente');
    if (fhInc) {
        var now = new Date();
        var pad = function(n){ return String(n).padStart(2, '0'); };
        fhInc.max = now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate())
                  + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
        fhInc.addEventListener('change', function() {
            if (fhInc.value && fhInc.value > fhInc.max) {
                fhInc.value = '';
                alert('La fecha y hora del incidente no puede ser futura.');
            }
        });
    }

})();
</script>
            </section>
