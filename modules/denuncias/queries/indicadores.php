<?php
declare(strict_types=1);
/**
 * Metis · Denuncias › Queries — indicadores ejecutivos
 *
 * Calcula indicadores de expediente sin depender del campo legacy semaforo.
 */

function ver_calcular_indicadores_ejecutivos(
    array $caso,
    array $participantes,
    array $declaraciones,
    array $evidencias,
    array $alertas,
    array $historial,
    array $gestionEjecutiva,
    array $contextoFamiliar = []
): array {
    $now = new DateTimeImmutable('now');

    $fechaIngresoRaw = (string)($caso['fecha_ingreso'] ?? $caso['created_at'] ?? '');
    try {
        $fechaIngreso = $fechaIngresoRaw !== '' ? new DateTimeImmutable($fechaIngresoRaw) : $now;
    } catch (Throwable $e) {
        $fechaIngreso = $now;
    }
    $diasDesdeIngreso = max(0, (int)$fechaIngreso->diff($now)->format('%a'));

    $ultimaActuacionFecha = $fechaIngreso;
    $ultimaActuacionTexto = 'Ingreso del caso';

    if (!empty($historial[0]['created_at'])) {
        try {
            $ultimaActuacionFecha = new DateTimeImmutable((string)$historial[0]['created_at']);
            $ultimaActuacionTexto = (string)($historial[0]['titulo'] ?? 'Actividad registrada');
        } catch (Throwable $e) {
            $ultimaActuacionFecha = $fechaIngreso;
            $ultimaActuacionTexto = 'Ingreso del caso';
        }
    } elseif (!empty($caso['updated_at'])) {
        try {
            $ultimaActuacionFecha = new DateTimeImmutable((string)$caso['updated_at']);
            $ultimaActuacionTexto = 'Actualización del caso';
        } catch (Throwable $e) {
            $ultimaActuacionFecha = $fechaIngreso;
            $ultimaActuacionTexto = 'Ingreso del caso';
        }
    }

    $diasSinMovimiento = max(0, (int)$ultimaActuacionFecha->diff($now)->format('%a'));

    $alertasPendientes = 0;
    $alertasAlta = 0;

    foreach ($alertas as $alerta) {
        if ((string)($alerta['estado'] ?? '') === 'pendiente') {
            $alertasPendientes++;

            if (in_array((string)($alerta['prioridad'] ?? ''), ['alta', 'critica'], true)) {
                $alertasAlta++;
            }
        }
    }

    $participantesReserva = 0;

    foreach ($participantes as $participante) {
        if ((int)($participante['solicita_reserva_identidad'] ?? 0) === 1) {
            $participantesReserva++;
        }
    }

    $gestionPendiente = 0;
    $gestionVencida = 0;
    $proximaGestion = null;
    $hoy = $now->format('Y-m-d');
    $resumenFamiliar = ver_resumen_contexto_familiar($contextoFamiliar);

    foreach ($gestionEjecutiva as $gestion) {
        $estado = (string)($gestion['estado'] ?? 'pendiente');

        if (in_array($estado, ['pendiente', 'en_proceso'], true)) {
            $gestionPendiente++;

            if (!$proximaGestion) {
                $proximaGestion = $gestion;
            }

            $fechaCompromiso = substr((string)($gestion['fecha_compromiso'] ?? ''), 0, 10);

            if ($fechaCompromiso !== '' && $fechaCompromiso < $hoy) {
                $gestionVencida++;
            }
        }
    }

    $completitud = 0;
    $completitud += count($participantes) > 0 ? 20 : 0;
    $completitud += count($declaraciones) > 0 ? 20 : 0;
    $completitud += count($evidencias) > 0 ? 20 : 0;
    $completitud += trim((string)($caso['estado_formal'] ?? '')) !== '' ? 20 : 0;
    $completitud += count($historial) > 0 ? 20 : 0;

    $textoBusqueda = mb_strtolower(
        (string)($caso['relato'] ?? '') . ' ' .
        (string)($caso['clasificacion_ia'] ?? '') . ' ' .
        (string)($caso['resumen_ia'] ?? '') . ' ' .
        (string)($caso['recomendacion_ia'] ?? ''),
        'UTF-8'
    );

    $aulaSeguraMarcada = (int)($caso['posible_aula_segura'] ?? 0) === 1;
    $aulaSeguraEstado = (string)($caso['aula_segura_estado'] ?? 'no_aplica');

    if ($aulaSeguraMarcada) {
        $aulaSegura = match ($aulaSeguraEstado) {
            'posible' => 'Posible Aula Segura: pendiente evaluación directiva',
            'en_evaluacion' => 'Aula Segura en evaluación directiva',
            'descartado' => 'Aula Segura descartada por Dirección',
            'procedimiento_iniciado' => 'Procedimiento Aula Segura iniciado',
            'suspension_cautelar' => 'Aula Segura con suspensión cautelar',
            'resuelto' => 'Procedimiento Aula Segura resuelto',
            'reconsideracion' => 'Aula Segura en reconsideración',
            'cerrado' => 'Aula Segura cerrada',
            default => 'Posible aplicación o revisión Aula Segura',
        };
    } else {
        $aulaSegura = str_contains($textoBusqueda, 'aula segura')
            || str_contains($textoBusqueda, 'arma')
            || str_contains($textoBusqueda, 'agresión grave')
            || str_contains($textoBusqueda, 'agresion grave')
            || str_contains($textoBusqueda, 'lesiones')
            ? 'Mención textual detectada: revisar si corresponde marcar Aula Segura'
            : 'No identificada';
    }

    $estadoCodigo = mb_strtolower((string)($caso['estado_codigo'] ?? ''), 'UTF-8');
    $estadoFormal = mb_strtolower((string)($caso['estado_formal'] ?? ''), 'UTF-8');

    $medidasResguardo = str_contains($estadoCodigo, 'resguardo') || str_contains($estadoFormal, 'resguardo')
        ? 'Medidas de resguardo activas o en revisión'
        : 'No identificadas';

    $prioridad = (string)($caso['prioridad'] ?? 'media');

    $score = 0;
    $score += in_array($prioridad, ['alta', 'critica'], true) ? 2 : 0;
    $score += $prioridad === 'critica' ? 1 : 0;
    $score += $alertasPendientes > 0 ? 1 : 0;
    $score += $alertasAlta > 0 ? 2 : 0;
    $score += $diasSinMovimiento > 15 ? 2 : ($diasSinMovimiento > 7 ? 1 : 0);
    $score += $gestionVencida > 0 ? 2 : 0;
    $score += $completitud < 60 ? 1 : 0;
    $score += (int)($caso['requiere_reanalisis_ia'] ?? 0) === 1 ? 1 : 0;
    $score += $aulaSeguraMarcada ? 2 : 0;
    $score += (int)($resumenFamiliar['alumnos_sin_apoderados'] ?? 0) > 0 ? 1 : 0;
    $score += (int)($resumenFamiliar['apoderados_principales'] ?? 0) === 0
        && (int)($resumenFamiliar['alumnos_total'] ?? 0) > 0 ? 1 : 0;

    if ($score >= 6) {
        $riesgoTexto = 'Riesgo alto';
        $riesgoClase = 'danger';
    } elseif ($score >= 3) {
        $riesgoTexto = 'Riesgo medio';
        $riesgoClase = 'warn';
    } else {
        $riesgoTexto = 'Riesgo bajo';
        $riesgoClase = 'ok';
    }

    if ($gestionVencida > 0) {
        $proximaAccion = 'Regularizar acciones ejecutivas vencidas.';
    } elseif ($alertasAlta > 0) {
        $proximaAccion = 'Atender alertas de prioridad alta.';
    } elseif (count($participantes) === 0) {
        $proximaAccion = 'Identificar y vincular participantes del caso.';
    } elseif ((int)($resumenFamiliar['alumnos_total'] ?? 0) > 0 && (int)($resumenFamiliar['alumnos_sin_apoderados'] ?? 0) > 0) {
        $proximaAccion = 'Completar contexto familiar del estudiante.';
    } elseif ((int)($resumenFamiliar['alumnos_total'] ?? 0) > 0 && (int)($resumenFamiliar['apoderados_principales'] ?? 0) === 0) {
        $proximaAccion = 'Definir apoderado principal del estudiante.';
    } elseif (count($declaraciones) === 0) {
        $proximaAccion = 'Registrar declaraciones o entrevistas relevantes.';
    } elseif (count($evidencias) === 0) {
        $proximaAccion = 'Incorporar evidencias o respaldos documentales.';
    } elseif ($diasSinMovimiento > 7) {
        $proximaAccion = 'Registrar una actuación de seguimiento.';
    } elseif ($proximaGestion) {
        $proximaAccion = 'Ejecutar acción: ' . (string)$proximaGestion['titulo'];
    } else {
        $proximaAccion = 'Mantener seguimiento del expediente.';
    }

    return [
        'riesgo_texto' => $riesgoTexto,
        'riesgo_clase' => $riesgoClase,
        'score' => $score,
        'dias_desde_ingreso' => $diasDesdeIngreso,
        'dias_sin_movimiento' => $diasSinMovimiento,
        'alertas_pendientes' => $alertasPendientes,
        'alertas_alta' => $alertasAlta,
        'participantes_total' => count($participantes),
        'participantes_reserva' => $participantesReserva,
        'declaraciones_total' => count($declaraciones),
        'evidencias_total' => count($evidencias),
        'gestion_pendiente' => $gestionPendiente,
        'gestion_vencida' => $gestionVencida,
        'ultima_actuacion_texto' => $ultimaActuacionTexto,
        'ultima_actuacion_fecha' => caso_fecha($ultimaActuacionFecha->format('Y-m-d H:i:s')),
        'proxima_accion' => $proximaAccion,
        'estado_formal' => (string)($caso['estado_formal'] ?? 'Sin estado formal'),
        'medidas_resguardo' => $medidasResguardo,
        'aula_segura' => $aulaSegura,
        'completitud' => $completitud,
        'completitud_texto' => $completitud >= 80 ? 'Expediente robusto' : ($completitud >= 60 ? 'Expediente aceptable' : 'Expediente incompleto'),
        'resumen_familiar' => $resumenFamiliar,
        'requiere_reanalisis' => (int)($caso['requiere_reanalisis_ia'] ?? 0) === 1,
    ];
}
