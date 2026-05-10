<?php
declare(strict_types=1);

/**
 * Metis · Denuncias › Guardar declaración
 *
 * Endpoint legacy/compatible para registrar declaraciones desde formularios
 * externos al flujo principal ver.php. Mantiene la misma lógica segura del
 * expediente: CSRF, tenant por colegio_id, hito 101 y reanálisis IA.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once __DIR__ . '/includes/ver_helpers.php';

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

CSRF::requireValid($_POST['_token'] ?? null);

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$casoId = cleanInt($_POST['caso_id'] ?? $_GET['id'] ?? 0);
$participanteId = !empty($_POST['participante_id']) ? cleanInt($_POST['participante_id']) : 0;
$nombreDeclarante = clean((string)($_POST['nombre_declarante'] ?? ''));
$runDeclarante = cleanRun((string)($_POST['run_declarante'] ?? ''));
$tipoDeclarante = clean((string)($_POST['tipo_declarante'] ?? 'externo'));
$calidadProcesal = clean((string)($_POST['calidad_procesal'] ?? 'declarante'));
$fechaDeclaracionRaw = trim((string)($_POST['fecha_declaracion'] ?? ''));
$textoDeclaracion = cleanText((string)($_POST['texto_declaracion'] ?? ''));
$observaciones = cleanText((string)($_POST['observaciones'] ?? ''));

if ($casoId <= 0 || $colegioId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

$stmtCaso = $pdo->prepare("\n    SELECT id\n    FROM casos\n    WHERE id = ?\n      AND colegio_id = ?\n    LIMIT 1\n");
$stmtCaso->execute([$casoId, $colegioId]);
if (!$stmtCaso->fetch()) {
    http_response_code(404);
    exit('Caso no encontrado o sin permisos.');
}

$tiposDeclarantePermitidos = ['alumno', 'apoderado', 'docente', 'asistente', 'externo', 'otro'];
if (!in_array($tipoDeclarante, $tiposDeclarantePermitidos, true)) {
    $tipoDeclarante = 'externo';
}

$calidadesPermitidas = ['victima', 'denunciante', 'denunciado', 'testigo', 'involucrado', 'declarante', 'otro'];
if (!in_array($calidadProcesal, $calidadesPermitidas, true)) {
    $calidadProcesal = 'declarante';
}

if ($participanteId > 0) {
    $stmtP = $pdo->prepare("\n        SELECT\n            p.id,\n            p.tipo_persona,\n            p.nombre_referencial,\n            p.run,\n            p.rol_en_caso\n        FROM caso_participantes p\n        INNER JOIN casos c\n            ON c.id = p.caso_id\n           AND c.colegio_id = ?\n        WHERE p.id = ?\n          AND p.caso_id = ?\n        LIMIT 1\n    ");
    $stmtP->execute([$colegioId, $participanteId, $casoId]);
    $participante = $stmtP->fetch();

    if (!$participante) {
        http_response_code(403);
        exit('El interviniente seleccionado no pertenece al expediente activo.');
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

    $rolMapa = [
        'victima'     => 'victima',
        'denunciante' => 'denunciante',
        'denunciado'  => 'denunciado',
        'testigo'     => 'testigo',
        'involucrado' => 'involucrado',
    ];
    $rolParticipante = strtolower(trim((string)($participante['rol_en_caso'] ?? '')));
    if (isset($rolMapa[$rolParticipante])) {
        $calidadProcesal = $rolMapa[$rolParticipante];
    }
}

if ($nombreDeclarante === '' || $textoDeclaracion === '') {
    http_response_code(422);
    exit('Datos incompletos para registrar la declaración.');
}

if ($runDeclarante === '') {
    $runDeclarante = '0-0';
}

$fechaDeclaracion = date('Y-m-d H:i:s');
if ($fechaDeclaracionRaw !== '') {
    $dtParsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $fechaDeclaracionRaw)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fechaDeclaracionRaw)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $fechaDeclaracionRaw);

    if (!$dtParsed) {
        http_response_code(422);
        exit('La fecha de declaración no tiene un formato válido.');
    }

    if ($dtParsed->getTimestamp() > time() + 300) {
        http_response_code(422);
        exit('La fecha de declaración no puede ser futura.');
    }

    $fechaDeclaracion = $dtParsed->format('Y-m-d H:i:s');
}

$evidenciaAdjunta = null;
if (isset($_FILES['evidencia_archivo']) && is_array($_FILES['evidencia_archivo'])) {
    $archivo = $_FILES['evidencia_archivo'];
    $errorArchivo = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorArchivo !== UPLOAD_ERR_NO_FILE) {
        if ($errorArchivo !== UPLOAD_ERR_OK) {
            http_response_code(422);
            exit('No fue posible recibir correctamente el archivo adjunto.');
        }

        $nombreOriginal = basename((string)($archivo['name'] ?? ''));
        $tmpName = (string)($archivo['tmp_name'] ?? '');
        $size = (int)($archivo['size'] ?? 0);

        if ($nombreOriginal === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
            http_response_code(422);
            exit('El archivo adjunto no es válido.');
        }

        if ($size <= 0 || $size > 20 * 1024 * 1024) {
            http_response_code(422);
            exit('El archivo adjunto supera el tamaño permitido de 20 MB.');
        }

        $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'mp3', 'mp4'];
        if ($extension === '' || !in_array($extension, $permitidas, true)) {
            http_response_code(422);
            exit('Tipo de archivo adjunto no permitido.');
        }

        $tipoEvidencia = clean((string)($_POST['evidencia_tipo'] ?? 'archivo'));
        if (!in_array($tipoEvidencia, ['documento', 'imagen', 'audio', 'video', 'archivo'], true)) {
            $tipoEvidencia = 'archivo';
        }

        $descripcionEvidencia = clean((string)($_POST['evidencia_descripcion'] ?? ''));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName) ?: 'application/octet-stream';

        $baseEvidencias = defined('EVIDENCE_PATH') ? rtrim((string)EVIDENCE_PATH, DIRECTORY_SEPARATOR) : rtrim(BASE_PATH . '/storage/evidencias', DIRECTORY_SEPARATOR);
        $directorio = $baseEvidencias . DIRECTORY_SEPARATOR . 'caso_' . $casoId;
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            http_response_code(500);
            exit('No fue posible preparar el directorio de evidencias.');
        }

        $nombreSeguro = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombreOriginal);
        $rutaFisica = $directorio . DIRECTORY_SEPARATOR . $nombreSeguro;
        if (!move_uploaded_file($tmpName, $rutaFisica)) {
            http_response_code(500);
            exit('No fue posible guardar el archivo adjunto.');
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

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("\n        INSERT INTO caso_declaraciones (\n            caso_id,\n            participante_id,\n            tipo_declarante,\n            nombre_declarante,\n            run_declarante,\n            calidad_procesal,\n            fecha_declaracion,\n            texto_declaracion,\n            requiere_reanalisis_ia,\n            observaciones,\n            tomada_por\n        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)\n    ");
    $stmt->execute([
        $casoId,
        $participanteId > 0 ? $participanteId : null,
        $tipoDeclarante,
        mb_substr($nombreDeclarante, 0, 150, 'UTF-8'),
        mb_substr($runDeclarante, 0, 20, 'UTF-8'),
        $calidadProcesal,
        $fechaDeclaracion,
        $textoDeclaracion,
        $observaciones !== '' ? $observaciones : null,
        $userId ?: null,
    ]);
    $declaracionId = (int)$pdo->lastInsertId();

    $pdo->prepare("\n        UPDATE casos\n        SET requiere_reanalisis_ia = 1,\n            updated_at = NOW()\n        WHERE id = ?\n          AND colegio_id = ?\n    ")->execute([$casoId, $colegioId]);

    $pdo->prepare("\n        INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)\n        VALUES (?, 'declaracion', 'Declaración registrada', ?, ?)\n    ")->execute([
        $casoId,
        'Se registró declaración de: ' . $nombreDeclarante . ' en calidad de ' . caso_label($calidadProcesal) . '.',
        $userId ?: null,
    ]);

    if ($evidenciaAdjunta !== null) {
        $stmtEv = $pdo->prepare("\n            INSERT INTO caso_evidencias (caso_id, tipo, nombre_archivo, ruta, descripcion, mime_type, tamano_bytes, subido_por)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n        ");
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

        $pdo->prepare("\n            INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)\n            VALUES (?, 'evidencia', 'Evidencia adjunta a declaración', ?, ?)\n        ")->execute([
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

    registrar_bitacora('denuncias', 'agregar_declaracion', 'caso_declaraciones', $declaracionId, 'Declaración agregada al caso.');
    if ($evidenciaAdjunta !== null && isset($evidenciaId)) {
        registrar_bitacora('denuncias', 'adjuntar_evidencia_declaracion', 'caso_evidencias', $evidenciaId, 'Evidencia adjunta durante el registro de una declaración.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('No fue posible guardar la declaración: ' . $e->getMessage());
}

header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=declaraciones');
exit;
