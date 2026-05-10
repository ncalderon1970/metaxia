<div class="seg-card">

    <!-- Filtro de período -->
    <div style="padding:.85rem 1.2rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <span style="font-size:.73rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">
            <i class="bi bi-funnel"></i> Filtrar por fecha de ingreso
        </span>
        <form method="get" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <input type="date" name="desde" value="<?= e($filtDesde) ?>"
                   style="border:1px solid #cbd5e1;border-radius:8px;padding:.32rem .65rem;font-size:.83rem;outline:none;background:#fff;">
            <span style="color:#94a3b8;font-size:.8rem;">—</span>
            <input type="date" name="hasta" value="<?= e($filtHasta) ?>"
                   style="border:1px solid #cbd5e1;border-radius:8px;padding:.32rem .65rem;font-size:.83rem;outline:none;background:#fff;">
            <button type="submit"
                    style="background:#0f172a;color:#fff;border:0;border-radius:7px;padding:.35rem .8rem;font-size:.8rem;font-weight:600;cursor:pointer;">
                Aplicar
            </button>
            <?php if ($filtDesde !== '' || $filtHasta !== ''): ?>
                <a href="<?= APP_URL ?>/modules/seguimiento/index.php"
                   style="font-size:.8rem;color:#64748b;text-decoration:none;white-space:nowrap;">
                    <i class="bi bi-x-circle"></i> Limpiar
                </a>
            <?php endif; ?>
        </form>
        <span style="margin-left:auto;font-size:.78rem;color:#94a3b8;white-space:nowrap;">
            <?= count($casos) ?> caso(s) visibles
            <?php if ($filtDesde !== '' || $filtHasta !== ''): ?>
                · <em>filtrado por período</em>
            <?php endif; ?>
        </span>
    </div>

    <?php if (!$casos): ?>
        <div class="seg-empty">
            <i class="bi bi-check-circle" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3;"></i>
            <?= ($filtDesde !== '' || $filtHasta !== '')
                ? 'No hay casos activos para el período seleccionado.'
                : 'No hay casos activos en seguimiento.' ?>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="seg-table">
            <thead>
                <tr>
                    <th>N° Caso</th>
                    <th>Riesgo</th>
                    <th>Prioridad</th>
                    <th>Estado</th>
                    <th>Partic.</th>
                    <th>Plan</th>
                    <th>Alertas</th>
                    <th>Última sesión</th>
                    <th>Próx. revisión</th>
                    <th>Sin mov.</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($casos as $c):
                $nivelR = (string)($c['nivel_riesgo'] ?? '');
                $tieneRevFutura  = !empty($c['proxima_revision']);
                $tieneRevVencida = !empty($c['revision_vencida_fecha']) && !$tieneRevFutura;
                $revFecha = $tieneRevFutura
                    ? date('d-m-Y', strtotime((string)$c['proxima_revision']))
                    : ($tieneRevVencida
                        ? date('d-m-Y', strtotime((string)$c['revision_vencida_fecha']))
                        : '—');
                $revVencida = $tieneRevVencida;
                $rowCls = match (true) {
                    $nivelR === 'critico' => 'row-critico',
                    $nivelR === 'alto'    => 'row-rojo',
                    $revVencida           => 'row-vencida',
                    default               => '',
                };
                $riesgoBadge = match ($nivelR) {
                    'critico' => '<span class="badge badge-negro">Crítico</span>',
                    'alto'    => '<span class="badge badge-rojo">Alto</span>',
                    'medio'   => '<span class="badge badge-amarillo">Medio</span>',
                    'bajo'    => '<span class="badge badge-verde">Bajo</span>',
                    default   => '<span class="badge" style="background:#fee2e2;color:#b91c1c;" title="Sin pauta de riesgo"><i class="bi bi-exclamation-triangle-fill"></i> Sin pauta</span>',
                };
                $prioBadge = match ((string)($c['prioridad'] ?? '')) {
                    'alta'  => '<span class="badge badge-rojo">Alta</span>',
                    'media' => '<span class="badge badge-amarillo">Media</span>',
                    default => '<span class="badge badge-gris">Baja</span>',
                };
            ?>
                <tr class="<?= $rowCls ?>">
                    <td>
                        <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$c['id'] ?>"
                           style="color:#2563eb;font-weight:600;text-decoration:none;">
                            <?= e((string)$c['numero_caso']) ?>
                        </a>
                    </td>
                    <td><?= $riesgoBadge ?></td>
                    <td><?= $prioBadge ?></td>
                    <td style="font-size:.78rem;color:#475569;">
                        <?= e((string)($c['estado_formal'] ?? $c['estado'] ?? '')) ?>
                    </td>
                    <td style="text-align:center;font-weight:600;">
                        <?= (int)$c['participantes'] ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ((int)$c['tiene_plan'] > 0): ?>
                            <i class="bi bi-check-circle-fill" style="color:#059669;"></i>
                        <?php else: ?>
                            <i class="bi bi-dash-circle" style="color:#dc2626;" title="Sin plan"></i>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ((int)$c['alertas'] > 0): ?>
                            <span class="badge badge-rojo"><?= (int)$c['alertas'] ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.77rem;color:#64748b;white-space:nowrap;">
                        <?= !empty($c['ultima_sesion'])
                            ? date('d-m-Y', strtotime((string)$c['ultima_sesion']))
                            : '<span style="color:#dc2626;">Sin sesión</span>' ?>
                    </td>
                    <td style="font-size:.77rem;white-space:nowrap;
                               color:<?= $revVencida ? '#dc2626' : '#374151' ?>;
                               font-weight:<?= $revVencida ? '700' : '400' ?>;">
                        <?php if ($revVencida): ?>
                            <i class="bi bi-exclamation-circle-fill" style="color:#dc2626;"></i>
                            Vencida: <?= $revFecha ?>
                        <?php else: ?>
                            <?= $revFecha ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.77rem;color:<?= (int)$c['dias_sin_mov'] >= 7 ? '#dc2626' : '#64748b' ?>;
                               font-weight:<?= (int)$c['dias_sin_mov'] >= 7 ? '700' : '400' ?>;">
                        <?= (int)$c['dias_sin_mov'] ?>d
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$c['id'] ?>&tab=seguimiento"
                           class="btn-ir">
                            <i class="bi bi-journal-check"></i> Seguimiento
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
