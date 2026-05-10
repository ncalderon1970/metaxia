<?php
declare(strict_types=1);
?>
<section class="card">
    <div class="card-header">
        <h2>Asistente IA de convivencia</h2>
        <p>Módulo premium. Orienta al equipo de convivencia con análisis preliminar del caso, medidas sugeridas y alertas de intervención.</p>
    </div>

    <?php if (empty($iaModuloActivo)): ?>
        <div class="empty-state">
            <p>El módulo premium IA no está habilitado para este establecimiento.</p>
            <p>Actívalo desde el panel financiero para acceder al análisis de casos, orientación de intervenciones y sugerencias de medidas.</p>
        </div>
    <?php elseif (!$caso): ?>
        <div class="empty-state">
            <p>Selecciona un caso para ejecutar o revisar el análisis IA.</p>
        </div>
    <?php else: ?>
        <div class="expediente-block">
            <form method="post" action="<?= e(APP_URL) ?>/modules/denuncias_ai/index.php?caso_id=<?= (int)$caso['id'] ?>">
                <?= CSRF::field(); ?>
                <input type="hidden" name="_accion" value="analizar_caso">
                <input type="hidden" name="caso_id" value="<?= (int)$caso['id'] ?>">
                <button class="btn btn-primary" type="submit">Analizar con IA</button>
            </form>
        </div>

        <?php if (empty($analisisIA)): ?>
            <div class="empty-state">
                <p>Este caso aún no tiene análisis IA registrado.</p>
            </div>
        <?php else: ?>
            <div class="expediente-grid">
                <div class="expediente-main">
                    <div class="expediente-block">
                        <h3>Clasificación y riesgo sugerido</h3>
                        <dl class="meta-grid">
                            <div><dt>Clasificación</dt><dd><?= e((string)$analisisIA['clasificacion_sugerida']) ?></dd></div>
                            <div><dt>Gravedad</dt><dd><?= e((string)$analisisIA['gravedad_sugerida']) ?></dd></div>
                            <div><dt>Riesgo</dt><dd><?= e((string)$analisisIA['riesgo_sugerido']) ?></dd></div>
                            <div><dt>Confianza</dt><dd><?= e((string)$analisisIA['confianza']) ?>%</dd></div>
                        </dl>
                    </div>
                    <div class="expediente-block"><h3>Resumen de hechos</h3><div class="text-box"><?= nl2br(e((string)$analisisIA['resumen_hechos'])) ?></div></div>
                    <div class="expediente-block"><h3>Orientación para el equipo de convivencia</h3><div class="text-box"><?= nl2br(e((string)$analisisIA['orientacion_equipo'])) ?></div></div>
                    <div class="expediente-block"><h3>Sugerencias de intervención</h3><div class="text-box"><?= nl2br(e((string)$analisisIA['sugerencia_intervencion'])) ?></div></div>
                    <div class="expediente-block"><h3>Fundamento sugerido</h3><div class="text-box"><?= nl2br(e((string)$analisisIA['sugerencia_fundamento'])) ?></div></div>
                </div>
                <aside class="expediente-side">
                    <div class="expediente-block"><h3>Artículos / referencias</h3><div class="text-box"><?= nl2br(e((string)$analisisIA['articulos_relacionados'])) ?></div></div>
                    <div class="expediente-block"><h3>Medidas sugeridas</h3><div class="text-box"><?= nl2br(e((string)$analisisIA['medidas_sugeridas'])) ?></div></div>
                    <div class="expediente-block"><h3>Alertas detectadas</h3><div class="text-box"><?= nl2br(e((string)($analisisIA['alertas_detectadas'] ?? 'Sin alertas explícitas.'))) ?></div></div>
                    <div class="expediente-block"><h3>Flags de intervención</h3>
                        <ul class="files">
                            <li>Protocolo: <?= e((string)($analisisIA['requiere_protocolo'] ?? 'No definido')) ?></li>
                            <li>Aula Segura: <?= (int)$analisisIA['requiere_aula_segura'] === 1 ? 'Sí' : 'No' ?></li>
                            <li>Derivación: <?= (int)$analisisIA['requiere_derivacion'] === 1 ? 'Sí' : 'No' ?></li>
                            <li>Medidas de resguardo: <?= (int)$analisisIA['requiere_medidas_resguardo'] === 1 ? 'Sí' : 'No' ?></li>
                        </ul>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
