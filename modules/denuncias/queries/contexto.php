<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Queries — contexto
 * Fase 2C — extraído de ver_queries.php
 */

function ver_cargar_contexto(PDO $pdo, int $casoId, int $colegioId): array
{
    $caso = ver_cargar_caso($pdo, $casoId, $colegioId);

    if (!$caso) {
        http_response_code(404);
        exit('Caso no encontrado o no pertenece al establecimiento.');
    }

    $estadosCaso = $pdo->query("\n        SELECT id, codigo, nombre\n        FROM estado_caso\n        WHERE activo = 1\n          AND codigo != 'borrador'\n        ORDER BY orden_visual ASC, id ASC\n    ")->fetchAll();

    $stmtParticipantes = $pdo->prepare("\n        SELECT *\n        FROM caso_participantes\n        WHERE caso_id = ?\n        ORDER BY id ASC\n    ");
    $stmtParticipantes->execute([$casoId]);
    $participantes = $stmtParticipantes->fetchAll();

    $declaraciones = ver_cargar_declaraciones($pdo, $casoId, $colegioId);

    $evidencias = ver_cargar_evidencias($pdo, $casoId, $colegioId);

    try {
        $stmtAlertas = $pdo->prepare("\n            SELECT a.*\n            FROM caso_alertas a\n            INNER JOIN casos c ON c.id = a.caso_id\n            WHERE a.caso_id = ?\n              AND c.colegio_id = ?\n            ORDER BY a.id DESC\n        ");
        $stmtAlertas->execute([$casoId, $colegioId]);
        $alertas = $stmtAlertas->fetchAll();
    } catch (Throwable $e) {
        $alertas = [];
    }

    $stmtHistorial = $pdo->prepare("\n        SELECT\n            h.*,\n            u.nombre AS usuario_nombre\n        FROM caso_historial h\n        INNER JOIN casos c ON c.id = h.caso_id\n        LEFT JOIN usuarios u ON u.id = h.user_id\n        WHERE h.caso_id = ?\n          AND c.colegio_id = ?\n        ORDER BY h.created_at DESC, h.id DESC\n    ");
    $stmtHistorial->execute([$casoId, $colegioId]);
    $historial = $stmtHistorial->fetchAll();

    $gestionEjecutiva = ver_cargar_gestion_ejecutiva($pdo, $casoId, $colegioId);
    $clasificacionNormativa = ver_cargar_clasificacion_normativa($pdo, $casoId, $colegioId);
    $resumenClasificacionNormativa = ver_resumen_clasificacion_normativa($clasificacionNormativa, $caso);
    $aulaSeguraCatalogo = ver_aula_segura_causales_catalogo($pdo);
    $aulaSegura = ver_cargar_aula_segura($pdo, $casoId, $colegioId, $caso);
    $aulaSeguraHistorial = ver_cargar_aula_segura_historial($pdo, $casoId, $colegioId);
    $resumenAulaSegura = ver_resumen_aula_segura($caso, $aulaSegura, $aulaSeguraCatalogo);
    $cierreData = ver_cargar_cierre_caso($pdo, $casoId, $colegioId);
    $cierreCaso = $cierreData['vigente'];
    $historialCierres = $cierreData['historial'];
    $contextoFamiliar = ver_cargar_contexto_familiar($pdo, $participantes, $colegioId);
    $seguimientoActual = ver_cargar_seguimiento($pdo, $casoId, $colegioId);
    $seguimientoParticipantes = ver_cargar_seguimiento_participantes($pdo, $casoId, $colegioId);
    $seguimientoSchema = ver_seguimiento_schema_status($pdo);
    $resumenSeguimiento = ver_resumen_seguimiento($seguimientoActual, $seguimientoParticipantes);

    // Fase 38K-2I: medidas preventivas y plan de intervención
    $medidasPreventivas = ver_cargar_plan_intervencion($pdo, $casoId, $colegioId);
    $resumenMedidasPreventivas = ver_resumen_medidas_preventivas($medidasPreventivas);

    $indicadoresEjecutivos = ver_calcular_indicadores_ejecutivos(
        $caso,
        $participantes,
        $declaraciones,
        $evidencias,
        $alertas,
        $historial,
        $gestionEjecutiva,
        $contextoFamiliar
    );

    return [
        'caso' => $caso,
        'estadosCaso' => $estadosCaso,
        'participantes' => $participantes,
        'declaraciones' => $declaraciones,
        'evidencias' => $evidencias,
        'alertas' => $alertas,
        'historial' => $historial,
        'gestionEjecutiva' => $gestionEjecutiva,
        'clasificacionNormativa' => $clasificacionNormativa,
        'resumenClasificacionNormativa' => $resumenClasificacionNormativa,
        'aulaSeguraCatalogo' => $aulaSeguraCatalogo,
        'aulaSegura' => $aulaSegura,
        'aulaSeguraHistorial' => $aulaSeguraHistorial,
        'resumenAulaSegura' => $resumenAulaSegura,
        'cierreCaso' => $cierreCaso,
        'historialCierres' => $historialCierres,
        'contextoFamiliar' => $contextoFamiliar,
        'seguimientoActual' => $seguimientoActual,
        'seguimientoParticipantes' => $seguimientoParticipantes,
        'seguimientoSchema' => $seguimientoSchema,
        'resumenSeguimiento' => $resumenSeguimiento,
        'medidasPreventivas' => $medidasPreventivas,
        'resumenMedidasPreventivas' => $resumenMedidasPreventivas,
        'indicadoresEjecutivos' => $indicadoresEjecutivos,
    ];
}

// ============================================================
// Fase 38K-2I — Medidas Preventivas y Plan de Intervención
// ============================================================

/**
 * Tipos de medida disponibles para el catálogo visual del tab.
 */