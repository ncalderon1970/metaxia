<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/seguimiento.php
 * Fase 2C
 */

if ($accion === 'guardar_plan_accion') {
            $pId         = (int)($_POST['participante_id']  ?? 0);
            $planTexto   = trim((string)($_POST['plan_accion']        ?? ''));
            $medidasP    = trim((string)($_POST['medidas_preventivas'] ?? ''));
            $motivoV     = trim((string)($_POST['motivo_version']     ?? 'Plan inicial'));
            $esMod       = (int)($_POST['es_modificacion']  ?? 0);
            $planAntId   = (int)($_POST['plan_anterior_id'] ?? 0);
            $userId2     = (int)(Auth::user()['id'] ?? 0);

            if ($pId <= 0 || $planTexto === '') {
                throw new RuntimeException('Participante y plan de acción son obligatorios.');
            }

            // Desactivar versión anterior
            if ($planAntId > 0) {
                $pdo->prepare("UPDATE caso_plan_accion SET vigente=0 WHERE id=? AND colegio_id=?")
                    ->execute([$planAntId, $colegioId]);
            }

            // Obtener número de versión
            $stmtVer = $pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM caso_plan_accion WHERE caso_id=? AND colegio_id=? AND participante_id=?");
            $stmtVer->execute([$casoId, $colegioId, $pId]);
            $version = (int)$stmtVer->fetchColumn();

            $pdo->prepare("
                INSERT INTO caso_plan_accion
                    (caso_id, colegio_id, participante_id, plan_accion, medidas_preventivas,
                     version, vigente, motivo_version, estado_plan, creado_por, created_at, updated_at)
                VALUES (?,?,?,?,?,?,1,?,?,?,NOW(),NOW())
            ")->execute([
                $casoId, $colegioId, $pId, $planTexto, $medidasP ?: null,
                $version, $motivoV, 'activo', $userId2 ?: null,
            ]);

            registrar_bitacora('denuncias', 'plan_accion', 'caso_plan_accion',
                (int)$pdo->lastInsertId(), "Plan de acción v{$version} guardado.");

            registrar_hito($pdo, $casoId, $colegioId, 103, $userId2);

            caso_redirect($casoId, 'plan_accion');
        }

        // ── Sesión de Seguimiento ───────────────────────────────────

if ($accion === 'guardar_sesion_seguimiento') {
            $pId        = (int)($_POST['participante_id']           ?? 0);
            $planId     = (int)($_POST['plan_accion_id']            ?? 0) ?: null;
            $obs        = trim((string)($_POST['observacion_avance']        ?? ''));
            $medidasSes = trim((string)($_POST['medidas_sesion']            ?? ''));
            $estadoCaso = trim((string)($_POST['estado_caso']               ?? 'en_proceso'));
            $cumpl      = trim((string)($_POST['cumplimiento_plan']         ?? 'en_proceso'));
            $proxRev    = trim((string)($_POST['proxima_revision']          ?? ''));
            $comApo     = trim((string)($_POST['comunicacion_apoderado']    ?? ''));
            $fechaCom   = trim((string)($_POST['fecha_comunicacion_apoderado'] ?? ''));
            $notasCom   = trim((string)($_POST['notas_comunicacion']        ?? ''));
            $userId2    = (int)(Auth::user()['id'] ?? 0);

            if ($pId <= 0 || $obs === '') {
                throw new RuntimeException('Participante y observación son obligatorios.');
            }

            $pdo->prepare("
                INSERT INTO caso_seguimiento_sesion
                    (caso_id, colegio_id, participante_id, plan_accion_id,
                     observacion_avance, medidas_sesion, estado_caso, cumplimiento_plan,
                     proxima_revision, comunicacion_apoderado, fecha_comunicacion_apoderado,
                     notas_comunicacion, registrado_por, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
            ")->execute([
                $casoId, $colegioId, $pId, $planId ?: null,
                $obs, $medidasSes ?: null, $estadoCaso, $cumpl,
                ($proxRev !== '' && strtotime($proxRev)) ? $proxRev : null,
                $comApo ?: null,
                ($fechaCom !== '' && strtotime($fechaCom)) ? $fechaCom : null,
                $notasCom ?: null,
                $userId2 ?: null,
            ]);

            registrar_bitacora('denuncias', 'seguimiento_sesion', 'caso_seguimiento_sesion',
                (int)$pdo->lastInsertId(), 'Sesión de seguimiento registrada.');

            registrar_hito($pdo, $casoId, $colegioId, 104, $userId2);

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

            $stmtParticipantes = $pdo->prepare("\n                SELECT *\n                FROM caso_participantes\n                WHERE caso_id = ?\n                ORDER BY id ASC\n            ");
            $stmtParticipantes->execute([$casoId]);
            $participantesSeguimiento = $stmtParticipantes->fetchAll();

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                INSERT INTO caso_seguimiento (\n                    colegio_id,\n                    caso_id,\n                    fecha_apertura,\n                    observacion_avance,\n                    proxima_revision,\n                    estado_seguimiento,\n                    medidas_preventivas,\n                    cumplimiento,\n                    comunicacion_apoderado_modalidad,\n                    comunicacion_apoderado_fecha,\n                    notas_comunicacion,\n                    actualizado_por,\n                    created_at,\n                    updated_at\n                ) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n                ON DUPLICATE KEY UPDATE\n                    colegio_id = VALUES(colegio_id),\n                    observacion_avance = VALUES(observacion_avance),\n                    proxima_revision = VALUES(proxima_revision),\n                    estado_seguimiento = VALUES(estado_seguimiento),\n                    medidas_preventivas = VALUES(medidas_preventivas),\n                    cumplimiento = VALUES(cumplimiento),\n                    comunicacion_apoderado_modalidad = VALUES(comunicacion_apoderado_modalidad),\n                    comunicacion_apoderado_fecha = VALUES(comunicacion_apoderado_fecha),\n                    notas_comunicacion = VALUES(notas_comunicacion),\n                    actualizado_por = VALUES(actualizado_por),\n                    updated_at = NOW()\n            ");
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

            $stmtInsertPart = $pdo->prepare("\n                INSERT INTO caso_seguimiento_participantes (\n                    colegio_id,\n                    caso_id,\n                    seguimiento_id,\n                    participante_id,\n                    tipo_participante,\n                    nombre_participante,\n                    run_participante,\n                    condicion,\n                    plan_accion,\n                    estado,\n                    created_at,\n                    updated_at\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n            ");

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

            $stmtHist = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'seguimiento', 'Seguimiento y cumplimiento actualizado', ?, ?)\n            ");
            $stmtHist->execute([
                $casoId,
                $detalleHistorial,
                $userId ?: null,
            ]);

            registrar_hito($pdo, $casoId, $colegioId, 104, $userId);

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
                $stmt = $pdo->prepare("\n                    INSERT INTO caso_aula_segura_historial (\n                        caso_id,\n                        caso_aula_segura_id,\n                        colegio_id,\n                        accion,\n                        estado_anterior,\n                        estado_nuevo,\n                        detalle,\n                        usuario_id,\n                        created_at\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())\n                ");
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
                } catch (Throwable $e) {}
            }
        }