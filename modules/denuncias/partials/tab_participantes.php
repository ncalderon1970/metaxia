<?php
$rolesCaso = [
    'victima'     => 'Víctima',
    'denunciante' => 'Denunciante',
    'denunciado'  => 'Denunciado/a',
    'testigo'     => 'Testigo',
    'involucrado' => 'Otro interviniente',
];

$normalizarRolParticipante = static function (?string $rol): string {
    $rol = strtolower(trim((string)$rol));
    if ($rol === 'otro' || $rol === 'otros' || $rol === 'otro_interviniente') {
        return 'involucrado';
    }
    return $rol !== '' ? $rol : 'involucrado';
};

$labelRolParticipante = static function (?string $rol) use ($rolesCaso, $normalizarRolParticipante): string {
    $rolNormalizado = $normalizarRolParticipante($rol);
    return $rolesCaso[$rolNormalizado] ?? 'Otro interviniente';
};

$puedeOperarParticipantes = Auth::canOperate()
    || Auth::can('gestionar_casos')
    || Auth::can('crear_denuncia')
    || Auth::can('gestionar_comunidad');

$fechaReferenciaIntervinientes = date('Y-m-d');
if (!empty($caso['fecha_hechos'])) {
    $fechaReferenciaIntervinientes = date('Y-m-d', strtotime((string)$caso['fecha_hechos']));
} elseif (!empty($caso['fecha_hora_incidente'])) {
    $fechaReferenciaIntervinientes = date('Y-m-d', strtotime((string)$caso['fecha_hora_incidente']));
} elseif (!empty($caso['fecha_ingreso'])) {
    $fechaReferenciaIntervinientes = date('Y-m-d', strtotime((string)$caso['fecha_ingreso']));
} elseif (!empty($caso['created_at'])) {
    $fechaReferenciaIntervinientes = date('Y-m-d', strtotime((string)$caso['created_at']));
}

