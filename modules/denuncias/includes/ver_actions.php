<?php
declare(strict_types=1);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));


        // ── Plan de Acción ─────────────────────────────────────────
        if ($accion === 'guardar_plan_accion') {
            if (!Auth::canOperate()) {
                throw new RuntimeException('No tiene permisos para registrar planes de acción.');
            }

            $pId       = (int)($_POST['participante_id']  ?? 0);
            $planTexto = trim((string)($_POST['plan_accion'] ?? ''));
            $medidasP  = trim((string)($_POST['medidas_preventivas'] ?? ''));
            $motivoV   = trim((string)($_POST['motivo_version'] ?? 'Plan inicial'));
            $esMod     = (int)($_POST['es_modificacion'] ?? 0);
            $planAntId = (int)($_POST['plan_anterior_id'] ?? 0);
            $userId2   = (int)(Auth::user()['id'] ?? 0);

            if ($pId <= 0) {
                throw new RuntimeException('Debe seleccionar un participante válido para el plan de acción.');
            }

            if ($planTexto === '') {
                throw new RuntimeException('El plan de acción es obligatorio.');
            }

            if (mb_strlen($planTexto, 'UTF-8') > 12000) {
                throw new RuntimeException('El plan de acción excede el largo permitido.');
            }

            if (mb_strlen($medidasP, 'UTF-8') > 12000) {
                throw new RuntimeException('Las medidas preventivas exceden el largo permitido.');
            }

            if ($motivoV === '') {
                $motivoV = $esMod === 1 ? 'Actualización del plan' : 'Plan inicial';
            }
            if (mb_strlen($motivoV, 'UTF-8') > 255) {
                $motivoV = mb_substr($motivoV, 0, 255, 'UTF-8');
            }

            $stmtParticipantePlan = $pdo->prepare("\n                SELECT\n                    cp.id,
                    cp.nombre_referencial,
                    cp.run,
                    cp.rol_en_caso,
                    cp.tipo_persona\n                FROM caso_participantes cp\n                INNER JOIN casos c ON c.id = cp.caso_id\n                WHERE cp.id = ?\n                  AND cp.caso_id = ?\n                  AND c.colegio_id = ?\n                LIMIT 1\n            ");
            $stmtParticipantePlan->execute([$pId, $casoId, $colegioId]);
            $participantePlan = $stmtParticipantePlan->fetch(PDO::FETCH_ASSOC);

            if (!$participantePlan) {
                throw new RuntimeException('El participante seleccionado no pertenece a este expediente.');
            }

            if ($planAntId > 0) {
                $stmtPlanAnterior = $pdo->prepare("\n                    SELECT id\n                    FROM caso_plan_accion\n                    WHERE id = ?\n                      AND caso_id = ?\n                      AND colegio_id = ?\n                      AND participante_id = ?\n                    LIMIT 1\n                ");
                $stmtPlanAnterior->execute([$planAntId, $casoId, $colegioId, $pId]);
                if (!$stmtPlanAnterior->fetchColumn()) {
                    throw new RuntimeException('El plan anterior no corresponde al expediente o participante seleccionado.');
                }
            }

            $pdo->beginTransaction();
            try {
                if ($esMod === 1 || $planAntId > 0) {
                    $stmtDesactivar = $pdo->prepare("\n                        UPDATE caso_plan_accion\n                        SET vigente = 0, updated_at = NOW()\n                        WHERE caso_id = ?\n                          AND colegio_id = ?\n                          AND participante_id = ?\n                          AND vigente = 1\n                    ");
                    $stmtDesactivar->execute([$casoId, $colegioId, $pId]);
                }

                $stmtVer = $pdo->prepare("\n                    SELECT COALESCE(MAX(version), 0) + 1\n                    FROM caso_plan_accion\n                    WHERE caso_id = ?\n                      AND colegio_id = ?\n                      AND participante_id = ?\n                ");
                $stmtVer->execute([$casoId, $colegioId, $pId]);
                $version = max(1, (int)$stmtVer->fetchColumn());

                $stmtInsertPlan = $pdo->prepare("\n                    INSERT INTO caso_plan_accion (\n                        caso_id, colegio_id, participante_id, plan_accion, medidas_preventivas,
                        version, vigente, motivo_version, estado_plan, creado_por, created_at, updated_at\n                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'activo', ?, NOW(), NOW())\n                ");
                $stmtInsertPlan->execute([
                    $casoId,
                    $colegioId,
                    $pId,
                    $planTexto,
                    $medidasP !== '' ? $medidasP : null,
                    $version,
                    $motivoV,
                    $userId2 ?: null,
                ]);
                $planAccionId = (int)$pdo->lastInsertId();

                $detalleHistorial = 'Se registró plan de acción v' . $version . ' para ' .
                    (string)($participantePlan['nombre_referencial'] ?? 'participante') .
                    ' (' . (string)($participantePlan['rol_en_caso'] ?? 'sin condición') . ').';

                if ($medidasP !== '') {
                    $detalleHistorial .= ' Incluye medidas preventivas asociadas.';
                }

                $stmtHistPlan = $pdo->prepare("\n                    INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)\n                    VALUES (?, 'plan_accion', 'Plan de acción registrado', ?, ?)\n                ");
                $stmtHistPlan->execute([$casoId, $detalleHistorial, $userId2 ?: null]);

                registrar_hito($pdo, $casoId, $colegioId, 103, $userId2);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            registrar_bitacora('denuncias', 'plan_accion', 'caso_plan_accion',
                $planAccionId, "Plan de acción v{$version} guardado.");

            if (function_exists('invalidar_cache_dashboard')) {
                invalidar_cache_dashboard($colegioId);
            }

            caso_redirect($casoId, 'plan_accion');
        }

        // ── Sesión de Seguimiento ───────────────────────────────────
        if ($accion === 'guardar_sesion_seguimiento') {
            if (!Auth::canOperate()) {
                throw new RuntimeException('No tiene permisos para registrar sesiones de seguimiento.');
            }

            $pId        = (int)($_POST['participante_id'] ?? 0);
            $planIdRaw  = (int)($_POST['plan_accion_id'] ?? 0);
            $planId     = $planIdRaw > 0 ? $planIdRaw : null;
            $obs        = trim((string)($_POST['observacion_avance'] ?? ''));
            $medidasSes = trim((string)($_POST['medidas_sesion'] ?? ''));
            $estadoCaso = trim((string)($_POST['estado_caso'] ?? 'en_proceso'));
            $cumpl      = trim((string)($_POST['cumplimiento_plan'] ?? 'en_proceso'));
            $proxRev    = trim((string)($_POST['proxima_revision'] ?? ''));
            $comApo     = trim((string)($_POST['comunicacion_apoderado'] ?? ''));
            $fechaCom   = trim((string)($_POST['fecha_comunicacion_apoderado'] ?? ''));
            $notasCom   = trim((string)($_POST['notas_comunicacion'] ?? ''));
            $userId2    = (int)(Auth::user()['id'] ?? 0);

            if ($pId <= 0) {
                throw new RuntimeException('Debe seleccionar un participante válido para la sesión.');
            }

            if ($obs === '') {
                throw new RuntimeException('La observación de avance es obligatoria.');
            }

            if (mb_strlen($obs, 'UTF-8') > 12000) {
                throw new RuntimeException('La observación de avance excede el largo permitido.');
            }

            if (mb_strlen($medidasSes, 'UTF-8') > 12000) {
                throw new RuntimeException('Las medidas de la sesión exceden el largo permitido.');
            }

            $estadosCasoPermitidos = ['en_proceso', 'en_revision', 'resuelto', 'cerrado'];
            if (!in_array($estadoCaso, $estadosCasoPermitidos, true)) {
                $estadoCaso = 'en_proceso';
            }

            $cumplimientosPermitidos = ['en_proceso', 'parcial', 'cumplido', 'no_cumplido'];
            if (!in_array($cumpl, $cumplimientosPermitidos, true)) {
                $cumpl = 'en_proceso';
            }

            $modalidadesPermitidas = ['', 'presencial', 'telefono', 'correo', 'whatsapp', 'libreta', 'no_corresponde'];
            if (!in_array($comApo, $modalidadesPermitidas, true)) {
                $comApo = '';
            }

            if ($proxRev !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $proxRev)) {
                throw new RuntimeException('La próxima revisión no tiene formato válido.');
            }

            if ($fechaCom !== '' && strtotime($fechaCom) === false) {
                throw new RuntimeException('La fecha de comunicación al apoderado no tiene formato válido.');
            }

            if (mb_strlen($notasCom, 'UTF-8') > 12000) {
                throw new RuntimeException('Las notas de comunicación exceden el largo permitido.');
            }

            $stmtParticipanteSesion = $pdo->prepare("\n                SELECT\n                    cp.id,
                    cp.nombre_referencial,
                    cp.run,
                    cp.rol_en_caso,
                    cp.tipo_persona\n                FROM caso_participantes cp\n                INNER JOIN casos c ON c.id = cp.caso_id\n                WHERE cp.id = ?\n                  AND cp.caso_id = ?\n                  AND c.colegio_id = ?\n                LIMIT 1\n            ");
            $stmtParticipanteSesion->execute([$pId, $casoId, $colegioId]);
            $participanteSesion = $stmtParticipanteSesion->fetch(PDO::FETCH_ASSOC);

            if (!$participanteSesion) {
                throw new RuntimeException('El participante seleccionado no pertenece a este expediente.');
            }

            if ($planId !== null) {
                $stmtPlanSesion = $pdo->prepare("\n                    SELECT id\n                    FROM caso_plan_accion\n                    WHERE id = ?\n                      AND caso_id = ?\n                      AND colegio_id = ?\n                      AND participante_id = ?\n                    LIMIT 1\n                ");
                $stmtPlanSesion->execute([$planId, $casoId, $colegioId, $pId]);
                if (!$stmtPlanSesion->fetchColumn()) {
                    throw new RuntimeException('El plan asociado no pertenece al expediente o participante seleccionado.');
                }
            }

            $sessionId = 0;

            $pdo->beginTransaction();
            try {
                $stmtInsertSesion = $pdo->prepare("\n                    INSERT INTO caso_seguimiento_sesion (\n                        caso_id, colegio_id, participante_id, plan_accion_id,
                        observacion_avance, medidas_sesion, estado_caso, cumplimiento_plan,
                        proxima_revision, comunicacion_apoderado, fecha_comunicacion_apoderado,
                        notas_comunicacion, registrado_por, created_at, updated_at\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n                ");
                $stmtInsertSesion->execute([
                    $casoId,
                    $colegioId,
                    $pId,
                    $planId,
                    $obs,
                    $medidasSes !== '' ? $medidasSes : null,
                    $estadoCaso,
                    $cumpl,
                    $proxRev !== '' ? $proxRev : null,
                    $comApo !== '' ? $comApo : null,
                    $fechaCom !== '' ? date('Y-m-d H:i:s', strtotime($fechaCom)) : null,
                    $notasCom !== '' ? $notasCom : null,
                    $userId2 ?: null,
                ]);
                $sessionId = (int)$pdo->lastInsertId();

                $detalleHistorial = 'Se registró sesión de seguimiento para ' .
                    (string)($participanteSesion['nombre_referencial'] ?? 'participante') .
                    '. Estado: ' . caso_label($estadoCaso) .
                    '. Cumplimiento: ' . caso_label($cumpl) . '.';

                if ($proxRev !== '') {
                    $detalleHistorial .= ' Próxima revisión: ' . caso_fecha_corta($proxRev) . '.';
                }
                if ($comApo !== '') {
                    $detalleHistorial .= ' Comunicación al apoderado: ' . caso_label($comApo) . '.';
                }
                $detalleHistorial .= ' Observación: ' . mb_substr($obs, 0, 700, 'UTF-8');

                $stmtHistSesion = $pdo->prepare("\n                    INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)\n                    VALUES (?, 'seguimiento', 'Sesión de seguimiento registrada', ?, ?)\n                ");
                $stmtHistSesion->execute([$casoId, $detalleHistorial, $userId2 ?: null]);

                registrar_hito($pdo, $casoId, $colegioId, 104, $userId2);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            registrar_bitacora('denuncias', 'seguimiento_sesion', 'caso_seguimiento_sesion',
                $sessionId, 'Sesión de seguimiento registrada.');

            if (function_exists('invalidar_cache_dashboard')) {
                invalidar_cache_dashboard($colegioId);
            }

            caso_redirect($casoId, 'seguimiento&seg_part=' . $pId);
        }

        if ($accion === 'actualizar_seguimiento') {
            $schema = ver_seguimiento_schema_status($pdo);
            if (!$schema['ok']) {
                throw new RuntimeException($schema['message']);
            }

            $observacionAvance = clean((string)($_POST['observacion_avance'] ?? ''));
            $proximaRevision = clean((string)($_POST['proxima_revision'] ?? ''));
            $estadoSeguimiento = clean((string)($_POST['estado_seguimiento'] ?? 'en_proceso'));
            $medidasPreventivas = clean((string)($_POST['medidas_preventivas'] ?? ''));
            $cumplimiento = clean((string)($_POST['cumplimiento'] ?? 'en_proceso'));
            $comunicacionModalidad = clean((string)($_POST['comunicacion_apoderado_modalidad'] ?? ''));
            $comunicacionFecha = clean((string)($_POST['comunicacion_apoderado_fecha'] ?? ''));
            $notasComunicacion = clean((string)($_POST['notas_comunicacion'] ?? ''));

            $estadosSeguimientoPermitidos = ['pendiente', 'en_proceso', 'en_revision', 'resuelto', 'cerrado'];
            if (!in_array($estadoSeguimiento, $estadosSeguimientoPermitidos, true)) {
                $estadoSeguimiento = 'en_proceso';
            }

            $cumplimientosPermitidos = ['pendiente', 'en_proceso', 'cumplido', 'no_cumplido', 'no_aplica'];
            if (!in_array($cumplimiento, $cumplimientosPermitidos, true)) {
                $cumplimiento = 'en_proceso';
            }

            $modalidadesPermitidas = ['', 'presencial', 'telefono', 'correo', 'reunion_online', 'libreta', 'no_corresponde'];
            if (!in_array($comunicacionModalidad, $modalidadesPermitidas, true)) {
                $comunicacionModalidad = '';
            }

            if ($proximaRevision !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $proximaRevision)) {
                throw new RuntimeException('La próxima revisión no tiene formato válido.');
            }

            if ($comunicacionFecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $comunicacionFecha)) {
                throw new RuntimeException('La fecha de comunicación al apoderado no tiene formato válido.');
            }

            // Medidas preventivas opcionales

            // Próxima revisión opcional — no bloquear el guardado

            $planes = is_array($_POST['plan_accion'] ?? null) ? $_POST['plan_accion'] : [];
            $estadosParticipante = is_array($_POST['estado_participante'] ?? null) ? $_POST['estado_participante'] : [];
            $estadosParticipantePermitidos = ['pendiente', 'en_proceso', 'cumplido', 'no_cumplido', 'no_aplica'];

            $stmtParticipantes = $pdo->prepare("\n                SELECT cp.*\n                FROM caso_participantes cp\n                INNER JOIN casos c ON c.id = cp.caso_id\n                WHERE cp.caso_id = ?\n                  AND c.colegio_id = ?\n                ORDER BY cp.id ASC\n            ");
            $stmtParticipantes->execute([$casoId, $colegioId]);
            $participantesSeguimiento = $stmtParticipantes->fetchAll();

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                INSERT INTO caso_seguimiento (\n                    colegio_id,
                    caso_id,
                    fecha_apertura,
                    observacion_avance,
                    proxima_revision,
                    estado_seguimiento,
                    medidas_preventivas,
                    cumplimiento,
                    comunicacion_apoderado_modalidad,
                    comunicacion_apoderado_fecha,
                    notas_comunicacion,
                    actualizado_por,
                    created_at,
                    updated_at\n                ) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n                ON DUPLICATE KEY UPDATE\n                    colegio_id = VALUES(colegio_id),
                    observacion_avance = VALUES(observacion_avance),
                    proxima_revision = VALUES(proxima_revision),
                    estado_seguimiento = VALUES(estado_seguimiento),
                    medidas_preventivas = VALUES(medidas_preventivas),
                    cumplimiento = VALUES(cumplimiento),
                    comunicacion_apoderado_modalidad = VALUES(comunicacion_apoderado_modalidad),
                    comunicacion_apoderado_fecha = VALUES(comunicacion_apoderado_fecha),
                    notas_comunicacion = VALUES(notas_comunicacion),
                    actualizado_por = VALUES(actualizado_por),
                    updated_at = NOW()\n            ");
            $stmt->execute([
                $colegioId,
                $casoId,
                $observacionAvance !== '' ? $observacionAvance : null,
                $proximaRevision !== '' ? $proximaRevision : null,
                $estadoSeguimiento,
                $medidasPreventivas !== '' ? $medidasPreventivas : null,
                $cumplimiento,
                $comunicacionModalidad !== '' ? $comunicacionModalidad : null,
                $comunicacionFecha !== '' ? $comunicacionFecha : null,
                $notasComunicacion !== '' ? $notasComunicacion : null,
                $userId ?: null,
            ]);

            $stmtSeg = $pdo->prepare("\n                SELECT id\n                FROM caso_seguimiento\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
            $stmtSeg->execute([$casoId, $colegioId]);
            $seguimientoId = (int)$stmtSeg->fetchColumn();

            $stmtDelete = $pdo->prepare("\n                DELETE FROM caso_seguimiento_participantes\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n            ");
            $stmtDelete->execute([$casoId, $colegioId]);

            $stmtInsertPart = $pdo->prepare("\n                INSERT INTO caso_seguimiento_participantes (\n                    colegio_id,
                    caso_id,
                    seguimiento_id,
                    participante_id,
                    tipo_participante,
                    nombre_participante,
                    run_participante,
                    condicion,
                    plan_accion,
                    estado,
                    created_at,
                    updated_at\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n            ");

            foreach ($participantesSeguimiento as $participanteSeguimiento) {
                $pid = (int)($participanteSeguimiento['id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }

                $plan = clean((string)($planes[$pid] ?? ''));
                $estadoPart = clean((string)($estadosParticipante[$pid] ?? 'pendiente'));
                if (!in_array($estadoPart, $estadosParticipantePermitidos, true)) {
                    $estadoPart = 'pendiente';
                }

                $stmtInsertPart->execute([
                    $colegioId,
                    $casoId,
                    $seguimientoId > 0 ? $seguimientoId : null,
                    $pid,
                    (string)($participanteSeguimiento['tipo_persona'] ?? ''),
                    (string)($participanteSeguimiento['nombre_referencial'] ?? 'N/N'),
                    (string)($participanteSeguimiento['run'] ?? '0-0'),
                    (string)($participanteSeguimiento['rol_en_caso'] ?? ''),
                    $plan !== '' ? $plan : null,
                    $estadoPart,
                ]);
            }

            $detalleHistorial = 'Se actualizó seguimiento, medidas preventivas y cumplimiento. Estado: ' . caso_label($estadoSeguimiento) . '. Cumplimiento: ' . caso_label($cumplimiento) . '.';
            if ($proximaRevision !== '') {
                $detalleHistorial .= ' Próxima revisión: ' . caso_fecha_corta($proximaRevision) . '.';
            }
            if ($comunicacionModalidad !== '') {
                $detalleHistorial .= ' Comunicación apoderado: ' . caso_label($comunicacionModalidad) . '.';
            }
            if ($observacionAvance !== '') {
                $detalleHistorial .= ' Observación: ' . mb_substr($observacionAvance, 0, 700, 'UTF-8');
            }

            $stmtHist = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id\n                ) VALUES (?, 'seguimiento', 'Seguimiento y cumplimiento actualizado', ?, ?)\n            ");
            $stmtHist->execute([
                $casoId,
                $detalleHistorial,
                $userId ?: null,
            ]);

            $estadoCasoMap = [
                'pendiente' => 'revision_inicial',
                'en_proceso' => 'seguimiento',
                'en_revision' => 'seguimiento',
                'resuelto' => 'cerrado',
                'cerrado' => 'cerrado',
            ];
            $codigoEstadoCaso = $estadoCasoMap[$estadoSeguimiento] ?? 'seguimiento';

            $stmtEstado = $pdo->prepare("\n                SELECT id\n                FROM estado_caso\n                WHERE codigo = ?\n                  AND activo = 1\n                LIMIT 1\n            ");
            $stmtEstado->execute([$codigoEstadoCaso]);
            $estadoCasoId = (int)$stmtEstado->fetchColumn();

            if ($estadoCasoId > 0) {
                $stmtUpdateCaso = $pdo->prepare("\n                    UPDATE casos\n                    SET estado_caso_id = ?,
                        updated_at = NOW()\n                    WHERE id = ?\n                      AND colegio_id = ?\n                    LIMIT 1\n                ");
                $stmtUpdateCaso->execute([$estadoCasoId, $casoId, $colegioId]);
            } else {
                $stmtUpdateCaso = $pdo->prepare("\n                    UPDATE casos\n                    SET updated_at = NOW()\n                    WHERE id = ?\n                      AND colegio_id = ?\n                    LIMIT 1\n                ");
                $stmtUpdateCaso->execute([$casoId, $colegioId]);
            }

            $pdo->commit();

            registrar_bitacora(
                'denuncias',
                'actualizar_seguimiento',
                'caso_seguimiento',
                $seguimientoId,
                'Seguimiento, medidas preventivas y cumplimiento del expediente actualizado.'
            );

            caso_redirect($casoId, 'seguimiento');
        }



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

                $stmt = $pdo->prepare("\n                    UPDATE caso_aula_segura\n                    SET causal_agresion_sexual = ?,
                        causal_agresion_fisica_lesiones = ?,
                        causal_armas = ?,
                        causal_artefactos_incendiarios = ?,
                        causal_infraestructura_esencial = ?,
                        causal_grave_reglamento = ?,
                        descripcion_hecho = ?,
                        fuente_informacion = ?,
                        evidencia_inicial = ?,
                        falta_reglamento = ?,
                        fundamento_proporcionalidad = ?,
                        estado = ?,
                        decision_director = ?,
                        fecha_evaluacion_directiva = ?,
                        evaluado_por = CASE WHEN ? IS NOT NULL THEN ? ELSE evaluado_por END,
                        fecha_inicio_procedimiento = ?,
                        iniciado_por = CASE WHEN ? IS NOT NULL THEN ? ELSE iniciado_por END,
                        comunicacion_apoderado_at = ?,
                        medio_comunicacion_apoderado = ?,
                        observacion_comunicacion_apoderado = ?,
                        suspension_cautelar = ?,
                        fecha_notificacion_suspension = ?,
                        fecha_limite_resolucion = ?,
                        fundamento_suspension = ?,
                        descargos_recibidos = ?,
                        fecha_descargos = ?,
                        observacion_descargos = ?,
                        resolucion = ?,
                        fecha_resolucion = ?,
                        fecha_notificacion_resolucion = ?,
                        fundamento_resolucion = ?,
                        reconsideracion_presentada = ?,
                        fecha_reconsideracion = ?,
                        fecha_limite_reconsideracion = ?,
                        fecha_resolucion_reconsideracion = ?,
                        resultado_reconsideracion = ?,
                        fundamento_reconsideracion = ?,
                        comunicacion_supereduc = ?,
                        fecha_comunicacion_supereduc = ?,
                        medio_comunicacion_supereduc = ?,
                        observacion_supereduc = ?,
                        observaciones = ?,
                        updated_at = NOW()\n                    WHERE id = ?\n                      AND caso_id = ?\n                      AND colegio_id = ?\n                    LIMIT 1\n                ");
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
                $stmt = $pdo->prepare("\n                    INSERT INTO caso_aula_segura (\n                        colegio_id, caso_id, posible_aula_segura,
                        causal_agresion_sexual, causal_agresion_fisica_lesiones, causal_armas,
                        causal_artefactos_incendiarios, causal_infraestructura_esencial, causal_grave_reglamento,
                        descripcion_hecho, fuente_informacion, evidencia_inicial,
                        falta_reglamento, fundamento_proporcionalidad, estado, decision_director,
                        fecha_evaluacion_directiva, evaluado_por, fecha_inicio_procedimiento, iniciado_por,
                        comunicacion_apoderado_at, medio_comunicacion_apoderado, observacion_comunicacion_apoderado,
                        suspension_cautelar, fecha_notificacion_suspension, fecha_limite_resolucion, fundamento_suspension,
                        descargos_recibidos, fecha_descargos, observacion_descargos,
                        resolucion, fecha_resolucion, fecha_notificacion_resolucion, fundamento_resolucion,
                        reconsideracion_presentada, fecha_reconsideracion, fecha_limite_reconsideracion,
                        fecha_resolucion_reconsideracion, resultado_reconsideracion, fundamento_reconsideracion,
                        comunicacion_supereduc, fecha_comunicacion_supereduc, medio_comunicacion_supereduc, observacion_supereduc,
                        observaciones, creado_por, created_at, updated_at\n                    ) VALUES (\n                        ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()\n                    )\n                ");
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

            $stmt = $pdo->prepare("\n                UPDATE casos\n                SET aula_segura_estado = ?,
                    updated_at = NOW()\n                WHERE id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
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

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id\n                ) VALUES (?, 'aula_segura', 'Aula Segura actualizada', ?, ?)\n            ");
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



        if ($accion === 'crear_accion_ejecutiva') {
            $titulo = clean((string)($_POST['titulo'] ?? ''));
            $descripcion = clean((string)($_POST['descripcion'] ?? ''));
            $responsableNombre = clean((string)($_POST['responsable_nombre'] ?? ''));
            $responsableRol = clean((string)($_POST['responsable_rol'] ?? ''));
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));
            $fechaCompromiso = clean((string)($_POST['fecha_compromiso'] ?? ''));

            if ($titulo === '') {
                throw new RuntimeException('El título de la acción ejecutiva es obligatorio.');
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta', 'critica'], true)) {
                $prioridad = 'media';
            }

            if ($fechaCompromiso !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCompromiso)) {
                throw new RuntimeException('La fecha compromiso no es válida.');
            }

            $stmt = $pdo->prepare("\n                INSERT INTO caso_gestion_ejecutiva (\n                    colegio_id,
                    caso_id,
                    titulo,
                    descripcion,
                    responsable_nombre,
                    responsable_rol,
                    prioridad,
                    estado,
                    fecha_compromiso,
                    creado_por,
                    created_at,
                    updated_at\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, NOW(), NOW())\n            ");
            $stmt->execute([
                $colegioId,
                $casoId,
                $titulo,
                $descripcion !== '' ? $descripcion : null,
                $responsableNombre !== '' ? $responsableNombre : null,
                $responsableRol !== '' ? $responsableRol : null,
                $prioridad,
                $fechaCompromiso !== '' ? $fechaCompromiso : null,
                $userId ?: null,
            ]);

            $accionId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id\n                ) VALUES (?, 'gestion_ejecutiva', 'Acción ejecutiva registrada', ?, ?)\n            ");
            $stmt->execute([
                $casoId,
                'Se registró acción ejecutiva: ' . $titulo . '.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'crear_accion_ejecutiva',
                'caso_gestion_ejecutiva',
                $accionId,
                'Acción ejecutiva agregada al caso.'
            );

            caso_redirect($casoId, 'gestion');
        }

        if ($accion === 'actualizar_accion_ejecutiva') {
            $accionId = (int)($_POST['accion_id'] ?? 0);
            $estadoGestion = clean((string)($_POST['estado'] ?? 'pendiente'));
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));
            $fechaCompromiso = clean((string)($_POST['fecha_compromiso'] ?? ''));
            $nota = clean((string)($_POST['nota'] ?? ''));

            if ($accionId <= 0) {
                throw new RuntimeException('Acción ejecutiva no válida.');
            }

            if (!in_array($estadoGestion, ['pendiente', 'en_proceso', 'cumplida', 'descartada'], true)) {
                $estadoGestion = 'pendiente';
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta', 'critica'], true)) {
                $prioridad = 'media';
            }

            if ($fechaCompromiso !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCompromiso)) {
                throw new RuntimeException('La fecha compromiso no es válida.');
            }

            $stmtActual = $pdo->prepare("\n                SELECT *\n                FROM caso_gestion_ejecutiva\n                WHERE id = ?\n                  AND caso_id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
            $stmtActual->execute([$accionId, $casoId, $colegioId]);
            $actual = $stmtActual->fetch();

            if (!$actual) {
                throw new RuntimeException('La acción ejecutiva no fue encontrada.');
            }

            $cerrar = in_array($estadoGestion, ['cumplida', 'descartada'], true);

            $stmt = $pdo->prepare("\n                UPDATE caso_gestion_ejecutiva\n                SET estado = ?,
                    prioridad = ?,
                    fecha_compromiso = ?,
                    fecha_cumplimiento = CASE WHEN ? = 1 THEN COALESCE(fecha_cumplimiento, NOW()) ELSE NULL END,
                    cerrado_por = CASE WHEN ? = 1 THEN ? ELSE NULL END,
                    updated_at = NOW()\n                WHERE id = ?\n                  AND caso_id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
            $stmt->execute([
                $estadoGestion,
                $prioridad,
                $fechaCompromiso !== '' ? $fechaCompromiso : null,
                $cerrar ? 1 : 0,
                $cerrar ? 1 : 0,
                $cerrar && $userId > 0 ? $userId : null,
                $accionId,
                $casoId,
                $colegioId,
            ]);

            $detalle = 'Se actualizó acción ejecutiva: ' . (string)$actual['titulo'] . '. Estado: ' . caso_label($estadoGestion) . '.';

            if ($nota !== '') {
                $detalle .= ' Nota: ' . $nota;
            }

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id\n                ) VALUES (?, 'gestion_ejecutiva', 'Acción ejecutiva actualizada', ?, ?)\n            ");
            $stmt->execute([
                $casoId,
                $detalle,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'actualizar_accion_ejecutiva',
                'caso_gestion_ejecutiva',
                $accionId,
                'Acción ejecutiva actualizada.'
            );

            caso_redirect($casoId, 'gestion');
        }

        if ($accion === 'guardar_marcadores_normativos') {
            $flags21809  = array_values(array_filter((array)($_POST['ley21809_flags']  ?? [])));
            $flagsRex782 = array_values(array_filter((array)($_POST['rex782_flags']    ?? [])));

            $json21809  = json_encode($flags21809,  JSON_UNESCAPED_UNICODE);
            $jsonRex782 = json_encode($flagsRex782, JSON_UNESCAPED_UNICODE);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO caso_clasificacion_normativa (
                        caso_id,
                        colegio_id,
                        ley21809_flags,
                        rex782_flags,
                        updated_at
                    ) VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        ley21809_flags = VALUES(ley21809_flags),
                        rex782_flags   = VALUES(rex782_flags),
                        updated_at     = NOW()
                ");
                $stmt->execute([$casoId, $colegioId, $json21809, $jsonRex782]);

                registrar_bitacora('denuncias', 'marcadores_normativos', 'casos', $casoId, 'Marcadores normativos actualizados.');
            } catch (Throwable $e) {
                throw new RuntimeException('Error al guardar marcadores normativos: ' . $e->getMessage());
            }

            caso_redirect($casoId, 'gestion');
        }

        if ($accion === 'registrar_cierre_formal') {
            if (!Auth::canOperate()) {
                throw new RuntimeException('No tiene permisos para cerrar expedientes.');
            }

            $fechaCierre    = clean((string)($_POST['fecha_cierre'] ?? date('Y-m-d')));
            $tipoCierre     = clean((string)($_POST['tipo_cierre'] ?? 'resuelto'));
            $fundamento     = clean((string)($_POST['fundamento'] ?? ''));
            $medidasFinales = clean((string)($_POST['medidas_finales'] ?? ''));
            $acuerdos       = clean((string)($_POST['acuerdos'] ?? ''));
            $derivaciones   = clean((string)($_POST['derivaciones'] ?? ''));
            $observaciones  = clean((string)($_POST['observaciones'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCierre)) {
                throw new RuntimeException('La fecha de cierre no es válida.');
            }

            if (strtotime($fechaCierre) > strtotime(date('Y-m-d'))) {
                throw new RuntimeException('La fecha de cierre no puede ser futura.');
            }

            if (!in_array($tipoCierre, ['resuelto', 'derivado', 'desestimado', 'acuerdo', 'otro'], true)) {
                $tipoCierre = 'resuelto';
            }

            if ($fundamento === '') {
                throw new RuntimeException('Debe registrar el fundamento o síntesis del cierre.');
            }

            if (mb_strlen($fundamento, 'UTF-8') < 10) {
                throw new RuntimeException('El fundamento del cierre debe ser más descriptivo.');
            }

            foreach ([
                'fundamento' => $fundamento,
                'medidas finales' => $medidasFinales,
                'acuerdos' => $acuerdos,
                'derivaciones' => $derivaciones,
                'observaciones' => $observaciones,
            ] as $campo => $valor) {
                if (mb_strlen((string)$valor, 'UTF-8') > 20000) {
                    throw new RuntimeException('El campo ' . $campo . ' excede el largo permitido.');
                }
            }

            $stmtCasoCierre = $pdo->prepare("\n                SELECT c.id, c.estado, c.estado_caso_id, ec.codigo AS estado_codigo\n                FROM casos c\n                LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n                WHERE c.id = ?\n                  AND c.colegio_id = ?\n                LIMIT 1\n            ");
            $stmtCasoCierre->execute([$casoId, $colegioId]);
            $casoCierreActual = $stmtCasoCierre->fetch(PDO::FETCH_ASSOC);

            if (!$casoCierreActual) {
                throw new RuntimeException('El expediente no existe o no pertenece al establecimiento activo.');
            }

            if ((string)($casoCierreActual['estado_codigo'] ?? '') === 'cerrado' || (string)($casoCierreActual['estado'] ?? '') === 'cerrado') {
                throw new RuntimeException('El expediente ya se encuentra cerrado.');
            }

            $stmtCierreVigente = $pdo->prepare("\n                SELECT id\n                FROM caso_cierre\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n                  AND estado_cierre = 'vigente'\n                LIMIT 1\n            ");
            $stmtCierreVigente->execute([$casoId, $colegioId]);
            if ((int)$stmtCierreVigente->fetchColumn() > 0) {
                throw new RuntimeException('El expediente ya tiene un cierre formal vigente.');
            }

            $bloqueosCierre = [];

            try {
                $stmt = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM caso_participantes cp\n                    INNER JOIN casos c ON c.id = cp.caso_id\n                    WHERE cp.caso_id = ?\n                      AND c.colegio_id = ?\n                ");
                $stmt->execute([$casoId, $colegioId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $bloqueosCierre[] = 'Debe existir al menos un participante registrado antes del cierre.';
                }
            } catch (Throwable $e) {
                $bloqueosCierre[] = 'No fue posible validar participantes del expediente.';
            }

            try {
                $stmt = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM caso_plan_accion\n                    WHERE caso_id = ?\n                      AND colegio_id = ?\n                      AND vigente = 1\n                ");
                $stmt->execute([$casoId, $colegioId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $bloqueosCierre[] = 'Debe existir al menos un plan de acción vigente antes del cierre.';
                }
            } catch (Throwable $e) {
                $bloqueosCierre[] = 'No fue posible validar el plan de acción del expediente.';
            }

            try {
                $stmt = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM caso_seguimiento_sesion\n                    WHERE caso_id = ?\n                      AND colegio_id = ?\n                ");
                $stmt->execute([$casoId, $colegioId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $bloqueosCierre[] = 'Debe existir al menos una sesión de seguimiento antes del cierre.';
                }
            } catch (Throwable $e) {
                $bloqueosCierre[] = 'No fue posible validar las sesiones de seguimiento del expediente.';
            }

            try {
                $stmt = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM caso_pauta_riesgo pr\n                    INNER JOIN casos c ON c.id = pr.caso_id\n                    WHERE pr.caso_id = ?\n                      AND c.colegio_id = ?\n                      AND pr.nivel_final IN ('alto', 'critico')\n                      AND (pr.derivado = 0 OR pr.derivado IS NULL)\n                ");
                $stmt->execute([$casoId, $colegioId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $bloqueosCierre[] = 'Existen pautas de riesgo alto/crítico sin derivación registrada.';
                }
            } catch (Throwable $e) {
                // Si la pauta aún no existe, no bloquear por falla técnica de tabla opcional.
            }

            if (!empty($bloqueosCierre)) {
                throw new RuntimeException('No es posible cerrar el expediente: ' . implode(' ', $bloqueosCierre));
            }

            $estadoCerradoId = null;
            try {
                $stmtEstado = $pdo->prepare("\n                    SELECT id\n                    FROM estado_caso\n                    WHERE codigo = 'cerrado'\n                    LIMIT 1\n                ");
                $stmtEstado->execute();
                $estadoCerradoId = (int)$stmtEstado->fetchColumn();
            } catch (Throwable $e) {
                $estadoCerradoId = 0;
            }

            if ($estadoCerradoId <= 0) {
                throw new RuntimeException('No se encontró el estado de sistema Cerrado.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("\n                    INSERT INTO caso_cierre (\n                        colegio_id,\n                        caso_id,\n                        fecha_cierre,\n                        tipo_cierre,\n                        fundamento,\n                        medidas_finales,\n                        acuerdos,\n                        derivaciones,\n                        observaciones,\n                        estado_cierre,\n                        cerrado_por,\n                        created_at,\n                        updated_at\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'vigente', ?, NOW(), NOW())\n                ");
                $stmt->execute([
                    $colegioId,
                    $casoId,
                    $fechaCierre,
                    $tipoCierre,
                    $fundamento,
                    $medidasFinales !== '' ? $medidasFinales : null,
                    $acuerdos !== '' ? $acuerdos : null,
                    $derivaciones !== '' ? $derivaciones : null,
                    $observaciones !== '' ? $observaciones : null,
                    $userId ?: null,
                ]);

                $cierreId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("\n                    UPDATE casos\n                    SET estado = 'cerrado',\n                        estado_caso_id = ?,\n                        updated_at = NOW()\n                    WHERE id = ?\n                      AND colegio_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$estadoCerradoId, $casoId, $colegioId]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('No fue posible actualizar el estado del expediente a Cerrado.');
                }

                $detalleCierre = 'Se registró cierre formal del caso. Tipo: ' . caso_label($tipoCierre) . '. Fundamento: ' . $fundamento;
                if ($derivaciones !== '') {
                    $detalleCierre .= ' Derivaciones: ' . $derivaciones;
                }

                $stmt = $pdo->prepare("\n                    INSERT INTO caso_historial (\n                        caso_id,\n                        tipo_evento,\n                        titulo,\n                        detalle,\n                        user_id\n                    ) VALUES (?, 'cierre', 'Cierre formal del expediente', ?, ?)\n                ");
                $stmt->execute([$casoId, $detalleCierre, $userId ?: null]);

                registrar_hito($pdo, $casoId, $colegioId, 110, $userId);

                registrar_bitacora(
                    'denuncias',
                    'registrar_cierre_formal',
                    'caso_cierre',
                    $cierreId,
                    'Cierre formal registrado para el expediente.'
                );

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if (function_exists('invalidar_cache_dashboard')) {
                invalidar_cache_dashboard($colegioId);
            }

            caso_redirect($casoId, 'cierre');
        }

        if ($accion === 'reabrir_caso') {
            if (!Auth::canOperate()) {
                throw new RuntimeException('No tiene permisos para reabrir expedientes.');
            }

            $motivo = clean((string)($_POST['motivo_reapertura'] ?? ''));

            if ($motivo === '') {
                throw new RuntimeException('Debe indicar el motivo de reapertura.');
            }

            if (mb_strlen($motivo, 'UTF-8') < 10) {
                throw new RuntimeException('El motivo de reapertura debe ser más descriptivo.');
            }

            if (mb_strlen($motivo, 'UTF-8') > 2000) {
                throw new RuntimeException('El motivo de reapertura excede el largo permitido.');
            }

            $stmtCierreVigente = $pdo->prepare("\n                SELECT id\n                FROM caso_cierre\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n                  AND estado_cierre = 'vigente'\n                ORDER BY id DESC\n                LIMIT 1\n            ");
            $stmtCierreVigente->execute([$casoId, $colegioId]);
            $cierreVigenteId = (int)$stmtCierreVigente->fetchColumn();

            if ($cierreVigenteId <= 0) {
                throw new RuntimeException('No existe un cierre formal vigente para reabrir.');
            }

            $estadoInvestigacionId = null;
            try {
                $stmtEstado = $pdo->prepare("\n                    SELECT id\n                    FROM estado_caso\n                    WHERE codigo = 'investigacion'\n                    LIMIT 1\n                ");
                $stmtEstado->execute();
                $estadoInvestigacionId = (int)$stmtEstado->fetchColumn();
            } catch (Throwable $e) {
                $estadoInvestigacionId = 0;
            }

            if ($estadoInvestigacionId <= 0) {
                $estadoInvestigacionId = 2;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("\n                    UPDATE caso_cierre\n                    SET estado_cierre = 'anulado',\n                        anulado_por = ?,\n                        anulado_at = NOW(),\n                        motivo_anulacion = ?,\n                        updated_at = NOW()\n                    WHERE id = ?\n                      AND caso_id = ?\n                      AND colegio_id = ?\n                      AND estado_cierre = 'vigente'\n                    LIMIT 1\n                ");
                $stmt->execute([$userId ?: null, $motivo, $cierreVigenteId, $casoId, $colegioId]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('No fue posible anular el cierre vigente.');
                }

                $stmt = $pdo->prepare("\n                    UPDATE casos\n                    SET estado = 'abierto',\n                        estado_caso_id = ?,\n                        updated_at = NOW()\n                    WHERE id = ?\n                      AND colegio_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$estadoInvestigacionId, $casoId, $colegioId]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('No fue posible reabrir el expediente.');
                }

                $stmt = $pdo->prepare("\n                    INSERT INTO caso_historial (\n                        caso_id,\n                        tipo_evento,\n                        titulo,\n                        detalle,\n                        user_id\n                    ) VALUES (?, 'reapertura', 'Reapertura del expediente', ?, ?)\n                ");
                $stmt->execute([
                    $casoId,
                    'Se reabrió el expediente. Motivo: ' . $motivo,
                    $userId ?: null,
                ]);

                registrar_bitacora(
                    'denuncias',
                    'reabrir_caso',
                    'casos',
                    $casoId,
                    'Expediente reabierto desde cierre formal.'
                );

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if (function_exists('invalidar_cache_dashboard')) {
                invalidar_cache_dashboard($colegioId);
            }

            caso_redirect($casoId, 'cierre');
        }

        if ($accion === 'actualizar_resumen') {
            $estado = clean((string)($_POST['estado'] ?? 'abierto'));
            $estadoCasoId = (int)($_POST['estado_caso_id'] ?? 0);
            $semaforo = clean((string)($_POST['semaforo'] ?? 'verde'));
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));
            $involucraMoviles = isset($_POST['involucra_moviles']) ? 1 : 0;
            $requiereReanalisis = isset($_POST['requiere_reanalisis_ia']) ? 1 : 0;

            if (!in_array($estado, ['abierto', 'cerrado'], true)) {
                $estado = 'abierto';
            }

            if (!in_array($semaforo, ['verde', 'amarillo', 'rojo'], true)) {
                $semaforo = 'verde';
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
                $prioridad = 'media';
            }

            $stmt = $pdo->prepare("
                UPDATE casos
                SET estado = ?,
                    estado_caso_id = ?,
                    semaforo = ?,
                    prioridad = ?,
                    involucra_moviles = ?,
                    requiere_reanalisis_ia = ?
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $stmt->execute([
                $estado,
                $estadoCasoId > 0 ? $estadoCasoId : null,
                $semaforo,
                $prioridad,
                $involucraMoviles,
                $requiereReanalisis,
                $casoId,
                $colegioId,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'actualizacion', 'Actualización del resumen', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se actualizó estado, semáforo, prioridad o indicadores del caso.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'actualizar_resumen',
                'casos',
                $casoId,
                'Resumen del caso actualizado.'
            );

            caso_redirect($casoId, 'resumen');
        }

        if ($accion === 'agregar_participante') {
            $tipoPersona = clean((string)($_POST['tipo_persona'] ?? 'externo'));
            $personaId = (int)($_POST['persona_id'] ?? 0);
            $personaAnualId = (int)($_POST['persona_anual_id'] ?? 0);
            $personaAnualTipo = clean((string)($_POST['persona_anual_tipo'] ?? ''));
            $nombre = clean((string)($_POST['nombre_referencial'] ?? ''));
            $run = cleanRun((string)($_POST['run'] ?? ''));
            $rolEnCaso = clean((string)($_POST['rol_en_caso'] ?? 'involucrado'));
            $reserva = isset($_POST['solicita_reserva_identidad']) ? 1 : 0;
            $observacion = clean((string)($_POST['observacion'] ?? ''));
            $observacionReserva = clean((string)($_POST['observacion_reserva'] ?? ''));

            $snapshotRun = cleanRun((string)($_POST['snapshot_run'] ?? ''));
            $snapshotNombres = clean((string)($_POST['snapshot_nombres'] ?? ''));
            $snapshotApellidoPaterno = clean((string)($_POST['snapshot_apellido_paterno'] ?? ''));
            $snapshotApellidoMaterno = clean((string)($_POST['snapshot_apellido_materno'] ?? ''));
            $snapshotNombreSocial = clean((string)($_POST['snapshot_nombre_social'] ?? ''));
            $snapshotSexo = clean((string)($_POST['snapshot_sexo'] ?? ''));
            $snapshotGenero = clean((string)($_POST['snapshot_genero'] ?? ''));
            $snapshotFechaNacimiento = clean((string)($_POST['snapshot_fecha_nacimiento'] ?? ''));
            $snapshotEdad = (int)($_POST['snapshot_edad'] ?? 0);
            $snapshotCurso = clean((string)($_POST['snapshot_curso'] ?? ''));
            $snapshotAnioEscolar = (int)($_POST['snapshot_anio_escolar'] ?? $_POST['anio_escolar'] ?? 0);
            $snapshotFechaReferencia = clean((string)($_POST['snapshot_fecha_referencia'] ?? ''));

            $tiposPermitidos = ['alumno', 'apoderado', 'docente', 'asistente', 'externo'];
            if (!in_array($tipoPersona, $tiposPermitidos, true)) {
                $tipoPersona = 'externo';
            }

            if ($personaAnualTipo !== '' && !in_array($personaAnualTipo, ['alumno', 'apoderado', 'docente', 'asistente'], true)) {
                $personaAnualTipo = '';
            }

            $rolesPermitidos = ['victima', 'denunciante', 'denunciado', 'testigo', 'involucrado'];
            if (!in_array($rolEnCaso, $rolesPermitidos, true)) {
                $rolEnCaso = 'involucrado';
            }

            $personaIdSql = null;
            $personaAnualIdSql = null;
            $personaAnualTipoSql = null;
            $identidadConfirmada = 0;
            $fechaIdentificacion = null;
            $identificadoPor = null;
            $observacionIdentificacion = null;

            if ($personaAnualId > 0 && $personaAnualTipo !== '') {
                $tablaAnual = match ($personaAnualTipo) {
                    'alumno' => 'alumnos_anual',
                    'apoderado' => 'apoderados_anual',
                    'docente' => 'docentes_anual',
                    'asistente' => 'asistentes_anual',
                    default => null,
                };

                $legacyColumn = match ($personaAnualTipo) {
                    'alumno' => 'alumno_legacy_id',
                    'apoderado' => 'apoderado_legacy_id',
                    'docente' => 'docente_legacy_id',
                    'asistente' => 'asistente_legacy_id',
                    default => null,
                };

                if ($tablaAnual === null || $legacyColumn === null) {
                    throw new RuntimeException('Tipo anual de persona no válido.');
                }

                $stmtPersona = $pdo->prepare("\n                    SELECT\n                        id,\n                        {$legacyColumn} AS legacy_id,\n                        run,\n                        nombres,\n                        apellido_paterno,\n                        apellido_materno,\n                        " . ($personaAnualTipo === 'alumno' ? "'' AS nombre," : "COALESCE(nombre, '') AS nombre,") . "\n                        fecha_nacimiento,\n                        sexo,\n                        genero,\n                        nombre_social,\n                        anio_escolar,\n                        " . ($personaAnualTipo === 'alumno' ? "COALESCE(curso, '') AS curso," : "COALESCE(cargo, '') AS curso,") . "\n                        vigente\n                    FROM {$tablaAnual}\n                    WHERE id = ?\n                      AND colegio_id = ?\n                      AND vigente = 1\n                    LIMIT 1\n                ");
                $stmtPersona->execute([$personaAnualId, $colegioId]);
                $persona = $stmtPersona->fetch(PDO::FETCH_ASSOC);

                if (!$persona) {
                    throw new RuntimeException('La persona anual seleccionada no pertenece al establecimiento o no está vigente.');
                }

                $partesNombre = array_filter([
                    trim((string)($persona['apellido_paterno'] ?? '')),
                    trim((string)($persona['apellido_materno'] ?? '')),
                    trim((string)($persona['nombres'] ?? '')),
                ], static fn($v): bool => $v !== '');

                $nombreOficial = trim(implode(' ', $partesNombre));
                if ($nombreOficial === '') {
                    $nombreOficial = trim((string)($persona['nombre'] ?? ''));
                }

                $nombreSocial = trim((string)($persona['nombre_social'] ?? ''));
                $nombre = $nombreSocial !== '' ? $nombreSocial : ($nombreOficial !== '' ? mb_strtoupper($nombreOficial, 'UTF-8') : 'NN');
                $run = cleanRun((string)($persona['run'] ?? '0-0'));

                $personaIdSql = (int)($persona['legacy_id'] ?? 0) > 0 ? (int)$persona['legacy_id'] : null;
                $personaAnualIdSql = (int)$persona['id'];
                $personaAnualTipoSql = $personaAnualTipo;
                $tipoPersona = $personaAnualTipo;
                $identidadConfirmada = 1;
                $fechaIdentificacion = date('Y-m-d H:i:s');
                $identificadoPor = $userId ?: null;
                $observacionIdentificacion = 'Vinculado desde comunidad educativa anual.';

                $snapshotRun = cleanRun((string)($persona['run'] ?? $run));
                $snapshotNombres = clean((string)($persona['nombres'] ?? ''));
                $snapshotApellidoPaterno = clean((string)($persona['apellido_paterno'] ?? ''));
                $snapshotApellidoMaterno = clean((string)($persona['apellido_materno'] ?? ''));
                $snapshotNombreSocial = clean((string)($persona['nombre_social'] ?? ''));
                $snapshotSexo = clean((string)($persona['sexo'] ?? ''));
                $snapshotGenero = clean((string)($persona['genero'] ?? ''));
                $snapshotFechaNacimiento = clean((string)($persona['fecha_nacimiento'] ?? ''));
                $snapshotCurso = clean((string)($persona['curso'] ?? ''));
                $snapshotAnioEscolar = (int)($persona['anio_escolar'] ?? $snapshotAnioEscolar);

                if ($snapshotFechaReferencia === '') {
                    $snapshotFechaReferencia = date('Y-m-d');
                    try {
                        $stmtFechaCaso = $pdo->prepare("\n                            SELECT DATE(COALESCE(fecha_hechos, fecha_hora_incidente, fecha_ingreso, created_at, NOW()))\n                            FROM casos\n                            WHERE id = ?\n                              AND colegio_id = ?\n                            LIMIT 1\n                        ");
                        $stmtFechaCaso->execute([$casoId, $colegioId]);
                        $snapshotFechaReferencia = (string)($stmtFechaCaso->fetchColumn() ?: $snapshotFechaReferencia);
                    } catch (Throwable $e) {
                        // fallback a fecha actual
                    }
                }

                if ($snapshotEdad <= 0 && $snapshotFechaNacimiento !== '' && $snapshotFechaReferencia !== '') {
                    try {
                        $snapshotEdad = (int)(new DateTimeImmutable($snapshotFechaNacimiento))->diff(new DateTimeImmutable($snapshotFechaReferencia))->y;
                    } catch (Throwable $e) {
                        $snapshotEdad = 0;
                    }
                }

                $stmtDuplicado = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM caso_participantes cp\n                    INNER JOIN casos c ON c.id = cp.caso_id\n                    WHERE cp.caso_id = ?\n                      AND cp.tipo_persona = ?\n                      AND (\n                            (cp.persona_anual_id = ? AND cp.persona_anual_tipo = ?)\n                         OR (cp.persona_id IS NOT NULL AND cp.persona_id = ?)\n                      )\n                      AND cp.rol_en_caso = ?\n                      AND c.colegio_id = ?\n                ");
                $stmtDuplicado->execute([
                    $casoId,
                    $tipoPersona,
                    $personaAnualIdSql,
                    $personaAnualTipoSql,
                    $personaIdSql ?: 0,
                    $rolEnCaso,
                    $colegioId,
                ]);

                if ((int)$stmtDuplicado->fetchColumn() > 0) {
                    throw new RuntimeException('Esta persona ya está registrada con el mismo rol en el caso.');
                }
            } else {
                $personaIdSql = null;
                $personaAnualIdSql = null;
                $personaAnualTipoSql = null;
                $nombre = $nombre !== '' ? mb_strtoupper($nombre, 'UTF-8') : 'NN';
                $run = $run !== '' ? $run : '0-0';
                $snapshotRun = $snapshotRun !== '' ? $snapshotRun : $run;
                $snapshotNombres = $snapshotNombres !== '' ? $snapshotNombres : $nombre;
                $snapshotAnioEscolar = $snapshotAnioEscolar > 0 ? $snapshotAnioEscolar : (int)date('Y');
                $snapshotFechaReferencia = $snapshotFechaReferencia !== '' ? $snapshotFechaReferencia : date('Y-m-d');
                $observacionIdentificacion = 'Interviniente externo o pendiente de vinculación con comunidad educativa anual.';
            }

            if ($nombre === '') {
                $nombre = 'NN';
            }

            if ($run === '') {
                $run = '0-0';
            }

            if ($nombre === 'NN' && $run === '0-0') {
                throw new RuntimeException('Debe ingresar nombre o RUN del interviniente.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("\n                    INSERT INTO caso_participantes (\n                        caso_id,\n                        tipo_persona,\n                        persona_id,\n                        persona_anual_id,\n                        persona_anual_tipo,\n                        nombre_referencial,\n                        run,\n                        snapshot_run,\n                        snapshot_nombres,\n                        snapshot_apellido_paterno,\n                        snapshot_apellido_materno,\n                        snapshot_nombre_social,\n                        snapshot_sexo,\n                        snapshot_genero,\n                        snapshot_fecha_nacimiento,\n                        snapshot_edad,\n                        snapshot_curso,\n                        snapshot_anio_escolar,\n                        snapshot_fecha_referencia,\n                        identidad_confirmada,\n                        fecha_identificacion,\n                        identificado_por,\n                        rol_en_caso,\n                        solicita_reserva_identidad,\n                        observacion_reserva,\n                        observacion,\n                        observacion_identificacion,\n                        created_at\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                ");
                $stmt->execute([
                    $casoId,
                    $tipoPersona,
                    $personaIdSql,
                    $personaAnualIdSql,
                    $personaAnualTipoSql,
                    $nombre,
                    $run,
                    $snapshotRun !== '' ? $snapshotRun : $run,
                    $snapshotNombres !== '' ? $snapshotNombres : $nombre,
                    $snapshotApellidoPaterno !== '' ? $snapshotApellidoPaterno : null,
                    $snapshotApellidoMaterno !== '' ? $snapshotApellidoMaterno : null,
                    $snapshotNombreSocial !== '' ? $snapshotNombreSocial : null,
                    $snapshotSexo !== '' ? $snapshotSexo : null,
                    $snapshotGenero !== '' ? $snapshotGenero : null,
                    $snapshotFechaNacimiento !== '' ? $snapshotFechaNacimiento : null,
                    $snapshotEdad > 0 ? $snapshotEdad : null,
                    $snapshotCurso !== '' ? $snapshotCurso : null,
                    $snapshotAnioEscolar > 0 ? $snapshotAnioEscolar : null,
                    $snapshotFechaReferencia !== '' ? $snapshotFechaReferencia : null,
                    $identidadConfirmada,
                    $fechaIdentificacion,
                    $identificadoPor,
                    $rolEnCaso,
                    $reserva,
                    $observacionReserva !== '' ? $observacionReserva : null,
                    $observacion !== '' ? $observacion : null,
                    $observacionIdentificacion,
                ]);

                $participanteId = (int)$pdo->lastInsertId();

                $stmtHist = $pdo->prepare("\n                    INSERT INTO caso_historial (\n                        caso_id,\n                        tipo_evento,\n                        titulo,\n                        detalle,\n                        user_id\n                    ) VALUES (?, 'participante', 'Interviniente agregado', ?, ?)\n                ");
                $stmtHist->execute([
                    $casoId,
                    'Se agregó interviniente: ' . $nombre . ' (' . $rolEnCaso . ')' . ($identidadConfirmada ? ' con identidad anual confirmada.' : ' pendiente de vinculación.'),
                    $userId ?: null,
                ]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            registrar_bitacora(
                'denuncias',
                'agregar_interviniente_anual',
                'caso_participantes',
                $participanteId,
                'Interviniente anual agregado al caso.'
            );

            caso_redirect($casoId, 'participantes');
        }

        if ($accion === 'agregar_declaracion') {
            $participanteId = (int)($_POST['participante_id'] ?? 0);
            $nombreDeclarante = clean((string)($_POST['nombre_declarante'] ?? ''));
            $runDeclarante = cleanRun((string)($_POST['run_declarante'] ?? ''));
            $tipoDeclarante = clean((string)($_POST['tipo_declarante'] ?? 'externo'));
            $calidadProcesal = clean((string)($_POST['calidad_procesal'] ?? 'declarante'));
            $textoDeclaracion = cleanText((string)($_POST['texto_declaracion'] ?? ''));
            $observaciones = cleanText((string)($_POST['observaciones'] ?? ''));
            $fechaDeclaracionRaw = trim((string)($_POST['fecha_declaracion'] ?? ''));

            $tiposDeclarantePermitidos = ['alumno', 'apoderado', 'docente', 'asistente', 'externo', 'otro'];
            if (!in_array($tipoDeclarante, $tiposDeclarantePermitidos, true)) {
                $tipoDeclarante = 'externo';
            }

            $calidadesPermitidas = ['victima', 'denunciante', 'denunciado', 'testigo', 'involucrado', 'declarante', 'otro'];
            if (!in_array($calidadProcesal, $calidadesPermitidas, true)) {
                $calidadProcesal = 'declarante';
            }

            if ($participanteId > 0) {
                $stmtP = $pdo->prepare("\n                    SELECT\n                        p.id,
                        p.tipo_persona,
                        p.nombre_referencial,
                        p.run,
                        p.rol_en_caso\n                    FROM caso_participantes p\n                    INNER JOIN casos c\n                        ON c.id = p.caso_id\n                       AND c.colegio_id = ?\n                    WHERE p.id = ?\n                      AND p.caso_id = ?\n                    LIMIT 1\n                ");
                $stmtP->execute([$colegioId, $participanteId, $casoId]);
                $participante = $stmtP->fetch();

                if (!$participante) {
                    throw new RuntimeException('El interviniente seleccionado no pertenece al expediente activo.');
                }

                if ($nombreDeclarante === '') {
                    $nombreDeclarante = clean((string)($participante['nombre_referencial'] ?? ''));
                }

                if ($runDeclarante === '' || $runDeclarante === '0-0') {
                    $runDeclarante = cleanRun((string)($participante['run'] ?? '0-0'));
                }

                $tipoParticipante = clean((string)($participante['tipo_persona'] ?? 'externo'));
                if (in_array($tipoParticipante, $tiposDeclarantePermitidos, true)) {
                    $tipoDeclarante = $tipoParticipante;
                }

                $rolParticipante = strtolower(trim((string)($participante['rol_en_caso'] ?? '')));
                $rolMapa = [
                    'victima'     => 'victima',
                    'denunciante' => 'denunciante',
                    'denunciado'  => 'denunciado',
                    'testigo'     => 'testigo',
                    'involucrado' => 'involucrado',
                ];
                if (isset($rolMapa[$rolParticipante])) {
                    $calidadProcesal = $rolMapa[$rolParticipante];
                }
            }

            if ($nombreDeclarante === '') {
                throw new RuntimeException('El nombre del declarante es obligatorio.');
            }

            if ($textoDeclaracion === '') {
                throw new RuntimeException('El texto de la declaración es obligatorio.');
            }

            if ($runDeclarante === '') {
                $runDeclarante = '0-0';
            }

            $fechaDeclaracion = date('Y-m-d H:i:s');
            if ($fechaDeclaracionRaw !== '') {
                $dtParsed = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $fechaDeclaracionRaw)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fechaDeclaracionRaw)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $fechaDeclaracionRaw);

                if (!$dtParsed) {
                    throw new RuntimeException('La fecha de declaración no tiene un formato válido.');
                }

                if ($dtParsed->getTimestamp() > time() + 300) {
                    throw new RuntimeException('La fecha de declaración no puede ser futura.');
                }

                $fechaDeclaracion = $dtParsed->format('Y-m-d H:i:s');
            }

            $evidenciaAdjunta = null;
            if (isset($_FILES['evidencia_archivo']) && is_array($_FILES['evidencia_archivo'])) {
                $archivo = $_FILES['evidencia_archivo'];
                $errorArchivo = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

                if ($errorArchivo !== UPLOAD_ERR_NO_FILE) {
                    if ($errorArchivo !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('No fue posible recibir correctamente el archivo adjunto.');
                    }

                    $nombreOriginal = basename((string)($archivo['name'] ?? ''));
                    $tmpName = (string)($archivo['tmp_name'] ?? '');
                    $size = (int)($archivo['size'] ?? 0);

                    if ($nombreOriginal === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
                        throw new RuntimeException('El archivo adjunto no es válido.');
                    }

                    $maxBytes = 20 * 1024 * 1024;
                    if ($size <= 0 || $size > $maxBytes) {
                        throw new RuntimeException('El archivo adjunto supera el tamaño permitido de 20 MB.');
                    }

                    $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
                    $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'mp3', 'mp4'];
                    if ($extension === '' || !in_array($extension, $permitidas, true)) {
                        throw new RuntimeException('Tipo de archivo adjunto no permitido.');
                    }

                    $tipoEvidencia = clean((string)($_POST['evidencia_tipo'] ?? 'archivo'));
                    $tiposEvidenciaPermitidos = ['documento', 'imagen', 'audio', 'video', 'archivo'];
                    if (!in_array($tipoEvidencia, $tiposEvidenciaPermitidos, true)) {
                        $tipoEvidencia = 'archivo';
                    }

                    $descripcionEvidencia = clean((string)($_POST['evidencia_descripcion'] ?? ''));
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($tmpName) ?: 'application/octet-stream';

                    $baseEvidencias = defined('EVIDENCE_PATH') ? rtrim((string)EVIDENCE_PATH, DIRECTORY_SEPARATOR) : rtrim(BASE_PATH . '/storage/evidencias', DIRECTORY_SEPARATOR);
                    $directorio = $baseEvidencias . DIRECTORY_SEPARATOR . 'caso_' . $casoId;

                    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
                        throw new RuntimeException('No fue posible preparar el directorio de evidencias.');
                    }

                    $nombreSeguro = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombreOriginal);
                    $rutaFisica = $directorio . DIRECTORY_SEPARATOR . $nombreSeguro;

                    if (!move_uploaded_file($tmpName, $rutaFisica)) {
                        throw new RuntimeException('No fue posible guardar el archivo adjunto.');
                    }

                    $evidenciaAdjunta = [
                        'tipo' => $tipoEvidencia,
                        'nombre_original' => $nombreOriginal,
                        'ruta_relativa' => 'storage/evidencias/caso_' . $casoId . '/' . $nombreSeguro,
                        'descripcion' => $descripcionEvidencia !== '' ? $descripcionEvidencia : ('Adjunto asociado a declaración de ' . $nombreDeclarante),
                        'mime' => $mime,
                        'size' => $size,
                    ];
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                INSERT INTO caso_declaraciones (\n                    caso_id,
                    participante_id,
                    tipo_declarante,
                    nombre_declarante,
                    run_declarante,
                    calidad_procesal,
                    fecha_declaracion,
                    texto_declaracion,
                    requiere_reanalisis_ia,
                    observaciones,
                    tomada_por\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)\n            ");
            $stmt->execute([
                $casoId,
                $participanteId > 0 ? $participanteId : null,
                $tipoDeclarante,
                mb_substr($nombreDeclarante, 0, 150, 'UTF-8'),
                $runDeclarante !== '' ? mb_substr($runDeclarante, 0, 20, 'UTF-8') : '0-0',
                $calidadProcesal,
                $fechaDeclaracion,
                $textoDeclaracion,
                $observaciones !== '' ? $observaciones : null,
                $userId ?: null,
            ]);
            $declaracionId = (int)$pdo->lastInsertId();

            $pdo->prepare("\n                UPDATE casos\n                SET requiere_reanalisis_ia = 1,
                    updated_at = NOW()\n                WHERE id = ?\n                  AND colegio_id = ?\n            ")->execute([$casoId, $colegioId]);

            $detalleHistorial = 'Se registró declaración de: ' . $nombreDeclarante . ' en calidad de ' . caso_label($calidadProcesal) . '.';

            $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id\n                ) VALUES (?, 'declaracion', 'Declaración registrada', ?, ?)\n            ")->execute([
                $casoId,
                $detalleHistorial,
                $userId ?: null,
            ]);

            if ($evidenciaAdjunta !== null) {
                $stmtEv = $pdo->prepare("\n                    INSERT INTO caso_evidencias (\n                        caso_id,
                        tipo,
                        nombre_archivo,
                        ruta,
                        descripcion,
                        mime_type,
                        tamano_bytes,
                        subido_por\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                $stmtEv->execute([
                    $casoId,
                    $evidenciaAdjunta['tipo'],
                    $evidenciaAdjunta['nombre_original'],
                    $evidenciaAdjunta['ruta_relativa'],
                    $evidenciaAdjunta['descripcion'],
                    $evidenciaAdjunta['mime'],
                    $evidenciaAdjunta['size'],
                    $userId ?: null,
                ]);
                $evidenciaId = (int)$pdo->lastInsertId();

                $pdo->prepare("\n                    INSERT INTO caso_historial (\n                        caso_id,
                        tipo_evento,
                        titulo,
                        detalle,
                        user_id\n                    ) VALUES (?, 'evidencia', 'Evidencia adjunta a declaración', ?, ?)\n                ")->execute([
                    $casoId,
                    'Se adjuntó evidencia asociada a la declaración de ' . $nombreDeclarante . ': ' . $evidenciaAdjunta['nombre_original'] . '.',
                    $userId ?: null,
                ]);
            }

            registrar_hito($pdo, $casoId, $colegioId, 101, $userId);
            if ($evidenciaAdjunta !== null) {
                registrar_hito($pdo, $casoId, $colegioId, 106, $userId);
            }

            $pdo->commit();

            registrar_bitacora(
                'denuncias',
                'agregar_declaracion',
                'caso_declaraciones',
                $declaracionId,
                'Declaración agregada al caso.'
            );

            if ($evidenciaAdjunta !== null && isset($evidenciaId)) {
                registrar_bitacora(
                    'denuncias',
                    'adjuntar_evidencia_declaracion',
                    'caso_evidencias',
                    $evidenciaId,
                    'Evidencia adjunta durante el registro de una declaración.'
                );
            }

            caso_redirect($casoId, 'declaraciones');
        }

        if ($accion === 'subir_evidencia') {
            if (!Auth::canOperate()) {
                throw new RuntimeException('No tienes permisos para subir evidencias en este expediente.');
            }

            $tipo = clean((string)($_POST['tipo'] ?? 'archivo'));
            $descripcion = clean((string)($_POST['descripcion'] ?? ''));

            $tiposPermitidos = ['archivo', 'imagen', 'documento', 'audio', 'video', 'otro'];
            if (!in_array($tipo, $tiposPermitidos, true)) {
                $tipo = 'archivo';
            }

            if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
                throw new RuntimeException('Debes seleccionar un archivo válido.');
            }

            $archivo = $_FILES['archivo'];
            $errorArchivo = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($errorArchivo !== UPLOAD_ERR_OK) {
                $mensajeArchivo = match ($errorArchivo) {
                    UPLOAD_ERR_NO_FILE => 'Debes seleccionar un archivo para subir.',
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido.',
                    default => 'No fue posible recibir correctamente el archivo.',
                };
                throw new RuntimeException($mensajeArchivo);
            }

            $nombreOriginal = basename((string)($archivo['name'] ?? ''));
            $tmpName = (string)($archivo['tmp_name'] ?? '');
            $tamanoBytes = (int)($archivo['size'] ?? 0);

            if ($nombreOriginal === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('El archivo recibido no es válido.');
            }

            $maxBytes = 20 * 1024 * 1024;
            if ($tamanoBytes <= 0 || $tamanoBytes > $maxBytes) {
                throw new RuntimeException('El archivo supera el tamaño permitido de 20 MB.');
            }

            $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
            $extensionesPermitidas = [
                'pdf',
                'jpg',
                'jpeg',
                'png',
                'webp',
                'doc',
                'docx',
                'xls',
                'xlsx',
                'txt',
                'mp3',
                'mp4',
            ];

            if ($extension === '' || !in_array($extension, $extensionesPermitidas, true)) {
                throw new RuntimeException('Tipo de archivo no permitido.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)($finfo->file($tmpName) ?: 'application/octet-stream');
            $mimeLower = strtolower($mime);

            $mimePermitido = false;
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $mimePermitido = str_starts_with($mimeLower, 'image/');
                $tipo = $tipo === 'archivo' ? 'imagen' : $tipo;
            } elseif ($extension === 'pdf') {
                $mimePermitido = in_array($mimeLower, ['application/pdf', 'application/x-pdf'], true);
                $tipo = $tipo === 'archivo' ? 'documento' : $tipo;
            } elseif (in_array($extension, ['txt'], true)) {
                $mimePermitido = str_starts_with($mimeLower, 'text/') || $mimeLower === 'application/octet-stream';
                $tipo = $tipo === 'archivo' ? 'documento' : $tipo;
            } elseif (in_array($extension, ['doc', 'docx', 'xls', 'xlsx'], true)) {
                $mimePermitido = true;
                $tipo = $tipo === 'archivo' ? 'documento' : $tipo;
            } elseif ($extension === 'mp3') {
                $mimePermitido = str_starts_with($mimeLower, 'audio/') || $mimeLower === 'application/octet-stream';
                $tipo = $tipo === 'archivo' ? 'audio' : $tipo;
            } elseif ($extension === 'mp4') {
                $mimePermitido = str_starts_with($mimeLower, 'video/') || $mimeLower === 'application/octet-stream';
                $tipo = $tipo === 'archivo' ? 'video' : $tipo;
            }

            if (!$mimePermitido) {
                throw new RuntimeException('El contenido del archivo no coincide con un tipo permitido.');
            }

            $baseEvidencias = defined('EVIDENCE_PATH')
                ? rtrim((string)EVIDENCE_PATH, DIRECTORY_SEPARATOR)
                : rtrim(BASE_PATH . '/storage/evidencias', DIRECTORY_SEPARATOR);

            $directorio = $baseEvidencias . DIRECTORY_SEPARATOR . 'caso_' . $casoId;

            if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
                throw new RuntimeException('No fue posible preparar el directorio de evidencias.');
            }

            $nombreSeguroBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombreOriginal);
            $nombreSeguroBase = trim((string)$nombreSeguroBase, '._-');
            if ($nombreSeguroBase === '') {
                $nombreSeguroBase = 'evidencia.' . $extension;
            }

            $nombreSeguro = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $nombreSeguroBase;
            $rutaFisica = $directorio . DIRECTORY_SEPARATOR . $nombreSeguro;
            $rutaRelativa = 'storage/evidencias/caso_' . $casoId . '/' . $nombreSeguro;
            $archivoGuardado = false;

            if (!move_uploaded_file($tmpName, $rutaFisica)) {
                throw new RuntimeException('No fue posible guardar el archivo en el servidor.');
            }
            $archivoGuardado = true;

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("\n                    INSERT INTO caso_evidencias (\n                        caso_id,
                        tipo,
                        nombre_archivo,
                        ruta,
                        descripcion,
                        mime_type,
                        tamano_bytes,
                        subido_por\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                $stmt->execute([
                    $casoId,
                    $tipo,
                    $nombreOriginal,
                    $rutaRelativa,
                    $descripcion !== '' ? $descripcion : null,
                    $mime,
                    $tamanoBytes,
                    $userId ?: null,
                ]);

                $evidenciaId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("\n                    INSERT INTO caso_historial (\n                        caso_id,
                        tipo_evento,
                        titulo,
                        detalle,
                        user_id,
                        created_at\n                    ) VALUES (?, 'evidencia', 'Evidencia agregada', ?, ?, NOW())\n                ");
                $stmt->execute([
                    $casoId,
                    'Se subió evidencia al expediente: ' . $nombreOriginal . '.',
                    $userId ?: null,
                ]);

                registrar_hito($pdo, $casoId, $colegioId, 106, $userId);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($archivoGuardado && is_file($rutaFisica)) {
                    @unlink($rutaFisica);
                }

                throw $e;
            }

            registrar_bitacora(
                'denuncias',
                'subir_evidencia',
                'caso_evidencias',
                $evidenciaId,
                'Evidencia agregada al expediente.'
            );

            if (function_exists('invalidar_cache_dashboard')) {
                invalidar_cache_dashboard($colegioId);
            }

            caso_redirect($casoId, 'evidencias');
        }

        if ($accion === 'actualizar_clasificacion') {
            $clasificacionIa = clean((string)($_POST['clasificacion_ia'] ?? ''));
            $resumenIa = clean((string)($_POST['resumen_ia'] ?? ''));
            $recomendacionIa = clean((string)($_POST['recomendacion_ia'] ?? ''));
            $requiereReanalisis = isset($_POST['requiere_reanalisis_ia']) ? 1 : 0;

            $areaMineduc = clean((string)($_POST['area_mineduc'] ?? ''));
            $ambitoMineduc = clean((string)($_POST['ambito_mineduc'] ?? ''));
            $tipoConducta = clean((string)($_POST['tipo_conducta'] ?? ''));
            $categoriaConvivencia = clean((string)($_POST['categoria_convivencia'] ?? ''));
            $conductaPrincipal = clean((string)($_POST['conducta_principal'] ?? ''));
            $gravedad = clean((string)($_POST['gravedad'] ?? 'media'));
            $causalAulaSegura = clean((string)($_POST['causal_aula_segura'] ?? ''));
            $fundamentoAulaSegura = clean((string)($_POST['fundamento_aula_segura'] ?? ''));
            $entidadDerivacion = clean((string)($_POST['entidad_derivacion'] ?? ''));
            $plazoRevision = clean((string)($_POST['plazo_revision'] ?? ''));
            $observacionesNormativas = clean((string)($_POST['observaciones_normativas'] ?? ''));

            if (!in_array($gravedad, ['baja', 'media', 'alta', 'critica'], true)) {
                $gravedad = 'media';
            }

            if ($plazoRevision !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $plazoRevision)) {
                throw new RuntimeException('La fecha de próxima revisión normativa no es válida.');
            }

            $reiteracion = isset($_POST['reiteracion']) ? 1 : 0;
            $involucraAdulto = isset($_POST['involucra_adulto']) ? 1 : 0;
            $discriminacion = isset($_POST['discriminacion']) ? 1 : 0;
            $ciberacoso = isset($_POST['ciberacoso']) ? 1 : 0;
            $acosoEscolar = isset($_POST['acoso_escolar']) ? 1 : 0;
            $violenciaFisica = isset($_POST['violencia_fisica']) ? 1 : 0;
            $violenciaPsicologica = isset($_POST['violencia_psicologica']) ? 1 : 0;
            $violenciaSexual = isset($_POST['violencia_sexual']) ? 1 : 0;
            $maltratoAdultoEstudiante = isset($_POST['maltrato_adulto_estudiante']) ? 1 : 0;
            $posibleAulaSegura = isset($_POST['posible_aula_segura']) ? 1 : 0;
            $requiereDenuncia = isset($_POST['requiere_denuncia']) ? 1 : 0;

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                INSERT INTO caso_clasificacion_normativa (\n                    colegio_id, caso_id, area_mineduc, ambito_mineduc, tipo_conducta,
                    categoria_convivencia, conducta_principal, gravedad, reiteracion, involucra_adulto,
                    discriminacion, ciberacoso, acoso_escolar, violencia_fisica, violencia_psicologica,
                    violencia_sexual, maltrato_adulto_estudiante, posible_aula_segura, causal_aula_segura,
                    fundamento_aula_segura, requiere_denuncia, entidad_derivacion, plazo_revision,
                    observaciones_normativas, creado_por, created_at, updated_at\n                ) VALUES (\n                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()\n                )\n                ON DUPLICATE KEY UPDATE\n                    area_mineduc = VALUES(area_mineduc),
                    ambito_mineduc = VALUES(ambito_mineduc),
                    tipo_conducta = VALUES(tipo_conducta),
                    categoria_convivencia = VALUES(categoria_convivencia),
                    conducta_principal = VALUES(conducta_principal),
                    gravedad = VALUES(gravedad),
                    reiteracion = VALUES(reiteracion),
                    involucra_adulto = VALUES(involucra_adulto),
                    discriminacion = VALUES(discriminacion),
                    ciberacoso = VALUES(ciberacoso),
                    acoso_escolar = VALUES(acoso_escolar),
                    violencia_fisica = VALUES(violencia_fisica),
                    violencia_psicologica = VALUES(violencia_psicologica),
                    violencia_sexual = VALUES(violencia_sexual),
                    maltrato_adulto_estudiante = VALUES(maltrato_adulto_estudiante),
                    posible_aula_segura = VALUES(posible_aula_segura),
                    causal_aula_segura = VALUES(causal_aula_segura),
                    fundamento_aula_segura = VALUES(fundamento_aula_segura),
                    requiere_denuncia = VALUES(requiere_denuncia),
                    entidad_derivacion = VALUES(entidad_derivacion),
                    plazo_revision = VALUES(plazo_revision),
                    observaciones_normativas = VALUES(observaciones_normativas),
                    updated_at = NOW()\n            ");
            $stmt->execute([
                $colegioId,
                $casoId,
                $areaMineduc !== '' ? $areaMineduc : null,
                $ambitoMineduc !== '' ? $ambitoMineduc : null,
                $tipoConducta !== '' ? $tipoConducta : null,
                $categoriaConvivencia !== '' ? $categoriaConvivencia : null,
                $conductaPrincipal !== '' ? $conductaPrincipal : null,
                $gravedad,
                $reiteracion,
                $involucraAdulto,
                $discriminacion,
                $ciberacoso,
                $acosoEscolar,
                $violenciaFisica,
                $violenciaPsicologica,
                $violenciaSexual,
                $maltratoAdultoEstudiante,
                $posibleAulaSegura,
                $causalAulaSegura !== '' ? $causalAulaSegura : null,
                $fundamentoAulaSegura !== '' ? $fundamentoAulaSegura : null,
                $requiereDenuncia,
                $entidadDerivacion !== '' ? $entidadDerivacion : null,
                $plazoRevision !== '' ? $plazoRevision : null,
                $observacionesNormativas !== '' ? $observacionesNormativas : null,
                $userId ?: null,
            ]);

            $stmt = $pdo->prepare("\n                UPDATE casos\n                SET clasificacion_ia = ?,
                    resumen_ia = ?,
                    recomendacion_ia = ?,
                    requiere_reanalisis_ia = ?,
                    semaforo = CASE\n                        WHEN ? = 'critica' THEN 'rojo'\n                        WHEN ? = 'alta' THEN 'rojo'\n                        WHEN ? = 'media' THEN COALESCE(semaforo, 'amarillo')\n                        ELSE COALESCE(semaforo, 'verde')\n                    END,
                    prioridad = CASE\n                        WHEN ? = 'critica' THEN 'alta'\n                        WHEN ? = 'alta' THEN 'alta'\n                        WHEN ? = 'media' THEN COALESCE(prioridad, 'media')\n                        ELSE COALESCE(prioridad, 'baja')\n                    END,
                    updated_at = NOW()\n                WHERE id = ?\n                  AND colegio_id = ?\n            ");
            $stmt->execute([
                $clasificacionIa !== '' ? $clasificacionIa : ($tipoConducta !== '' ? $tipoConducta : null),
                $resumenIa !== '' ? $resumenIa : null,
                $recomendacionIa !== '' ? $recomendacionIa : null,
                $requiereReanalisis,
                $gravedad,
                $gravedad,
                $gravedad,
                $gravedad,
                $gravedad,
                $gravedad,
                $casoId,
                $colegioId,
            ]);

            $detalle = 'Se actualizó clasificación normativa. Tipo conducta: ' . ($tipoConducta !== '' ? $tipoConducta : 'sin clasificar') . '. Gravedad: ' . caso_label($gravedad) . '.';

            if ($posibleAulaSegura === 1) {
                $detalle .= ' Marcado como posible Aula Segura.';
            }

            if ($requiereDenuncia === 1) {
                $detalle .= ' Requiere denuncia o derivación.';
            }

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id\n                ) VALUES (?, 'clasificacion', 'Clasificación normativa actualizada', ?, ?)\n            ");
            $stmt->execute([
                $casoId,
                $detalle,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'actualizar_clasificacion_normativa',
                'caso_clasificacion_normativa',
                $casoId,
                'Clasificación normativa del caso actualizada.'
            );

            $pdo->commit();

            caso_redirect($casoId, 'clasificacion');
        }

        if ($accion === 'agregar_alerta') {
            $tipo = clean((string)($_POST['tipo'] ?? 'alerta'));
            $mensaje = clean((string)($_POST['mensaje'] ?? ''));
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));

            if ($mensaje === '') {
                throw new RuntimeException('El mensaje de la alerta es obligatorio.');
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
                $prioridad = 'media';
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_alertas (
                    caso_id,
                    tipo,
                    mensaje,
                    prioridad,
                    estado,
                    fecha_alerta
                ) VALUES (?, ?, ?, ?, 'pendiente', NOW())
            ");
            $stmt->execute([
                $casoId,
                $tipo,
                $mensaje,
                $prioridad,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'alerta', 'Alerta registrada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se registró alerta: ' . $mensaje,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_alerta',
                'caso_alertas',
                (int)$pdo->lastInsertId(),
                'Alerta agregada al caso.'
            );

            caso_redirect($casoId, 'historial');
        }

        if ($accion === 'agregar_historial') {
            $tipoEvento = clean((string)($_POST['tipo_evento'] ?? 'actualizacion'));
            $titulo = clean((string)($_POST['titulo'] ?? ''));
            $detalle = clean((string)($_POST['detalle'] ?? ''));

            $tiposHistorialPermitidos = [
                'creacion',
                'cambio_estado',
                'declaracion',
                'participante',
                'alerta',
                'evidencia',
                'plan_accion',
                'seguimiento',
                'gestion',
                'gestion_ejecutiva',
                'comunicacion_apoderado',
                'cierre',
                'reapertura',
                'aula_segura',
                'analisis_ia',
                'borrador',
                'actualizacion',
                'registro_desde_borrador',
                'actualizacion_borrador',
                'nota',
                'manual',
            ];

            if (!in_array($tipoEvento, $tiposHistorialPermitidos, true)) {
                $tipoEvento = 'actualizacion';
            }

            if ($titulo === '') {
                throw new RuntimeException('El título del registro es obligatorio.');
            }

            if ($detalle === '') {
                throw new RuntimeException('El detalle del historial es obligatorio.');
            }

            $titulo = mb_substr($titulo, 0, 150, 'UTF-8');

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $casoId,
                $tipoEvento,
                $titulo,
                $detalle,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_historial',
                'caso_historial',
                (int)$pdo->lastInsertId(),
                'Registro agregado al historial del caso.'
            );

            caso_redirect($casoId, 'historial');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
