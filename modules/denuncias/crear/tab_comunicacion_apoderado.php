<?php
// Fase marcadores: pestaña 3 — comunicación inicial al apoderado.
// Muestra una fila compacta por cada alumno registrado en tab 1,
// con modalidad, fecha y estado en la misma línea.
?>
<section class="nd-tab-panel" data-tab-panel="comunicacion_apoderado">

    <section class="nd-panel nd-com-panel">
        <div class="nd-head">
            <span class="nd-step" style="background:#7c3aed;"><i class="bi bi-person-lines-fill"></i></span>
            <h3>Comunicación al apoderado</h3>
        </div>

        <div class="nd-body">
            <div class="nd-tab-note nd-tab-note-blue">
                Registra la comunicación inicial al apoderado de cada alumno involucrado.
                Si aún no corresponde, puedes continuar y actualizarla en seguimiento.
            </div>

            <!-- ── Lista dinámica de alumnos ── -->
            <div id="comApoderadoLista">
                <!-- Se rellena por JS con los alumnos del tab 1 -->
            </div>

            <div id="comApoderadoVacio" style="display:none;padding:.75rem;background:#f8fafd;
                 border-radius:8px;border:1px dashed #dde3ec;font-size:.82rem;color:#888;text-align:center;">
                <i class="bi bi-info-circle"></i>
                No hay alumnos registrados en la pestaña de intervinientes.
                Agrega los participantes primero y luego vuelve aquí.
            </div>

            <!-- ── Notas generales ── -->
            <div style="margin-top:1.1rem;">
                <label class="nd-label">Notas generales de comunicación</label>
                <textarea
                    name="comunicacion_apoderado_notas"
                    id="comunicacionApoderadoNotas"
                    class="nd-control nd-com-textarea"
                    rows="4"
                    maxlength="2500"
                    placeholder="Registre resultado, orientaciones entregadas, acuerdos iniciales, medidas informadas o razones por las cuales queda pendiente."
                ></textarea>
                <div style="display:flex;justify-content:space-between;margin-top:.3rem;">
                    <div class="nd-help">Evita juicios anticipados. Registra hechos, medio de contacto y acuerdos relevantes.</div>
                    <div class="nd-help" id="comunicacionNotasContador">0 / 2500 caracteres</div>
                </div>
            </div>
        </div>
    </section>

    <div class="nd-mini-nav nd-mini-nav--inter">
        <div class="nd-mini-nav-left">
            <button type="submit" class="nd-submit"><i class="bi bi-save"></i> Registrar denuncia</button>
            <a href="<?= APP_URL ?>/modules/denuncias/index.php" class="nd-link"><i class="bi bi-x-lg"></i> Cancelar</a>
        </div>
        <button type="button" class="nd-link" data-tab-target="datos_denuncia">
            <i class="bi bi-arrow-left"></i> Volver a datos de la denuncia
        </button>
    </div>
</section>

