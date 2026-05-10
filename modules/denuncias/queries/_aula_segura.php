<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Queries — aula_segura
 * Fase 2C — extraído de ver_queries.php
 */

function ver_aula_segura_default(): array
{
    return [
        'id' => null,
        'caso_id' => null,
        'colegio_id' => null,
        'posible_aula_segura' => 0,
        'causal_agresion_sexual' => 0,
        'causal_agresion_fisica_lesiones' => 0,
        'causal_armas' => 0,
        'causal_artefactos_incendiarios' => 0,
        'causal_infraestructura_esencial' => 0,
        'causal_grave_reglamento' => 0,
        'descripcion_hecho' => '',
        'fuente_informacion' => '',
        'evidencia_inicial' => '',
        'falta_reglamento' => '',
        'fundamento_proporcionalidad' => '',
        'estado' => 'no_aplica',
        'decision_director' => '',
        'fecha_evaluacion_directiva' => '',
        'evaluado_por' => null,
        'fecha_inicio_procedimiento' => '',
        'iniciado_por' => null,
        'comunicacion_apoderado_at' => '',
        'medio_comunicacion_apoderado' => '',
        'observacion_comunicacion_apoderado' => '',
        'suspension_cautelar' => 0,
        'fecha_notificacion_suspension' => '',
        'fecha_limite_resolucion' => '',
        'fundamento_suspension' => '',
        'descargos_recibidos' => 0,
        'fecha_descargos' => '',
        'observacion_descargos' => '',
        'resolucion' => '',
        'fecha_resolucion' => '',
        'fecha_notificacion_resolucion' => '',
        'fundamento_resolucion' => '',
        'reconsideracion_presentada' => 0,
        'fecha_reconsideracion' => '',
        'fecha_limite_reconsideracion' => '',
        'fecha_resolucion_reconsideracion' => '',
        'resultado_reconsideracion' => '',
        'fundamento_reconsideracion' => '',
        'comunicacion_supereduc' => 0,
        'fecha_comunicacion_supereduc' => '',
        'medio_comunicacion_supereduc' => '',
        'observacion_supereduc' => '',
        'observaciones' => '',
        'creado_por' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function ver_aula_segura_causales_catalogo(PDO $pdo): array
{
    $fallback = [
        ['codigo' => 'agresion_sexual', 'nombre' => 'Agresión de carácter sexual', 'tipo' => 'legal'],
        ['codigo' => 'agresion_fisica_lesiones', 'nombre' => 'Agresión física que produce lesiones', 'tipo' => 'legal'],
        ['codigo' => 'armas', 'nombre' => 'Uso, porte, posesión o tenencia de armas', 'tipo' => 'legal'],
        ['codigo' => 'artefactos_incendiarios', 'nombre' => 'Uso, porte, posesión o tenencia de artefactos incendiarios', 'tipo' => 'legal'],
        ['codigo' => 'infraestructura_esencial', 'nombre' => 'Actos contra infraestructura esencial para la prestación del servicio educativo', 'tipo' => 'legal'],
        ['codigo' => 'grave_reglamento', 'nombre' => 'Conducta grave o gravísima del Reglamento Interno', 'tipo' => 'reglamento'],
    ];

    try {
        $stmt = $pdo->query("
            SELECT codigo, nombre, tipo, descripcion, activo, orden
            FROM aula_segura_causales
            WHERE activo = 1
            ORDER BY orden ASC, id ASC
        ");
        $rows = $stmt->fetchAll();
        return $rows ?: $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function ver_aula_segura_campo_por_codigo(string $codigo): ?string
{
    $map = [
        'agresion_sexual' => 'causal_agresion_sexual',
        'agresion_fisica_lesiones' => 'causal_agresion_fisica_lesiones',
        'armas' => 'causal_armas',
        'artefactos_incendiarios' => 'causal_artefactos_incendiarios',
        'infraestructura_esencial' => 'causal_infraestructura_esencial',
        'grave_reglamento' => 'causal_grave_reglamento',
    ];

    return $map[$codigo] ?? null;
}

function ver_cargar_aula_segura(PDO $pdo, int $casoId, int $colegioId, array $caso): array
{
    $default = ver_aula_segura_default();

    $default['caso_id'] = $casoId;
    $default['colegio_id'] = $colegioId;
    $default['posible_aula_segura'] = (int)($caso['posible_aula_segura'] ?? 0);
    $default['estado'] = (string)($caso['aula_segura_estado'] ?? ($default['posible_aula_segura'] === 1 ? 'posible' : 'no_aplica'));

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM caso_aula_segura
            WHERE caso_id = ?
              AND colegio_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$casoId, $colegioId]);
        $row = $stmt->fetch();
        return $row ? array_merge($default, $row) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function ver_cargar_aula_segura_historial(PDO $pdo, int $casoId, int $colegioId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                h.*,
                u.nombre AS usuario_nombre
            FROM caso_aula_segura_historial h
            LEFT JOIN usuarios u ON u.id = h.usuario_id
            WHERE h.caso_id = ?
              AND (h.colegio_id = ? OR h.colegio_id IS NULL)
            ORDER BY h.created_at DESC, h.id DESC
            LIMIT 80
        ");
        $stmt->execute([$casoId, $colegioId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function ver_resumen_aula_segura(array $caso, array $aulaSegura, array $catalogo): array
{
    $posible = (int)($caso['posible_aula_segura'] ?? $aulaSegura['posible_aula_segura'] ?? 0) === 1;
    $estado = (string)($caso['aula_segura_estado'] ?? $aulaSegura['estado'] ?? 'no_aplica');

    if (!$posible) {
        return [
            'visible' => false,
            'estado' => 'no_aplica',
            'estado_texto' => 'No marcada en denuncia',
            'estado_clase' => 'soft',
            'accion' => 'No se requiere completar la pestaña Aula Segura.',
            'causales' => [],
            'causales_texto' => 'Sin causales marcadas',
        ];
    }

    $causales = [];

    foreach ($catalogo as $causal) {
        $codigo = (string)($causal['codigo'] ?? '');
        $campo = ver_aula_segura_campo_por_codigo($codigo);

        if ($campo !== null && (int)($aulaSegura[$campo] ?? 0) === 1) {
            $causales[] = (string)($causal['nombre'] ?? $codigo);
        }
    }

    if (!$causales && !empty($caso['aula_segura_causales_preliminares'])) {
        $preliminares = json_decode((string)$caso['aula_segura_causales_preliminares'], true);

        if (is_array($preliminares)) {
            foreach ($catalogo as $causal) {
                if (in_array((string)($causal['codigo'] ?? ''), $preliminares, true)) {
                    $causales[] = (string)($causal['nombre'] ?? $causal['codigo']);
                }
            }
        } else {
            $causales[] = (string)$caso['aula_segura_causales_preliminares'];
        }
    }

    $estadoTexto = match ($estado) {
        'posible' => 'Posible Aula Segura',
        'en_evaluacion' => 'En evaluación directiva',
        'descartado' => 'Descartado por Dirección',
        'procedimiento_iniciado' => 'Procedimiento iniciado',
        'suspension_cautelar' => 'Suspensión cautelar registrada',
        'resuelto' => 'Procedimiento resuelto',
        'reconsideracion' => 'En reconsideración',
        'cerrado' => 'Cerrado',
        default => 'Posible Aula Segura',
    };

    $estadoClase = match ($estado) {
        'posible' => 'warn',
        'en_evaluacion' => 'blue',
        'descartado' => 'soft',
        'procedimiento_iniciado', 'suspension_cautelar' => 'danger',
        'resuelto', 'cerrado' => 'ok',
        'reconsideracion' => 'warn',
        default => 'warn',
    };

    $accion = match ($estado) {
        'posible' => 'Dirección debe evaluar si inicia o descarta el procedimiento.',
        'en_evaluacion' => 'Completar evaluación directiva y dejar decisión fundada.',
        'descartado' => 'Mantener trazabilidad del descarte y continuar gestión ordinaria del caso.',
        'procedimiento_iniciado' => 'Controlar notificación, descargos, plazos y resolución fundada.',
        'suspension_cautelar' => 'Controlar plazo de resolución y notificaciones asociadas.',
        'resuelto' => 'Verificar notificación, eventual reconsideración y comunicación que corresponda.',
        'reconsideracion' => 'Resolver reconsideración y cerrar trazabilidad del procedimiento.',
        'cerrado' => 'Mantener antecedentes disponibles en historial y reporte.',
        default => 'Revisar antecedentes preliminares.',
    };

    return [
        'visible' => true,
        'estado' => $estado,
        'estado_texto' => $estadoTexto,
        'estado_clase' => $estadoClase,
        'accion' => $accion,
        'causales' => $causales,
        'causales_texto' => $causales ? implode(' · ', $causales) : 'Sin causal preliminar visible',
    ];
}