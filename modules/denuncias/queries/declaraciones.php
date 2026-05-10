<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Queries — declaraciones
 *
 * Carga declaraciones del expediente aplicando validación de tenant mediante
 * JOIN con casos. No usa introspección dinámica del esquema.
 */

function ver_cargar_declaraciones(PDO $pdo, int $casoId, int $colegioId): array
{
    try {
        $stmt = $pdo->prepare("\n            SELECT\n                d.*,\n                p.nombre_referencial AS participante_nombre,\n                p.rol_en_caso AS participante_rol\n            FROM caso_declaraciones d\n            INNER JOIN casos c\n                ON c.id = d.caso_id\n               AND c.colegio_id = ?\n            LEFT JOIN caso_participantes p\n                ON p.id = d.participante_id\n               AND p.caso_id = d.caso_id\n            WHERE d.caso_id = ?\n            ORDER BY d.fecha_declaracion DESC, d.id DESC\n        ");
        $stmt->execute([$colegioId, $casoId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
