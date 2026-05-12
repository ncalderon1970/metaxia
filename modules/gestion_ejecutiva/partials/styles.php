<?php
declare(strict_types=1);
?>
<style>
    .ge-hero {
        border: 1px solid rgba(15, 23, 42, .08);
        border-radius: 18px;
        background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 62%, #059669 100%);
        color: #fff;
        padding: 1.25rem;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .14);
        margin-bottom: 1rem;
    }
    .ge-hero h1 { font-size: 1.35rem; margin: 0; font-weight: 800; }
    .ge-hero p { margin: .35rem 0 0; color: rgba(255,255,255,.82); max-width: 920px; }
    .ge-kpi-grid { display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: .85rem; margin-bottom: 1rem; }
    .ge-kpi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 1rem; box-shadow: 0 10px 26px rgba(15,23,42,.06); }
    .ge-kpi-label { color: #64748b; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .ge-kpi-value { color: #0f172a; font-size: 1.45rem; font-weight: 900; margin-top: .15rem; }
    .ge-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; box-shadow: 0 10px 26px rgba(15,23,42,.05); margin-bottom: 1rem; overflow: hidden; }
    .ge-section-header { display: flex; justify-content: space-between; align-items: center; padding: .9rem 1rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .ge-section-header h2 { font-size: .95rem; margin: 0; font-weight: 900; color: #0f172a; }
    .ge-table { width: 100%; border-collapse: collapse; }
    .ge-table th { font-size: .72rem; text-transform: uppercase; color: #64748b; background: #fff; padding: .75rem 1rem; border-bottom: 1px solid #e2e8f0; }
    .ge-table td { padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: .86rem; }
    .ge-table tr:last-child td { border-bottom: 0; }
    .ge-muted { color: #64748b; }
    .ge-empty { padding: 1.2rem; color: #64748b; font-size: .9rem; }
    .ge-grid-2 { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 1rem; }
    @media (max-width: 1180px) { .ge-kpi-grid { grid-template-columns: repeat(3, minmax(0,1fr)); } .ge-grid-2 { grid-template-columns: 1fr; } }
    @media (max-width: 720px) { .ge-kpi-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } .ge-table { min-width: 720px; } .ge-section { overflow-x: auto; } }
</style>
