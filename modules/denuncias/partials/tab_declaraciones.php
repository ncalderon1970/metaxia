<?php
// Tab: Declaraciones
// ── Agrupar participantes que son la misma persona ──────────────────────────
// Si comparten RUN (≠ 0-0) o nombre exacto, se presentan como una sola fila.
$participantesAgrupados = [];
foreach ($participantes as $p) {
    $run    = trim((string)($p['run'] ?? ''));
    $nombre = mb_strtoupper(trim((string)($p['nombre_referencial'] ?? '')), 'UTF-8');

    $clave = ($run !== '' && $run !== '0-0')
           ? 'run:' . preg_replace('/[.\-\s]/', '', $run)
           : 'nom:' . preg_replace('/\s+/', ' ', $nombre);

    if (!isset($participantesAgrupados[$clave])) {
        $participantesAgrupados[$clave] = [
            'id'                => (int)$p['id'],
            'nombre_referencial'=> $nombre,
            'run'               => $run,
            'roles'             => [],
            'rol_principal'     => (string)($p['rol_en_caso'] ?? ''),
            'tipo_persona'      => (string)($p['tipo_persona'] ?? ''),
        ];
    }
    $participantesAgrupados[$clave]['roles'][] = (string)($p['rol_en_caso'] ?? '');
}
?>

