<?php
// Fase 0.5.38G: estilos de la pantalla crear denuncia.
?>
<style>
/* nd-wrap eliminado — formulario usa ancho completo del layout */
#formNuevaDenuncia,
.nd-tab-panel,
.nd-panel {
    width: 100%;
    box-sizing: border-box;
}

.nd-hero {
    background:
        radial-gradient(circle at 88% 18%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.nd-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.nd-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 960px;
    line-height: 1.55;
}

.nd-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    margin-bottom: 1rem;
    overflow: visible;
}

.nd-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    gap: .75rem;
    align-items: center;
}

.nd-step {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: #2563eb;
    color: #fff;
    display: grid;
    place-items: center;
    font-weight: 900;
    flex: 0 0 auto;
}

.nd-head h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 900;
    color: #0f172a;
}

.nd-body {
    padding: 1.5rem 1.75rem;
}

.nd-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .9rem;
}

.nd-grid.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.nd-full {
    grid-column: 1 / -1;
}

.nd-label {
    display: block;
    font-size: .78rem;
    color: #334155;
    font-weight: 900;
    margin-bottom: .35rem;
}

.nd-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .68rem .78rem;
    background: #fff;
    font-size: .92rem;
    outline: none;
}

.nd-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
}

.nd-control[readonly] {
    background: #f8fafc;
    color: #0f172a;
    font-weight: 850;
}

.nd-help {
    color: #64748b;
    font-size: .76rem;
    margin-top: .25rem;
    line-height: 1.35;
}

.nd-alert {
    border-radius: 16px;
    padding: .9rem 1rem;
    font-size: .86rem;
    line-height: 1.45;
    margin-bottom: 1rem;
}

.nd-alert.info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
}

.nd-alert.warn {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
}