$anioEscolarIntervinientes = (int)date('Y', strtotime($fechaReferenciaIntervinientes));
?>

    <section class="exp-card">
        <div class="exp-title">Intervinientes</div>

        <?php if (!$participantes): ?>
            <div class="exp-empty">No hay intervinientes registrados.</div>
        <?php else: ?>
            <?php foreach ($participantes as $p): ?>
                <?php
                    $rolActual = $normalizarRolParticipante((string)($p['rol_en_caso'] ?? ''));
                    $participanteId = (int)($p['id'] ?? 0);
                ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e($p['nombre_referencial']) ?></div>
                    <div class="exp-item-meta">
                        RUN <?= e($p['run']) ?> ·
                        <?= e(caso_label($p['tipo_persona'])) ?> ·
                        <?= e($labelRolParticipante($rolActual)) ?>
                    </div>

                    <?php if (!empty($p['persona_id'])): ?>
                        <span class="exp-badge ok">
                            <i class="bi bi-link-45deg"></i>
                            Vinculado a comunidad educativa
                        </span>
                    <?php endif; ?>

                    <?php if ((int)$p['solicita_reserva_identidad'] === 1): ?>
                        <span class="exp-badge warn">Solicita reserva de identidad</span>
                    <?php endif; ?>

                    <?php if (!empty($p['observacion'])): ?>
                        <div class="exp-item-text"><?= e($p['observacion']) ?></div>
                    <?php endif; ?>

                    <?php if ($participanteId > 0): ?>
                        <?php if ($puedeOperarParticipantes): ?>
                            <details style="margin-top:.85rem;">
                                <summary class="exp-link warn" style="display:inline-flex;cursor:pointer;list-style:none;align-items:center;gap:.35rem;">
                                    <i class="bi bi-arrow-left-right"></i>
                                    Reclasificar
                                </summary>

                                <div class="exp-help" style="margin-top:.65rem;">
                                    Permite corregir la calidad del interviniente cuando la investigación entrega nuevos antecedentes.
                                </div>

                                <form method="post" action="<?= APP_URL ?>/modules/denuncias/reclasificar_participante.php" style="margin-top:.75rem;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">
                                    <input type="hidden" name="participante_id" value="<?= $participanteId ?>">

                                    <div class="exp-grid-3">
                                        <div>
                                            <label class="exp-label">Nueva calidad</label>
                                            <select class="exp-control" name="rol_nuevo" required>
                                                <?php foreach ($rolesCaso as $valorRol => $textoRol): ?>
                                                    <option value="<?= e($valorRol) ?>" <?= $valorRol === $rolActual ? 'selected' : '' ?>>
                                                        <?= e($textoRol) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="exp-field-full" style="grid-column:span 2;">
                                            <label class="exp-label">Motivo de reclasificación</label>
                                            <input class="exp-control" type="text" name="motivo_reclasificacion"
                                                   maxlength="1000" required
                                                   placeholder="Ej.: Nuevos antecedentes de la investigación permiten corregir la calidad registrada.">
                                        </div>
                                    </div>

                                    <div style="margin-top:.75rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                                        <button class="exp-submit" type="submit">
                                            <i class="bi bi-check2-circle"></i>
                                            Guardar reclasificación
                                        </button>
                                        <span class="exp-help" style="margin:0;">
                                            El cambio quedará registrado en el historial del expediente.
                                        </span>
                                    </div>
                                </form>
                            </details>
                        <?php else: ?>
                            <div class="exp-help" style="margin-top:.75rem;">
                                Reclasificación disponible solo para usuarios con permiso de gestión del expediente.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="exp-card" id="seccionAgregarParticipante">
        <div class="exp-title">Agregar intervinientes</div>

        <div class="exp-help">
            Busca por RUN o nombre en la base institucional anual de alumnos, apoderados, docentes o asistentes.
            La búsqueda usa el año escolar <strong><?= (int)$anioEscolarIntervinientes ?></strong>, asociado a la fecha de referencia del caso.
            Si la persona no aparece, selecciona <strong>Externo / no vinculado</strong> e ingrésala manualmente.
        </div>

        <!-- ── Buscador ── -->
        <div class="vp-search-bar">
            <select class="exp-control vp-tipo" id="vpTipo" style="width:160px;flex-shrink:0;">
                <option value="alumno">Alumno/a</option>
                <option value="apoderado">Apoderado/a</option>
                <option value="funcionario">Docente / Asist.</option>
                <option value="todos">Todos</option>
                <option value="externo">Externo (manual)</option>
            </select>
            <div style="position:relative;flex:1;">
                <input class="exp-control" type="text" id="vpBusqueda"
                       placeholder="Buscar por RUN o nombre…" autocomplete="off">
                <div id="vpResultados" class="vp-results" style="display:none;"></div>
            </div>
            <div id="vpSpinner" style="display:none;font-size:.8rem;color:#888;">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </div>

        <!-- ── Formulario (se llena automáticamente al seleccionar) ── -->
        <form method="post" id="vpForm" style="margin-top:1.1rem;">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion"       value="agregar_participante">
            <input type="hidden" name="persona_id"    id="vpPersonaId"   value="">
            <input type="hidden" name="tipo_persona"  id="vpTipoPersona" value="alumno">
            <input type="hidden" name="persona_anual_id" id="vpPersonaAnualId" value="">
            <input type="hidden" name="persona_anual_tipo" id="vpPersonaAnualTipo" value="">
            <input type="hidden" name="anio_escolar" id="vpAnioEscolar" value="<?= (int)$anioEscolarIntervinientes ?>">
            <input type="hidden" name="snapshot_run" id="vpSnapshotRun" value="">
            <input type="hidden" name="snapshot_nombres" id="vpSnapshotNombres" value="">
            <input type="hidden" name="snapshot_apellido_paterno" id="vpSnapshotApellidoPaterno" value="">
            <input type="hidden" name="snapshot_apellido_materno" id="vpSnapshotApellidoMaterno" value="">
            <input type="hidden" name="snapshot_nombre_social" id="vpSnapshotNombreSocial" value="">
            <input type="hidden" name="snapshot_sexo" id="vpSnapshotSexo" value="">
            <input type="hidden" name="snapshot_genero" id="vpSnapshotGenero" value="">
            <input type="hidden" name="snapshot_fecha_nacimiento" id="vpSnapshotFechaNacimiento" value="">
            <input type="hidden" name="snapshot_edad" id="vpSnapshotEdad" value="">
            <input type="hidden" name="snapshot_curso" id="vpSnapshotCurso" value="">
            <input type="hidden" name="snapshot_anio_escolar" id="vpSnapshotAnioEscolar" value="<?= (int)$anioEscolarIntervinientes ?>">
            <input type="hidden" name="snapshot_fecha_referencia" id="vpSnapshotFechaReferencia" value="<?= e($fechaReferenciaIntervinientes) ?>">

            <div class="exp-grid-3">
                <div class="exp-field-full" style="grid-column:1/-1;">
                    <label class="exp-label">Nombre</label>
                    <input class="exp-control" type="text" name="nombre_referencial"
                           id="vpNombre" required placeholder="Se completa al seleccionar o ingresar manualmente">
                </div>

                <div>
                    <label class="exp-label">RUN</label>
                    <input class="exp-control" type="text" name="run"
                           id="vpRun" placeholder="0-0">
                </div>

                <div>
                    <label class="exp-label">Calidad en el caso</label>
                    <select class="exp-control" name="rol_en_caso" id="vpRol">
                        <?php foreach ($rolesCaso as $valorRol => $textoRol): ?>
                            <option value="<?= e($valorRol) ?>"><?= e($textoRol) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Observación</label>
                    <input class="exp-control" type="text" name="observacion" id="vpObservacion">
                </div>

                <div>
                    <label class="exp-label">Reserva identidad</label>
                    <label style="display:flex;align-items:center;gap:.4rem;margin-top:.5rem;font-size:.85rem;">
                        <input type="checkbox" name="solicita_reserva_identidad" value="1" id="vpReserva">
                        Solicita reserva
                    </label>
                </div>
            </div>

            <div style="margin-top:.85rem;">
                <label class="exp-label">Observación de reserva</label>
                <input class="exp-control" type="text" name="observacion_reserva" id="vpObsReserva">
            </div>

            <div id="vpFuenteBadge" style="display:none;margin-top:.75rem;">
                <span class="exp-badge ok" id="vpFuenteTexto"></span>
                <button type="button" id="vpLimpiar" style="margin-left:.5rem;background:none;
                    border:none;color:#c0392b;font-size:.8rem;cursor:pointer;">
                    <i class="bi bi-x-circle"></i> Limpiar selección
                </button>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit green" type="submit">
                    <i class="bi bi-person-plus"></i>
                    Agregar interviniente
                </button>
            </div>
        </form>
    </section>

