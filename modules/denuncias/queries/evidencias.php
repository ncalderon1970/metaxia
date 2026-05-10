<?php
declare(strict_types=1);
/**
 * Metis · Denuncias › Queries — evidencias
 *
 * Carga segura de evidencias del expediente. Toda consulta se valida
 * contra casos.colegio_id para mantener la regla multi-tenant.
 */

if (!function_exists('ver_cargar_evidencias')) {
function ver_cargar_evidencias(PDO $pdo, int $casoId, int $colegioId): array
{
    if ($casoId <= 0 || $colegioId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT\n                e.id,\n                e.caso_id,\n                e.tipo,\n                e.nombre_archivo,\n                e.ruta,\n                e.descripcion,\n                e.mime_type,\n                e.tamano_bytes,\n                e.subido_por,\n                e.created_at,\n                u.nombre AS subido_por_nombre\n            FROM caso_evidencias e\n            INNER JOIN casos c ON c.id = e.caso_id\n            LEFT JOIN usuarios u ON u.id = e.subido_por\n            WHERE e.caso_id = ?\n              AND c.colegio_id = ?\n            ORDER BY e.created_at DESC, e.id DESC\n        ");
        $stmt->execute([$casoId, $colegioId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[Metis] ver_cargar_evidencias: ' . $e->getMessage());
        return [];
    }
}
}

if (!function_exists('ver_evidencia_icono')) {
function ver_evidencia_icono(?string $tipo, ?string $mime): string
{
    $tipo = strtolower(trim((string)$tipo));
    $mime = strtolower(trim((string)$mime));

    if (str_contains($mime, 'pdf')) {
        return 'bi-file-earmark-pdf';
    }

    if (str_starts_with($mime, 'image/') || in_array($tipo, ['imagen', 'foto'], true)) {
        return 'bi-file-earmark-image';
    }

    if (str_starts_with($mime, 'audio/')) {
        return 'bi-file-earmark-music';
    }

    if (str_starts_with($mime, 'video/')) {
        return 'bi-file-earmark-play';
    }

    if (str_contains($mime, 'word') || str_contains($mime, 'document')) {
        return 'bi-file-earmark-word';
    }

    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) {
        return 'bi-file-earmark-excel';
    }

    return 'bi-paperclip';
}
}
