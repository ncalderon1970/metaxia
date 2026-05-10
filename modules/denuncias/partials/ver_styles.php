<style>
.exp-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.24), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 1.8rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.exp-hero h2 {
    margin: 0 0 .4rem;
    font-size: 1.75rem;
    font-weight: 700;
}

.exp-hero p {
    margin: 0;
    color: #bfdbfe;
}

.exp-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.exp-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .58rem .95rem;
    font-size: .84rem;
    font-weight: 700;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.exp-btn:hover {
    color: #fff;
}

.exp-btn.green {
    background: #059669;
    border-color: #10b981;
}

.exp-btn.warn {
    background: #f59e0b;
    border-color: #fbbf24;
    color: #111827;
}

.exp-btn.warn:hover {
    color: #111827;
}

.exp-help {
    background: #ecfeff;
    border: 1px solid #99f6e4;
    color: #115e59;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-size: .88rem;
    margin-bottom: 1rem;
}

.exp-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    margin-top: .9rem;
}

.exp-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: .52rem .95rem;
    font-size: .84rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.exp-link.green {
    background: #ecfdf5;
    color: #047857;
    border-color: #bbf7d0;
}

.exp-link.warn {
    background: #fffbeb;
    color: #92400e;
    border-color: #fde68a;
}

.exp-tabs {
    display: flex;
    gap: .25rem;
    border-bottom: 1px solid #dbe3ef;
    margin-bottom: 1.2rem;
    overflow-x: auto;
}

.exp-tab {
    padding: .8rem 1rem;
    text-decoration: none;
    color: #2563eb;
    font-weight: 700;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
}

.exp-tab.active {
    background: #fff;
    color: #0f172a;
    border: 1px solid #bfdbfe;
    border-bottom: 3px solid #2563eb;
    border-radius: 12px 12px 0 0;
}

.exp-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    padding: 1.3rem;
    margin-bottom: 1.2rem;
}

.exp-title {
    font-size: .72rem;
    color: #2563eb;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    padding-bottom: .65rem;
    margin-bottom: 1.15rem;
    border-bottom: 1px solid #bfdbfe;
}

.exp-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.exp-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

.exp-label {
    display: block;
    font-size: .76rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: .35rem;
}

.exp-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

textarea.exp-control {
    min-height: 130px;
    resize: vertical;
}

.exp-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37,99,235,.12);
}

.exp-submit {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 0;
    border-radius: 8px;
    background: #1e3a8a;
    color: #fff;
    padding: .58rem 1.2rem;
    font-size: .84rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background .15s;
}

