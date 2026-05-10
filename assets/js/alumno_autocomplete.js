/**
 * alumno_autocomplete.js  v2
 * Búsqueda de alumnos con dropdown anclado a document.body (position:fixed)
 * para evitar que overflow:hidden en contenedores padre lo recorte.
 *
 * Uso:  initAlumnoAutocomplete(inputEl, _opcionesEl, config)
 * config: { onSelect(al), onClear(), advertirApoderado: bool }
 *
 * Nota: _opcionesEl ya no se usa para renderizar; se mantiene el parámetro
 * solo por compatibilidad con llamadas existentes.
 */
(function () {

  const DEBOUNCE_MS = 280;

  /* ── Normaliza RUN quitando puntos, guión → mayúscula ── */
  function normRun(s) {
    return String(s || '').replace(/\./g, '').replace(/-/g, '').toUpperCase();
  }

  /* ── ¿Parece un RUN completo? (7-8 dígitos + guión + DV) ── */
  function esRunCompleto(q) {
    return /^\d{7,8}-?[\dKk]$/.test(q.replace(/\./g, '').trim());
  }

  /* ── Escape HTML ── */
  function esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ================================================================
     initAlumnoAutocomplete
  ================================================================ */
  window.initAlumnoAutocomplete = function (inputEl, _opcionesEl, config) {
    config = config || {};
    if (!inputEl) return;

    let timer              = null;
    let alumnoSeleccionado = null;

    /* ── Crear dropdown anclado a <body> ────────────────────────── */
    const dropdown = document.createElement('div');
    dropdown.className = 'ac-dropdown-body';
    dropdown.style.cssText =
      'position:fixed;z-index:9999;display:none;' +
      'background:#fff;border:1px solid #e2e8f0;border-radius:10px;' +
      'box-shadow:0 8px 24px rgba(0,0,0,.13);' +
      'max-height:260px;overflow-y:auto;' +
      'scrollbar-width:thin;scrollbar-color:#cbd5e1 #f8fafc;';
    document.body.appendChild(dropdown);

    /* ── Posicionar dropdown bajo el input ──────────────────────── */
    function posicionar() {
      const r = inputEl.getBoundingClientRect();
      dropdown.style.top   = (r.bottom + 2) + 'px';
      dropdown.style.left  = r.left + 'px';
      dropdown.style.width = r.width + 'px';
    }

    /* ── Renderizar lista de resultados ─────────────────────────── */
    function renderOpciones(alumnos) {
      dropdown.innerHTML = '';

      if (!alumnos.length) {
        dropdown.innerHTML =
          '<div class="ac-item text-muted fst-italic">' +
            '<i class="bi bi-exclamation-circle me-1"></i>' +
            'Alumno no encontrado en el sistema' +
          '</div>';
        posicionar();
        dropdown.style.display = 'block';
        return;
      }

      alumnos.forEach(function (al) {
        const item = document.createElement('div');
        item.className = 'ac-item';

        const adv = config.advertirApoderado && al.tiene_apoderado_principal
          ? ' <span class="badge bg-warning text-dark ms-1" style="font-size:.68rem;">Ya tiene apoderado</span>'
          : '';

        item.innerHTML =
          '<div class="fw-semibold">' +
            esc(al.apellido_paterno) + ' ' +
            esc(al.apellido_materno || '') + ' ' +
            esc(al.nombres) +
          adv + '</div>' +
          '<small class="text-muted">' +
            esc(al.run) + ' &nbsp;·&nbsp; ' + esc(al.curso || '—') +
          '</small>';

        item.addEventListener('mousedown', function (e) {
          e.preventDefault();
          seleccionar(al);
        });

        dropdown.appendChild(item);
      });

      posicionar();
      dropdown.style.display = 'block';
    }

    /* ── Seleccionar alumno ──────────────────────────────────────── */
    function seleccionar(al) {
      alumnoSeleccionado = al;
      inputEl.value      = al.run;   // muestra solo el RUN en el input
      inputEl.classList.add('ac-locked');
      inputEl.readOnly   = true;
      mostrarBotonLimpiar();
      cerrar();
      if (typeof config.onSelect === 'function') config.onSelect(al);
    }

    /* ── Limpiar selección ──────────────────────────────────────── */
    function limpiar() {
      alumnoSeleccionado = null;
      inputEl.value      = '';
      inputEl.classList.remove('ac-locked');
      inputEl.readOnly   = false;
      ocultarBotonLimpiar();
      inputEl.focus();
      if (typeof config.onClear === 'function') config.onClear();
    }

    /* ── Botón ✕ ────────────────────────────────────────────────── */
    function mostrarBotonLimpiar() {
      const wrap = inputEl.closest('.ac-wrapper') || inputEl.parentElement;
      wrap.style.position = 'relative';
      let btn = wrap.querySelector('.ac-clear-btn');
      if (!btn) {
        btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'ac-clear-btn';
        btn.innerHTML = '&times;';
        btn.title     = 'Quitar alumno';
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); limpiar(); });
        wrap.appendChild(btn);
      }
      btn.style.display = 'flex';
    }

    function ocultarBotonLimpiar() {
      const wrap = inputEl.closest('.ac-wrapper') || inputEl.parentElement;
      const btn  = wrap.querySelector('.ac-clear-btn');
      if (btn) btn.style.display = 'none';
    }

    /* ── Cerrar dropdown ────────────────────────────────────────── */
    function cerrar() {
      dropdown.style.display = 'none';
      dropdown.innerHTML     = '';
    }

    /* ── Buscar con debounce y auto-selección por RUN exacto ─────── */
    function buscar(q) {
      clearTimeout(timer);
      if (q.length < 2) { cerrar(); return; }

      /* RUN completo → busca inmediato y auto-selecciona si hay match */
      if (esRunCompleto(q)) {
        fetch(APP_URL + '/api/alumnos_buscar.php?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (alumnos) {
            const qNorm = normRun(q);
            const exacto = alumnos.find(function (a) {
              return normRun(a.run) === qNorm;
            });
            if (exacto) {
              seleccionar(exacto);   // silencioso, sin dropdown
            } else {
              renderOpciones(alumnos);
            }
          })
          .catch(function () { cerrar(); });
        return;
      }

      /* Búsqueda por nombre con debounce */
      timer = setTimeout(function () {
        fetch(APP_URL + '/api/alumnos_buscar.php?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(renderOpciones)
          .catch(function () { cerrar(); });
      }, DEBOUNCE_MS);
    }

    /* ── Eventos del input ───────────────────────────────────────── */
    inputEl.addEventListener('input', function () {
      if (alumnoSeleccionado) return;
      buscar(inputEl.value.trim());
    });

    inputEl.addEventListener('blur', function () {
      setTimeout(cerrar, 200);
    });

    inputEl.addEventListener('focus', function () {
      if (alumnoSeleccionado) return;
      const q = inputEl.value.trim();
      if (q.length >= 2) buscar(q);
    });

    /* ── Reposicionar al hacer scroll o resize ───────────────────── */
    window.addEventListener('scroll', function () {
      if (dropdown.style.display !== 'none') posicionar();
    }, true /* capture para atrapar scroll en cualquier elemento */);

    window.addEventListener('resize', function () {
      if (dropdown.style.display !== 'none') posicionar();
    });

    /* ── Exponer limpiar externamente ────────────────────────────── */
    inputEl._acLimpiar = limpiar;

    /* ── Limpiar dropdown del DOM al destruir el input ───────────── */
    // MutationObserver para remover el dropdown del body si el input es eliminado
    const observer = new MutationObserver(function () {
      if (!document.body.contains(inputEl)) {
        cerrar();
        if (dropdown.parentNode) dropdown.parentNode.removeChild(dropdown);
        observer.disconnect();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  };

  /* ── API pública: limpiar desde fuera ──────────────────────────── */
  window.limpiarAutocomplete = function (inputEl) {
    if (inputEl && typeof inputEl._acLimpiar === 'function') inputEl._acLimpiar();
  };

})();
