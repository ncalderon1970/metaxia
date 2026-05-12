<?php
declare(strict_types=1);

/**
 * Fase 23B.1 · Repositorio Gestión Ejecutiva Institucional.
 *
 * Reglas:
 * - Toda consulta operacional filtra por colegio_id vía casos.
 * - No usa INFORMATION_SCHEMA, SHOW TABLES ni SHOW COLUMNS.
 * - No usa u.nombre; cualquier nombre de usuario debe venir desde nombre_display.
 * - Si una tabla aún no existe en un ambiente, se captura la excepción y el módulo degrada con seguridad.
 */

if (!function_exists('metis_ge_estado_caso_abierto_sql')) {
    function metis_ge_estado_caso_abierto_sql(string $aliasCaso = 'c', string $aliasEstado = 'ec'): string
    {
        return "COALESCE({$aliasEstado}.codigo, {$aliasCaso}.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')
                AND COALESCE({$aliasCaso}.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')";
    }
}

if (!function_exists('metis_ge_safe_fetch_all')) {
    function metis_ge_safe_fetch_all(PDO $pdo, string $sql, array $params = [], array $context = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $param = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':'), $value, $param);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if (function_exists('metis_log_exception')) {
                metis_log_exception($e, array_merge(['modulo' => 'gestion_ejecutiva_repository'], $context), 'warning');
            }
            return [];
        }
    }
}

if (!function_exists('metis_ge_safe_fetch_value')) {
    function metis_ge_safe_fetch_value(PDO $pdo, string $sql, array $params = [], array $context = []): int
    {
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $param = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':'), $value, $param);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if (function_exists('metis_log_exception')) {
                metis_log_exception($e, array_merge(['modulo' => 'gestion_ejecutiva_repository'], $context), 'warning');
            }
            return 0;
        }
    }
}

function metis_ge_kpis(PDO $pdo, int $colegioId): array
{
    if ($colegioId <= 0) {
        return [
            'alertas_pendientes' => 0,
            'alertas_criticas' => 0,
            'acciones_abiertas' => 0,
            'acciones_vencidas' => 0,
            'casos_abiertos' => 0,
            'casos_sin_movimiento' => 0,
        ];
    }

    $casosAbiertosSql = "
        SELECT COUNT(*)
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE c.colegio_id = :colegio_id
          AND " . metis_ge_estado_caso_abierto_sql('c', 'ec') . "
    ";

    $alertasPendientesSql = "
        SELECT COUNT(*)
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        WHERE c.colegio_id = :colegio_id
          AND a.estado = 'pendiente'
    ";

    $alertasCriticasSql = "
        SELECT COUNT(*)
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        WHERE c.colegio_id = :colegio_id
          AND a.estado = 'pendiente'
          AND a.prioridad IN ('critica', 'alta')
    ";

    $accionesAbiertasSql = "
        SELECT COUNT(*)
        FROM caso_gestion_ejecutiva ge
        INNER JOIN casos c ON c.id = ge.caso_id
        WHERE c.colegio_id = :colegio_id
          AND COALESCE(ge.estado, 'pendiente') NOT IN ('cerrada', 'cerrado', 'completada', 'cumplida', 'finalizada')
    ";

    $accionesVencidasSql = "
        SELECT COUNT(*)
        FROM caso_gestion_ejecutiva ge
        INNER JOIN casos c ON c.id = ge.caso_id
        WHERE c.colegio_id = :colegio_id
          AND ge.fecha_compromiso IS NOT NULL
          AND ge.fecha_compromiso < CURDATE()
          AND COALESCE(ge.estado, 'pendiente') NOT IN ('cerrada', 'cerrado', 'completada', 'cumplida', 'finalizada')
    ";

    $sinMovimientoSql = "
        SELECT COUNT(*)
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        LEFT JOIN (
            SELECT caso_id, MAX(created_at) AS ultimo_movimiento
            FROM caso_historial
            GROUP BY caso_id
        ) h ON h.caso_id = c.id
        WHERE c.colegio_id = :colegio_id
          AND " . metis_ge_estado_caso_abierto_sql('c', 'ec') . "
          AND DATEDIFF(NOW(), COALESCE(h.ultimo_movimiento, c.updated_at, c.created_at, c.fecha_ingreso)) >= 7
    ";

    return [
        'alertas_pendientes' => metis_ge_safe_fetch_value($pdo, $alertasPendientesSql, ['colegio_id' => $colegioId], ['consulta' => 'alertas_pendientes']),
        'alertas_criticas' => metis_ge_safe_fetch_value($pdo, $alertasCriticasSql, ['colegio_id' => $colegioId], ['consulta' => 'alertas_criticas']),
        'acciones_abiertas' => metis_ge_safe_fetch_value($pdo, $accionesAbiertasSql, ['colegio_id' => $colegioId], ['consulta' => 'acciones_abiertas']),
        'acciones_vencidas' => metis_ge_safe_fetch_value($pdo, $accionesVencidasSql, ['colegio_id' => $colegioId], ['consulta' => 'acciones_vencidas']),
        'casos_abiertos' => metis_ge_safe_fetch_value($pdo, $casosAbiertosSql, ['colegio_id' => $colegioId], ['consulta' => 'casos_abiertos']),
        'casos_sin_movimiento' => metis_ge_safe_fetch_value($pdo, $sinMovimientoSql, ['colegio_id' => $colegioId], ['consulta' => 'casos_sin_movimiento']),
    ];
}