.exp-submit:hover { background: #1e40af; }

.exp-submit.blue  { background: #2563eb; }
.exp-submit.blue:hover { background: #1d4ed8; }

.exp-submit.green { background: #059669; }
.exp-submit.green:hover { background: #047857; }

.exp-submit.red   { background: #dc2626; }
.exp-submit.red:hover { background: #b91c1c; }

.exp-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 600;
}

.exp-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.exp-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    border-radius: 999px;
    padding: .2rem .62rem;
    font-size: .72rem;
    font-weight: 600;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    margin: .15rem .2rem .15rem 0;
}

.exp-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.exp-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.exp-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.exp-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.exp-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: .8rem;
}

.exp-item-title {
    font-weight: 700;
    color: #0f172a;
    margin-bottom: .2rem;
}

.exp-item-meta {
    color: #64748b;
    font-size: .76rem;
    margin-bottom: .45rem;
}

.exp-item-text {
    color: #334155;
    line-height: 1.5;
    font-size: .88rem;
}

.exp-empty {
    text-align: center;
    color: #94a3b8;
    padding: 2rem 1rem;
    font-size: .88rem;
}

/* ── Timeline (tab Historial) ─────────────────────────── */
.exp-timeline {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.exp-timeline-item {
    display: flex;
    gap: .85rem;
    align-items: flex-start;
    padding-bottom: 1rem;
}

.exp-timeline-item.last { padding-bottom: 0; }

.exp-timeline-left {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex-shrink: 0;
    width: 34px;
}

.exp-timeline-icon {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .85rem;
    flex-shrink: 0;
    z-index: 1;
}

.exp-timeline-line {
    width: 2px;
    flex: 1;
    min-height: 20px;
    background: #e2e8f0;
    margin-top: 4px;
    border-radius: 2px;
}

.exp-timeline-body {
    flex: 1;
    min-width: 0;
    padding-bottom: .25rem;
}

.exp-timeline-title {
    font-size: .9rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: .3rem;
    line-height: 1.3;
}

.exp-timeline-meta {
    display: flex;
    align-items: center;
    gap: .4rem;
    flex-wrap: wrap;
    font-size: .76rem;
    color: #64748b;
    margin-bottom: .4rem;
}

.exp-timeline-badge {
    border-radius: 999px;
    padding: .14rem .55rem;
    font-size: .7rem;
    font-weight: 700;
}

.exp-timeline-text {
    font-size: .86rem;
    color: #334155;
    line-height: 1.5;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    border-radius: 8px;
    padding: .6rem .75rem;
}

.exp-summary {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    gap: 1.2rem;
}

.exp-data {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: .4rem .9rem;
    font-size: .9rem;
}

.exp-data strong {
    color: #334155;
}

.exp-data span {
    color: #0f172a;
}

@media (max-width: 920px) {
    .exp-grid-2,
    .exp-grid-3,
    .exp-summary {
        grid-template-columns: 1fr;
    }

    .exp-data {
        grid-template-columns: 1fr;
    }
}

.exp-exec-card {
    border-top: 5px solid #2563eb;
}

.exp-exec-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.exp-exec-subtitle {
    color: #64748b;
    font-size: .86rem;
    line-height: 1.45;
}

.exp-risk-pill {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .55rem .9rem;
    font-size: .85rem;
    font-weight: 700;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
}

.exp-risk-pill.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.exp-risk-pill.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.exp-risk-pill.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.exp-exec-grid,
.exp-management-kpis {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .8rem;
    margin-bottom: 1rem;
}

.exp-exec-kpi,
.exp-management-kpis article {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: .9rem;
}

.exp-exec-kpi span,
.exp-management-kpis span {
    display: block;
    color: #64748b;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.exp-exec-kpi strong,
.exp-management-kpis strong {
    display: block;
    color: #0f172a;
    font-size: 1.55rem;
    line-height: 1;
    margin-top: .35rem;
}

.exp-exec-kpi strong.ok,
.exp-management-kpis strong.ok {
    color: #047857;
}

.exp-exec-kpi strong.warn,
.exp-management-kpis strong.warn {
    color: #92400e;
}

.exp-exec-kpi strong.danger,
.exp-management-kpis strong.danger {
    color: #b91c1c;
}

.exp-exec-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .8rem;
}

.exp-exec-detail {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: .9rem;
}

.exp-exec-detail.highlight {
    background: #eff6ff;
    border-color: #bfdbfe;
}

.exp-exec-detail strong {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 700;
    margin-bottom: .25rem;
}

.exp-exec-detail span {
    display: block;
    color: #0f172a;
    font-weight: 700;
    line-height: 1.35;
}

.exp-exec-detail small {
    display: block;
    color: #64748b;
    margin-top: .25rem;
    line-height: 1.35;
}

.exp-exec-alerts {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: .9rem;
}

.exp-management-form,
.exp-management-update {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .9rem;
}

.exp-field.full {
    grid-column: 1 / -1;
}

.exp-management-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: .9rem;
}

.exp-management-item.overdue {
    border-color: #fecaca;
    background: #fff7f7;
}

.exp-management-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: .7rem;
}

.exp-management-meta-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .75rem;
    margin-top: .9rem;
}

.exp-management-meta-grid div {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: .75rem;
}

.exp-management-meta-grid strong {
    display: block;
    color: #64748b;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: .25rem;
}

.exp-management-meta-grid span {
    color: #0f172a;
    font-weight: 600;
    line-height: 1.3;
}

.exp-management-update {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px dashed #cbd5e1;
}

@media (max-width: 920px) {
    .exp-exec-grid,
    .exp-management-kpis,
    .exp-exec-detail-grid,
    .exp-management-form,
    .exp-management-update,
    .exp-management-meta-grid {
        grid-template-columns: 1fr;
    }
}



.exp-family-card {
    border-top: 5px solid #059669;
}

.exp-family-student {
    background: #f8fafc;
    border: 1px solid #dbeafe;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.exp-family-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: .85rem;
}

.exp-family-name {
    color: #0f172a;
    font-weight: 700;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}

.exp-family-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .7rem;
    margin: .9rem 0 1rem;
}

.exp-family-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: .75rem;
}

