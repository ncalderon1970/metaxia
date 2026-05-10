<?php
declare(strict_types=1);
/**
 * Metis · Denuncias › Queries — caso
 *
 * Carga base del expediente. Mantiene la regla multi-tenant:
 * todo caso se consulta por id + colegio_id.
 */

function ver_cargar_caso(PDO $pdo, int $casoId, int $colegioId): ?array
{
    if ($casoId <= 0 || $colegioId <= 0) {
        return null;
    }

    try {
        $stmtCaso = $pdo->prepare("
            SELECT
                c.*,
                ec.nombre AS estado_formal,
                ec.codigo AS estado_codigo
            FROM casos c
            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
            WHERE c.id = ?
              AND c.colegio_id = ?
            LIMIT 1
        ");
        $stmtCaso->execute([$casoId, $colegioId]);

        $caso = $stmtCaso->fetch();
        return $caso ?: null;
    } catch (Throwable $e) {
        error_log('[Metis] ver_cargar_caso: ' . $e->getMessage());
        return null;
    }
}
