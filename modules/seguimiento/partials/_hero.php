<div class="seg-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
                         color:#93c5fd;display:block;margin-bottom:.35rem;">
                <i class="bi bi-graph-up"></i> Metis · Control Directivo
            </span>
            <h1 style="font-size:1.7rem;font-weight:800;color:#fff;margin-bottom:.25rem;">
                Seguimiento de casos
            </h1>
            <p style="font-size:.87rem;color:#93c5fd;margin:0;">
                Vista panorámica de todos los casos activos · <?= date('d-m-Y H:i') ?>
            </p>
        </div>
        <div style="display:flex;gap:.5rem;align-self:center;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/modules/denuncias/index.php"
               style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);
                      border-radius:7px;font-size:.83rem;font-weight:600;padding:.45rem 1rem;
                      text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;">
                <i class="bi bi-folder2-open"></i> Todas las denuncias
            </a>
            <a href="<?= APP_URL ?>/modules/alertas/index.php"
               style="background:rgba(220,38,38,.25);color:#fff;border:1px solid rgba(220,38,38,.4);
                      border-radius:7px;font-size:.83rem;font-weight:600;padding:.45rem 1rem;
                      text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;">
                <i class="bi bi-bell-fill"></i> Alertas
            </a>
        </div>
    </div>
</div>