<!-- ══ REGISTRAR DECLARACIÓN ═════════════════════════════════════════════════ -->
<section class="exp-card">
    <div class="exp-title">
        <i class="bi bi-chat-square-text-fill" style="color:#0369a1;"></i>
        Registrar declaración
    </div>

    <form method="post" enctype="multipart/form-data">
        <?= CSRF::field() ?>
        <input type="hidden" name="_accion" value="agregar_declaracion">

        <div style="display:grid;grid-template-columns:1fr auto;gap:.75rem;align-items:start;">
            <div>
                <label class="exp-label">Interviniente</label>
                <select class="exp-control" name="participante_id" id="decl-participante" required>
                    <option value="">— Seleccionar interviniente —</option>
                    <?php foreach ($participantesAgrupados as $grupo):
                        $rolesUnicos = array_unique($grupo['roles']);
                        $rolesLabel  = implode(' / ', array_map('caso_label', $rolesUnicos));
                        $esDoble     = count($rolesUnicos) > 1;
                    ?>
                        <option value="<?= (int)$grupo['id'] ?>"
                                data-nombre="<?= e($grupo['nombre_referencial']) ?>"
                                data-rol="<?= e($grupo['rol_principal']) ?>">
                            <?= $esDoble ? '⚠ ' : '' ?><?= e($grupo['nombre_referencial']) ?> · <?= e($rolesLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php
                $tienenDobleRol = array_filter($participantesAgrupados, fn($g) => count(array_unique($g['roles'])) > 1);
                if ($tienenDobleRol):
                ?>
                <div style="margin-top:.35rem;font-size:.73rem;color:#b45309;">
                    <i class="bi bi-info-circle"></i>
                    Las personas marcadas con ⚠ aparecen como víctima <em>y</em> denunciante en el caso.
                </div>
                <?php endif; ?>
            </div>

            <div style="min-width:210px;">
                <label class="exp-label">Fecha y hora de la declaración *</label>
                <input class="exp-control" type="datetime-local" name="fecha_declaracion"
                       id="decl-fecha"
                       value="<?= date('Y-m-d\TH:i') ?>"
                       max="<?= date('Y-m-d\TH:i') ?>"
                       required>
                <div style="margin-top:.3rem;font-size:.72rem;color:#64748b;">
                    <i class="bi bi-info-circle"></i>
                    Indica cuándo se tomó la declaración (puede diferir de la fecha de ingreso al sistema).
                </div>
            </div>
        </div>

        <div style="margin-top:1rem;">
            <label class="exp-label">Texto de la declaración *</label>
            <textarea class="exp-control" name="texto_declaracion" rows="5" required
                      placeholder="Relato completo del declarante..."></textarea>
        </div>

        <div style="margin-top:1rem;">
            <label class="exp-label">Observaciones internas</label>
            <textarea class="exp-control" name="observaciones" rows="2"
                      placeholder="Notas del encargado, contexto relevante..."></textarea>
        </div>

        <!-- Evidencia adjunta en el mismo acto -->
        <div style="margin-top:1.25rem;padding:1rem;background:#f0f9ff;border:1px solid #bae6fd;
                    border-radius:10px;">
            <div style="font-size:.78rem;font-weight:700;color:#0369a1;margin-bottom:.75rem;">
                <i class="bi bi-paperclip"></i>
                Adjuntar evidencia en este momento
                <span style="font-weight:400;color:#64748b;">(opcional)</span>
            </div>
            <div class="exp-grid-3">
                <div>
                    <label class="exp-label">Tipo de evidencia</label>
                    <select class="exp-control" name="evidencia_tipo">
                        <option value="">— Sin adjunto —</option>
                        <option value="documento">Documento</option>
                        <option value="imagen">Imagen</option>
                        <option value="audio">Audio</option>
                        <option value="video">Video</option>
                        <option value="archivo">Otro archivo</option>
                    </select>
                </div>
                <div>
                    <label class="exp-label">Archivo</label>
                    <input class="exp-control" type="file" name="evidencia_archivo">
                </div>
                <div>
                    <label class="exp-label">Descripción del archivo</label>
                    <input class="exp-control" type="text" name="evidencia_descripcion"
                           placeholder="Ej: Captura de pantalla, informe médico...">
                </div>
            </div>
        </div>

        <div style="margin-top:1rem;">
            <button class="exp-submit green" type="submit">
                <i class="bi bi-check-circle-fill"></i>
                Registrar declaración
            </button>
        </div>
    </form>
</section>

<!-- ══ NUEVO INTERVINIENTE DETECTADO ══════════════════════════════════════════ -->
<section class="exp-card" id="seccionNuevoInterviniente">
    <div class="exp-title" style="cursor:pointer;user-select:none;"
         onclick="toggleNuevoInterviniente()" id="tituloNuevoInterviniente">
        <i class="bi bi-person-plus-fill" style="color:#0369a1;"></i>
        ¿Detectaste un nuevo participante?
        <span id="iconoToggleInterviniente"
              style="margin-left:auto;font-size:.8rem;color:#64748b;">
            <i class="bi bi-chevron-down"></i> Agregar
        </span>
    </div>

    <div id="cuerpoNuevoInterviniente" style="display:none;margin-top:.75rem;">

        <div class="exp-help" style="margin-bottom:1rem;">
            Si durante la toma de declaración detectaste que hubo otro participante
            que aún no está registrado, agrégalo aquí. Quedará en el expediente y
            podrás vincular su declaración a continuación.
        </div>

        <!-- Buscador rápido -->
        <div class="vp-search-bar" style="margin-bottom:1rem;">
            <select class="exp-control" id="niTipo" style="width:160px;flex-shrink:0;"
                    onchange="niLimpiar()">
                <option value="alumno">Alumno/a</option>
                <option value="apoderado">Apoderado/a</option>
                <option value="funcionario">Docente / Asist.</option>
                <option value="todos">Todos</option>
                <option value="externo">Externo (manual)</option>
            </select>
            <div style="position:relative;flex:1;">
                <input class="exp-control" type="text" id="niBusqueda"
                       placeholder="Buscar por RUN o nombre…" autocomplete="off"
                       oninput="niBuscar(this.value)">
                <div id="niResultados"
                     style="display:none;position:absolute;top:100%;left:0;right:0;
                            background:#fff;border:1px solid #cbd5e1;border-radius:8px;
                            box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:50;max-height:220px;
                            overflow-y:auto;"></div>
            </div>
        </div>

        <form method="post" id="formNuevoInterviniente">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion"       value="agregar_participante">
            <input type="hidden" name="_redirect_tab" value="declaraciones">
            <input type="hidden" name="persona_id"    id="niPersonaId" value="">
            <input type="hidden" name="tipo_persona"  id="niTipoPersona" value="externo">

            <div class="exp-grid-3">
                <div style="grid-column:1/-1;">
                    <label class="exp-label">Nombre completo *</label>
                    <input class="exp-control" type="text" name="nombre_referencial"
                           id="niNombre" required
                           placeholder="Se completa al seleccionar o ingresar manualmente">
                </div>
                <div>
                    <label class="exp-label">RUN</label>
                    <input class="exp-control" type="text" name="run"
                           id="niRun" placeholder="0-0">
                </div>
                <div>
                    <label class="exp-label">Rol en el caso *</label>
                    <select class="exp-control" name="rol_en_caso">
                        <option value="victima">Víctima</option>
                        <option value="denunciante">Denunciante</option>
                        <option value="denunciado">Denunciado</option>
                        <option value="testigo">Testigo</option>
                        <option value="involucrado" selected>Otro interviniente</option>
                    </select>
                </div>
                <div>
                    <label class="exp-label">Observación</label>
                    <input class="exp-control" type="text" name="observacion"
                           placeholder="Ej: Detectado durante declaración de...">
                </div>
            </div>

            <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center;">
                <button class="exp-submit" type="submit">
                    <i class="bi bi-person-check-fill"></i>
                    Agregar interviniente
                </button>
                <button type="button" class="exp-submit"
                        style="background:#64748b;"
                        onclick="toggleNuevoInterviniente()">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</section>

<!-- ══ DECLARACIONES REGISTRADAS ══════════════════════════════════════════════ -->
<section class="exp-card">
    <div class="exp-title">
        <i class="bi bi-list-ul" style="color:#0369a1;"></i>
        Declaraciones registradas
        <?php if ($declaraciones): ?>
            <span style="font-size:.72rem;font-weight:400;color:#94a3b8;margin-left:.4rem;">
                (<?= count($declaraciones) ?>)
            </span>
        <?php endif; ?>
    </div>

    <?php if (!$declaraciones): ?>
        <div class="exp-empty">No hay declaraciones registradas aún.</div>
    <?php else: ?>
        <?php foreach ($declaraciones as $d): ?>
            <article class="exp-item">
                <div style="display:flex;align-items:flex-start;gap:.75rem;">
                    <div style="flex:1;">
                        <div class="exp-item-title"><?= e($d['nombre_declarante']) ?></div>
                        <div class="exp-item-meta">
                            <?= e(caso_label($d['calidad_procesal'])) ?> ·
                            <?= e(caso_fecha((string)$d['fecha_declaracion'])) ?>
                            <?php if (!empty($d['participante_nombre'])): ?>
                                · Interviniente: <?= e($d['participante_nombre']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?= APP_URL ?>/modules/denuncias/imprimir_declaracion.php?id=<?= (int)$d['id'] ?>"
                       target="_blank"
                       title="Imprimir declaración para firma"
                       style="flex-shrink:0;display:inline-flex;align-items:center;gap:.3rem;
                              padding:.35rem .75rem;border-radius:7px;font-size:.75rem;font-weight:600;
                              background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;
                              text-decoration:none;white-space:nowrap;transition:background .15s;">
                        <i class="bi bi-printer-fill"></i>
                        Imprimir / Firmar
                    </a>
                </div>

                <div class="exp-item-text" style="margin-top:.6rem;"><?= nl2br(e($d['texto_declaracion'])) ?></div>

                <?php if (!empty($d['observaciones'])): ?>
                    <hr>
                    <div class="exp-item-text">
                        <strong>Observaciones internas:</strong><br>
                        <?= nl2br(e($d['observaciones'])) ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<!-- ══ JS ════════════════════════════════════════════════════════════════════ -->
<script>
// (sin auto-relleno — nombre, run y calidad procesal se derivan en el servidor)

// ── Panel "Nuevo interviniente" ───────────────────────────────────────────────
function toggleNuevoInterviniente() {
    const cuerpo = document.getElementById('cuerpoNuevoInterviniente');
    const icono  = document.getElementById('iconoToggleInterviniente');
    const abierto = cuerpo.style.display !== 'none';

    cuerpo.style.display = abierto ? 'none' : 'block';
    icono.innerHTML = abierto
        ? '<i class="bi bi-chevron-down"></i> Agregar'
        : '<i class="bi bi-chevron-up"></i> Cerrar';
}

// ── Buscador rápido en "Nuevo interviniente" ──────────────────────────────────
let niTimer = null;

function niBuscar(q) {
    clearTimeout(niTimer);
    const tipo = document.getElementById('niTipo').value;
    if (tipo === 'externo' || q.trim().length < 2) {
        document.getElementById('niResultados').style.display = 'none';
        return;
    }
    niTimer = setTimeout(() => {
        const url = '<?= APP_URL ?>/modules/denuncias/ver.php'
                  + '?id=<?= (int)$casoId ?>&ajax=buscar_participante'
                  + '&tipo=' + encodeURIComponent(tipo)
                  + '&q='    + encodeURIComponent(q);

        fetch(url)
            .then(r => r.json())
            .then(data => niMostrarResultados(data))
            .catch(() => {});
    }, 280);
}

function niMostrarResultados(data) {
    const box = document.getElementById('niResultados');
    if (!data.ok || !data.items.length) {
        box.style.display = 'none';
        return;
    }
    box.innerHTML = '';
    data.items.forEach(item => {
        const div = document.createElement('div');
        div.style.cssText = 'padding:.55rem .85rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.82rem;';
        div.innerHTML = '<strong>' + item.nombre + '</strong>'
            + (item.run && item.run !== '0-0' ? ' <span style="color:#64748b;">· ' + item.run + '</span>' : '')
            + (item.curso ? ' <em style="color:#94a3b8;">· ' + item.curso + '</em>' : '')
            + ' <span style="color:#0369a1;font-size:.72rem;">[' + item.tipo_label + ']</span>';
        div.addEventListener('mouseenter', () => div.style.background = '#f0f9ff');
        div.addEventListener('mouseleave', () => div.style.background = '');
        div.addEventListener('click', () => niSeleccionar(item));
        box.appendChild(div);
    });
    box.style.display = 'block';
}

function niSeleccionar(item) {
    document.getElementById('niNombre').value     = item.nombre;
    document.getElementById('niRun').value        = item.run !== '0-0' ? item.run : '';
    document.getElementById('niPersonaId').value  = item.id;
    document.getElementById('niTipoPersona').value= item.tipo;
    document.getElementById('niBusqueda').value   = item.nombre;
    document.getElementById('niResultados').style.display = 'none';
}

function niLimpiar() {
    document.getElementById('niPersonaId').value   = '';
    document.getElementById('niNombre').value      = '';
    document.getElementById('niRun').value         = '';
    document.getElementById('niBusqueda').value    = '';
    document.getElementById('niResultados').style.display = 'none';
}

// Cerrar resultados al hacer click fuera
document.addEventListener('click', e => {
    const box = document.getElementById('niResultados');
    if (box && !box.contains(e.target) && e.target.id !== 'niBusqueda') {
        box.style.display = 'none';
    }
});
</script>
