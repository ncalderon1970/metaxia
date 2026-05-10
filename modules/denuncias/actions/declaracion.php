<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Actions — actions/declaracion.php
 * Fase 2C
 */

if ($accion === 'agregar_declaracion') {
            $participanteId = (int)($_POST['participante_id'] ?? 0);
            $nombreDeclarante = clean((string)($_POST['nombre_declarante'] ?? ''));
            $runDeclarante = cleanRun((string)($_POST['run_declarante'] ?? ''));
            $calidadProcesal = clean((string)($_POST['calidad_procesal'] ?? 'declarante'));
            $textoDeclaracion = clean((string)($_POST['texto_declaracion'] ?? ''));
            $observaciones = clean((string)($_POST['observaciones'] ?? ''));

            // fecha_declaracion: viene del campo datetime-local (ej. "2025-05-07T14:30")
            $fechaDeclaracionRaw = trim((string)($_POST['fecha_declaracion'] ?? ''));
            // Validar y convertir a "YYYY-MM-DD HH:MM:SS" para MySQL; fallback: ahora
            $fechaDeclaracion = date('Y-m-d H:i:s');
            $dtParsed = DateTime::createFromFormat('Y-m-d\TH:i', $fechaDeclaracionRaw)
                     ?: DateTime::createFromFormat('Y-m-d H:i:s', $fechaDeclaracionRaw)
                     ?: DateTime::createFromFormat('Y-m-d H:i',   $fechaDeclaracionRaw);
            if ($dtParsed) {
                $fechaDeclaracion = $dtParsed->format('Y-m-d H:i:s');
            }

            if ($participanteId > 0) {
                $stmtP = $pdo->prepare("
                    SELECT nombre_referencial, run, rol_en_caso
                    FROM caso_participantes
                    WHERE id = ?
                      AND caso_id = ?
                    LIMIT 1
                ");
                $stmtP->execute([$participanteId, $casoId]);
                $participante = $stmtP->fetch();

                if ($participante) {
                    if ($nombreDeclarante === '') {
                        $nombreDeclarante = (string)$participante['nombre_referencial'];
                        $runDeclarante    = (string)$participante['run'];
                    }
                    // Derivar calidad procesal del rol del interviniente
                    $rolMapa = [
                        'victima'     => 'victima',
                        'denunciante' => 'denunciante',
                        'denunciado'  => 'denunciado',
                        'testigo'     => 'testigo',
                    ];
                    $rolParticipante = strtolower(trim((string)($participante['rol_en_caso'] ?? '')));
                    if (isset($rolMapa[$rolParticipante])) {
                        $calidadProcesal = $rolMapa[$rolParticipante];
                    }
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

            $stmt = $pdo->prepare("
                INSERT INTO caso_declaraciones (
                    caso_id,
                    participante_id,
                    tipo_declarante,
                    nombre_declarante,
                    run_declarante,
                    calidad_procesal,
                    texto_declaracion,
                    observaciones,
                    fecha_declaracion,
                    requiere_reanalisis_ia,
                    tomada_por
                ) VALUES (?, ?, 'externo', ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->execute([
                $casoId,
                $participanteId > 0 ? $participanteId : null,
                $nombreDeclarante,
                $runDeclarante,
                $calidadProcesal,
                $textoDeclaracion,
                $observaciones !== '' ? $observaciones : null,
                $fechaDeclaracion,    // Valor del usuario o fecha/hora actual como fallback
                $userId ?: null,
            ]);

            $stmt = $pdo->prepare("
                UPDATE casos
                SET requiere_reanalisis_ia = 1
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $stmt->execute([$casoId, $colegioId]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'declaracion', 'Declaración agregada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se registró declaración de: ' . $nombreDeclarante . '.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_declaracion',
                'caso_declaraciones',
                (int)$pdo->lastInsertId(),
                'Declaración agregada al caso.'
            );

            registrar_hito($pdo, $casoId, $colegioId, 101, $userId);

            caso_redirect($casoId, 'declaraciones');
        }

if ($accion === 'subir_evidencia') {
            $tipo = clean((string)($_POST['tipo'] ?? 'archivo'));
            $descripcion = clean((string)($_POST['descripcion'] ?? ''));

            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Debes seleccionar un archivo válido.');
            }

            $archivo = $_FILES['archivo'];
            $nombreOriginal = basename((string)$archivo['name']);
            $mime = (string)($archivo['type'] ?? 'application/octet-stream');

            $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
            $permitidas = [
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

            if ($extension !== '' && !in_array($extension, $permitidas, true)) {
                throw new RuntimeException('Tipo de archivo no permitido.');
            }

            $directorio = dirname(__DIR__, 2) . '/storage/evidencias/caso_' . $casoId;

            if (!is_dir($directorio)) {
                mkdir($directorio, 0775, true);
            }

            $nombreSeguro = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' .
                preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombreOriginal);

            $rutaFisica = $directorio . '/' . $nombreSeguro;

            if (!move_uploaded_file((string)$archivo['tmp_name'], $rutaFisica)) {
                throw new RuntimeException('No fue posible guardar el archivo.');
            }

            $rutaRelativa = 'storage/evidencias/caso_' . $casoId . '/' . $nombreSeguro;

            $stmt = $pdo->prepare("
                INSERT INTO caso_evidencias (
                    caso_id,
                    tipo,
                    nombre_archivo,
                    ruta,
                    mime_type,
                    descripcion,
                    subido_por
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $tipo,
                $nombreOriginal,
                $rutaRelativa,
                $mime,
                $descripcion !== '' ? $descripcion : null,
                $userId ?: null,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'evidencia', 'Evidencia agregada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se subió evidencia: ' . $nombreOriginal . '.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'subir_evidencia',
                'caso_evidencias',
                (int)$pdo->lastInsertId(),
                'Evidencia agregada al caso.'
            );

            registrar_hito($pdo, $casoId, $colegioId, 106, $userId);

            caso_redirect($casoId, 'evidencias');
        }