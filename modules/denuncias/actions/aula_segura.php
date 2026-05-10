<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Actions — Aula Segura
 * Archivo completo alineado a Fase 10A.
 *
 * Este archivo contiene la lógica operacional de Aula Segura para uso modular.
 * Requiere contexto ya cargado: $pdo, $accion, $caso, $casoId, $colegioId, $user, $userId.
 */

if (!function_exists('aula_fecha_sql')) {
    function aula_fecha_sql(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException('Una de las fechas de Aula Segura no tiene formato válido.');
        }
        return $value;
    }
}

if (!function_exists('aula_datetime_sql')) {
    function aula_datetime_sql(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if (!$ts) {
            throw new RuntimeException('Una de las fechas/hora de Aula Segura no tiene formato válido.');
        }
        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('aula_sumar_dias_habiles')) {
    function aula_sumar_dias_habiles(string $fecha, int $dias): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
        if (!$dt) {
            throw new RuntimeException('No fue posible calcular el plazo de Aula Segura.');
        }
        $sumados = 0;
        while ($sumados < $dias) {
            $dt = $dt->modify('+1 day');
            $diaSemana = (int)$dt->format('N');
            if ($diaSemana <= 5) {
                $sumados++;
            }
        }
        return $dt->format('Y-m-d');
    }
}

if (!function_exists('aula_bool')) {
    function aula_bool(string $name): int
    {
        return isset($_POST[$name]) ? 1 : 0;
    }
}

if (!function_exists('aula_historial')) {
    function aula_historial(PDO $pdo, int $casoId, int $colegioId, ?int $aulaId, string $accion, ?string $estadoAnterior, string $estadoNuevo, string $detalle, int $userId): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO caso_aula_segura_historial (
                    caso_id,
                    caso_aula_segura_id,
                    colegio_id,
                    accion,
                    estado_anterior,
                    estado_nuevo,
                    detalle,
                    usuario_id,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $casoId,
                $aulaId,
                $colegioId,
                $accion,
                $estadoAnterior,
                $estadoNuevo,
                $detalle,
                $userId > 0 ? $userId : null,
            ]);
        } catch (Throwable $e) {
            // El historial de Aula Segura no debe bloquear el flujo principal.
        }
    }
}