.exp-family-kpi span {
    display: block;
    color: #64748b;
    font-size: .66rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.exp-family-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.25rem;
    margin-top: .25rem;
}

.exp-family-list {
    display: grid;
    gap: .75rem;
}

.exp-family-person {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: .95rem;
}

.exp-family-person.inactive {
    opacity: .74;
    background: #f8fafc;
}

.exp-family-person-title {
    color: #0f172a;
    font-weight: 700;
    margin-bottom: .18rem;
}

.exp-family-contact {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .55rem;
    color: #334155;
    font-size: .82rem;
    line-height: 1.35;
    margin-top: .55rem;
}

.exp-family-contact strong {
    color: #0f172a;
}

.exp-family-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 12px;
    padding: .65rem .75rem;
    font-size: .82rem;
    line-height: 1.4;
    margin-top: .65rem;
}

@media (max-width: 920px) {
    .exp-family-kpis,
    .exp-family-contact {
        grid-template-columns: 1fr;
    }
}

.exp-exec-family-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .75rem;
    border-radius: 18px;
    padding: .95rem;
    margin: 1rem 0;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}

.exp-exec-family-summary.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
}

.exp-exec-family-summary.warn {
    background: #fffbeb;
    border-color: #fde68a;
}

.exp-exec-family-summary.danger {
    background: #fef2f2;
    border-color: #fecaca;
}

.exp-exec-family-summary strong {
    display: block;
    color: #64748b;
    font-size: .66rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .25rem;
}

.exp-exec-family-summary span {
    display: block;
    color: #0f172a;
    font-weight: 700;
    line-height: 1.35;
}

.exp-exec-family-summary small {
    display: block;
    color: #475569;
    margin-top: .25rem;
    line-height: 1.35;
}

.exp-exec-family-notes {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .75rem .9rem;
    margin-bottom: 1rem;
    line-height: 1.45;
    font-size: .86rem;
}

.exp-exec-family-notes strong {
    display: block;
    margin-bottom: .35rem;
}

.exp-exec-family-notes span {
    display: inline-flex;
    margin: .12rem .2rem .12rem 0;
    background: #fff;
    border: 1px solid #fde68a;
    border-radius: 999px;
    padding: .28rem .62rem;
    font-weight: 600;
}

@media (max-width: 1200px) {
    .exp-exec-family-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 920px) {
    .exp-exec-family-summary {
        grid-template-columns: 1fr;
    }
}
.exp-close-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1rem;
}

.exp-close-state {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #f8fafc;
    padding: .95rem;
}

.exp-close-state.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
}

.exp-close-state.warn {
    background: #fffbeb;
    border-color: #fde68a;
}

.exp-close-state.danger {
    background: #fef2f2;
    border-color: #fecaca;
}

.exp-close-state strong {
    display: block;
    color: #64748b;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .25rem;
}

.exp-close-state span {
    display: block;
    color: #0f172a;
    font-size: 1.15rem;
    font-weight: 700;
}

.exp-close-state small {
    display: block;
    color: #475569;
    margin-top: .25rem;
    line-height: 1.35;
}

.exp-close-current,
.exp-card-soft {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-top: 1rem;
}

.exp-close-warning {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.45;
    font-weight: 600;
    margin-bottom: 1rem;
}

.exp-form-gap {
    display: grid;
    gap: .9rem;
}

.exp-muted-text {
    color: #64748b;
    font-size: .88rem;
    line-height: 1.45;
}

