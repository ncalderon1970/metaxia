<!-- ── Análisis reglamentario ── -->
<div class="tab-section-title">
    <span class="icon-box"><i class="bi bi-stars"></i></span>
    Análisis reglamentario
</div>

<?php if (!empty($caso['requiere_reanalisis_ia'])): ?>
    <div class="alert d-flex align-items-start gap-3 mb-4"
         style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; color:#92400e; padding:1rem 1.25rem;">
        <i class="bi bi-exclamation-triangle-fill" style="font-size:1.2rem; flex-shrink:0; margin-top:.05rem;"></i>
        <div>
            <strong>Reanálisis pendiente.</strong>
            Este caso tiene nueva información incorporada que requiere revisión del análisis IA.
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">

    <!-- Motor premium -->
    <div class="col-xl-7">
        <div style="background: linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
                    border-radius:12px; padding:2rem; color:#fff; position:relative; overflow:hidden;">
            <!-- Decoración de fondo -->
            <div style="position:absolute;top:-30px;right:-30px;width:140px;height:140px;
                        border-radius:50%;background:rgba(59,130,246,.15);"></div>
            <div style="position:absolute;bottom:-20px;right:40px;width:80px;height:80px;
                        border-radius:50%;background:rgba(59,130,246,.08);"></div>

            <div style="position:relative;">
                <div style="font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
                            color:#93c5fd;margin-bottom:.5rem;">
                    <i class="bi bi-stars me-1"></i> Motor premium
                </div>
                <h5 style="font-size:1.1rem;font-weight:700;margin-bottom:.6rem;color:#fff;">
                    Análisis de IA reglamentaria
                </h5>
                <p style="font-size:.875rem;color:#cbd5e1;margin-bottom:1.5rem;line-height:1.6;">
                    Esta herramienta apoya el análisis reglamentario y la toma de decisiones del equipo de convivencia escolar, aplicando el marco normativo vigente al relato e información del expediente.
                </p>

                <?php if ($iaActiva && tiene_permiso('gestionar_casos')): ?>
                    <a class="btn d-inline-flex align-items-center gap-2"
                       href="<?= APP_URL ?>/modules/denuncias/analizar_ia.php?id=<?= (int)$caso['id'] ?>"
                       style="background:#3b82f6;color:#fff;border:none;border-radius:8px;
                              font-size:.84rem;font-weight:600;padding:.6rem 1.25rem;
                              box-shadow:0 4px 14px rgba(59,130,246,.4); transition:all .15s;">
                        <i class="bi bi-stars"></i> Analizar con IA
                    </a>
                <?php else: ?>
                    <button class="btn d-inline-flex align-items-center gap-2" disabled
                            style="background:rgba(255,255,255,.1);color:#94a3b8;border:1px solid rgba(255,255,255,.15);
                                   border-radius:8px;font-size:.84rem;font-weight:600;padding:.6rem 1.25rem;">
                        <i class="bi bi-lock"></i> Análisis premium bloqueado
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Estado del servicio -->
    <div class="col-xl-5">
        <div style="border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;height:100%;">
            <div class="tab-section-title mb-3" style="margin-bottom:.75rem !important;">
                <span class="icon-box"><i class="bi bi-shield-check"></i></span>
                Estado del servicio
            </div>

            <?php if ($iaActiva): ?>
                <div class="d-flex align-items-start gap-3"
                     style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;
                            padding:1rem 1.25rem;color:#15803d;">
                    <i class="bi bi-check-circle-fill" style="font-size:1.3rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;font-size:.875rem;margin-bottom:.25rem;">Módulo activo</div>
                        <div style="font-size:.8rem;opacity:.85;">
                            El servicio premium de análisis IA está activo para este establecimiento.
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-start gap-3"
                     style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;
                            padding:1rem 1.25rem;color:#475569;">
                    <i class="bi bi-lock-fill" style="font-size:1.3rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;font-size:.875rem;margin-bottom:.25rem;">Módulo no disponible</div>
                        <div style="font-size:.8rem;opacity:.85;">
                            El análisis reglamentario premium está disponible solo para establecimientos con servicio activo.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