.nd-alert.danger {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.nd-inter-card {
    border: 1px solid #dbeafe;
    background: linear-gradient(180deg, #eff6ff 0%, #ffffff 60%);
    border-radius: 18px;
    padding: 1rem;
    position: relative;
}

.nd-search-wrap {
    position: relative;
}

.nd-results {
    display: none;
    position: absolute;
    z-index: 90;
    left: 0;
    right: 0;
    top: calc(100% + .35rem);
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 16px;
    box-shadow: 0 18px 38px rgba(15, 23, 42, .16);
    overflow: hidden;
    max-height: 310px;
    overflow-y: auto;
}

.nd-results.show {
    display: block;
}

.nd-result {
    width: 100%;
    border: 0;
    background: #fff;
    padding: .8rem .9rem;
    display: block;
    text-align: left;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
}

.nd-result:hover {
    background: #f8fafc;
}

.nd-result strong {
    display: block;
    color: #0f172a;
    font-size: .88rem;
}

.nd-result span {
    display: block;
    color: #64748b;
    font-size: .75rem;
    margin-top: .18rem;
}

.nd-selected {
    display: none;
    margin-top: .8rem;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #065f46;
    border-radius: 15px;
    padding: .75rem .85rem;
    font-size: .83rem;
    font-weight: 850;
}

.nd-selected.show {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
}

.nd-mini-btn {
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    border-radius: 999px;
    padding: .35rem .7rem;
    font-size: .74rem;
    font-weight: 900;
    cursor: pointer;
    white-space: nowrap;
}

.nd-anon {
    border: 1px solid #fde68a;
    background: #fffbeb;
    color: #92400e;
    border-radius: 16px;
    padding: .8rem .9rem;
    display: flex;
    align-items: flex-start;
    gap: .65rem;
}

.nd-anon.disabled {
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #94a3b8;
}

.nd-anon input {
    margin-top: .15rem;
    transform: scale(1.12);
}

.nd-aula {
    border: 1px solid #fed7aa;
    background: #fff7ed;
    border-radius: 18px;
    padding: 1rem;
}

.nd-main-check {
    display: flex;
    gap: .65rem;
    align-items: flex-start;
    color: #7f1d1d;
    font-weight: 900;
}

.nd-main-check input {
    margin-top: .2rem;
    transform: scale(1.15);
}

.nd-causales {
    display: none;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #fed7aa;
}

.nd-causales.show {
    display: block;
}

.nd-causal {
    display: flex;
    gap: .55rem;
    align-items: flex-start;
    background: #fff;
    border: 1px solid #fed7aa;
    border-radius: 14px;
    padding: .7rem .8rem;
    margin-bottom: .55rem;
    color: #431407;
    font-size: .86rem;
    font-weight: 850;
}

.nd-inter-tools {
    display: flex;
    justify-content: space-between;
    gap: .8rem;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: .9rem;
}

.nd-inter-tools p {
    margin: 0;
    color: #64748b;
    font-size: .82rem;
    line-height: 1.45;
}

.nd-add-btn {
    border: 0;
    background: #059669;
    color: #fff;
    border-radius: 999px;
    padding: .62rem .95rem;
    font-size: .82rem;
    font-weight: 900;
    cursor: pointer;
    display: inline-flex;
    gap: .4rem;
    align-items: center;
    white-space: nowrap;
}

.nd-remove-btn {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
    border-radius: 999px;
    padding: .42rem .78rem;
    font-size: .76rem;
    font-weight: 900;
    cursor: pointer;
    display: none;
    align-items: center;
    gap: .35rem;
}

.nd-inter-card + .nd-inter-card {
    margin-top: .85rem;
}

.nd-card-title-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    margin-bottom: .85rem;
}

.nd-card-title-row strong {
    color: #1e3a8a;
    font-size: .9rem;
}

.nd-unknown {
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #334155;
    border-radius: 16px;
    padding: .75rem .85rem;
    display: flex;
    align-items: flex-start;
    gap: .65rem;
}

.nd-unknown input {
    margin-top: .15rem;
    transform: scale(1.12);
}

.nd-inter-card.is-unknown {
    border-color: #fde68a;
    background: linear-gradient(180deg, #fffbeb 0%, #ffffff 62%);
}

.nd-inter-card.is-unknown .nd-selected {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.nd-inter-summary {
    margin-top: 1rem;
    border: 1px solid #bbf7d0;
    background: #ecfdf5;
    border-radius: 18px;
    overflow: hidden;
}

.nd-summary-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .8rem;
    flex-wrap: wrap;
    padding: .9rem 1rem;
    border-bottom: 1px solid #bbf7d0;
}

.nd-summary-title {
    color: #065f46;
    font-size: .92rem;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
}

.nd-summary-count {
    border: 1px solid #86efac;
    background: #fff;
    color: #047857;
    border-radius: 999px;
    padding: .28rem .65rem;
    font-size: .74rem;
    font-weight: 900;
}

.nd-summary-body {
    padding: .55rem .75rem .7rem;
}

.nd-summary-empty {
    color: #047857;
    font-size: .82rem;
    font-weight: 800;
}

.nd-summary-list {
    display: grid;
    gap: .35rem;
}

.nd-summary-item {
    background: #fff;
    border: 1px solid #bbf7d0;
    border-left: 4px solid #10b981;
    border-radius: 12px;
    padding: .42rem .55rem;
}

.nd-summary-main {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto;
    align-items: center;
    gap: .55rem;
}

.nd-summary-line {
    display: flex;
    align-items: baseline;
    gap: .55rem;
    flex-wrap: wrap;
    min-width: 0;
}

.nd-summary-name {
    color: #064e3b;
    font-weight: 950;
    font-size: .86rem;
    line-height: 1.18;
}

.nd-summary-meta {
    color: #334155;
    font-size: .72rem;
    line-height: 1.18;
    white-space: nowrap;
}

.nd-summary-actions {
    display: inline-flex;
    gap: .35rem;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.nd-summary-badge {
    border-radius: 999px;
    padding: .18rem .48rem;
    font-size: .66rem;
    font-weight: 950;
    border: 1px solid #dbeafe;
    background: #eff6ff;
    color: #1d4ed8;
    white-space: nowrap;
}

.nd-summary-badge.denunciante {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.nd-summary-badge.victima {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.nd-summary-badge.testigo {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.nd-summary-badge.denunciado {
    background: #f5f3ff;
    border-color: #ddd6fe;
    color: #6d28d9;
}

.nd-summary-counters {
    display: flex;
    flex-wrap: wrap;
    gap: .32rem;
    margin-top: .5rem;
}

.nd-counter-pill {
    background: rgba(255,255,255,.85);
    border: 1px solid #bbf7d0;
    color: #065f46;
    border-radius: 999px;
    padding: .18rem .5rem;
    font-size: .68rem;
    font-weight: 900;
}

.nd-summary-delete {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
    border-radius: 999px;
    padding: .2rem .46rem;
    font-size: .66rem;
    font-weight: 950;
    cursor: pointer;
    white-space: nowrap;
}

.nd-reserva-pill {
    border: 1px solid #fde68a;
    background: #fffbeb;
    color: #92400e;
    border-radius: 999px;
    padding: .16rem .45rem;
    font-size: .64rem;
    font-weight: 950;
    white-space: nowrap;
}

/* Toggle anónimo en resumen de intervinientes */
.nd-anon-toggle {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    cursor: pointer;
    white-space: nowrap;
    user-select: none;
}
.nd-anon-toggle-track {
    width: 32px;
    height: 18px;
    border-radius: 999px;
    background: #cbd5e1;
    position: relative;
    transition: background .2s;
    flex-shrink: 0;
}
.nd-anon-toggle-thumb {
    width: 13px;
    height: 13px;
    border-radius: 50%;
    background: #fff;
    position: absolute;
    top: 50%;
    left: 2.5px;
    transform: translateY(-50%);
    transition: left .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.nd-anon-toggle.active .nd-anon-toggle-track {
    background: #2563eb;
}
.nd-anon-toggle.active .nd-anon-toggle-thumb {
    left: 16.5px;
}
.nd-anon-toggle-label {
    font-size: .72rem;
    font-weight: 600;
    color: #64748b;
    transition: color .2s;
}
.nd-anon-toggle.active .nd-anon-toggle-label {
    color: #2563eb;
}

@media (max-width: 720px) {
    .nd-summary-main {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .nd-summary-actions {
        justify-content: flex-start;
    }

    .nd-summary-meta {
        white-space: normal;
    }
}

.nd-capturador-note {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    color: #475569;
    border-radius: 14px;
    padding: .72rem .85rem;
    font-size: .8rem;
    line-height: 1.4;
    margin-top: .85rem;
}
.nd-form-tabs {
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 1rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: .55rem;
    box-shadow: 0 10px 24px rgba(15,23,42,.05);
}

.nd-tab-button {
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    color: #334155;
    border-radius: 999px;
    padding: .62rem .95rem;
    font-weight: 600;
    font-size: .84rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    font-family: inherit;
    transition: all .15s;
}

.nd-tab-button.active {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
    box-shadow: 0 8px 18px rgba(15,23,42,.18);
}

.nd-btn-borrador {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border: none;
    background: #1e3a8a;
    color: #fff;
    border-radius: 999px;
    padding: .62rem 1.15rem;
    font-weight: 600;
    font-size: .84rem;
    cursor: pointer;
    font-family: inherit;
    box-shadow: 0 4px 14px rgba(30,58,138,.25);
    transition: background .15s, transform .12s;
    white-space: nowrap;
}
.nd-btn-borrador:hover {
    background: #1e40af;
    transform: translateY(-1px);
}

/* Grid 4 columnas para campos de interviniente */
.nd-inter-grid {
    display: grid;
    grid-template-columns: 160px 1fr 1fr 180px;
    gap: .75rem;
    align-items: start;
}

.nd-tab-panel { display: none; }
.nd-tab-panel.active { display: block; }

.nd-subtitle {
    color: #475569;
    font-size: .86rem;
    line-height: 1.45;
    margin: -.25rem 0 1rem;
}

.nd-required { color: #dc2626; font-weight: 950; }

.nd-check-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .6rem;
}

.nd-check-card {
    display: flex;
    gap: .55rem;
    align-items: flex-start;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: .72rem .82rem;
    color: #334155;
    font-size: .83rem;
    font-weight: 850;
    line-height: 1.35;
}

.nd-check-card input { margin-top: .15rem; transform: scale(1.08); }
.nd-check-card strong { color: #0f172a; }

.nd-mini-nav {
    display: flex;
    justify-content: space-between;
    gap: .65rem;
    flex-wrap: wrap;
    margin: .4rem 0 1rem;
}

.nd-tab-note {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #065f46;
    border-radius: 16px;
    padding: .85rem 1rem;
    font-size: .84rem;
    line-height: 1.45;
    margin-bottom: 1rem;
}

@media (max-width: 720px) { .nd-check-grid { grid-template-columns: 1fr; } }
.nd-btns {
    display: flex;
    gap: .65rem;
    flex-wrap: wrap;
    margin-bottom: 1.4rem;
}

.nd-submit,
.nd-link {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .72rem 1.1rem;
    font-weight: 900;
    text-decoration: none;
    border: 0;
    cursor: pointer;
}

.nd-submit {
    background: #2563eb;
    color: #fff;
}

.nd-link {
    background: #fff;
    color: #334155;
    border: 1px solid #cbd5e1;
}

.nd-side {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    padding: 1.2rem;
    margin-bottom: 1rem;
}

.nd-side h3 {
    margin: 0 0 .8rem;
    font-size: 1rem;
    font-weight: 900;
}

.nd-side p,
.nd-side li {
    color: #64748b;
    font-size: .8rem;
    line-height: 1.45;
}

.nd-side ul {
    padding-left: 1.1rem;
    margin-bottom: 0;
}

@media (max-width: 1050px) {
    .nd-grid,
    .nd-grid.three {
        grid-template-columns: 1fr;
    }
    .nd-hero {
        padding: 1rem;
    }
    .nd-body {
        padding: 1rem;
    }
}

@media (max-width: 720px) {
    .nd-check-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .nd-grid.three {
        grid-template-columns: 1fr;
    }
}

/* Ajuste Fase 0.5.38G-2: marcadores compactos, fuente menor y sin negrita */
.nd-head-muted { background: #f8fafc; }
.nd-head-muted h3 { font-size: .9rem; font-weight: 850; color: #334155; }
.nd-panel-compact .nd-body { padding-top: .9rem; }
.nd-check-grid.compact { gap: .42rem; }
.nd-check-grid.compact .nd-check-card { gap: .46rem; border-radius: 12px; padding: .5rem .6rem; font-size: .74rem; font-weight: 400; line-height: 1.28; }
.nd-check-grid.compact .nd-check-card input { margin-top: .1rem; transform: scale(.96); }
.nd-check-title { display: block; color: #0f172a; font-size: .74rem; font-weight: 400; line-height: 1.22; }
.nd-check-desc { display: block; color: #64748b; font-size: .69rem; font-weight: 400; margin-top: .08rem; line-height: 1.24; }
.nd-panel-compact .nd-subtitle { font-size: .78rem; line-height: 1.4; margin: -.2rem 0 .75rem; }


/* Fase 0.5.38J: comunicación al apoderado */
.nd-tab-note-blue {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1e3a8a;
}

.nd-com-panel .nd-body {
    padding: 1.25rem;
}

.nd-com-options {
    display: flex;
    flex-wrap: wrap;
    gap: .85rem;
    margin-top: .35rem;
}

.nd-com-option {
    width: 180px;
    min-height: 132px;
    border: 1px solid #dbe3ef;
    background: #fff;
    border-radius: 16px;
    display: grid;
    place-items: center;
    text-align: center;
    padding: 1rem .8rem;
    color: #64748b;
    cursor: pointer;
    position: relative;
    transition: .15s ease;
}

.nd-com-option:hover {
    border-color: #93c5fd;
    background: #f8fafc;
}

.nd-com-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.nd-com-option.is-active {
    border-color: #2563eb;
    background: #eff6ff;
    box-shadow: 0 0 0 3px rgba(37,99,235,.16);
    color: #1d4ed8;
}

.nd-com-icon {
    display: block;
    font-size: 1.75rem;
    line-height: 1;
    margin-bottom: .55rem;
}

.nd-com-title {
    display: block;
    font-size: .95rem;
    font-weight: 850;
    line-height: 1.25;
}

.nd-com-textarea {
    min-height: 128px;
    resize: vertical;
}

@media (max-width: 720px) {
    .nd-com-option {
        width: 100%;
        min-height: 105px;
    }
}
</style>
