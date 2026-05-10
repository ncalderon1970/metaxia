<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Queries — cierre
 * Fase 2C — extraído de ver_queries.php
 */

function ver_cargar_cierre_caso(PDO $pdo, int $casoId, int $colegioId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                cc.*,
                uc.nombre AS cerrado_por_nombre,
                ua.nombre AS anulado_por_nombre
            FROM caso_cierre cc
            LEFT JOIN usuarios uc ON uc.id = cc.cerrado_por
            LEFT JOIN usuarios ua ON ua.id = cc.anulado_por
            WHERE cc.caso_id = ?
              AND cc.colegio_id = ?
            ORDER BY cc.id DESC
        ");
        $stmt->execute([$casoId, $colegioId]);
        $historial = $stmt->fetchAll();

        $vigente = null;
        foreach ($historial as $row) {
            if ((string)($row['estado_cierre'] ?? '') === 'vigente') {
                $vigente = $row;
                break;
            }
        }

        return [
            'vigente' => $vigente,
            'historial' => $historial,
        ];
    } catch (Throwable $e) {
        return [
            'vigente' => null,
            'historial' => [],
        ];
    }
}

function ver_cargar_gestion_ejecutiva(PDO $pdo, int $casoId, int $colegioId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                g.*,
                u.nombre AS creado_por_nombre,
                uc.nombre AS cerrado_por_nombre
            FROM caso_gestion_ejecutiva g
            LEFT JOIN usuarios u ON u.id = g.creado_por
            LEFT JOIN usuarios uc ON uc.id = g.cerrado_por
            WHERE g.caso_id = ?
              AND g.colegio_id = ?
            ORDER BY
                CASE g.estado
                    WHEN 'pendiente' THEN 1
                    WHEN 'en_proceso' THEN 2
                    WHEN 'cumplida' THEN 3
                    WHEN 'descartada' THEN 4
                    ELSE 5
                END,
                CASE g.prioridad
                    WHEN 'critica' THEN 1
                    WHEN 'alta' THEN 2
                    WHEN 'media' THEN 3
                    WHEN 'baja' THEN 4
                    ELSE 5
                END,
                COALESCE(g.fecha_compromiso, '2999-12-31') ASC,
                g.id DESC
        ");
        $stmt->execute([$casoId, $colegioId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}