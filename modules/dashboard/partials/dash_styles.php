<?php // Dashboard CSS ?>
<style>
.dash-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.25), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.dash-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.9rem;
    font-weight: 900;
}

.dash-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 900px;
    line-height: 1.55;
}

.dash-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.dash-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius: 7px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.dash-btn.green {
    background: #059669;
    border-color: #10b981;
}

.dash-btn.warn {
    background: #f59e0b;
    border-color: #fbbf24;
    color: #111827;
}

.dash-btn:hover {
    color: #fff;
}

.dash-btn.warn:hover {
    color: #111827;
}

.dash-kpis {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.dash-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    text-align: center;
}

/* ── KPI clickable ── */
.dash-kpi-link {
    cursor: pointer;
    position: relative;
    transition: transform .18s, box-shadow .18s, border-color .18s;
    user-select: none;
    -webkit-user-select: none;
}
.dash-kpi-link:hover {
    transform: translateY(-3px);
    box-shadow: 0 18px 36px rgba(15,23,42,.13);
    border-color: #93c5fd;
}
.dash-kpi-link:active {
    transform: translateY(-1px);
}
.dash-kpi-arrow {
    position: absolute;
    bottom: .55rem;
    right: .6rem;
    font-size: 1.1rem;
    color: #94a3b8;
    opacity: 0;
    transition: opacity .18s, right .18s;
}
.dash-kpi-link:hover .dash-kpi-arrow {
    opacity: 1;
    right: .4rem;
}

/* Estados de alerta en KPIs */
.dash-kpi-danger {
    border-color: #fca5a5 !important;
    background: #fff5f5 !important;
}
.dash-kpi-danger strong { color: #b91c1c !important; }
.dash-kpi-danger:hover  { border-color: #f87171 !important; }

.dash-kpi-warn {
    border-color: #fcd34d !important;
    background: #fffbeb !important;
}
.dash-kpi-warn strong { color: #92400e !important; }
.dash-kpi-warn:hover  { border-color: #f59e0b !important; }

.dash-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.dash-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.9rem;
    line-height: 1;
    margin-top: .35rem;
}

.dash-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(360px, .85fr);
    gap: 1.2rem;
    align-items: start;
}

.dash-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.dash-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.dash-panel-title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    font-weight: 900;
}

.dash-panel-body {
    padding: 1.2rem;
}

.dash-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border-radius: 7px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: .48rem .85rem;
    font-size: .78rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.dash-link.green {
    background: #ecfdf5;
    color: #047857;
    border-color: #bbf7d0;
}

.dash-link.red {
    background: #fef2f2;
    color: #b91c1c;
    border-color: #fecaca;
}

.dash-link.warn {
    background: #fffbeb;
    color: #92400e;
    border-color: #fde68a;
}

.dash-health {
    display: grid;
    gap: .75rem;
}

.dash-health-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: .85rem;
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: .9rem;
}

.dash-health-icon {
    width: 42px;
    height: 42px;
    border-radius: 15px;
    display: grid;
    place-items: center;
    font-size: 1.2rem;
}

.dash-health-icon.ok {
    background: #ecfdf5;
    color: #047857;
}

.dash-health-icon.warn {
    background: #fffbeb;
    color: #92400e;
}

.dash-health-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .12rem;
}

.dash-health-text {
    color: #64748b;
    font-size: .78rem;
    line-height: 1.35;
}

.dash-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .75rem;
}

.dash-item-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .2rem;
}

.dash-item-title a {
    color: #0f172a;
    text-decoration: none;
}

.dash-item-title a:hover {
    color: #2563eb;
}

.dash-meta {
    color: #64748b;
    font-size: .76rem;
    margin-top: .25rem;
}

.dash-text {
    color: #334155;
    line-height: 1.45;
    font-size: .86rem;
    margin-top: .45rem;
}

.dash-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 7px;
    padding: .24rem .6rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .12rem;
}

.dash-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.dash-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.dash-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.dash-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.dash-tools {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .75rem;
}

.dash-tool {
    text-decoration: none;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .8rem;
    align-items: center;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 16px;
    padding: .9rem;
}

.dash-tool:hover {
    background: #f1f5f9;
}

.dash-tool-icon {
    width: 42px;
    height: 42px;
    border-radius: 15px;
    display: grid;
    place-items: center;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 1.15rem;
}

.dash-tool-icon.warn {
    background: #fffbeb;
    color: #92400e;
}

.dash-tool-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .1rem;
}

.dash-tool-text {
    color: #64748b;
    font-size: .76rem;
    line-height: 1.3;
}

.dash-error {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-weight: 800;
}

.dash-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}

@media (max-width: 1300px) {
    .dash-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .dash-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .dash-kpis,
    .dash-tools {
        grid-template-columns: 1fr;
    }

    .dash-health-item {
        grid-template-columns: auto 1fr;
    }

    .dash-health-item .dash-badge {
        grid-column: 1 / -1;
        width: fit-content;
    }
}
</style>