.exp-submit.danger {
    background: #dc2626;
    color: #fff;
}

@media (max-width: 980px) {
    .exp-close-grid {
        grid-template-columns: 1fr;
    }
}



/* Fase 0.5.35 - Clasificación normativa avanzada */
.exp-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.exp-field-full {
    grid-column: 1 / -1;
}

.exp-check-grid {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
}

.exp-check-grid label,
.exp-check-inline {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font-size: .8rem;
    font-weight: 700;
    padding: .46rem .72rem;
}

.exp-alert-box {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 18px;
    padding: 1rem;
}

.exp-title-small {
    color: #0f172a;
    font-weight: 700;
    font-size: .95rem;
}

.exp-kpi-grid-4 {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.exp-kpi-box strong.ok { color: #047857; }
.exp-kpi-box strong.warn { color: #92400e; }
.exp-kpi-box strong.danger { color: #b91c1c; }
.exp-kpi-box strong.soft { color: #475569; }

@media (max-width: 900px) {
    .exp-form-grid,
    .exp-kpi-grid-4 {
        grid-template-columns: 1fr;
    }
}


/* Fase 0.5.36C - Aula Segura condicional */
.exp-tab-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .16rem .45rem;
    margin-left: .35rem;
    font-size: .66rem;
    font-weight: 700;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
}

.exp-tab-badge.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.exp-tab-badge.danger { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.exp-tab-badge.ok { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.exp-tab-badge.blue { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
.exp-tab-badge.soft { background:#f8fafc; border-color:#e2e8f0; color:#475569; }

.exp-aula-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 1rem;
    align-items: stretch;
    background:
        radial-gradient(circle at 92% 12%, rgba(245,158,11,.18), transparent 28%),
        linear-gradient(135deg, #fff 0%, #fffbeb 100%);
    border-color: #fde68a;
}

.exp-aula-hero-main h3 {
    margin: .65rem 0 .35rem;
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 700;
}

.exp-aula-hero-main p {
    margin: 0;
    color: #475569;
    line-height: 1.5;
}

.exp-aula-hero-side {
    border: 1px solid #fcd34d;
    background: #fff7ed;
    border-radius: 18px;
    padding: 1rem;
}

.exp-aula-hero-side strong {
    display: block;
    color: #92400e;
    font-size: .76rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .35rem;
}

.exp-aula-hero-side span {
    display: block;
    color: #0f172a;
    font-weight: 600;
    line-height: 1.35;
}

.exp-aula-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.exp-card-headline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .8rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.exp-card-headline h3 {
    margin: 0;
    color: #0f172a;
    font-size: .9rem;
    font-weight: 700;
}

.exp-aula-info-list {
    display: grid;
    gap: .7rem;
}

.exp-aula-info-list > div {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 15px;
    padding: .85rem;
}

.exp-aula-info-list strong,
.exp-aula-timeline strong {
    display: block;
    color: #64748b;
    font-size: .76rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .2rem;
}

.exp-aula-info-list span,
.exp-aula-timeline span {
    display: block;
    color: #0f172a;
    font-weight: 400;
    font-size: .9rem;
    line-height: 1.45;
}

.exp-aula-causales {
    display: grid;
    gap: .55rem;
}

.exp-aula-causal {
    display: grid;
    grid-template-columns: 28px 1fr;
    gap: .55rem;
    align-items: start;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 15px;
    padding: .75rem;
}

.exp-aula-causal.active {
    background: #fffbeb;
    border-color: #fde68a;
}

.exp-aula-causal i {
    color: #94a3b8;
    font-size: 1.05rem;
    margin-top: .05rem;
}

.exp-aula-causal.active i {
    color: #d97706;
}

.exp-aula-causal strong {
    display: block;
    color: #0f172a;
    font-size: .88rem;
    line-height: 1.25;
}

.exp-aula-causal small {
    display: block;
    color: #64748b;
    margin-top: .15rem;
    font-weight: 400;
    font-size: .82rem;
}

.exp-aula-timeline {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .7rem;
}

.exp-aula-timeline > div {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 15px;
    padding: .85rem;
}

.exp-empty-state.compact {
    padding: 1.2rem;
}

@media (max-width: 980px) {
    .exp-aula-hero,
    .exp-aula-grid,
    .exp-aula-timeline {
        grid-template-columns: 1fr;
    }
}


/* Fase 0.5.36D - Formulario operativo Aula Segura */
.exp-aula-form {
    display: grid;
    gap: 1rem;
}

.exp-aula-form-section {
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 18px;
    padding: 1rem;
}

.exp-aula-form-section h4 {
    margin: 0 0 .2rem;
    color: #0f172a;
    font-size: .9rem;
    font-weight: 700;
}

.exp-aula-form-section p {
    margin: 0 0 .85rem;
    color: #64748b;
    font-size: .9rem;
    line-height: 1.5;
}

.exp-form-grid {
    display: grid;
    gap: .8rem;
}

.exp-form-grid.two {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.exp-form-grid.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.exp-form-grid .full {
    grid-column: 1 / -1;
}

.exp-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 600;
    margin-bottom: .35rem;
}

.exp-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    color: #0f172a;
    font-size: .9rem;
}

.exp-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.10);
}

.exp-help {
    display: block;
    color: #64748b;
    margin-top: .3rem;
    font-size: .72rem;
    line-height: 1.35;
}

.exp-check-card {
    display: flex;
    align-items: center;
    gap: .5rem;
    min-height: 43px;
    border: 1px solid #cbd5e1;
    background: #fff;
    border-radius: 8px;
    padding: .62rem .78rem;
    color: #334155;
    font-size: .84rem;
    font-weight: 700;
}

.exp-aula-causales.editable .exp-aula-causal {
    cursor: pointer;
    grid-template-columns: 22px 1fr;
}

.exp-aula-causales.editable .exp-aula-causal input {
    margin-top: .18rem;
}

.exp-actions-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .6rem;
}

.exp-btn.primary {
    background: #059669;
    border-color: #10b981;
    color: #fff;
}

@media (max-width: 980px) {
    .exp-form-grid.two,
    .exp-form-grid.three {
        grid-template-columns: 1fr;
    }
}


/* Fase 0.5.38I-4 — Panel ejecutivo atractivo, compacto y títulos negros */
.exp-executive-board-v2 {
    margin: 0 0 1rem;
}

.exp-executive-titlebar-v2 {
    margin: 0 0 .95rem;
}

.exp-executive-titlebar-v2 h2 {
    margin: 0;
    color: #0f172a;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: #2563eb;
}

.exp-executive-titlebar-v2 p {
    margin: .12rem 0 0;
    color: #64748b;
    font-size: .9rem;
    line-height: 1.45;
}

.exp-executive-top-v2 {
    display: grid;
    grid-template-columns: minmax(330px, .86fr) minmax(390px, 1fr);
    gap: .85rem;
    align-items: stretch;
    margin-bottom: .8rem;
}

.exp-kpi-grid-v2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .72rem;
}

.exp-kpi-box-v2,
.exp-general-data-v2,
.exp-info-box-v2,
.exp-relato-box-v2 {
    background: #ffffff;
    border: 0;
    border-radius: 15px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, .065);
}

.exp-kpi-box-v2 {
    min-height: 88px;
    padding: .78rem .85rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
}

.exp-kpi-box-v2 span {
    display: block;
    color: #64748b;
    font-size: .76rem;
    line-height: 1.14;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.exp-kpi-box-v2 strong {
    display: block;
    margin-top: .3rem;
    color: #0f172a;
    font-size: 2rem;
    line-height: .95;
    font-weight: 700;
}

.exp-kpi-box-v2 strong.ok {
    color: #00a65a;
}

.exp-kpi-box-v2 strong.warn {
    color: #a16207;
}

.exp-kpi-box-v2 strong.danger {
    color: #dc2626;
}

.exp-general-data-v2 {
    padding: 1rem 1.1rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.exp-general-data-v2 h3 {
    margin: 0 0 .38rem;
    color: #0f172a;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: #2563eb;
}

.exp-general-data-v2 dl {
    margin: 0;
    display: grid;
    gap: .08rem;
}

.exp-general-data-v2 dl div {
    display: grid;
    grid-template-columns: 150px minmax(0, 1fr);
    gap: .65rem;
    align-items: start;
}

.exp-general-data-v2 dt {
    margin: 0;
    color: #334155;
    font-size: .84rem;
    line-height: 1.18;
    font-weight: 600;
}

.exp-general-data-v2 dd {
    margin: 0;
    color: #0f172a;
    font-size: .84rem;
    line-height: 1.18;
    font-weight: 400;
    overflow-wrap: anywhere;
}

.exp-general-data-v2 dd.status {
    font-weight: 600;
    text-transform: capitalize;
}

.exp-general-data-v2 dd.status.ok {
    color: #047857;
}

.exp-general-data-v2 dd.status.warn {
    color: #92400e;
}

.exp-general-data-v2 dd.status.danger {
    color: #b91c1c;
}

.exp-general-data-v2 dd.status.soft {
    color: #475569;
}

.exp-executive-middle-v2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .8rem;
    margin-bottom: .8rem;
}

.exp-info-box-v2 {
    min-height: 86px;
    padding: .85rem .95rem;
}

.exp-info-box-v2 h3,
.exp-relato-box-v2 h3 {
    margin: 0 0 .22rem;
    color: #2563eb;
    font-size: .78rem;
    line-height: 1.18;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.exp-info-box-v2 p,
.exp-relato-box-v2 p {
    margin: 0;
    color: #0f172a;
    font-size: .9rem;
    line-height: 1.5;
    font-weight: 400;
}

.exp-info-box-v2 p.strong-line {
    font-size: .9rem;
    font-weight: 400;
    line-height: 1.5;
}

.exp-info-box-v2 small {
    display: block;
    margin-top: .22rem;
    color: #475569;
    font-size: .8rem;
    line-height: 1.25;
}

.exp-relato-box-v2 {
    min-height: 92px;
    padding: .95rem;
}

.exp-relato-box-v2 p {
    text-transform: none;
}

@media (max-width: 1180px) {
    .exp-executive-top-v2 {
        grid-template-columns: 1fr;
    }

    .exp-general-data-v2 dl div {
        grid-template-columns: 145px minmax(0, 1fr);
    }
}

@media (max-width: 760px) {
    .exp-kpi-grid-v2,
    .exp-executive-middle-v2 {
        grid-template-columns: 1fr;
    }

    .exp-general-data-v2 dl div {
        grid-template-columns: 1fr;
        gap: .05rem;
        margin-bottom: .32rem;
    }

    .exp-kpi-box-v2 strong {
        font-size: 1.8rem;
    }
}


/* Fase 0.5.38K — Seguimiento del expediente */
.exp-seg-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 1.25rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.05);
    margin-bottom: 1.2rem;
}

.exp-seg-head,
.exp-seg-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.exp-seg-head h3,
.exp-seg-progress-card h3,
.exp-seg-section-head h3 {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: -.01em;
}

.exp-seg-head h3 i,
.exp-seg-progress-card h3 i,
.exp-seg-section-head h3 i {
    color: #2563eb;
    margin-right: .35rem;
}

.exp-seg-head p {
    margin: .25rem 0 0;
    color: #64748b;
    font-size: .84rem;
    line-height: 1.45;
    max-width: 900px;
}

.exp-seg-suggest {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 0;
    border-radius: 999px;
    background: #6d28d9;
    color: #fff;
    padding: .55rem .9rem;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 10px 22px rgba(109,40,217,.18);
}

.exp-seg-table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    margin-bottom: 1.1rem;
}

.exp-seg-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 980px;
}