<style>
/* ── Buscador de intervinientes en ver.php ── */
.vp-search-bar    { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
.vp-results       { position:absolute; top:100%; left:0; right:0; z-index:200;
                    background:#fff; border:1px solid #c8d6f0; border-radius:8px;
                    box-shadow:0 6px 24px rgba(0,0,0,.13); max-height:280px;
                    overflow-y:auto; margin-top:2px; }
.vp-result-item   { padding:.6rem .85rem; cursor:pointer; border-bottom:1px solid #f0f3f7;
                    font-size:.84rem; display:flex; justify-content:space-between;
                    align-items:center; gap:.5rem; transition:background .12s; }
.vp-result-item:hover { background:#f0f5ff; }
.vp-result-item:last-child { border-bottom:none; }
.vp-result-nombre { font-weight:600; color:#1a3a5c; }
.vp-result-meta   { font-size:.74rem; color:#888; white-space:nowrap; }
.vp-result-tipo   { font-size:.7rem; background:#e8f0fe; color:#1a3a5c;
                    border-radius:12px; padding:.1rem .5rem; font-weight:600; }
.vp-msg           { padding:.7rem .85rem; font-size:.8rem; color:#888; text-align:center; }
</style>

<script>
(function () {
    'use strict';

    var ajaxUrl = '<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&ajax=buscar_participante';
    var csrfToken = '<?= e(CSRF::token()) ?>';

    var inpTipo    = document.getElementById('vpTipo');
    var inpBusq    = document.getElementById('vpBusqueda');
    var resultados = document.getElementById('vpResultados');
    var spinner    = document.getElementById('vpSpinner');
    var fuenteBadge= document.getElementById('vpFuenteBadge');
    var fuenteTexto= document.getElementById('vpFuenteTexto');
    var btnLimpiar = document.getElementById('vpLimpiar');

    var inpNombre   = document.getElementById('vpNombre');
    var inpRun      = document.getElementById('vpRun');
    var inpPersonaId= document.getElementById('vpPersonaId');
    var inpTipoP    = document.getElementById('vpTipoPersona');
    var inpPersonaAnualId = document.getElementById('vpPersonaAnualId');
    var inpPersonaAnualTipo = document.getElementById('vpPersonaAnualTipo');
    var inpAnioEscolar = document.getElementById('vpAnioEscolar');
    var snapRun = document.getElementById('vpSnapshotRun');
    var snapNombres = document.getElementById('vpSnapshotNombres');
    var snapApellidoPaterno = document.getElementById('vpSnapshotApellidoPaterno');
    var snapApellidoMaterno = document.getElementById('vpSnapshotApellidoMaterno');
    var snapNombreSocial = document.getElementById('vpSnapshotNombreSocial');
    var snapSexo = document.getElementById('vpSnapshotSexo');
    var snapGenero = document.getElementById('vpSnapshotGenero');
    var snapFechaNacimiento = document.getElementById('vpSnapshotFechaNacimiento');
    var snapEdad = document.getElementById('vpSnapshotEdad');
    var snapCurso = document.getElementById('vpSnapshotCurso');
    var snapAnioEscolar = document.getElementById('vpSnapshotAnioEscolar');
    var snapFechaReferencia = document.getElementById('vpSnapshotFechaReferencia');

    var timerBusq = null;
    var modoManual = false;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function limpiarSeleccion() {
        inpNombre.value    = '';
        inpRun.value       = '';
        inpPersonaId.value = '';
        inpPersonaAnualId.value = '';
        inpPersonaAnualTipo.value = '';
        snapRun.value = '';
        snapNombres.value = '';
        snapApellidoPaterno.value = '';
        snapApellidoMaterno.value = '';
        snapNombreSocial.value = '';
        snapSexo.value = '';
        snapGenero.value = '';
        snapFechaNacimiento.value = '';
        snapEdad.value = '';
        snapCurso.value = '';
        snapAnioEscolar.value = inpAnioEscolar.value || '';
        snapFechaReferencia.value = '<?= e($fechaReferenciaIntervinientes) ?>';
        inpBusq.value      = '';
        fuenteBadge.style.display = 'none';
        inpNombre.readOnly = false;
        inpRun.readOnly    = false;
        modoManual = false;
    }

    function seleccionar(item) {
        inpNombre.value    = item.nombre  || '';
        inpRun.value       = item.run     || '0-0';
        inpPersonaId.value = item.persona_id || '';
        inpPersonaAnualId.value = item.persona_anual_id || item.id || '';
        inpPersonaAnualTipo.value = item.persona_anual_tipo || item.tipo || inpTipo.value;
        inpTipoP.value     = item.tipo    || inpTipo.value;
        snapRun.value = item.snapshot_run || item.run || '0-0';
        snapNombres.value = item.snapshot_nombres || '';
        snapApellidoPaterno.value = item.snapshot_apellido_paterno || '';
        snapApellidoMaterno.value = item.snapshot_apellido_materno || '';
        snapNombreSocial.value = item.snapshot_nombre_social || item.nombre_social || '';
        snapSexo.value = item.snapshot_sexo || item.sexo || '';
        snapGenero.value = item.snapshot_genero || item.genero || '';
        snapFechaNacimiento.value = item.snapshot_fecha_nacimiento || item.fecha_nacimiento || '';
        snapEdad.value = item.snapshot_edad || item.edad || '';
        snapCurso.value = item.snapshot_curso || item.curso || '';
        snapAnioEscolar.value = item.snapshot_anio_escolar || item.anio_escolar || inpAnioEscolar.value || '';
        snapFechaReferencia.value = item.snapshot_fecha_referencia || '<?= e($fechaReferenciaIntervinientes) ?>';
        inpNombre.readOnly = true;
        inpRun.readOnly    = true;
        fuenteTexto.textContent = 'Vinculado anual: ' + (item.nombre || '') + ' · ' + (item.tipo_label || item.tipo || '') + (item.anio_escolar ? ' · Año ' + item.anio_escolar : '');
        fuenteBadge.style.display = 'block';
        resultados.style.display  = 'none';
        inpBusq.value = '';
    }

    function buscar(q, tipo) {
        if (tipo === 'externo') {
            resultados.style.display = 'none';
            inpNombre.readOnly = false;
            inpRun.readOnly    = false;
            modoManual = true;
            return;
        }
        if (q.length < 2) { resultados.style.display = 'none'; return; }

        spinner.style.display = 'inline';
        fetch(ajaxUrl + '&tipo=' + encodeURIComponent(tipo) + '&anio_escolar=' + encodeURIComponent(inpAnioEscolar.value || '') + '&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(data){
                spinner.style.display = 'none';
                resultados.innerHTML = '';
                resultados.style.display = 'block';

                if (!data.ok || !data.items || data.items.length === 0) {
                    var msg = document.createElement('div');
                    msg.className = 'vp-msg';
                    msg.textContent = data.message || 'Sin coincidencias. Cambia el tipo a "Externo" para ingresar manualmente.';
                    resultados.appendChild(msg);
                    return;
                }

                data.items.forEach(function(item) {
                    var div = document.createElement('div');
                    div.className = 'vp-result-item';
                    div.innerHTML =
                        '<div>' +
                            '<div class="vp-result-nombre">' + escHtml(item.nombre) + '</div>' +
                            '<div class="vp-result-meta">RUN ' + escHtml(item.run || '0-0') +
                            (item.curso ? ' · ' + escHtml(item.curso) : '') +
                            (item.edad ? ' · ' + escHtml(item.edad) + ' años' : '') +
                            (item.anio_escolar ? ' · Año ' + escHtml(item.anio_escolar) : '') + '</div>' +
                        '</div>' +
                        '<span class="vp-result-tipo">' + escHtml(item.tipo_label || item.tipo || '') + '</span>';
                    div.addEventListener('click', function(){ seleccionar(item); });
                    resultados.appendChild(div);
                });
            })
            .catch(function(){
                spinner.style.display = 'none';
                resultados.style.display = 'none';
            });
    }

    inpBusq.addEventListener('input', function(){
        clearTimeout(timerBusq);
        timerBusq = setTimeout(function(){
            buscar(inpBusq.value.trim(), inpTipo.value);
        }, 320);
    });

    inpBusq.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); }
    });

    inpTipo.addEventListener('change', function(){
        limpiarSeleccion();
        inpNombre.readOnly = inpTipo.value !== 'externo' ? false : false;
        if (inpTipo.value === 'externo') {
            inpNombre.readOnly = false;
            inpRun.readOnly    = false;
            inpTipoP.value     = 'externo';
            inpPersonaAnualId.value = '';
            inpPersonaAnualTipo.value = '';
            resultados.style.display = 'none';
        }
    });

    btnLimpiar && btnLimpiar.addEventListener('click', limpiarSeleccion);

    document.addEventListener('click', function(e){
        if (!resultados.contains(e.target) && e.target !== inpBusq) {
            resultados.style.display = 'none';
        }
    });
})();
</script>
