<style>
.seg-hero     { background:linear-gradient(135deg,#0f172a,#1a3a5c,#2563eb);
                border-radius:14px;color:#fff;padding:2rem 2.5rem;margin-bottom:1.5rem; }
.seg-kpis     { display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
                gap:.75rem;margin-bottom:1.5rem; }
.seg-kpi      { background:#fff;border:1px solid #e2e8f0;border-radius:12px;
                padding:1rem 1.25rem;text-align:center; }
.seg-kpi-val  { font-size:1.9rem;font-weight:800;line-height:1;color:var(--c-metis-primary); }
.seg-kpi-val.warn { color:var(--c-metis-danger); }
.seg-kpi-val.amber{ color:var(--c-metis-warning); }
.seg-kpi-val.ok   { color:var(--c-metis-success); }
.seg-kpi-lbl  { font-size:.7rem;color:#64748b;margin-top:.25rem;font-weight:600; }
.seg-card     { background:#fff;border:1px solid #e2e8f0;border-radius:12px;
                box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden; }
.seg-table    { width:100%;border-collapse:collapse;font-size:.82rem; }
.seg-table th { font-size:.68rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
                color:#64748b;padding:.65rem 1rem;border-bottom:1px solid #e2e8f0;
                background:#f8fafc;white-space:nowrap;text-align:left; }
.seg-table td { padding:.7rem 1rem;border-top:1px solid #f1f5f9;vertical-align:middle; }
.seg-table tr:hover td { background:#f8fafd; }
.seg-table tr.row-critico td { background:#fef2f2; }
.seg-table tr.row-rojo    td { background:#fff8f8; }
.seg-table tr.row-vencida td { background:#fffbeb; }
.badge        { display:inline-flex;align-items:center;gap:.25rem;border-radius:7px;
                padding:.18rem .55rem;font-size:.71rem;font-weight:700;white-space:nowrap; }
.badge-rojo   { background:#fee2e2;color:#7f1d1d; }
.badge-negro  { background:#0f172a;color:#e2e8f0; }
.badge-verde  { background:#d1fae5;color:#064e3b; }
.badge-amarillo{ background:#fef3c7;color:#78350f; }
.badge-gris   { background:#f1f5f9;color:#334155; }
.badge-azul   { background:#e0f2fe;color:#0c4a6e; }
.dot          { width:8px;height:8px;border-radius:50%;display:inline-block; }
.btn-ir       { font-size:.74rem;font-weight:600;padding:.3rem .75rem;border-radius:7px;
                border:1.5px solid #bae6fd;background:#e0f2fe;color:#0c4a6e;
                text-decoration:none;display:inline-flex;align-items:center;gap:.3rem; }
.btn-ir:hover { background:var(--c-metis-action);color:#fff;border-color:var(--c-metis-action); }
.alerta-warn  { background:#fef3c7;border:1px solid #fde68a;border-radius:8px;
                padding:.6rem 1rem;margin-bottom:1rem;font-size:.82rem;color:#92400e;
                display:flex;align-items:center;gap:.6rem; }
.seg-empty    { text-align:center;padding:3rem;color:#94a3b8; }
</style>
