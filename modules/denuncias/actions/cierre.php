<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/cierre.php
 * Fase 2C
 */

if ($accion === 'registrar_cierre_formal') {
            $fechaCierre = clean((string)($_POST['fecha_cierre'] ?? date('Y-m-d')));
            $tipoCierre = clean((string)($_POST['tipo_cierre'] ?? 'resuelto'));
            $fundamento = clean((string)($_POST['fundamento'] ?? ''));
            $medidasFinales = clean((string)($_POST['medidas_finales'] ?? ''));
            $acuerdos = clean((string)($_POST['acuerdos'] ?? ''));
            $derivaciones = clean((string)($_POST['derivaciones'] ?? ''));
            $observaciones = clean((string)($_POST['observaciones'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCierre)) {
                throw new RuntimeException('La fecha de cierre no es válida.');
            }

            if (!in_array($tipoCierre, ['resuelto', 'derivado', 'desestimado', 'acuerdo', 'otro'], true)) {
                $tipoCierre = 'resuelto';
            }

            if ($fundamento === '') {
                throw new RuntimeException('Debe registrar el fundamento o síntesis del cierre.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                UPDATE caso_cierre\n                SET estado_cierre = 'anulado',\n                    anulado_por = ?,\n                    anulado_at = NOW(),\n                    motivo_anulacion = 'Reemplazado por nuevo cierre formal',\n                    updated_at = NOW()\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n                  AND estado_cierre = 'vigente'\n            ");
            $stmt->execute([$userId ?: null, $casoId, $colegioId]);

            $stmt = $pdo->prepare("\n                INSERT INTO caso_cierre (\n                    colegio_id,\n                    caso_id,\n                    fecha_cierre,\n                    tipo_cierre,\n                    fundamento,\n                    medidas_finales,\n                    acuerdos,\n                    derivaciones,\n                    observaciones,\n                    estado_cierre,\n                    cerrado_por,\n                    created_at,\n                    updated_at\n                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'vigente', ?, NOW(), NOW())\n            ");
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

            $estadoCerradoId = null;
            try {
                $stmtEstado = $pdo->prepare("SELECT id FROM estado_caso WHERE codigo = 'cerrado' LIMIT 1");
                $stmtEstado->execute();
                $estadoCerradoId = $stmtEstado->fetchColumn() ?: null;
            } catch (Throwable $e) {}

            $stmt = $pdo->prepare("\n                UPDATE casos\n                SET estado = 'cerrado',\n                    estado_caso_id = COALESCE(?, estado_caso_id),\n                    updated_at = NOW()\n                WHERE id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
            $stmt->execute([$estadoCerradoId ?: null, $casoId, $colegioId]);

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'cierre', 'Cierre formal del expediente', ?, ?)\n            ");
            $stmt->execute([
                $casoId,
                'Se registró cierre formal del caso. Tipo: ' . caso_label($tipoCierre) . '. Fundamento: ' . $fundamento,
                $userId ?: null,
            ]);

            registrar_hito($pdo, $casoId, $colegioId, 110, $userId);

            registrar_bitacora(
                'denuncias',
                'registrar_cierre_formal',
                'caso_cierre',
                $cierreId,
                'Cierre formal registrado para el expediente.'
            );

            $pdo->commit();
            invalidar_cache_dashboard($colegioId);

            caso_redirect($casoId, 'cierre');
        }

if ($accion === 'reabrir_caso') {
            $motivo = clean((string)($_POST['motivo_reapertura'] ?? ''));

            if ($motivo === '') {
                throw new RuntimeException('Debe indicar el motivo de reapertura.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("\n                UPDATE caso_cierre\n                SET estado_cierre = 'anulado',\n                    anulado_por = ?,\n                    anulado_at = NOW(),\n                    motivo_anulacion = ?,\n                    updated_at = NOW()\n                WHERE caso_id = ?\n                  AND colegio_id = ?\n                  AND estado_cierre = 'vigente'\n                LIMIT 1\n            ");
            $stmt->execute([$userId ?: null, $motivo, $casoId, $colegioId]);

            $stmt = $pdo->prepare("\n                UPDATE casos\n                SET estado = 'abierto',\n                    updated_at = NOW()\n                WHERE id = ?\n                  AND colegio_id = ?\n                LIMIT 1\n            ");
            $stmt->execute([$casoId, $colegioId]);

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'reapertura', 'Reapertura del expediente', ?, ?)\n            ");
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
            invalidar_cache_dashboard($colegioId);

            caso_redirect($casoId, 'cierre');
        }