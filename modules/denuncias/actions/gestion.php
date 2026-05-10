<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/gestion.php
 * Fase 2C
 */

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

            $stmt = $pdo->prepare("\n                INSERT INTO caso_gestion_ejecutiva (\n                    colegio_id,\n                    caso_id,\n                    titulo,\n                    descripcion,\n                    responsable_nombre,\n                    responsable_rol,\n                    prioridad,\n                    estado,\n                    fecha_compromiso,\n                    creado_por,\n                    created_at,\n                    updated_at\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, NOW(), NOW())\n            ");
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

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'gestion_ejecutiva', 'Acción ejecutiva registrada', ?, ?)\n            ");
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

            avanzar_a_investigacion($pdo, $casoId, $userId);

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

            $stmt = $pdo->prepare("\n                UPDATE caso_gestion_ejecutiva\n                SET estado = ?,\n                    prioridad = ?,\n                    fecha_compromiso = ?,\n                    fecha_cumplimiento = CASE WHEN ? = 1 THEN COALESCE(fecha_cumplimiento, NOW()) ELSE NULL END,\n                    cerrado_por = CASE WHEN ? = 1 THEN ? ELSE NULL END,\n                    updated_at = NOW()\n                WHERE id = ?\n                  AND caso_id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
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

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'gestion_ejecutiva', 'Acción ejecutiva actualizada', ?, ?)\n            ");
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

            avanzar_a_investigacion($pdo, $casoId, $userId);

            caso_redirect($casoId, 'gestion');
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