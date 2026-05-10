<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Queries — seguimiento
 * Fase 2C — extraído de ver_queries.php
 */

function ver_seguimiento_default(): array
{
    return [
        'id' => null,
        'caso_id' => null,
        'colegio_id' => null,
        'observacion_avance' => '',
        'proxima_revision' => '',
        'estado_seguimiento' => 'en_proceso',
        'medidas_preventivas' => '',
        'cumplimiento' => 'en_proceso',
        'comunicacion_apoderado_modalidad' => '',
        'comunicacion_apoderado_fecha' => '',
        'notas_comunicacion' => '',
        'actualizado_por' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function ver_seguimiento_schema_status(PDO $pdo): array
{
    try {
        $pdo->query("SELECT 1 FROM caso_seguimiento LIMIT 0");
        $pdo->query("SELECT 1 FROM caso_seguimiento_participantes LIMIT 0");
        return ['ok' => true, 'missing' => [], 'message' => 'Estructura de seguimiento disponible.'];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'missing' => [$e->getMessage()],
            'message' => 'Estructura de seguimiento no disponible: ' . $e->getMessage(),
        ];
    }
}

function ver_cargar_seguimiento(PDO $pdo, int $casoId, int $colegioId): array
{
    $default = ver_seguimiento_default();
    $default['caso_id'] = $casoId;
    $default['colegio_id'] = $colegioId;

    try {
        $stmt = $pdo->prepare("
            SELECT cs.*
            FROM caso_seguimiento cs
            WHERE cs.caso_id = ?
              AND cs.colegio_id = ?
            ORDER BY cs.id DESC
            LIMIT 1
        ");
        $stmt->execute([$casoId, $colegioId]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        $row = array_merge($default, $row);

        if (($row['observacion_avance'] ?? '') === '' && !empty($row['objetivo_general'])) {
            $row['observacion_avance'] = (string)$row['objetivo_general'];
        }
        if (($row['estado_seguimiento'] ?? '') === '' && !empty($row['estado'])) {
            $row['estado_seguimiento'] = (string)$row['estado'] === 'cerrado' ? 'cerrado' : 'en_proceso';
        }

        return $row;
    } catch (Throwable $e) {
        return $default;
    }
}

function ver_cargar_seguimiento_participantes(PDO $pdo, int $casoId, int $colegioId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT csp.*
            FROM caso_seguimiento_participantes csp
            WHERE csp.caso_id = ?
              AND csp.colegio_id = ?
            ORDER BY csp.id ASC
        ");
        $stmt->execute([$casoId, $colegioId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['participante_id']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function ver_resumen_seguimiento(array $seguimiento, array $seguimientoParticipantes): array
{
    $hoy = new DateTimeImmutable('today');
    $proximaRevisionRaw = trim((string)($seguimiento['proxima_revision'] ?? ''));
    $diasRevision = null;
    $revisionClase = 'soft';
    $revisionTexto = 'Sin fecha de revisión';

    if ($proximaRevisionRaw !== '') {
        try {
            $proximaRevision = new DateTimeImmutable(substr($proximaRevisionRaw, 0, 10));
            $diasRevision = (int)$hoy->diff($proximaRevision)->format('%r%a');

            if ($diasRevision < 0) {
                $revisionClase = 'danger';
                $revisionTexto = 'Seguimiento vencido hace ' . abs($diasRevision) . ' día(s)';
            } elseif ($diasRevision <= 3) {
                $revisionClase = 'warn';
                $revisionTexto = 'Revisión próxima en ' . $diasRevision . ' día(s)';
            } else {
                $revisionClase = 'ok';
                $revisionTexto = 'Revisión programada';
            }
        } catch (Throwable $e) {
            $revisionClase = 'warn';
            $revisionTexto = 'Fecha de revisión inválida';
        }
    }

    $pendientes = 0;
    $cumplidos = 0;
    $noCumplidos = 0;

    foreach ($seguimientoParticipantes as $fila) {
        $estado = (string)($fila['estado'] ?? 'pendiente');
        if (in_array($estado, ['pendiente', 'en_proceso'], true)) {
            $pendientes++;
        } elseif ($estado === 'cumplido') {
            $cumplidos++;
        } elseif ($estado === 'no_cumplido') {
            $noCumplidos++;
        }
    }

    $cumplimiento = (string)($seguimiento['cumplimiento'] ?? 'en_proceso');
    $cumplimientoClase = match ($cumplimiento) {
        'cumplido', 'no_aplica' => 'ok',
        'no_cumplido' => 'danger',
        'pendiente', 'en_proceso' => 'warn',
        default => 'soft',
    };

    $estado = (string)($seguimiento['estado_seguimiento'] ?? 'en_proceso');
    $estadoClase = match ($estado) {
        'cerrado', 'resuelto' => 'ok',
        'en_revision', 'en_proceso' => 'warn',
        'pendiente' => 'danger',
        default => 'soft',
    };

    return [
        'estado' => $estado,
        'estado_clase' => $estadoClase,
        'cumplimiento' => $cumplimiento,
        'cumplimiento_clase' => $cumplimientoClase,
        'revision_texto' => $revisionTexto,
        'revision_clase' => $revisionClase,
        'dias_revision' => $diasRevision,
        'total_participantes' => count($seguimientoParticipantes),
        'pendientes' => $pendientes,
        'cumplidos' => $cumplidos,
        'no_cumplidos' => $noCumplidos,
        'tiene_medidas' => trim((string)($seguimiento['medidas_preventivas'] ?? '')) !== '',
        'tiene_comunicacion' => trim((string)($seguimiento['comunicacion_apoderado_modalidad'] ?? '')) !== ''
            || trim((string)($seguimiento['comunicacion_apoderado_fecha'] ?? '')) !== '',
    ];
}

function ver_cargar_plan_intervencion(PDO $pdo, int $casoId, int $colegioId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                pi.*,
                COALESCE(cp.nombre_referencial, '') AS nombre_participante,
                COALESCE(cp.rol_en_caso, '') AS condicion_participante
            FROM caso_plan_intervencion pi
            LEFT JOIN caso_participantes cp ON cp.id = pi.participante_id
            WHERE pi.caso_id = ?
              AND pi.colegio_id = ?
            ORDER BY
                CASE COALESCE(pi.estado, 'pendiente')
                    WHEN 'pendiente'  THEN 1
                    WHEN 'en_proceso' THEN 2
                    WHEN 'cumplida'   THEN 3
                    WHEN 'incumplida' THEN 4
                    WHEN 'no_aplica'  THEN 5
                    ELSE 6
                END,
                pi.id ASC
        ");
        $stmt->execute([$casoId, $colegioId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Resumen numérico de medidas preventivas para mostrar en la pestaña y badge.
 */

function ver_resumen_medidas_preventivas(array $medidas): array
{
    $total = count($medidas);
    $pendientes = 0;
    $enProceso  = 0;
    $cumplidas  = 0;
    $incumplidas = 0;
    $vencidas   = 0;
    $hoy = date('Y-m-d');

    foreach ($medidas as $m) {
        $estado = (string)($m['estado'] ?? 'pendiente');
        $fechaCompromiso = isset($m['fecha_compromiso']) ? substr((string)$m['fecha_compromiso'], 0, 10) : '';

        if ($estado === 'pendiente')   { $pendientes++; }
        if ($estado === 'en_proceso')  { $enProceso++; }
        if ($estado === 'cumplida')    { $cumplidas++; }
        if ($estado === 'incumplida')  { $incumplidas++; }

        // Vencida = tiene fecha_compromiso, está pasada y no está cumplida
        if ($fechaCompromiso !== '' && $fechaCompromiso < $hoy
            && !in_array($estado, ['cumplida', 'no_aplica'], true)) {
            $vencidas++;
        }
    }

    return [
        'total'      => $total,
        'pendientes' => $pendientes,
        'en_proceso' => $enProceso,
        'cumplidas'  => $cumplidas,
        'incumplidas'=> $incumplidas,
        'vencidas'   => $vencidas,
        'activas'    => $pendientes + $enProceso,
    ];
}

function ver_tipos_medida(): array
{
    return [
        'preventiva'       => 'Medida preventiva',
        'resguardo'        => 'Medida de resguardo',
        'apoyo_psicosocial'=> 'Apoyo psicosocial',
        'comunicacion'     => 'Comunicación apoderado',
        'sancion'          => 'Sanción disciplinaria',
        'derivacion'       => 'Derivación a red externa',
        'otra'             => 'Otra medida',
    ];
}

/**
 * Estados de cumplimiento de una medida.
 */

function ver_estados_medida(): array
{
    return [
        'pendiente'   => 'Pendiente',
        'en_proceso'  => 'En proceso',
        'cumplida'    => 'Cumplida',
        'incumplida'  => 'Incumplida',
        'no_aplica'   => 'No aplica',
    ];
}

/**
 * Carga el plan de intervención / medidas preventivas del caso.
 * Usa JOIN con casos para garantizar filtro por colegio incluso
 * si caso_plan_intervencion no tiene columna colegio_id.
 */