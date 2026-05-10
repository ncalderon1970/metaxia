<?php
// Fase 38K-2H: scripts de búsqueda RUN/NOMBRE con diagnóstico y fallback controlado.
?>
<script>
(function () {
    const relato = document.getElementById('relato');
    const contador = document.getElementById('relatoContador');
    const posibleAula = document.getElementById('posibleAulaSegura');
    const bloqueCausales = document.getElementById('bloqueCausalesAulaSegura');
    const prioridad = document.getElementById('prioridad');
    const semaforo = document.getElementById('semaforo');
    const form = document.getElementById('formNuevaDenuncia');
    const fechaHoraIncidente = document.getElementById('fechaHoraIncidente');
    const tabButtons = Array.from(document.querySelectorAll('[data-tab-target]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-tab-panel]'));
    const denuncianteOculto = document.getElementById('denuncianteOculto');
    const container = document.getElementById('intervinientesContainer');
    const addBtn = document.getElementById('agregarInterviniente');
    const resumenLista = document.getElementById('intervinientesLista');
    const resumenVacio = document.getElementById('intervinientesVacio');
    const resumenTotal = document.getElementById('intervinientesTotal');
    const resumenContadores = document.getElementById('intervinientesContadores');
    const timerState = { value: null };
    const intervinientes = [];
    const comunicacionOpciones = Array.from(document.querySelectorAll('.nd-com-option'));
    const comunicacionRadios = Array.from(document.querySelectorAll('input[name="comunicacion_apoderado_modalidad"]'));
    const comunicacionFecha = document.getElementById('comunicacionApoderadoFecha');
    const comunicacionEstado = document.getElementById('comunicacionApoderadoEstado');
    const comunicacionNotas = document.getElementById('comunicacionApoderadoNotas');
    const comunicacionContador = document.getElementById('comunicacionNotasContador');

    function upper(value) {
        return (value || '').toString().trim().toUpperCase();
    }

    function escapeHtml(value) {
        return (value || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function conditionLabel(value) {
        const labels = {
            denunciante: 'Denunciante',
            victima: 'Víctima',
            testigo: 'Testigo',
            denunciado: 'Denunciado',
        };
        return labels[value] || 'Sin condición';
    }

    function tipoLabel(value) {
        const labels = {
            alumno: 'Alumno',
            funcionario: 'Docente / Asistente',
            docente: 'Docente',
            asistente: 'Asistente',
            apoderado: 'Apoderado',
            externo: 'Otro actor civil',
        };
        return labels[value] || 'Interviniente';
    }

    function captureCard() {
        return container ? container.querySelector('[data-interviniente-card]') : null;
    }

    function cardParts(card) {
        return {
            tipoBusqueda: card ? card.querySelector('[data-tipo-busqueda]') : null,
            busqueda: card ? card.querySelector('[data-busqueda]') : null,
            resultados: card ? card.querySelector('[data-resultados]') : null,
            busquedaHelp: card ? card.querySelector('[data-busqueda-help]') : null,
            nombre: card ? card.querySelector('[data-nombre-referencial]') : null,
            personaId: card ? card.querySelector('[data-persona-id]') : null,
            tipoPersona: card ? card.querySelector('[data-tipo-persona]') : null,
            run: card ? card.querySelector('[data-run]') : null,
            rol: card ? card.querySelector('[data-rol-en-caso]') : null,
            anonCheck: card ? card.querySelector('[data-es-anonimo-checkbox]') : null,
            anonValue: card ? card.querySelector('[data-es-anonimo-value]') : null,
            anonBox: card ? card.querySelector('[data-anon-box]') : null,
            nn: card ? card.querySelector('[data-es-nn]') : null,
            selected: card ? card.querySelector('[data-seleccionado]') : null,
            selectedText: card ? card.querySelector('[data-seleccionado-texto]') : null,
        };
    }

    function getCaptureState() {
        const p = cardParts(captureCard());
        const condicion = p.rol ? p.rol.value : '';
        const esNN = !!(p.nn && p.nn.checked);
        const nombre = p.nombre ? upper(p.nombre.value) : '';
        const run = p.run && p.run.value.trim() !== '' ? p.run.value.trim() : (esNN ? '0-0' : '');
        const tipoBusqueda = p.tipoBusqueda ? p.tipoBusqueda.value : 'alumno';
        const tipoPersona = p.tipoPersona && p.tipoPersona.value ? p.tipoPersona.value : (tipoBusqueda === 'funcionario' ? 'docente' : tipoBusqueda);
        const esAnonimo = !!(p.anonCheck && !p.anonCheck.disabled && p.anonCheck.checked);

        return {
            tipoBusqueda,
            tipoPersona,
            tipoTexto: tipoLabel(tipoPersona),
            personaId: p.personaId ? p.personaId.value.trim() : '',
            run: run || '',
            nombre: nombre || (esNN ? 'N/N' : ''),
            condicion,
            condicionTexto: conditionLabel(condicion),
            esAnonimo,
            esNN,
            busquedaTexto: p.busqueda ? p.busqueda.value.trim() : '',
        };
    }

    function updateSelectedLine() {
        const p = cardParts(captureCard());
        if (!p.selected || !p.selectedText) return;
        const state = getCaptureState();

        if (!state.nombre) {
            p.selected.classList.remove('show');
            p.selectedText.textContent = '';
            return;
        }

        const reserva = state.esAnonimo ? ' · Reserva de identidad' : '';
        const nn = state.esNN ? ' · No identificado' : '';
        p.selectedText.textContent = `${state.tipoTexto}: ${state.nombre} · RUN ${state.run || '0-0'} · Condición: ${state.condicionTexto}${reserva}${nn}`;
        p.selected.classList.add('show');
    }

    function hidden(name, value) {
        return `<input type="hidden" name="${escapeHtml(name)}" value="${escapeHtml(value)}">`;
    }

    function renderResumenIntervinientes() {
        if (!resumenLista || !resumenVacio || !resumenTotal || !resumenContadores) return;

        const counts = { denunciante: 0, victima: 0, testigo: 0, denunciado: 0, sin_condicion: 0 };
        resumenLista.innerHTML = '';
        resumenContadores.innerHTML = '';
        resumenTotal.textContent = `${intervinientes.length} registrado(s)`;

        if (intervinientes.length === 0) {
            resumenVacio.style.display = 'block';
            resumenLista.style.display = 'none';
            resumenContadores.style.display = 'none';
            if (denuncianteOculto) denuncianteOculto.value = '';
            return;
        }

        resumenVacio.style.display = 'none';
        resumenLista.style.display = 'grid';
        resumenContadores.style.display = 'flex';

        intervinientes.forEach((item, index) => {
            if (item.condicion && counts[item.condicion] !== undefined) counts[item.condicion]++;
            else counts.sin_condicion++;

            const row = document.createElement('div');
            row.className = 'nd-summary-item';
            const reserva = item.esAnonimo ? ' · Reserva de identidad para informes a apoderados/comunidad' : '';
            const nn = item.esNN ? ' · N/N' : '';
            const badgeClass = item.condicion || 'sin_condicion';

            const reservaPill = item.esAnonimo ? '<span class="nd-reserva-pill">Reserva</span>' : '';
            const nnPill = item.esNN ? '<span class="nd-reserva-pill">N/N</span>' : '';

            row.innerHTML = `
                ${hidden('p_tipo_busqueda[]', item.tipoBusqueda)}
                ${hidden('p_busqueda[]', item.busquedaTexto || item.nombre)}
                ${hidden('p_persona_id[]', item.personaId || '')}
                ${hidden('p_tipo_persona[]', item.tipoPersona || '')}
                ${hidden('p_run[]', item.run || '0-0')}
                ${hidden('p_nombre_referencial[]', item.nombre || 'N/N')}
                ${hidden('p_rol_en_caso[]', item.condicion || '')}
                ${hidden('p_es_anonimo[]', item.esAnonimo ? '1' : '0')}
                <div class="nd-summary-main">
                    <div class="nd-summary-line">
                        <span class="nd-summary-name">${index + 1}. ${escapeHtml(item.nombre)}</span>
                        <span class="nd-summary-meta">${escapeHtml(item.tipoTexto)} · RUN ${escapeHtml(item.run || '0-0')}</span>
                        ${nnPill}
                        ${reservaPill}
                    </div>
                    <div class="nd-summary-actions">
                        <span class="nd-anon-toggle${item.esAnonimo ? ' active' : ''}" data-toggle-anon="${index}" title="Activar reserva de identidad para informes a apoderados">
                            <span class="nd-anon-toggle-track"><span class="nd-anon-toggle-thumb"></span></span>
                            <span class="nd-anon-toggle-label">Anónimo</span>
                        </span>
                        <span class="nd-summary-badge ${escapeHtml(badgeClass)}">${escapeHtml(item.condicionTexto)}</span>
                        <button type="button" class="nd-summary-delete" data-delete-interviniente="${index}">
                            <i class="bi bi-trash"></i> Quitar
                        </button>
                    </div>
                </div>
            `;
            resumenLista.appendChild(row);
        });

        [
            ['Denunciantes', counts.denunciante],
            ['Víctimas', counts.victima],
            ['Testigos', counts.testigo],
            ['Denunciados', counts.denunciado],
            ['Sin condición', counts.sin_condicion],
        ].forEach(([label, count]) => {
            const pill = document.createElement('span');
            pill.className = 'nd-counter-pill';
            pill.textContent = `${label}: ${count}`;
            resumenContadores.appendChild(pill);
        });

        const denunciante = intervinientes.find(item => item.condicion === 'denunciante');
        if (denuncianteOculto) denuncianteOculto.value = denunciante ? upper(denunciante.nombre) : '';

        // Notificar al tab de comunicación con la lista actualizada
        document.dispatchEvent(new CustomEvent('metis:intervinientesActualizados', {
            detail: intervinientes.slice()
        }));
    }

    // Responder a solicitud del tab de comunicación
    document.addEventListener('metis:solicitarIntervinientes', function () {
        document.dispatchEvent(new CustomEvent('metis:intervinientesActualizados', {
            detail: intervinientes.slice()
        }));
    });

    // Emitir evento al activar un tab
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-tab-target]');
        if (btn) {
            const target = btn.dataset.tabTarget;
            setTimeout(function () {
                document.dispatchEvent(new CustomEvent('metis:tabActivated', { detail: target }));
            }, 50);
        }
    });

    function clearCapture() {
        const card = captureCard();
        const p = cardParts(card);
        if (!card) return;
        card.classList.remove('is-unknown');
        if (p.tipoBusqueda) p.tipoBusqueda.value = 'alumno';
        if (p.busqueda) p.busqueda.value = '';
        if (p.resultados) {
            p.resultados.classList.remove('show');
            p.resultados.innerHTML = '';
        }
        if (p.nombre) {
            p.nombre.value = '';
            p.nombre.readOnly = true;
        }
        if (p.personaId) p.personaId.value = '';
        if (p.tipoPersona) p.tipoPersona.value = '';
        if (p.run) p.run.value = '';
        if (p.rol) p.rol.value = '';
        if (p.nn) p.nn.checked = false;
        if (p.anonCheck) {
            p.anonCheck.checked = false;
            p.anonCheck.disabled = true;
        }
        if (p.anonValue) p.anonValue.value = '0';
        if (p.anonBox) p.anonBox.classList.add('disabled');
        if (p.selected) p.selected.classList.remove('show');
        if (p.selectedText) p.selectedText.textContent = '';
        updateTipoMode();
    }

    function clearSelected() {
        const p = cardParts(captureCard());
        if (p.personaId) p.personaId.value = '';
        if (p.tipoPersona) p.tipoPersona.value = '';
        if (p.run) p.run.value = '';
        if (p.nombre) {
            p.nombre.value = '';
            p.nombre.readOnly = (p.tipoBusqueda && p.tipoBusqueda.value !== 'externo');
        }
        if (p.selected) p.selected.classList.remove('show');
        if (p.selectedText) p.selectedText.textContent = '';
        updateAnon();
        updateSelectedLine();
    }

    function setNN() {
        const card = captureCard();
        const p = cardParts(card);
        if (p.personaId) p.personaId.value = '';
        if (p.tipoPersona) p.tipoPersona.value = p.tipoBusqueda ? p.tipoBusqueda.value : 'externo';
        if (p.run) p.run.value = '0-0';
        if (p.busqueda) p.busqueda.value = 'N/N';
        if (p.nombre) {
            p.nombre.readOnly = true;
            p.nombre.value = 'N/N';
        }
        if (p.resultados) p.resultados.classList.remove('show');
        if (card) card.classList.add('is-unknown');
        updateAnon();
        updateSelectedLine();
    }

    function unsetNN() {
        const card = captureCard();
        const p = cardParts(card);
        if (card) card.classList.remove('is-unknown');
        if (p.busqueda) p.busqueda.value = '';
        clearSelected();
        updateTipoMode();
    }

    function updateAnon() {
        const p = cardParts(captureCard());
        const habilitado = p.rol && p.rol.value === 'denunciante';
        if (p.anonCheck) {
            p.anonCheck.disabled = !habilitado;
            if (!habilitado) p.anonCheck.checked = false;
        }
        if (p.anonValue) p.anonValue.value = (habilitado && p.anonCheck && p.anonCheck.checked) ? '1' : '0';
        if (p.anonBox) p.anonBox.classList.toggle('disabled', !habilitado);
        updateSelectedLine();
    }

    function updateTipoMode() {
        const p = cardParts(captureCard());
        const tipo = p.tipoBusqueda ? p.tipoBusqueda.value : 'alumno';
        if (p.nn && p.nn.checked) {
            setNN();
            return;
        }
        if (!p.busqueda || !p.nombre || !p.busquedaHelp || !p.resultados) return;
        p.resultados.classList.remove('show');
        p.resultados.innerHTML = '';
        if (tipo === 'externo') {
            p.nombre.readOnly = false;
            if (p.tipoPersona) p.tipoPersona.value = 'externo';
            if (p.run && !p.run.value) p.run.value = '0-0';
            p.busqueda.placeholder = 'Puede digitar nombre o referencia';
            p.busquedaHelp.textContent = 'Para otro actor civil, el nombre completo se ingresa manualmente. Si no se conoce, usa N/N.';
        } else {
            p.nombre.readOnly = true;
            p.busqueda.placeholder = 'Digite RUN o nombre para buscar';
            p.busquedaHelp.textContent = 'Buscará coincidencias según el tipo seleccionado.';
        }
        updateSelectedLine();
    }

    function metaDebugLine(meta) {
        if (!meta || !meta.diagnostico || !meta.diagnostico.tablas) return '';
        const t = meta.diagnostico.tablas;
        const partes = [];
        ['alumnos', 'apoderados', 'docentes', 'asistentes'].forEach((tabla) => {
            if (t[tabla]) {
                partes.push(`${tabla}: ${t[tabla].en_colegio_sesion}/${t[tabla].total}`);
            }
        });
        if (!partes.length) return '';
        return `<div style="margin-top:.35rem;font-size:.75rem;color:#64748b;">Colegio sesión: ${escapeHtml(meta.colegio_id || 'sin dato')} · Registros colegio/total: ${escapeHtml(partes.join(' · '))}</div>`;
    }

    function renderResults(items, message = '', meta = null) {
        const p = cardParts(captureCard());
        if (!p.resultados) return;
        p.resultados.innerHTML = '';

        if (message) {
            const notice = document.createElement('div');
            notice.style.cssText = 'padding:.65rem .85rem;color:#475569;font-size:.8rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;';
            notice.innerHTML = `${escapeHtml(message)}${metaDebugLine(meta)}`;
            p.resultados.appendChild(notice);
        }

        if (!items.length) {
            if (!message) {
                p.resultados.innerHTML = '<div style="padding:.85rem;color:#64748b;font-size:.82rem;">Sin coincidencias. Revisa el tipo seleccionado, que existan datos para el colegio conectado o marca N/N si la persona aún no está identificada.</div>';
            }
            p.resultados.classList.add('show');
            return;
        }

        items.forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'nd-result';
            const extra = item.extra ? ` · ${escapeHtml(item.extra)}` : '';
            btn.innerHTML = `<strong>${escapeHtml(item.nombre)}</strong><span>${escapeHtml(item.tipo_label || item.tipo_persona || 'Persona')} · RUN: ${escapeHtml(item.run || 'SIN RUN')}${extra}</span>`;
            btn.addEventListener('click', () => selectItem(item));
            p.resultados.appendChild(btn);
        });
        p.resultados.classList.add('show');
    }

    let searchSerial = 0;

    async function doSearch() {
        const p = cardParts(captureCard());
        const tipo = p.tipoBusqueda ? p.tipoBusqueda.value : 'alumno';
        const q = p.busqueda ? p.busqueda.value.trim() : '';
        const mySerial = ++searchSerial;

        if (p.nn && p.nn.checked) return;
        if (tipo === 'externo') {
            if (p.nombre) p.nombre.value = upper(q);
            if (p.tipoPersona) p.tipoPersona.value = 'externo';
            if (p.run && !p.run.value) p.run.value = '0-0';
            updateAnon();
            updateSelectedLine();
            return;
        }
        if (q.length < 2) {
            if (p.resultados) {
                p.resultados.classList.remove('show');
                p.resultados.innerHTML = '';
            }
            return;
        }

        try {
            const url = new URL(window.location.pathname, window.location.origin);
            url.searchParams.set('ajax', 'buscar_interviniente');
            url.searchParams.set('tipo', tipo);
            url.searchParams.set('q', q);

            if (location.hostname === 'localhost' || location.hostname === '127.0.0.1') {
                url.searchParams.set('debug', '1');
            }

            if (p.resultados) {
                p.resultados.innerHTML = '<div style="padding:.85rem;color:#64748b;font-size:.82rem;">Buscando coincidencias...</div>';
                p.resultados.classList.add('show');
            }

            const res = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            const raw = await res.text();
            let data = null;
            try {
                data = raw ? JSON.parse(raw) : null;
            } catch (jsonError) {
                throw new Error('La respuesta del servidor no es JSON válido. Respuesta recibida: ' + raw.substring(0, 180));
            }

            if (mySerial !== searchSerial) return;

            if (!res.ok || !data || data.ok === false) {
                throw new Error((data && data.message) ? data.message : 'Respuesta no válida del servidor.');
            }

            renderResults(Array.isArray(data.items) ? data.items : [], data.message || '', data.meta || null);
        } catch (error) {
            if (mySerial !== searchSerial) return;
            if (p.resultados) {
                p.resultados.innerHTML = `<div style="padding:.85rem;color:#991b1b;font-size:.82rem;">No fue posible buscar coincidencias. ${escapeHtml(error.message || '')}</div>`;
                p.resultados.classList.add('show');
            }
        }
    }

    function selectItem(item) {
        const card = captureCard();
        const p = cardParts(card);
        if (p.nn) p.nn.checked = false;
        if (card) card.classList.remove('is-unknown');
        if (p.personaId) p.personaId.value = item.id || '';
        if (p.tipoPersona) p.tipoPersona.value = item.tipo_persona || '';
        if (p.run) p.run.value = item.run || '0-0';
        if (p.nombre) p.nombre.value = upper(item.nombre || '');
        if (p.busqueda) p.busqueda.value = item.run ? `${item.run} · ${item.nombre}` : item.nombre;
        if (p.resultados) p.resultados.classList.remove('show');
        updateAnon();
        updateSelectedLine();

        // ── Verificar condición especial del alumno ──────────
        var tipoP = (item.tipo_persona || '').toLowerCase();
        if ((tipoP === 'alumno' || tipoP === 'alumnos') && item.id) {
            fetch('<?= APP_URL ?>/modules/denuncias/ajax/condicion_alumno.php?alumno_id=' + encodeURIComponent(item.id))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.ok && data.condiciones && data.condiciones.length > 0) {
                        mostrarAlertaCondicion(item.nombre, data.condiciones);
                    }
                })
                .catch(function(){});
        }
    }

    function mostrarAlertaCondicion(nombre, condiciones) {
        // Mostrar alerta visual en el formulario
        var alerta = document.getElementById('alertaCondicionEspecial');
        if (!alerta) {
            alerta = document.createElement('div');
            alerta.id = 'alertaCondicionEspecial';
            alerta.style.cssText = 'background:#fef3c7;border:1px solid #fde68a;border-radius:10px;' +
                'padding:.85rem 1.1rem;margin:1rem 0;font-size:.83rem;color:#92400e;' +
                'display:flex;align-items:flex-start;gap:.65rem;';
            // Insertar antes del nd-mini-nav del tab datos
            var miniNav = document.querySelector('[data-tab-panel="datos_denuncia"] .nd-mini-nav');
            if (miniNav) miniNav.parentNode.insertBefore(alerta, miniNav);
        }

        var hayTea = condiciones.some(function(c){ return (c.tipo_condicion||'').startsWith('tea'); });
        var lista  = condiciones.map(function(c){ return '<strong>' + escapeHtml(c.condicion_nombre) + '</strong>' +
            (c.estado_diagnostico ? ' (' + c.estado_diagnostico + ')' : ''); }).join(', ');

        alerta.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;flex-shrink:0;margin-top:.1rem;"></i>' +
            '<div><strong>Condición especial registrada:</strong> ' + escapeHtml(nombre) + ' tiene: ' + lista + '.' +
            (hayTea ? ' <span style="font-weight:700;">Se recomienda activar el Protocolo TEA en el Contexto normativo.</span>' : '') + '</div>';

        // Auto-marcar TEA en contexto normativo si corresponde
        if (hayTea) {
            var chkTea = document.querySelector('input[name="involucra_nna_tea"]');
            if (chkTea && !chkTea.checked) {
                chkTea.checked = true;
                chkTea.dispatchEvent(new Event('change'));
            }
        }
    }

    function validarCaptureParaAgregar() {
        const p = cardParts(captureCard());
        const state = getCaptureState();
        const errores = [];

        if (!state.condicion) errores.push('Debe seleccionar la condición del interviniente.');

        if (p.nn && p.nn.checked) {
            setNN();
            return errores.length ? { ok: false, errores } : { ok: true, item: getCaptureState() };
        }

        if (!state.nombre) errores.push('Debe seleccionar o ingresar nombre completo. Si no lo conoce, marque N/N.');

        if (state.tipoBusqueda !== 'externo' && !state.personaId) {
            errores.push('Debe seleccionar una coincidencia de la lista o marcar N/N.');
        }

        return errores.length ? { ok: false, errores } : { ok: true, item: getCaptureState() };
    }

    function agregarIntervinienteActual() {
        const validacion = validarCaptureParaAgregar();
        if (!validacion.ok) {
            alert(validacion.errores.join('\n'));
            return;
        }

        const item = validacion.item;
        if (!item.run) item.run = '0-0';
        if (!item.tipoPersona || item.tipoPersona === 'funcionario') {
            item.tipoPersona = item.tipoBusqueda === 'funcionario' ? 'docente' : item.tipoBusqueda;
        }
        item.tipoTexto = tipoLabel(item.tipoPersona);
        item.condicionTexto = conditionLabel(item.condicion);
        item.esAnonimo = item.condicion === 'denunciante' ? item.esAnonimo : false;
        item.esNN = item.nombre === 'N/N' || item.run === '0-0';

        intervinientes.push(item);
        renderResumenIntervinientes();
        clearCapture();
        const p = cardParts(captureCard());
        if (p.busqueda) p.busqueda.focus();
    }

    function validarIntervinientesAntesDeGuardar() {
        if (intervinientes.length === 0) {
            alert('Debe agregar al menos un interviniente a la lista. Si no conoce los datos, use N/N y RUN 0-0.');
            return false;
        }
        renderResumenIntervinientes();
        return true;
    }

    function activarTab(nombre) {
        tabButtons.forEach((btn) => {
            btn.classList.toggle('active', btn.getAttribute('data-tab-target') === nombre);
        });
        tabPanels.forEach((panel) => {
            panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === nombre);
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', function () {
            const target = btn.getAttribute('data-tab-target');
            if (target) activarTab(target);
        });
    });
    if (relato && contador) {
        const updateCount = () => {
            const len = relato.value.length;
            contador.textContent = `${len} / 5000 caracteres`;
            contador.style.color = len > 4500 ? '#dc2626' : '#64748b';
        };
        relato.addEventListener('input', updateCount);
        updateCount();
    }

    if (posibleAula && bloqueCausales) {
        const toggleAula = () => {
            bloqueCausales.classList.toggle('show', posibleAula.checked);
            if (posibleAula.checked) {
                if (prioridad) prioridad.value = 'alta';
                if (semaforo) semaforo.value = 'rojo';
            }
        };
        posibleAula.addEventListener('change', toggleAula);
        toggleAula();
    }

    if (addBtn) addBtn.addEventListener('click', agregarIntervinienteActual);

    if (container) {
        container.addEventListener('change', function (event) {
            if (event.target.matches('[data-tipo-busqueda]')) {
                clearSelected();
                const p = cardParts(captureCard());
                if (p.busqueda) p.busqueda.value = '';
                updateTipoMode();
            }
            if (event.target.matches('[data-rol-en-caso]')) updateAnon();
            if (event.target.matches('[data-es-anonimo-checkbox]')) updateAnon();
            if (event.target.matches('[data-es-nn]')) {
                if (event.target.checked) setNN();
                else unsetNN();
            }
        });

        container.addEventListener('input', function (event) {
            const p = cardParts(captureCard());
            if (event.target.matches('[data-busqueda]')) {
                if (p.nn && p.nn.checked) {
                    setNN();
                    return;
                }
                if (p.tipoBusqueda && p.tipoBusqueda.value === 'externo') {
                    if (p.nombre) p.nombre.value = upper(p.busqueda.value);
                    if (p.tipoPersona) p.tipoPersona.value = 'externo';
                    if (p.run && !p.run.value) p.run.value = '0-0';
                    updateAnon();
                    updateSelectedLine();
                    return;
                }
                clearTimeout(timerState.value);
                timerState.value = setTimeout(doSearch, 220);
            }
            if (event.target.matches('[data-nombre-referencial]')) {
                p.nombre.value = upper(p.nombre.value);
                updateAnon();
                updateSelectedLine();
            }
        });

        container.addEventListener('click', function (event) {
            if (event.target.closest('[data-limpiar-interviniente]')) {
                const p = cardParts(captureCard());
                if (p.nn) p.nn.checked = false;
                const card = captureCard();
                if (card) card.classList.remove('is-unknown');
                clearSelected();
                if (p.busqueda) p.busqueda.focus();
            }
        });

        container.addEventListener('keydown', function (event) {
            if (event.target.matches('[data-busqueda]') && event.key === 'Enter') {
                event.preventDefault();
                clearTimeout(timerState.value);
                doSearch();
            }
        });
    }

    if (resumenLista) {
        resumenLista.addEventListener('click', function (event) {
            // Eliminar interviniente
            const btn = event.target.closest('[data-delete-interviniente]');
            if (btn) {
                const idx = parseInt(btn.getAttribute('data-delete-interviniente') || '-1', 10);
                if (idx >= 0 && idx < intervinientes.length) {
                    intervinientes.splice(idx, 1);
                    renderResumenIntervinientes();
                    if (typeof actualizarComunicacionApoderado === 'function') actualizarComunicacionApoderado();
                }
            }
            // Toggle anónimo
            const tog = event.target.closest('[data-toggle-anon]');
            if (tog) {
                const idx = parseInt(tog.getAttribute('data-toggle-anon') || '-1', 10);
                if (idx >= 0 && idx < intervinientes.length) {
                    intervinientes[idx].esAnonimo = !intervinientes[idx].esAnonimo;
                    renderResumenIntervinientes();
                }
            }
        });
    }

    document.addEventListener('click', function (event) {
        const p = cardParts(captureCard());
        if (!p.resultados || !p.busqueda) return;
        if (event.target === p.busqueda || p.resultados.contains(event.target)) return;
        p.resultados.classList.remove('show');
    });

    function actualizarComunicacionApoderado() {
        comunicacionOpciones.forEach((option) => {
            const radio = option.querySelector('input[type="radio"]');
            option.classList.toggle('is-active', !!(radio && radio.checked));
        });

        const seleccionada = comunicacionRadios.find((radio) => radio.checked);
        if (comunicacionEstado && seleccionada && comunicacionEstado.value === 'pendiente') {
            comunicacionEstado.value = 'realizada';
        }
    }

    comunicacionRadios.forEach((radio) => {
        radio.addEventListener('change', actualizarComunicacionApoderado);
    });

    comunicacionOpciones.forEach((option) => {
        option.addEventListener('click', function () {
            const radio = option.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });

    if (comunicacionNotas && comunicacionContador) {
        const updateComCount = () => {
            const len = comunicacionNotas.value.length;
            comunicacionContador.textContent = `${len} / 2500 caracteres`;
            comunicacionContador.style.color = len > 2250 ? '#dc2626' : '#64748b';
        };
        comunicacionNotas.addEventListener('input', updateComCount);
        updateComCount();
    }

    if (comunicacionEstado) {
        comunicacionEstado.addEventListener('change', function () {
            if (comunicacionEstado.value !== 'realizada') {
                comunicacionRadios.forEach((radio) => { radio.checked = false; });
                actualizarComunicacionApoderado();
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (fechaHoraIncidente && !fechaHoraIncidente.value) {
                event.preventDefault();
                activarTab('datos_denuncia');
                fechaHoraIncidente.focus();
                alert('Debe indicar fecha y hora del incidente.');
                return;
            }

            if (posibleAula && posibleAula.checked) {
                const checked = form.querySelectorAll('input[name="aula_segura_causales[]"]:checked');
                if (checked.length === 0) {
                    event.preventDefault();
                    activarTab('datos_denuncia');
                    alert('Debe seleccionar al menos una causal preliminar de Aula Segura o desmarcar la alerta.');
                    return;
                }
            }
            const comunicacionSeleccionada = form.querySelector('input[name="comunicacion_apoderado_modalidad"]:checked');
            const comunicacionFechaValor = comunicacionFecha ? comunicacionFecha.value.trim() : '';
            if (comunicacionSeleccionada && comunicacionFechaValor === '') {
                event.preventDefault();
                activarTab('comunicacion_apoderado');
                if (comunicacionFecha) comunicacionFecha.focus();
                alert('Debe indicar la fecha de comunicación al apoderado o dejar la modalidad sin seleccionar.');
                return;
            }
            if (!comunicacionSeleccionada && comunicacionFechaValor !== '') {
                event.preventDefault();
                activarTab('comunicacion_apoderado');
                alert('Debe seleccionar la modalidad de comunicación al apoderado.');
                return;
            }

            if (!validarIntervinientesAntesDeGuardar()) {
                event.preventDefault();
                activarTab('intervinientes');
            }
        });
    }

    actualizarComunicacionApoderado();
    updateTipoMode();

    // ── Restaurar intervinientes de borrador si existen ──
    if (window._borradorPart && window._borradorPart.length > 0) {
        var condTextosBorrador = {
            'denunciante': 'Denunciante', 'victima': 'Víctima',
            'testigo': 'Testigo', 'denunciado': 'Denunciado'
        };
        window._borradorPart.forEach(function (p) {
            intervinientes.push({
                nombre:         p.nombre,
                run:            p.run,
                tipoBusqueda:   p.tipo,
                tipoPersona:    p.tipo,
                tipoTexto:      p.tipo.charAt(0).toUpperCase() + p.tipo.slice(1),
                condicion:      p.condicion,
                condicionTexto: condTextosBorrador[p.condicion] || p.condicion,
                personaId:      '',
                busquedaTexto:  p.nombre,
                esAnonimo:      p.esAnonimo || false,
                esNN:           false,
            });
        });
        window._borradorPart = null;
    }

    renderResumenIntervinientes();
})();
</script>
