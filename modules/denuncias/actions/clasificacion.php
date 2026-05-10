<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/clasificacion.php
 * Fase 2C
 */


if ($accion === 'actualizar_contexto_normativo') {
            $marcoLegal          = clean((string)($_POST['marco_legal'] ?? 'ley21809'));
            $involucraTeA        = isset($_POST['involucra_nna_tea']) ? 1 : 0;
            $interesSuperior     = isset($_POST['interes_superior_aplicado']) ? 1 : 0;
            $autonomiaProgresiva = isset($_POST['autonomia_progresiva_considerada']) ? 1 : 0;
            $coordSenape         = isset($_POST['requiere_coordinacion_senape']) ? 1 : 0;
            $coordSalud          = isset($_POST['requiere_coordinacion_salud']) ? 1 : 0;

            $marcosValidos = ['ley21809','rex782','ley21545','ley21430','reglamento','combinado'];
            if (!in_array($marcoLegal, $marcosValidos, true)) {
                $marcoLegal = 'ley21809';
            }

            // Guardar en caso_clasificacion_normativa (columna observaciones_normativas
            // + upsert contexto como JSON en campo dedicado si existe, o como texto)
            $contextoJson = json_encode([
                'marco_legal'                     => $marcoLegal,
                'involucra_nna_tea'               => $involucraTeA,
                'interes_superior_aplicado'       => $interesSuperior,
                'autonomia_progresiva_considerada'=> $autonomiaProgresiva,
                'requiere_coordinacion_senape'    => $coordSenape,
                'requiere_coordinacion_salud'     => $coordSalud,
            ], JSON_UNESCAPED_UNICODE);

            // Intentar guardar en casos (columnas pueden o no existir)
            try {
                $pdo->prepare("
                    UPDATE casos
                    SET marco_legal = ?,
                        involucra_nna_tea = ?,
                        interes_superior_aplicado = ?,
                        autonomia_progresiva_considerada = ?,
                        requiere_coordinacion_senape = ?,
                        requiere_coordinacion_salud = ?,
                        updated_at = NOW()
                    WHERE id = ? AND colegio_id = ?
                ")->execute([
                    $marcoLegal, $involucraTeA, $interesSuperior,
                    $autonomiaProgresiva, $coordSenape, $coordSalud,
                    $casoId, $colegioId,
                ]);
            } catch (Throwable $e) {
                // Columnas extendidas no existen aún — guardar sólo marco_legal
                try {
                    $pdo->prepare("UPDATE casos SET marco_legal = ?, updated_at = NOW() WHERE id = ? AND colegio_id = ?")
                        ->execute([$marcoLegal, $casoId, $colegioId]);
                } catch (Throwable $e2) {
                    error_log('[Metis] contexto_normativo: no se pudo guardar en casos: ' . $e2->getMessage());
                }
            }

            // Guardar flags de contexto en caso_clasificacion_normativa.contexto_normativo_flags
            // (columna opcional — si no existe, se omite silenciosamente)
            try {
                $pdo->prepare("
                    INSERT INTO caso_clasificacion_normativa (caso_id, colegio_id, contexto_normativo_flags, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE contexto_normativo_flags = VALUES(contexto_normativo_flags), updated_at = NOW()
                ")->execute([$casoId, $colegioId, $contextoJson]);
            } catch (Throwable $e) {
                // columna contexto_normativo_flags no existe aún — ignorar
            }

            registrar_bitacora('denuncias', 'actualizar_contexto_normativo', 'casos', $casoId,
                'Contexto normativo actualizado. Marco: ' . $marcoLegal . '.');

            caso_redirect($casoId, 'clasificacion');
        }

if ($accion === 'guardar_marcadores_normativos') {
            $marcadores = array_values(array_unique(array_filter((array)($_POST['marcadores'] ?? []))));

            // Validar contra catálogo: sólo códigos activos permitidos
            if ($marcadores) {
                $ph = implode(',', array_fill(0, count($marcadores), '?'));
                $stmtVal = $pdo->prepare("SELECT codigo FROM marcadores_normativos WHERE codigo IN ($ph) AND activo = 1");
                $stmtVal->execute($marcadores);
                $marcadores = array_column($stmtVal->fetchAll(), 'codigo');
            }

            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM caso_marcadores_normativos WHERE caso_id = ? AND colegio_id = ?")
                ->execute([$casoId, $colegioId]);

            if ($marcadores) {
                $stmtIns = $pdo->prepare("
                    INSERT INTO caso_marcadores_normativos (caso_id, colegio_id, marcador_codigo, user_id, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                foreach ($marcadores as $codigo) {
                    $stmtIns->execute([$casoId, $colegioId, $codigo, $userId ?: null]);
                }
            }

            $pdo->commit();

            registrar_bitacora('denuncias', 'marcadores_normativos', 'caso_marcadores_normativos', $casoId,
                'Marcadores normativos actualizados (' . count($marcadores) . ' marcados).');

            registrar_hito($pdo, $casoId, $colegioId, 107, $userId);

            $returnTab = in_array(($_POST['_return_tab'] ?? ''), ['gestion', 'clasificacion'], true)
                ? $_POST['_return_tab']
                : 'gestion';

            caso_redirect($casoId, $returnTab);
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

            // Marcadores normativos (JSON)
            $flags21809  = array_values(array_filter((array)($_POST['ley21809_flags']  ?? [])));
            $flagsRex782 = array_values(array_filter((array)($_POST['rex782_flags']    ?? [])));
            $json21809   = json_encode($flags21809,  JSON_UNESCAPED_UNICODE);
            $jsonRex782  = json_encode($flagsRex782, JSON_UNESCAPED_UNICODE);

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

            $stmt = $pdo->prepare("
                INSERT INTO caso_clasificacion_normativa (
                    colegio_id, caso_id, area_mineduc, ambito_mineduc, tipo_conducta,
                    categoria_convivencia, conducta_principal, gravedad, reiteracion, involucra_adulto,
                    discriminacion, ciberacoso, acoso_escolar, violencia_fisica, violencia_psicologica,
                    violencia_sexual, maltrato_adulto_estudiante, posible_aula_segura, causal_aula_segura,
                    fundamento_aula_segura, requiere_denuncia, entidad_derivacion, plazo_revision,
                    observaciones_normativas, creado_por, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    area_mineduc = VALUES(area_mineduc),
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
                    updated_at = NOW()
            ");
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

            // Semáforo y prioridad: valor directo del panel 2B si fue enviado; si no, se deriva de gravedad
            $semaforoFinal = in_array(($_POST['semaforo'] ?? ''), ['verde','amarillo','rojo','negro'], true)
                ? $_POST['semaforo']
                : match($gravedad) {
                    'critica', 'alta' => 'rojo',
                    'media'           => 'amarillo',
                    default           => 'verde',
                };
            $prioridadFinal = in_array(($_POST['prioridad'] ?? ''), ['baja','media','alta'], true)
                ? $_POST['prioridad']
                : match($gravedad) {
                    'critica', 'alta' => 'alta',
                    'media'           => 'media',
                    default           => 'baja',
                };
            $estadoCasoIdDirecto = (isset($_POST['estado_caso_id']) && (int)$_POST['estado_caso_id'] > 0)
                ? (int)$_POST['estado_caso_id'] : null;

            $setCasoId = $estadoCasoIdDirecto !== null ? ', estado_caso_id = ?' : '';

            $stmt = $pdo->prepare("
                UPDATE casos
                SET clasificacion_ia = ?,
                    resumen_ia = ?,
                    recomendacion_ia = ?,
                    requiere_reanalisis_ia = ?,
                    semaforo = ?,
                    prioridad = ?{$setCasoId},
                    updated_at = NOW()
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $execParams = [
                $clasificacionIa !== '' ? $clasificacionIa : ($tipoConducta !== '' ? $tipoConducta : null),
                $resumenIa !== '' ? $resumenIa : null,
                $recomendacionIa !== '' ? $recomendacionIa : null,
                $requiereReanalisis,
                $semaforoFinal,
                $prioridadFinal,
            ];
            if ($estadoCasoIdDirecto !== null) {
                $execParams[] = $estadoCasoIdDirecto;
            }
            $execParams[] = $casoId;
            $execParams[] = $colegioId;
            $stmt->execute($execParams);

            $detalle = 'Se actualizó clasificación normativa. Tipo conducta: ' . ($tipoConducta !== '' ? $tipoConducta : 'sin clasificar') . '. Gravedad: ' . caso_label($gravedad) . '.';

            if ($posibleAulaSegura === 1) {
                $detalle .= ' Marcado como posible Aula Segura.';
            }

            if ($requiereDenuncia === 1) {
                $detalle .= ' Requiere denuncia o derivación.';
            }

            $stmt = $pdo->prepare("\n                INSERT INTO caso_historial (\n                    caso_id,\n                    tipo_evento,\n                    titulo,\n                    detalle,\n                    user_id\n                ) VALUES (?, 'clasificacion', 'Clasificación normativa actualizada', ?, ?)\n            ");
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

            registrar_hito($pdo, $casoId, $colegioId, 107, $userId);

            $pdo->commit();

            // Guardar marcadores JSON fuera de la transacción para que un fallo en
            // columnas opcionales no revierta los indicadores booleanos ya confirmados.
            try {
                $pdo->prepare("
                    UPDATE caso_clasificacion_normativa
                    SET ley21809_flags = ?, rex782_flags = ?, updated_at = NOW()
                    WHERE caso_id = ? AND colegio_id = ?
                ")->execute([$json21809, $jsonRex782, $casoId, $colegioId]);
            } catch (Throwable $e) {
                error_log('[Metis] JSON flags update failed: ' . $e->getMessage());
            }

            caso_redirect($casoId, 'clasificacion');
        }