function metis_ge_alertas_criticas(PDO $pdo, int $colegioId, int $limit = 8): array
{
    $limit = max(1, min(25, $limit));
    $sql = "
        SELECT
            a.id,
            a.caso_id,
            a.tipo,
            a.mensaje,
            a.prioridad,
            a.fecha_alerta,
            c.numero_caso,
            c.fecha_ingreso
        FROM caso_alertas a
        INNER JOIN casos c ON c.id = a.caso_id
        WHERE c.colegio_id = :colegio_id
          AND a.estado = 'pendiente'
          AND a.prioridad IN ('critica', 'alta')
        ORDER BY FIELD(a.prioridad, 'critica', 'alta', 'media', 'baja'), a.fecha_alerta ASC, a.id ASC
        LIMIT {$limit}
    ";
    return metis_ge_safe_fetch_all($pdo, $sql, ['colegio_id' => $colegioId], ['consulta' => 'alertas_criticas_listado']);
}

function metis_ge_acciones_pendientes(PDO $pdo, int $colegioId, int $limit = 8): array
{
    $limit = max(1, min(25, $limit));
    $sql = "
        SELECT
            ge.id,
            ge.caso_id,
            ge.titulo,
            ge.responsable_nombre,
            ge.responsable_rol,
            ge.prioridad,
            ge.estado,
            ge.fecha_compromiso,
            c.numero_caso
        FROM caso_gestion_ejecutiva ge
        INNER JOIN casos c ON c.id = ge.caso_id
        WHERE c.colegio_id = :colegio_id
          AND COALESCE(ge.estado, 'pendiente') NOT IN ('cerrada', 'cerrado', 'completada', 'cumplida', 'finalizada')
        ORDER BY
            CASE WHEN ge.fecha_compromiso IS NOT NULL AND ge.fecha_compromiso < CURDATE() THEN 0 ELSE 1 END,
            ge.fecha_compromiso ASC,
            FIELD(ge.prioridad, 'critica', 'alta', 'media', 'baja'),
            ge.id DESC
        LIMIT {$limit}
    ";
    return metis_ge_safe_fetch_all($pdo, $sql, ['colegio_id' => $colegioId], ['consulta' => 'acciones_pendientes']);
}

function metis_ge_casos_prioritarios(PDO $pdo, int $colegioId, int $limit = 8): array
{
    $limit = max(1, min(25, $limit));
    $sql = "
        SELECT
            c.id,
            c.numero_caso,
            c.fecha_ingreso,
            c.contexto,
            c.estado,
            COUNT(DISTINCT a.id) AS alertas_pendientes,
            SUM(CASE WHEN a.prioridad IN ('critica', 'alta') THEN 1 ELSE 0 END) AS alertas_criticas,
            COUNT(DISTINCT ge.id) AS acciones_abiertas,
            DATEDIFF(NOW(), COALESCE(h.ultimo_movimiento, c.updated_at, c.created_at, c.fecha_ingreso)) AS dias_sin_movimiento
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        LEFT JOIN caso_alertas a ON a.caso_id = c.id AND a.estado = 'pendiente'
        LEFT JOIN caso_gestion_ejecutiva ge ON ge.caso_id = c.id AND COALESCE(ge.estado, 'pendiente') NOT IN ('cerrada', 'cerrado', 'completada', 'cumplida', 'finalizada')
        LEFT JOIN (
            SELECT caso_id, MAX(created_at) AS ultimo_movimiento
            FROM caso_historial
            GROUP BY caso_id
        ) h ON h.caso_id = c.id
        WHERE c.colegio_id = :colegio_id
          AND " . metis_ge_estado_caso_abierto_sql('c', 'ec') . "
        GROUP BY c.id, c.numero_caso, c.fecha_ingreso, c.contexto, c.estado, h.ultimo_movimiento, c.updated_at, c.created_at
        HAVING alertas_pendientes > 0 OR acciones_abiertas > 0 OR dias_sin_movimiento >= 7
        ORDER BY alertas_criticas DESC, alertas_pendientes DESC, acciones_abiertas DESC, dias_sin_movimiento DESC, c.id DESC
        LIMIT {$limit}
    ";
    return metis_ge_safe_fetch_all($pdo, $sql, ['colegio_id' => $colegioId], ['consulta' => 'casos_prioritarios']);
}

function metis_ge_validar_caso(PDO $pdo, int $colegioId, int $casoId): ?array
{
    if ($colegioId <= 0 || $casoId <= 0) {
        return null;
    }

    $sql = "
        SELECT id, colegio_id, numero_caso
        FROM casos
        WHERE id = :caso_id
          AND colegio_id = :colegio_id
        LIMIT 1
    ";
    $rows = metis_ge_safe_fetch_all($pdo, $sql, ['caso_id' => $casoId, 'colegio_id' => $colegioId], ['consulta' => 'validar_caso']);
    return $rows[0] ?? null;
}