.exp-seg-table th {
    background: #f8fafc;
    color: #475569;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-align: left;
    padding: .7rem .8rem;
    border-bottom: 1px solid #e2e8f0;
}

.exp-seg-table td {
    padding: .65rem .8rem;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle;
}

.exp-seg-table tr:last-child td {
    border-bottom: 0;
}

.exp-seg-person strong {
    display: block;
    color: #0f172a;
    font-size: .86rem;
    font-weight: 700;
    line-height: 1.25;
}

.exp-seg-person span {
    display: block;
    margin-top: .14rem;
    color: #64748b;
    font-size: .74rem;
    line-height: 1.3;
}

.exp-seg-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: .22rem .55rem;
    font-size: .68rem;
    font-weight: 700;
    white-space: nowrap;
}

.exp-seg-badge.victima {
    background: #cffafe;
    color: #0e7490;
}

.exp-seg-badge.denunciado {
    background: #fee2e2;
    color: #b91c1c;
}

.exp-seg-badge.denunciante {
    background: #fef3c7;
    color: #92400e;
}

.exp-seg-badge.testigo {
    background: #eef2ff;
    color: #4338ca;
}

.exp-seg-badge.otro {
    background: #f1f5f9;
    color: #475569;
}

.exp-seg-control {
    width: 100%;
    border: 1px solid #dbe3ef;
    border-radius: 12px;
    padding: .62rem .75rem;
    background: #fff;
    color: #0f172a;
    font-size: .86rem;
    outline: none;
}