<style>
/* ── Filas compactas de comunicación por alumno ── */
.nd-com-row         {
    display: grid;
    grid-template-columns: 1fr 140px 170px 140px;
    align-items: center;
    gap: .5rem;
    padding: .55rem .75rem;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    background: #fff;
    margin-bottom: .45rem;
    transition: border-color .15s;
}
.nd-com-row:hover   { border-color: #b0c4de; }
.nd-com-row-name    {
    font-size: .82rem;
    font-weight: 600;
    color: #1a3a5c;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.nd-com-row-cond    {
    font-size: .7rem;
    color: #888;
    margin-top: .1rem;
}
.nd-com-row select,
.nd-com-row input[type=date] {
    width: 100%;
    padding: .3rem .45rem;
    border: 1px solid #cdd5e0;
    border-radius: 6px;
    font-size: .76rem;
    background: #fff;
    box-sizing: border-box;
    color: #2c3e50;
}
.nd-com-row select:focus,
.nd-com-row input[type=date]:focus {
    outline: none;
    border-color: #1a3a5c;
    box-shadow: 0 0 0 2px rgba(26,58,92,.1);
}
.nd-com-row-header  {
    display: grid;
    grid-template-columns: 1fr 140px 170px 140px;
    gap: .5rem;
    padding: .3rem .75rem;
    margin-bottom: .25rem;
}
.nd-com-row-header span {
    font-size: .69rem;
    font-weight: 700;
    color: #888;
    text-transform: uppercase;
    letter-spacing: .04em;
}
@media(max-width: 680px) {
    .nd-com-row,
    .nd-com-row-header {
        grid-template-columns: 1fr;
    }
    .nd-com-row-header { display: none; }
}
</style>

<script>
(function () {
    'use strict';

    var lista    = document.getElementById('comApoderadoLista');
    var vacio    = document.getElementById('comApoderadoVacio');
    var contador = document.getElementById('comunicacionNotasContador');
    var textarea = document.getElementById('comunicacionApoderadoNotas');

    // Contador de caracteres
    if (textarea && contador) {
        textarea.addEventListener('input', function () {
            contador.textContent = textarea.value.length + ' / 2500 caracteres';
        });
    }

    // Modalidades
    var modalidades = [
        { val: '',           lbl: '— Sin comunicación —' },
        { val: 'presencial', lbl: '🏫 Presencial' },
        { val: 'telefono',   lbl: '📞 Teléfono' },
        { val: 'correo',     lbl: '✉️ Correo electrónico' },
        { val: 'whatsapp',   lbl: '💬 WhatsApp' },
        { val: 'libreta',    lbl: '📓 Libreta de comunicaciones' },
    ];

    // Estados
    var estados = [
        { val: 'pendiente',       lbl: 'Pendiente' },
        { val: 'realizada',       lbl: 'Realizada' },
        { val: 'no_corresponde',  lbl: 'No corresponde' },
    ];

    function condTexto(condicion) {
        var map = {
            victima:      'Víctima',
            denunciado:   'Denunciado/a',
            denunciante:  'Denunciante',
            testigo:      'Testigo',
        };
        return map[condicion] || (condicion || 'Interviniente');
    }

    // Agrupa alumnos duplicados (mismo personaId o nombre) en una sola fila,
    // combinando sus condiciones cuando la víctima y el denunciante son la misma persona.
    function agruparAlumnos(intervinientes) {
        var grupos = {};
        var orden  = [];

        intervinientes.forEach(function (item) {
            var tipo = (item.tipoPersona || item.tipoBusqueda || '').toLowerCase();
            if (tipo !== 'alumno' && tipo !== 'alumnos') return;

            var key = (item.personaId && item.personaId !== '')
                ? 'id_' + item.personaId
                : 'nom_' + (item.nombre || 'NN').toUpperCase();

            if (!grupos[key]) {
                grupos[key] = { base: item, condiciones: [] };
                orden.push(key);
            }
            if (item.condicion && grupos[key].condiciones.indexOf(item.condicion) === -1) {
                grupos[key].condiciones.push(item.condicion);
            }
        });

        return orden.map(function (key) {
            var g = grupos[key];
            var condicionTexto = g.condiciones.map(condTexto).join(' / ');
            return Object.assign({}, g.base, {
                condicion:     g.condiciones.join(','),
                condicionTexto: condicionTexto,
            });
        });
    }

    function buildSelect(name, options, extraClass) {
        var sel = document.createElement('select');
        sel.name = name;
        if (extraClass) sel.className = extraClass;
        options.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o.val;
            opt.textContent = o.lbl;
            sel.appendChild(opt);
        });
        return sel;
    }

    function buildRow(item, idx) {
        var row = document.createElement('div');
        row.className = 'nd-com-row';

        // Col 1: nombre + condición
        var nameDiv = document.createElement('div');
        nameDiv.innerHTML =
            '<div class="nd-com-row-name">' + escapeHtml(item.nombre || 'N/N') + '</div>' +
            '<div class="nd-com-row-cond">' + escapeHtml(condTexto(item.condicion)) + '</div>';
        row.appendChild(nameDiv);

        // Col 2: modalidad
        var selMod = buildSelect(
            'com_modalidad[' + idx + ']',
            modalidades
        );
        row.appendChild(selMod);

        // Col 3: fecha y hora
        var inpFecha = document.createElement('input');
        inpFecha.type = 'datetime-local';
        inpFecha.name = 'com_fecha[' + idx + ']';
        row.appendChild(inpFecha);

        // Col 4: estado
        var selEst = buildSelect(
            'com_estado[' + idx + ']',
            estados
        );
        row.appendChild(selEst);

        // Hidden: nombre referencial
        var hidNombre = document.createElement('input');
        hidNombre.type = 'hidden';
        hidNombre.name = 'com_nombre[' + idx + ']';
        hidNombre.value = item.nombre || 'N/N';
        row.appendChild(hidNombre);

        // Hidden: condición
        var hidCond = document.createElement('input');
        hidCond.type = 'hidden';
        hidCond.name = 'com_condicion[' + idx + ']';
        hidCond.value = item.condicion || '';
        row.appendChild(hidCond);

        return row;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renderLista(intervinientes) {
        lista.innerHTML = '';

        var alumnos = agruparAlumnos(intervinientes);

        if (alumnos.length === 0) {
            vacio.style.display = 'block';
            lista.style.display = 'none';
            return;
        }

        vacio.style.display = 'none';
        lista.style.display = 'block';

        // Encabezado
        var header = document.createElement('div');
        header.className = 'nd-com-row-header';
        header.innerHTML =
            '<span>Alumno/a</span>' +
            '<span>Modalidad</span>' +
            '<span>Fecha</span>' +
            '<span>Estado</span>';
        lista.appendChild(header);

        alumnos.forEach(function (item, idx) {
            lista.appendChild(buildRow(item, idx));
        });
    }

    // ── Escuchar cambios en el array intervinientes ──────────
    // El script principal emite un evento personalizado al actualizar
    document.addEventListener('metis:intervinientesActualizados', function (e) {
        renderLista(e.detail || []);
    });

    // Intentar leer estado inicial si ya hay intervinientes al activar la pestaña
    document.addEventListener('metis:tabActivated', function (e) {
        if (e.detail === 'comunicacion_apoderado') {
            // Pedir al script principal el estado actual
            var ev = new CustomEvent('metis:solicitarIntervinientes');
            document.dispatchEvent(ev);
        }
    });

    // Fallback: observar el DOM para capturar hidden inputs existentes
    // (por si el tab ya tenía intervinientes al cargarse la página)
    function intentarLeerDesdeDOM() {
        var nombres = document.querySelectorAll('input[name="p_nombre_referencial[]"]');
        var tipos   = document.querySelectorAll('input[name="p_tipo_persona[]"]');
        var roles   = document.querySelectorAll('input[name="p_rol_en_caso[]"]');

        if (nombres.length === 0) return;

        var items = [];
        nombres.forEach(function (inp, i) {
            items.push({
                nombre:      inp.value,
                tipoPersona: tipos[i] ? tipos[i].value : '',
                condicion:   roles[i] ? roles[i].value : '',
            });
        });
        renderLista(items);
    }

    // Intentar al cargarse (por si hay datos del servidor en edición)
    setTimeout(intentarLeerDesdeDOM, 300);

})();
</script>
