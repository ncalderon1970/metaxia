<?php
declare(strict_types=1);
$registros = $registros ?? [];
?>
<div class="metis-table-wrapper">
<table class="metis-table">
    <thead>
        <tr>
            <th>RUN</th>
            <th>Nombre / Nombre social</th>
            <th>Año</th>
            <th>Sexo</th>
            <th>Género</th>
            <th>Dato funcional</th>
            <th>Vigencia</th>
            <th class="metis-text-right">Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($registros as $r): ?>
        <?php
            $nombreLegal = trim(($r['nombres'] ?? '') . ' ' . ($r['apellido_paterno'] ?? '') . ' ' . ($r['apellido_materno'] ?? ''));
            $nombreSocial = trim((string)($r['nombre_social'] ?? ''));
            $vigente = (int)($r['vigente'] ?? 0) === 1;
            $datoFuncional = $r['dato_funcional'] ?? $r['curso'] ?? $r['cargo'] ?? '';
        ?>
        <tr>
            <td><?= htmlspecialchars((string)($r['run'] ?? '')) ?></td>
            <td>
                <div class="comunidad-name">
                    <strong><?= htmlspecialchars($nombreLegal) ?></strong>
                    <?php if ($nombreSocial !== ''): ?>
                        <span class="comunidad-social">Nombre social: <?= htmlspecialchars($nombreSocial) ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td><?= htmlspecialchars((string)($r['anio_escolar'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['sexo'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($r['genero'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)$datoFuncional) ?></td>
            <td><span class="comunidad-badge <?= $vigente ? 'comunidad-badge--ok' : 'comunidad-badge--off' ?>"><?= $vigente ? 'Vigente' : 'Histórico' ?></span></td>
            <td class="metis-text-right comunidad-actions">
                <a class="metis-btn metis-btn--sm metis-btn--secondary" href="editar.php?tipo=<?= urlencode((string)($tipo ?? 'alumnos')) ?>&id=<?= (int)($r['id'] ?? 0) ?>&anio_escolar=<?= urlencode((string)($r['anio_escolar'] ?? '')) ?>">Editar</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$registros): ?>
        <tr><td colspan="8" class="metis-empty">No se encontraron registros para los filtros seleccionados.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
