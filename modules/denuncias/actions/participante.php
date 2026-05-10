<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/participante.php
 * Fase 2C
 */

if ($accion === 'agregar_participante') {
            $tipoPersona = clean((string)($_POST['tipo_persona'] ?? 'externo'));
            $nombre = clean((string)($_POST['nombre_referencial'] ?? ''));
            $run = cleanRun((string)($_POST['run'] ?? ''));
            $rolEnCaso = clean((string)($_POST['rol_en_caso'] ?? 'involucrado'));
            $reserva = isset($_POST['solicita_reserva_identidad']) ? 1 : 0;
            $observacion = clean((string)($_POST['observacion'] ?? ''));
            $observacionReserva = clean((string)($_POST['observacion_reserva'] ?? ''));

            if ($nombre === '') {
                throw new RuntimeException('El nombre del participante es obligatorio.');
            }

            if ($run === '') {
                $run = '0-0';
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_participantes (
                    caso_id,
                    tipo_persona,
                    nombre_referencial,
                    run,
                    rol_en_caso,
                    solicita_reserva_identidad,
                    observacion_reserva,
                    observacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $tipoPersona,
                $nombre,
                $run,
                $rolEnCaso,
                $reserva,
                $observacionReserva !== '' ? $observacionReserva : null,
                $observacion !== '' ? $observacion : null,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'participante', 'Participante agregado', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se agregó participante: ' . $nombre . ' (' . $rolEnCaso . ').',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_participante',
                'caso_participantes',
                (int)$pdo->lastInsertId(),
                'Participante agregado al caso.'
            );

            // Permite volver al tab desde donde se invocó (ej: declaraciones)
            $redirectTab   = clean((string)($_POST['_redirect_tab'] ?? 'participantes'));
            $tabsPermitidos = ['participantes', 'declaraciones'];
            caso_redirect($casoId, in_array($redirectTab, $tabsPermitidos, true) ? $redirectTab : 'participantes');
        }