if ($accion === 'marcar_posible_aula_segura') {
    if (!Auth::canOperate()) {
        throw new RuntimeException('No tienes permisos para realizar esta acción.');
    }

    // Acción idempotente: si ya está marcada, solo vuelve a la pestaña.
    if ((int)($caso['posible_aula_segura'] ?? 0) === 1) {
        caso_redirect($casoId, 'aula_segura');
    }

    $aulaId = null;
    $txActiva = false;

    try {
        $pdo->beginTransaction();
        $txActiva = true;

        $stmt = $pdo->prepare("
            UPDATE casos
            SET posible_aula_segura = 1,
                aula_segura_estado = 'posible',
                aula_segura_marcado_por = ?,
                aula_segura_marcado_at = COALESCE(aula_segura_marcado_at, NOW()),
                updated_at = NOW()
            WHERE id = ?
              AND colegio_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId ?: null, $casoId, $colegioId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No fue posible activar Aula Segura para este expediente.');
        }

        $stmtActual = $pdo->prepare("
            SELECT id, estado
            FROM caso_aula_segura
            WHERE caso_id = ?
              AND colegio_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtActual->execute([$casoId, $colegioId]);
        $actualAula = $stmtActual->fetch();

        if ($actualAula) {
            $aulaId = (int)$actualAula['id'];
            $pdo->prepare("
                UPDATE caso_aula_segura
                SET posible_aula_segura = 1,
                    estado = CASE
                        WHEN estado IS NULL OR estado = '' OR estado = 'no_aplica' THEN 'posible'
                        ELSE estado
                    END,
                    updated_at = NOW()
                WHERE id = ?
                  AND caso_id = ?
                  AND colegio_id = ?
                LIMIT 1
            ")->execute([$aulaId, $casoId, $colegioId]);
        } else {
            $pdo->prepare("
                INSERT INTO caso_aula_segura (
                    colegio_id,
                    caso_id,
                    posible_aula_segura,
                    estado,
                    creado_por,
                    created_at,
                    updated_at
                ) VALUES (?, ?, 1, 'posible', ?, NOW(), NOW())
            ")->execute([$colegioId, $casoId, $userId ?: null]);
            $aulaId = (int)$pdo->lastInsertId();
        }

        aula_historial(
            $pdo,
            $casoId,
            $colegioId,
            $aulaId,
            'marcar_posible_aula_segura',
            'no_aplica',
            'posible',
            'Posible Aula Segura activada retroactivamente desde la pestaña Aula Segura por un funcionario autorizado.',
            $userId
        );

        $pdo->prepare("
            INSERT INTO caso_historial (
                caso_id,
                tipo_evento,
                titulo,
                detalle,
                user_id
            ) VALUES (?, 'aula_segura', 'Aula Segura marcada retroactivamente', ?, ?)
        ")->execute([
            $casoId,
            'Posible Aula Segura fue activada retroactivamente desde la pestaña Aula Segura. La decisión formal deberá fundarse por Dirección en el formulario del procedimiento.',
            $userId ?: null,
        ]);

        registrar_hito($pdo, $casoId, $colegioId, 105, $userId);

        $pdo->commit();
        $txActiva = false;
    } catch (Throwable $e) {
        if ($txActiva && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    registrar_bitacora(
        'denuncias',
        'marcar_aula_segura_retroactivo',
        'casos',
        $casoId,
        'Aula Segura marcada retroactivamente en caso ' . (string)($caso['numero_caso'] ?? $casoId)
    );

    if (function_exists('invalidar_cache_dashboard')) {
        invalidar_cache_dashboard($colegioId);
    }

    caso_redirect($casoId, 'aula_segura');
}

if ($accion === 'actualizar_aula_segura') {
    if ((int)($caso['posible_aula_segura'] ?? 0) !== 1) {
        throw new RuntimeException('Este caso no fue marcado como posible Aula Segura en la denuncia. No corresponde ingresar datos en esta pestaña.');
    }

    $rolActual = (string)($user['rol_codigo'] ?? '');
    $puedeResolverAula = in_array($rolActual, ['superadmin', 'director', 'admin_sistema'], true) || Auth::can('admin_sistema');

    $estadosPermitidos = [
        'posible',
        'en_evaluacion',
        'descartado',
        'procedimiento_iniciado',
        'suspension_cautelar',
        'resuelto',
        'reconsideracion',
        'cerrado',
    ];

    $estadoNuevo = clean((string)($_POST['estado'] ?? 'en_evaluacion'));
    if (!in_array($estadoNuevo, $estadosPermitidos, true)) {
        $estadoNuevo = 'en_evaluacion';
    }

    if (in_array($estadoNuevo, ['descartado', 'procedimiento_iniciado', 'suspension_cautelar', 'resuelto', 'reconsideracion', 'cerrado'], true) && !$puedeResolverAula) {
        throw new RuntimeException('Solo Dirección o un administrador autorizado puede iniciar, descartar, resolver o cerrar Aula Segura.');
    }

    $causalAgresionSexual = aula_bool('causal_agresion_sexual');
    $causalAgresionFisicaLesiones = aula_bool('causal_agresion_fisica_lesiones');
    $causalArmas = aula_bool('causal_armas');
    $causalArtefactos = aula_bool('causal_artefactos_incendiarios');
    $causalInfraestructura = aula_bool('causal_infraestructura_esencial');
    $causalGraveReglamento = aula_bool('causal_grave_reglamento');

    $hayCausal = ($causalAgresionSexual + $causalAgresionFisicaLesiones + $causalArmas + $causalArtefactos + $causalInfraestructura + $causalGraveReglamento) > 0;
    if (!$hayCausal) {
        throw new RuntimeException('Debe marcar al menos una causal de Aula Segura.');
    }

    $descripcionHecho = clean((string)($_POST['descripcion_hecho'] ?? ''));
    $fuenteInformacion = clean((string)($_POST['fuente_informacion'] ?? ''));
    $evidenciaInicial = clean((string)($_POST['evidencia_inicial'] ?? ''));
    $faltaReglamento = clean((string)($_POST['falta_reglamento'] ?? ''));
    $fundamentoProporcionalidad = clean((string)($_POST['fundamento_proporcionalidad'] ?? ''));
    $decisionDirector = clean((string)($_POST['decision_director'] ?? ''));
    $observaciones = clean((string)($_POST['observaciones'] ?? ''));

    if ($descripcionHecho === '' && in_array($estadoNuevo, ['procedimiento_iniciado', 'suspension_cautelar', 'resuelto', 'reconsideracion', 'cerrado'], true)) {
        throw new RuntimeException('Debe registrar una descripción objetiva del hecho antes de iniciar o resolver el procedimiento.');
    }

    if ($causalGraveReglamento === 1 && ($faltaReglamento === '' || $fundamentoProporcionalidad === '')) {
        throw new RuntimeException('Si se marca conducta grave o gravísima del Reglamento Interno, debe indicar la falta y el fundamento de proporcionalidad.');
    }

    if ($estadoNuevo === 'descartado' && $observaciones === '') {
        throw new RuntimeException('Para descartar Aula Segura debe dejar una observación o fundamento.');
    }

    $fechaEvaluacion = aula_fecha_sql($_POST['fecha_evaluacion_directiva'] ?? null);
    if ($fechaEvaluacion === null && in_array($estadoNuevo, ['en_evaluacion', 'descartado', 'procedimiento_iniciado', 'suspension_cautelar', 'resuelto', 'reconsideracion', 'cerrado'], true)) {
        $fechaEvaluacion = date('Y-m-d');
    }

    $fechaInicioProcedimiento = aula_fecha_sql($_POST['fecha_inicio_procedimiento'] ?? null);
    if (in_array($estadoNuevo, ['procedimiento_iniciado', 'suspension_cautelar', 'resuelto', 'reconsideracion', 'cerrado'], true) && $fechaInicioProcedimiento === null) {
        throw new RuntimeException('Debe indicar fecha de inicio del procedimiento.');
    }

    $comunicacionApoderadoAt = aula_datetime_sql($_POST['comunicacion_apoderado_at'] ?? null);
    $medioComunicacionApoderado = clean((string)($_POST['medio_comunicacion_apoderado'] ?? ''));
    $observacionComunicacionApoderado = clean((string)($_POST['observacion_comunicacion_apoderado'] ?? ''));

    $suspensionCautelar = aula_bool('suspension_cautelar');
    $fechaNotificacionSuspension = aula_fecha_sql($_POST['fecha_notificacion_suspension'] ?? null);
    $fechaLimiteResolucion = aula_fecha_sql($_POST['fecha_limite_resolucion'] ?? null);
    $fundamentoSuspension = clean((string)($_POST['fundamento_suspension'] ?? ''));

    if ($suspensionCautelar === 1) {
        if ($fechaNotificacionSuspension === null) {
            throw new RuntimeException('Si registra suspensión cautelar, debe indicar fecha de notificación.');
        }

        if ($fundamentoSuspension === '') {
            throw new RuntimeException('Si registra suspensión cautelar, debe indicar fundamento.');
        }

        if ($fechaLimiteResolucion === null) {
            $fechaLimiteResolucion = aula_sumar_dias_habiles($fechaNotificacionSuspension, 10);
        }

        if (in_array($estadoNuevo, ['posible', 'en_evaluacion', 'procedimiento_iniciado'], true)) {
            $estadoNuevo = 'suspension_cautelar';
        }
    }

    $descargosRecibidos = aula_bool('descargos_recibidos');
    $fechaDescargos = aula_fecha_sql($_POST['fecha_descargos'] ?? null);
    $observacionDescargos = clean((string)($_POST['observacion_descargos'] ?? ''));

    if ($descargosRecibidos === 1 && $fechaDescargos === null) {
        throw new RuntimeException('Si registra descargos recibidos, debe indicar fecha de descargos.');
    }

    $resolucion = clean((string)($_POST['resolucion'] ?? ''));
    $fechaResolucion = aula_fecha_sql($_POST['fecha_resolucion'] ?? null);
    $fechaNotificacionResolucion = aula_fecha_sql($_POST['fecha_notificacion_resolucion'] ?? null);
    $fundamentoResolucion = clean((string)($_POST['fundamento_resolucion'] ?? ''));

    if ($resolucion !== '') {
        if ($fechaResolucion === null) {
            throw new RuntimeException('Si registra resolución, debe indicar fecha de resolución.');
        }
        if ($fundamentoResolucion === '') {
            throw new RuntimeException('Si registra resolución, debe indicar fundamento de la resolución.');
        }
        if (!in_array($estadoNuevo, ['reconsideracion', 'cerrado'], true)) {
            $estadoNuevo = 'resuelto';
        }
    }

    $reconsideracionPresentada = aula_bool('reconsideracion_presentada');
    $fechaReconsideracion = aula_fecha_sql($_POST['fecha_reconsideracion'] ?? null);
    $fechaLimiteReconsideracion = aula_fecha_sql($_POST['fecha_limite_reconsideracion'] ?? null);
    $fechaResolucionReconsideracion = aula_fecha_sql($_POST['fecha_resolucion_reconsideracion'] ?? null);
    $resultadoReconsideracion = clean((string)($_POST['resultado_reconsideracion'] ?? ''));
    $fundamentoReconsideracion = clean((string)($_POST['fundamento_reconsideracion'] ?? ''));

    if ($reconsideracionPresentada === 1) {
        if ($fechaReconsideracion === null) {
            throw new RuntimeException('Si registra reconsideración, debe indicar fecha de presentación.');
        }
        if (!in_array($estadoNuevo, ['cerrado'], true)) {
            $estadoNuevo = 'reconsideracion';
        }
    }

    $comunicacionSupereduc = aula_bool('comunicacion_supereduc');
    $fechaComunicacionSupereduc = aula_fecha_sql($_POST['fecha_comunicacion_supereduc'] ?? null);
    $medioComunicacionSupereduc = clean((string)($_POST['medio_comunicacion_supereduc'] ?? ''));
    $observacionSupereduc = clean((string)($_POST['observacion_supereduc'] ?? ''));

    if ($comunicacionSupereduc === 1 && $fechaComunicacionSupereduc === null) {
        throw new RuntimeException('Si registra comunicación a Supereduc, debe indicar fecha de comunicación.');
    }

    $stmtActual = $pdo->prepare("\n                SELECT *\n                FROM caso_aula_segura\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n                ORDER BY id DESC\n                LIMIT 1\n            ");
    $stmtActual->execute([$casoId, $colegioId]);
    $actualAula = $stmtActual->fetch();
    $estadoAnterior = $actualAula ? (string)($actualAula['estado'] ?? 'posible') : (string)($caso['aula_segura_estado'] ?? 'posible');

    $pdo->beginTransaction();

    if ($actualAula) {
        $aulaId = (int)$actualAula['id'];

        $stmt = $pdo->prepare("\n                    UPDATE caso_aula_segura\n                    SET causal_agresion_sexual = ?,\n                        causal_agresion_fisica_lesiones = ?,\n                        causal_armas = ?,\n                        causal_artefactos_incendiarios = ?,\n                        causal_infraestructura_esencial = ?,\n                        causal_grave_reglamento = ?,\n                        descripcion_hecho = ?,\n                        fuente_informacion = ?,\n                        evidencia_inicial = ?,\n                        falta_reglamento = ?,\n                        fundamento_proporcionalidad = ?,\n                        estado = ?,\n                        decision_director = ?,\n                        fecha_evaluacion_directiva = ?,\n                        evaluado_por = CASE WHEN ? IS NOT NULL THEN ? ELSE evaluado_por END,\n                        fecha_inicio_procedimiento = ?,\n                        iniciado_por = CASE WHEN ? IS NOT NULL THEN ? ELSE iniciado_por END,\n                        comunicacion_apoderado_at = ?,\n                        medio_comunicacion_apoderado = ?,\n                        observacion_comunicacion_apoderado = ?,\n                        suspension_cautelar = ?,\n                        fecha_notificacion_suspension = ?,\n                        fecha_limite_resolucion = ?,\n                        fundamento_suspension = ?,\n                        descargos_recibidos = ?,\n                        fecha_descargos = ?,\n                        observacion_descargos = ?,\n                        resolucion = ?,\n                        fecha_resolucion = ?,\n                        fecha_notificacion_resolucion = ?,\n                        fundamento_resolucion = ?,\n                        reconsideracion_presentada = ?,\n                        fecha_reconsideracion = ?,\n                        fecha_limite_reconsideracion = ?,\n                        fecha_resolucion_reconsideracion = ?,\n                        resultado_reconsideracion = ?,\n                        fundamento_reconsideracion = ?,\n                        comunicacion_supereduc = ?,\n                        fecha_comunicacion_supereduc = ?,\n                        medio_comunicacion_supereduc = ?,\n                        observacion_supereduc = ?,\n                        observaciones = ?,\n                        updated_at = NOW()\n                    WHERE id = ?\n                      AND caso_id = ?\n                      AND colegio_id = ?\n                    LIMIT 1\n                ");
        $stmt->execute([
            $causalAgresionSexual,
            $causalAgresionFisicaLesiones,
            $causalArmas,
            $causalArtefactos,
            $causalInfraestructura,
            $causalGraveReglamento,
            $descripcionHecho !== '' ? $descripcionHecho : null,
            $fuenteInformacion !== '' ? $fuenteInformacion : null,
            $evidenciaInicial !== '' ? $evidenciaInicial : null,
            $faltaReglamento !== '' ? $faltaReglamento : null,
            $fundamentoProporcionalidad !== '' ? $fundamentoProporcionalidad : null,
            $estadoNuevo,
            $decisionDirector !== '' ? $decisionDirector : null,
            $fechaEvaluacion,
            $fechaEvaluacion,
            $userId ?: null,
            $fechaInicioProcedimiento,
            $fechaInicioProcedimiento,
            $userId ?: null,
            $comunicacionApoderadoAt,
            $medioComunicacionApoderado !== '' ? $medioComunicacionApoderado : null,
            $observacionComunicacionApoderado !== '' ? $observacionComunicacionApoderado : null,
            $suspensionCautelar,
            $fechaNotificacionSuspension,
            $fechaLimiteResolucion,
            $fundamentoSuspension !== '' ? $fundamentoSuspension : null,
            $descargosRecibidos,
            $fechaDescargos,
            $observacionDescargos !== '' ? $observacionDescargos : null,
            $resolucion !== '' ? $resolucion : null,
            $fechaResolucion,
            $fechaNotificacionResolucion,
            $fundamentoResolucion !== '' ? $fundamentoResolucion : null,
            $reconsideracionPresentada,
            $fechaReconsideracion,
            $fechaLimiteReconsideracion,
            $fechaResolucionReconsideracion,
            $resultadoReconsideracion !== '' ? $resultadoReconsideracion : null,
            $fundamentoReconsideracion !== '' ? $fundamentoReconsideracion : null,
            $comunicacionSupereduc,
            $fechaComunicacionSupereduc,
            $medioComunicacionSupereduc !== '' ? $medioComunicacionSupereduc : null,
            $observacionSupereduc !== '' ? $observacionSupereduc : null,
            $observaciones !== '' ? $observaciones : null,
            $aulaId,
            $casoId,
            $colegioId,
        ]);
    } else {
        $stmt = $pdo->prepare("\n                    INSERT INTO caso_aula_segura (\n                        colegio_id, caso_id, posible_aula_segura,\n                        causal_agresion_sexual, causal_agresion_fisica_lesiones, causal_armas,\n                        causal_artefactos_incendiarios, causal_infraestructura_esencial, causal_grave_reglamento,\n                        descripcion_hecho, fuente_informacion, evidencia_inicial,\n                        falta_reglamento, fundamento_proporcionalidad, estado, decision_director,\n                        fecha_evaluacion_directiva, evaluado_por, fecha_inicio_procedimiento, iniciado_por,\n                        comunicacion_apoderado_at, medio_comunicacion_apoderado, observacion_comunicacion_apoderado,\n                        suspension_cautelar, fecha_notificacion_suspension, fecha_limite_resolucion, fundamento_suspension,\n                        descargos_recibidos, fecha_descargos, observacion_descargos,\n                        resolucion, fecha_resolucion, fecha_notificacion_resolucion, fundamento_resolucion,\n                        reconsideracion_presentada, fecha_reconsideracion, fecha_limite_reconsideracion,\n                        fecha_resolucion_reconsideracion, resultado_reconsideracion, fundamento_reconsideracion,\n                        comunicacion_supereduc, fecha_comunicacion_supereduc, medio_comunicacion_supereduc, observacion_supereduc,\n                        observaciones, creado_por, created_at, updated_at\n                    ) VALUES (\n                        ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()\n                    )\n                ");
        $stmt->execute([
            $colegioId,
            $casoId,
            $causalAgresionSexual,
            $causalAgresionFisicaLesiones,
            $causalArmas,
            $causalArtefactos,
            $causalInfraestructura,
            $causalGraveReglamento,
            $descripcionHecho !== '' ? $descripcionHecho : null,
            $fuenteInformacion !== '' ? $fuenteInformacion : null,
            $evidenciaInicial !== '' ? $evidenciaInicial : null,
            $faltaReglamento !== '' ? $faltaReglamento : null,
            $fundamentoProporcionalidad !== '' ? $fundamentoProporcionalidad : null,
            $estadoNuevo,
            $decisionDirector !== '' ? $decisionDirector : null,
            $fechaEvaluacion,
            $fechaEvaluacion ? ($userId ?: null) : null,
            $fechaInicioProcedimiento,
            $fechaInicioProcedimiento ? ($userId ?: null) : null,
            $comunicacionApoderadoAt,
            $medioComunicacionApoderado !== '' ? $medioComunicacionApoderado : null,
            $observacionComunicacionApoderado !== '' ? $observacionComunicacionApoderado : null,
            $suspensionCautelar,
            $fechaNotificacionSuspension,
            $fechaLimiteResolucion,
            $fundamentoSuspension !== '' ? $fundamentoSuspension : null,
            $descargosRecibidos,
            $fechaDescargos,
            $observacionDescargos !== '' ? $observacionDescargos : null,
            $resolucion !== '' ? $resolucion : null,
            $fechaResolucion,
            $fechaNotificacionResolucion,
            $fundamentoResolucion !== '' ? $fundamentoResolucion : null,
            $reconsideracionPresentada,
            $fechaReconsideracion,
            $fechaLimiteReconsideracion,
            $fechaResolucionReconsideracion,
            $resultadoReconsideracion !== '' ? $resultadoReconsideracion : null,
            $fundamentoReconsideracion !== '' ? $fundamentoReconsideracion : null,
            $comunicacionSupereduc,
            $fechaComunicacionSupereduc,
            $medioComunicacionSupereduc !== '' ? $medioComunicacionSupereduc : null,
            $observacionSupereduc !== '' ? $observacionSupereduc : null,
            $observaciones !== '' ? $observaciones : null,
            $userId ?: null,
        ]);
        $aulaId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("\n                UPDATE casos\n                SET aula_segura_estado = ?,\n                    updated_at = NOW()\n                WHERE id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
    $stmt->execute([$estadoNuevo, $casoId, $colegioId]);

    aula_historial(
        $pdo,
        $casoId,
        $colegioId,
        $aulaId,
        'actualizar_aula_segura',
        $estadoAnterior,
        $estadoNuevo,
        'Se actualizó la evaluación/procedimiento Aula Segura. Estado: ' . caso_label($estadoNuevo) . '.',
        $userId
    );

    $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'aula_segura', 'Aula Segura actualizada', ?, ?)\n            ");
    $stmt->execute([
        $casoId,
        'Se actualizó Aula Segura. Estado: ' . caso_label($estadoNuevo) . '.',
        $userId ?: null,
    ]);

    registrar_bitacora(
        'denuncias',
        'actualizar_aula_segura',
        'caso_aula_segura',
        $aulaId,
        'Aula Segura actualizada en expediente.'
    );

    $pdo->commit();

    caso_redirect($casoId, 'aula_segura');
}
