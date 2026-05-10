<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Queries — clasificacion
 * Fase 2C — extraído de ver_queries.php
 */

function ver_clasificacion_normativa_default(): array
{
    return [
        'id' => null,
        'colegio_id' => null,
        'caso_id' => null,
        'area_mineduc' => '',
        'ambito_mineduc' => '',
        'tipo_conducta' => '',
        'categoria_convivencia' => '',
        'conducta_principal' => '',
        'gravedad' => 'media',
        'reiteracion' => 0,
        'involucra_adulto' => 0,
        'discriminacion' => 0,
        'ciberacoso' => 0,
        'acoso_escolar' => 0,
        'violencia_fisica' => 0,
        'violencia_psicologica' => 0,
        'violencia_sexual' => 0,
        'maltrato_adulto_estudiante' => 0,
        'posible_aula_segura' => 0,
        'causal_aula_segura' => '',
        'fundamento_aula_segura' => '',
        'requiere_denuncia' => 0,
        'entidad_derivacion' => '',
        'plazo_revision' => '',
        'observaciones_normativas' => '',
        'creado_por' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function ver_cargar_clasificacion_normativa(PDO $pdo, int $casoId, int $colegioId): array
{
    $default = ver_clasificacion_normativa_default();

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM caso_clasificacion_normativa
            WHERE caso_id = ? AND colegio_id = ? LIMIT 1
        ");
        $stmt->execute([$casoId, $colegioId]);
        $row = $stmt->fetch();
        return $row ? array_merge($default, $row) : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function ver_resumen_clasificacion_normativa(array $clasificacion, array $caso): array
{
    $tieneNormativa = !empty($clasificacion['id']);
    $posibleAulaSegura = (int)($clasificacion['posible_aula_segura'] ?? 0) === 1;
    $requiereDenuncia = (int)($clasificacion['requiere_denuncia'] ?? 0) === 1;
    $gravedad = (string)($clasificacion['gravedad'] ?? 'media');

    if (!$tieneNormativa) {
        return [
            'estado_texto' => 'Clasificación normativa pendiente',
            'estado_clase' => 'warn',
            'accion' => 'Completar clasificación normativa del expediente.',
            'tipo_conducta' => 'Sin clasificar',
            'gravedad' => 'Sin dato',
            'aula_segura' => false,
            'requiere_denuncia' => false,
        ];
    }

    if ($posibleAulaSegura) {
        $estadoTexto = 'Posible Aula Segura';
        $estadoClase = 'danger';
        $accion = 'Revisar causal, plazos y notificaciones del procedimiento Aula Segura.';
    } elseif ($requiereDenuncia) {
        $estadoTexto = 'Requiere derivación/denuncia';
        $estadoClase = 'danger';
        $accion = 'Verificar denuncia o derivación a entidad competente.';
    } elseif (in_array($gravedad, ['alta', 'critica'], true)) {
        $estadoTexto = 'Caso de alta criticidad';
        $estadoClase = 'danger';
        $accion = 'Priorizar medidas de resguardo, seguimiento y comunicación institucional.';
    } else {
        $estadoTexto = 'Clasificación normativa registrada';
        $estadoClase = 'ok';
        $accion = 'Mantener seguimiento conforme a reglamento interno y medidas aplicadas.';
    }

    return [
        'estado_texto' => $estadoTexto,
        'estado_clase' => $estadoClase,
        'accion' => $accion,
        'tipo_conducta' => (string)($clasificacion['tipo_conducta'] ?: ($caso['clasificacion_ia'] ?? 'Sin clasificar')),
        'gravedad' => caso_label($gravedad),
        'aula_segura' => $posibleAulaSegura,
        'requiere_denuncia' => $requiereDenuncia,
    ];
}