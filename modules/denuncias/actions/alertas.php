<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/alertas.php
 * Fase 2C
 */

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
            $tipoEvento = clean((string)($_POST['tipo_evento'] ?? 'nota'));
            $titulo = clean((string)($_POST['titulo'] ?? 'Registro'));
            $detalle = clean((string)($_POST['detalle'] ?? ''));

            if ($detalle === '') {
                throw new RuntimeException('El detalle del historial es obligatorio.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $tipoEvento,
                $titulo !== '' ? $titulo : 'Registro',
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
