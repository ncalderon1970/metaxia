<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/gestion_ejecutiva_repository.php';

/**
 * Fase 23B.1 · Servicio de consolidación ejecutiva.
 * Orquesta consultas livianas para la Bandeja Ejecutiva.
 */
function metis_ge_dashboard(PDO $pdo, int $colegioId): array
{
    return [
        'kpis' => metis_ge_kpis($pdo, $colegioId),
        'alertas_criticas' => metis_ge_alertas_criticas($pdo, $colegioId, 8),
        'acciones_pendientes' => metis_ge_acciones_pendientes($pdo, $colegioId, 8),
        'casos_prioritarios' => metis_ge_casos_prioritarios($pdo, $colegioId, 8),
    ];
}

function metis_ge_prioridad_badge(?string $prioridad): string
{
    $prioridad = strtolower(trim((string)$prioridad));
    return match ($prioridad) {
        'critica', 'crítica' => 'danger',
        'alta' => 'warning',
        'media' => 'primary',
        'baja' => 'secondary',
        default => 'secondary',
    };
}

function metis_ge_estado_badge(?string $estado): string
{
    $estado = strtolower(trim((string)$estado));
    return match ($estado) {
        'pendiente' => 'warning',
        'en_proceso', 'en proceso' => 'primary',
        'cerrado', 'cerrada', 'completada', 'cumplida', 'finalizada' => 'success',
        default => 'secondary',
    };
}

function metis_ge_fecha_corta(?string $fecha): string
{
    $fecha = trim((string)$fecha);
    if ($fecha === '') {
        return 'Sin fecha';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d-m-Y', $ts) : $fecha;
}
