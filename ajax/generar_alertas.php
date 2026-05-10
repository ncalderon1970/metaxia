<?php
declare(strict_types=1);
/**
 * Motor de alertas automáticas — Metis
 * Fase 16: generación sin semáforo legacy y con validación por colegio vía casos.
 */
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/DB.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/helpers.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$generadas = 0;
$errores = [];

if ($colegioId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Sin colegio activo.']);
    exit;
}

$canOperate = false;
try {
    $canOperate = Auth::canOperate();
} catch (Throwable $e) {
    $canOperate = false;
}

if (!$canOperate && method_exists('Auth', 'can')) {
    foreach (['gestionar_casos', 'crear_denuncia', 'gestionar_alertas', 'gestionar_seguimiento'] as $permiso) {
        try {
            if (Auth::can($permiso)) {
                $canOperate = true;
                break;
            }
        } catch (Throwable $e) {
            // Ignorar permisos inexistentes.
        }
    }
}

if (!$canOperate) {
    echo json_encode(['ok' => false, 'msg' => 'No tiene permisos para generar alertas.']);
    exit;
}

function alerta_texto_prioridad(?string $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['baja', 'media', 'alta'], true) ? $value : 'media';
}

function alerta_automatica_unica(PDO $pdo, int $casoId, string $tipo, string $mensaje, string $prioridad = 'media'): bool
{
    $tipo = trim($tipo);
    $mensaje = trim($mensaje);
    $prioridad = alerta_texto_prioridad($prioridad);

    if ($casoId <= 0 || $tipo === '' || $mensaje === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT id\n            FROM caso_alertas\n            WHERE caso_id = ?\n              AND tipo = ?\n              AND estado = 'pendiente'\n            LIMIT 1\n        ");
        $stmt->execute([$casoId, $tipo]);

        if ($stmt->fetchColumn()) {
            return false;
        }

        $stmt = $pdo->prepare("\n            INSERT INTO caso_alertas (\n                caso_id,\n                tipo,\n                mensaje,\n                prioridad,\n                estado,\n                fecha_alerta,\n                created_at\n            ) VALUES (?, ?, ?, ?, 'pendiente', NOW(), NOW())\n        ");
        $stmt->execute([$casoId, $tipo, $mensaje, $prioridad]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    // 1) Casos abiertos sin movimiento reciente, usando historial cuando existe y updated_at como respaldo.
    try {
        $stmt = $pdo->prepare("\n            SELECT\n                c.id,\n                c.numero_caso,\n                c.prioridad,\n                DATEDIFF(NOW(), COALESCE(h.ultimo_movimiento, c.updated_at, c.fecha_ingreso)) AS dias\n            FROM casos c\n            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n            LEFT JOIN (\n                SELECT caso_id, MAX(created_at) AS ultimo_movimiento\n                FROM caso_historial\n                GROUP BY caso_id\n            ) h ON h.caso_id = c.id\n            WHERE c.colegio_id = ?\n              AND COALESCE(ec.codigo, c.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')\n              AND c.estado NOT IN ('cerrado', 'borrador', 'archivado')\n              AND DATEDIFF(NOW(), COALESCE(h.ultimo_movimiento, c.updated_at, c.fecha_ingreso)) >= 7\n            ORDER BY dias DESC\n            LIMIT 50\n        ");
        $stmt->execute([$colegioId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $caso) {
            $dias = (int)($caso['dias'] ?? 0);
            $tipo = $dias >= 15 ? 'sin_movimiento_15' : 'sin_movimiento_7';
            $prioridad = $dias >= 15 ? 'alta' : 'media';
            $mensaje = 'Caso sin movimiento: ' . (string)$caso['numero_caso'] . ' — El expediente lleva ' . $dias . ' días sin actualización registrada.';

            if (alerta_automatica_unica($pdo, (int)$caso['id'], $tipo, $mensaje, $prioridad)) {
                $generadas++;
            }
        }
    } catch (Throwable $e) {
        $errores[] = 'sin_movimiento: ' . $e->getMessage();
    }

    // 2) Seguimientos con próxima revisión vencida.
    try {
        $stmt = $pdo->prepare("\n            SELECT\n                c.id,\n                c.numero_caso,\n                MIN(cs.proxima_revision) AS proxima\n            FROM casos c\n            INNER JOIN caso_seguimiento cs ON cs.caso_id = c.id AND cs.colegio_id = c.colegio_id\n            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n            WHERE c.colegio_id = ?\n              AND cs.proxima_revision IS NOT NULL\n              AND cs.proxima_revision < CURDATE()\n              AND COALESCE(cs.estado_seguimiento, cs.estado, '') NOT IN ('cerrado', 'completado', 'finalizado')\n              AND COALESCE(ec.codigo, c.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')\n              AND c.estado NOT IN ('cerrado', 'borrador', 'archivado')\n            GROUP BY c.id, c.numero_caso\n            LIMIT 50\n        ");
        $stmt->execute([$colegioId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $caso) {
            $fecha = $caso['proxima'] ? date('d-m-Y', strtotime((string)$caso['proxima'])) : 'sin fecha';
            $mensaje = 'Revisión vencida: ' . (string)$caso['numero_caso'] . ' — Fecha límite: ' . $fecha . '.';

            if (alerta_automatica_unica($pdo, (int)$caso['id'], 'revision_vencida', $mensaje, 'alta')) {
                $generadas++;
            }
        }
    } catch (Throwable $e) {
        $errores[] = 'revision_vencida: ' . $e->getMessage();
    }

    // 3) Casos en investigación/seguimiento/resolución sin plan de acción vigente.
    try {
        $stmt = $pdo->prepare("\n            SELECT\n                c.id,\n                c.numero_caso,\n                c.prioridad,\n                ec.codigo AS estado_codigo\n            FROM casos c\n            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n            LEFT JOIN caso_plan_accion pa\n                ON pa.caso_id = c.id\n               AND pa.colegio_id = c.colegio_id\n               AND pa.vigente = 1\n            WHERE c.colegio_id = ?\n              AND COALESCE(ec.codigo, '') IN ('investigacion', 'resolucion', 'seguimiento')\n              AND c.estado NOT IN ('cerrado', 'borrador', 'archivado')\n              AND pa.id IS NULL\n            LIMIT 50\n        ");
        $stmt->execute([$colegioId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $caso) {
            $prioridad = alerta_texto_prioridad((string)($caso['prioridad'] ?? 'media'));
            $prioridadAlerta = $prioridad === 'alta' ? 'alta' : 'media';
            $mensaje = 'Caso sin plan de acción: ' . (string)$caso['numero_caso'] . ' — El expediente se encuentra en gestión activa, pero no registra plan vigente.';

            if (alerta_automatica_unica($pdo, (int)$caso['id'], 'sin_plan_accion', $mensaje, $prioridadAlerta)) {
                $generadas++;
            }
        }
    } catch (Throwable $e) {
        $errores[] = 'sin_plan_accion: ' . $e->getMessage();
    }

    // 4) Planes vencidos pendientes o en proceso.
    try {
        $stmt = $pdo->prepare("\n            SELECT\n                c.id,\n                c.numero_caso,\n                MIN(pi.fecha_vencimiento) AS fecha_vencimiento\n            FROM caso_plan_intervencion pi\n            INNER JOIN casos c ON c.id = pi.caso_id AND c.colegio_id = pi.colegio_id\n            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n            WHERE c.colegio_id = ?\n              AND pi.fecha_vencimiento IS NOT NULL\n              AND pi.fecha_vencimiento < CURDATE()\n              AND pi.estado IN ('pendiente', 'en_proceso')\n              AND COALESCE(ec.codigo, c.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')\n              AND c.estado NOT IN ('cerrado', 'borrador', 'archivado')\n            GROUP BY c.id, c.numero_caso\n            LIMIT 50\n        ");
        $stmt->execute([$colegioId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $caso) {
            $fecha = $caso['fecha_vencimiento'] ? date('d-m-Y', strtotime((string)$caso['fecha_vencimiento'])) : 'sin fecha';
            $mensaje = 'Plan vencido: ' . (string)$caso['numero_caso'] . ' — Existe una acción con vencimiento anterior al ' . $fecha . ' sin cierre registrado.';

            if (alerta_automatica_unica($pdo, (int)$caso['id'], 'plan_vencido', $mensaje, 'alta')) {
                $generadas++;
            }
        }
    } catch (Throwable $e) {
        $errores[] = 'plan_vencido: ' . $e->getMessage();
    }

    // 5) Pauta de riesgo alto/crítico sin derivación.
    try {
        $stmt = $pdo->prepare("\n            SELECT DISTINCT\n                c.id,\n                c.numero_caso,\n                pr.nivel_final\n            FROM caso_pauta_riesgo pr\n            INNER JOIN casos c ON c.id = pr.caso_id\n            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n            WHERE c.colegio_id = ?\n              AND pr.nivel_final IN ('alto', 'critico')\n              AND COALESCE(pr.derivado, 0) = 0\n              AND COALESCE(ec.codigo, c.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')\n              AND c.estado NOT IN ('cerrado', 'borrador', 'archivado')\n            LIMIT 50\n        ");
        $stmt->execute([$colegioId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $caso) {
            $nivel = strtoupper((string)($caso['nivel_final'] ?? 'alto'));
            $mensaje = 'Riesgo ' . $nivel . ' sin derivación: ' . (string)$caso['numero_caso'] . ' — Revisar derivación y medidas de resguardo.';

            if (alerta_automatica_unica($pdo, (int)$caso['id'], 'riesgo_alto_sin_derivacion', $mensaje, 'alta')) {
                $generadas++;
            }
        }
    } catch (Throwable $e) {
        $errores[] = 'riesgo_alto_sin_derivacion: ' . $e->getMessage();
    }

    // 6) Aula Segura marcada como posible sin decisión directiva.
    try {
        $stmt = $pdo->prepare("\n            SELECT\n                c.id,\n                c.numero_caso,\n                c.aula_segura_estado\n            FROM casos c\n            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n            WHERE c.colegio_id = ?\n              AND COALESCE(c.posible_aula_segura, 0) = 1\n              AND COALESCE(c.aula_segura_estado, '') IN ('posible', 'en_evaluacion', 'no_aplica', '')\n              AND COALESCE(ec.codigo, c.estado, '') NOT IN ('cerrado', 'borrador', 'archivado')\n              AND c.estado NOT IN ('cerrado', 'borrador', 'archivado')\n            LIMIT 50\n        ");
        $stmt->execute([$colegioId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $caso) {
            $mensaje = 'Aula Segura pendiente: ' . (string)$caso['numero_caso'] . ' — El expediente fue marcado como posible Aula Segura y requiere evaluación directiva.';

            if (alerta_automatica_unica($pdo, (int)$caso['id'], 'aula_segura_pendiente', $mensaje, 'alta')) {
                $generadas++;
            }
        }
    } catch (Throwable $e) {
        $errores[] = 'aula_segura_pendiente: ' . $e->getMessage();
    }

    try {
        registrar_bitacora(
            'alertas',
            'generar_alertas',
            'caso_alertas',
            null,
            'Generación automática de alertas. Nuevas: ' . $generadas
        );
    } catch (Throwable $e) {
        // La bitácora no debe bloquear el JSON.
    }

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    exit;
}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_alertas a\n        INNER JOIN casos c ON c.id = a.caso_id\n        WHERE c.colegio_id = ?\n          AND a.estado = 'pendiente'\n    ");
    $stmt->execute([$colegioId]);
    $pendientes = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $pendientes = 0;
}

echo json_encode([
    'ok' => true,
    'generadas' => $generadas,
    'pendientes' => $pendientes,
    'errores' => $errores,
    'ts' => date('H:i'),
]);