.exp-seg-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37,99,235,.10);
}

.exp-seg-plan {
    min-height: 48px;
    resize: vertical;
}

.exp-seg-select {
    min-width: 150px;
}

.exp-seg-progress-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.exp-seg-progress-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(180px, .5fr) minmax(220px, .6fr);
    gap: .9rem;
    margin-top: .8rem;
    align-items: start;
}

.exp-seg-field label,
.exp-seg-notes label {
    display: block;
    color: #475569;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .03em;
    margin-bottom: .32rem;
}

.exp-seg-measures {
    margin: 1rem 0;
}

.exp-seg-soft-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: .28rem .65rem;
    font-size: .72rem;
    font-weight: 700;
}

.exp-seg-measures-box {
    min-height: 120px;
    line-height: 1.45;
    resize: vertical;
}

.exp-seg-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .9rem;
    margin: 1rem 0;
}

.exp-seg-notes {
    margin-top: .75rem;
}

.exp-seg-submit-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.exp-seg-submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    border: 0;
    border-radius: 12px;
    background: #059669;
    color: #fff;
    padding: .75rem 1.35rem;
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 12px 24px rgba(5,150,105,.18);
}

.exp-seg-empty {
    color: #64748b;
    text-align: center;
    padding: 1.2rem;
    font-size: .88rem;
}


