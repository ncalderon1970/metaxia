<?php
$ie = $indicadoresEjecutivos ?? [];

$numeroCaso = trim((string)($caso['numero_caso'] ?? ''));
$fechaIngreso = trim((string)($caso['fecha_ingreso'] ?? ''));
$fechaHechos = trim((string)($caso['fecha_hechos'] ?? ''));
$lugarHechos = trim((string)($caso['lugar_hechos'] ?? ''));
$contexto = trim((string)($caso['contexto'] ?? ''));
$relato = trim((string)($caso['relato'] ?? ''));

$estadoTexto = trim((string)($caso['estado_formal'] ?? ''));
$estadoTexto = $estadoTexto !== ''
    ? $estadoTexto
    : (function_exists('caso_label') ? caso_label((string)($caso['estado'] ?? '')) : (string)($caso['estado'] ?? ''));

$semaforo = trim((string)($caso['semaforo'] ?? ''));
$prioridad = trim((string)($caso['prioridad'] ?? ''));

$fmtFecha = static function (?string $value): string {
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
};

$fmtLabel = static function (string $value): string {
    $value = trim($value);

    if ($value === '') {
        return '-';
    }

    if (function_exists('caso_label')) {
        return caso_label($value);
    }

    $value = str_replace('_', ' ', $value);
    return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
};

$valorGeneral = static function (?string $value): string {
    $value = trim((string)$value);
    return $value !== '' ? $value : '-';
};

$claseSemaforo = static function (string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');

    if (in_array($value, ['verde', 'bajo', 'ok'], true)) {
        return 'ok';
    }

    if (in_array($value, ['amarillo', 'medio', 'naranjo', 'alerta'], true)) {
        return 'warn';
    }

    if (in_array($value, ['rojo', 'alto', 'critico', 'crítico'], true)) {
        return 'danger';
    }

    return 'soft';
};

$clasePrioridad = static function (string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');

    if (in_array($value, ['baja', 'bajo', 'verde'], true)) {
        return 'ok';
    }

    if (in_array($value, ['media', 'medio', 'normal'], true)) {
        return 'warn';
    }

    if (in_array($value, ['alta', 'alto', 'urgente', 'critica', 'crítica'], true)) {
        return 'danger';
    }

    return 'soft';
};

$diasDesdeIngreso = (int)($ie['dias_desde_ingreso'] ?? 0);
$diasSinMovimiento = (int)($ie['dias_sin_movimiento'] ?? 0);
$alertasPendientes = (int)($ie['alertas_pendientes'] ?? 0);
$intervinientesTotal = (int)($ie['participantes_total'] ?? $ie['intervinientes_total'] ?? 0);

?>
<section class="exp-executive-board-v2">
    <header class="exp-executive-titlebar-v2">
        <h2><i class="bi bi-speedometer2"></i> Panel ejecutivo del expediente</h2>
        <p>Lectura rápida para control directivo del caso y estado mínimo del expediente.</p>
    </header>

    <div class="exp-executive-top-v2">
        <div class="exp-kpi-grid-v2">
            <article class="exp-kpi-box-v2">
                <span>Días desde ingreso</span>
                <strong><?= number_format($diasDesdeIngreso, 0, ',', '.') ?></strong>
            </article>

            <article class="exp-kpi-box-v2">
                <span>Días sin movimiento</span>
                <strong class="<?= $diasSinMovimiento > 7 ? 'warn' : 'ok' ?>">
                    <?= number_format($diasSinMovimiento, 0, ',', '.') ?>
                </strong>
            </article>

            <article class="exp-kpi-box-v2">
                <span>Alertas pendientes</span>
                <strong class="<?= $alertasPendientes > 0 ? 'danger' : 'ok' ?>">
                    <?= number_format($alertasPendientes, 0, ',', '.') ?>
                </strong>
            </article>

            <article class="exp-kpi-box-v2">
                <span>Intervinientes</span>
                <strong><?= number_format($intervinientesTotal, 0, ',', '.') ?></strong>
            </article>
        </div>

        <aside class="exp-general-data-v2">
            <h3><i class="bi bi-info-circle-fill"></i> Datos generales del caso</h3>

            <dl>
                <div>
                    <dt>N° caso</dt>
                    <dd><?= e($numeroCaso !== '' ? $numeroCaso : 'Sin número') ?></dd>
                </div>

                <div>
                    <dt>Fecha ingreso</dt>
                    <dd><?= e($fmtFecha($fechaIngreso)) ?></dd>
                </div>

                <div>
                    <dt>Estado</dt>
                    <dd><?= e($valorGeneral($estadoTexto)) ?></dd>
                </div>

                <div>
                    <dt>Semáforo</dt>
                    <dd class="status <?= e($claseSemaforo($semaforo)) ?>"><?= e($fmtLabel($semaforo)) ?></dd>
                </div>

                <div>
                    <dt>Prioridad</dt>
                    <dd class="status <?= e($clasePrioridad($prioridad)) ?>"><?= e($fmtLabel($prioridad)) ?></dd>
                </div>

                <div>
                    <dt>Contexto</dt>
                    <dd><?= e($valorGeneral($contexto)) ?></dd>
                </div>

                <div>
                    <dt>Lugar hechos</dt>
                    <dd><?= e($valorGeneral($lugarHechos)) ?></dd>
                </div>

                <div>
                    <dt>Fecha hechos</dt>
                    <dd><?= e($fmtFecha($fechaHechos)) ?></dd>
                </div>
            </dl>
        </aside>
    </div>


    <article class="exp-relato-box-v2">
        <h3><i class="bi bi-journal-text"></i> Relato principal</h3>
        <p><?= nl2br(e($relato !== '' ? $relato : 'Sin relato registrado.')) ?></p>
    </article>
</section>