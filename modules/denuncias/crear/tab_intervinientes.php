<?php
// Fase 0.5.38G: pestaña 1 - intervinientes.
?>
<section class="nd-tab-panel active" data-tab-panel="intervinientes">
            <section class="nd-panel">
                <div class="nd-head"><span class="nd-step">1</span><h3>Intervinientes</h3></div>
                <div class="nd-body">
                    <div class="nd-inter-tools">
                        <button type="button" class="nd-add-btn" id="agregarInterviniente">
                            <i class="bi bi-plus-circle"></i>
                            Agregar otro interviniente
                        </button>
                    </div>

                    <div id="intervinientesContainer">
                        <div class="nd-inter-card" data-interviniente-card>
                            <div class="nd-card-title-row">
                                <strong>Interviniente 1</strong>
                                <button type="button" class="nd-remove-btn" data-remove-interviniente>
                                    <i class="bi bi-trash"></i>
                                    Quitar
                                </button>
                            </div>

                            <div class="nd-inter-grid">
                                <div class="nd-inter-col-tipo">
                                    <label class="nd-label">Tipo</label>
                                    <select class="nd-control" data-tipo-busqueda>
                                        <option value="alumno">Alumno</option>
                                        <option value="funcionario">Docente / Asistente</option>
                                        <option value="apoderado">Apoderado</option>
                                        <option value="externo">Otro actor civil</option>
                                    </select>
                                </div>

                                <div class="nd-search-wrap nd-inter-col-run">
                                    <label class="nd-label">RUN / Nombre</label>
                                    <input type="text" class="nd-control" maxlength="180" placeholder="Digite RUN o nombre para buscar" data-busqueda>
                                    <div class="nd-results" data-resultados></div>
                                    <div class="nd-help" data-busqueda-help>Buscará coincidencias según el tipo seleccionado.</div>
                                </div>

                                <div class="nd-inter-col-nombre">
                                    <label class="nd-label">Nombre completo</label>
                                    <input type="text" class="nd-control" maxlength="180" readonly placeholder="Se completa al seleccionar" data-nombre-referencial>
                                    <div class="nd-help">Para "otro actor civil" o N/N se permite ingreso manual/controlado.</div>
                                </div>

                                <div class="nd-inter-col-cond">
                                    <label class="nd-label">Condición</label>
                                    <select class="nd-control" data-rol-en-caso>
                                        <option value="">Seleccione condición</option>
                                        <option value="denunciante">Denunciante</option>
                                        <option value="victima">Víctima</option>
                                        <option value="testigo">Testigo</option>
                                        <option value="denunciado">Denunciado</option>
                                    </select>
                                </div>


                            </div><!-- /nd-inter-grid -->

                            <input type="hidden" value="" data-persona-id>
                            <input type="hidden" value="" data-tipo-persona>
                            <input type="hidden" value="" data-run>

                            <div class="nd-selected" data-seleccionado>
                                <span data-seleccionado-texto></span>
                                <button type="button" class="nd-mini-btn" data-limpiar-interviniente>Cambiar</button>
                            </div>
                        </div>
                    </div>

                    <div class="nd-inter-summary" id="intervinientesResumen">
                        <div class="nd-summary-head">
                            <div class="nd-summary-title">
                                <i class="bi bi-list-check"></i>
                                Intervinientes registrados durante la digitación
                            </div>
                            <span class="nd-summary-count" id="intervinientesTotal">0 registrado(s)</span>
                        </div>
                        <div class="nd-summary-body">
                            <div class="nd-summary-empty" id="intervinientesVacio">
                                Aún no has agregado intervinientes a la lista. Usa el capturador superior y presiona "Agregar interviniente a la lista".
                            </div>
                            <div class="nd-summary-list" id="intervinientesLista" style="display:none;"></div>
                            <div class="nd-summary-counters" id="intervinientesContadores" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </section>

                <div class="nd-mini-nav">
                    <span></span>
                    <button type="button" class="nd-submit" data-tab-target="datos_denuncia"><i class="bi bi-arrow-right"></i> Continuar a datos de la denuncia</button>
                </div>
            </section>