.exp-seg-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .75rem;
    margin: .8rem 0 1.1rem;
}

.exp-seg-kpi {
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: .85rem;
    background: #f8fafc;
}

.exp-seg-kpi span {
    display: block;
    color: #64748b;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: .28rem;
}

.exp-seg-kpi strong {
    display: block;
    color: #0f172a;
    font-size: .86rem;
    font-weight: 700;
    line-height: 1.25;
}

.exp-seg-kpi.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
}

.exp-seg-kpi.ok strong {
    color: #047857;
}

.exp-seg-kpi.warn {
    background: #fffbeb;
    border-color: #fde68a;
}

.exp-seg-kpi.warn strong {
    color: #b45309;
}

.exp-seg-kpi.danger {
    background: #fff1f2;
    border-color: #fecdd3;
}

.exp-seg-kpi.danger strong {
    color: #be123c;
}

.exp-seg-kpi.soft {
    background: #f8fafc;
    border-color: #e2e8f0;
}

.exp-seg-footnote {
    margin-top: .85rem;
    color: #64748b;
    font-size: .78rem;
    font-weight: 700;
}

.exp-seg-submit:disabled {
    opacity: .55;
    cursor: not-allowed;
    box-shadow: none;
}

@media (max-width: 1050px) {
    .exp-seg-progress-grid,
    .exp-seg-summary-grid,
    .exp-seg-kpis {
        grid-template-columns: 1fr;
    }

    .exp-seg-submit-row {
        justify-content: stretch;
    }

    .exp-seg-submit {
        width: 100%;
    }
}

</style>
