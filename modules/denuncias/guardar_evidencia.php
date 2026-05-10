<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/includes/ver_helpers.php';

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

CSRF::requireValid($_POST['_token'] ?? null);

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('No tienes permisos para subir evidencias.');
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$casoId = cleanInt($_POST['caso_id'] ?? 0);
$tipo = clean((string)($_POST['tipo'] ?? 'archivo'));
$descripcion = clean((string)($_POST['descripcion'] ?? ''));

if ($casoId <= 0 || $colegioId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

$stmtCaso = $pdo->prepare("\n    SELECT id\n    FROM casos\n    WHERE id = ?\n      AND colegio_id = ?\n    LIMIT 1\n");
$stmtCaso->execute([$casoId, $colegioId]);

if (!$stmtCaso->fetchColumn()) {
    http_response_code(404);
    exit('Caso no encontrado o no pertenece al establecimiento.');
}

$tiposPermitidos = ['archivo', 'imagen', 'documento', 'audio', 'video', 'otro'];
if (!in_array($tipo, $tiposPermitidos, true)) {
    $tipo = 'archivo';
}

if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
    exit('Debes seleccionar un archivo válido.');
}

$archivo = $_FILES['archivo'];
$errorArchivo = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

if ($errorArchivo !== UPLOAD_ERR_OK) {
    $mensajeArchivo = match ($errorArchivo) {
        UPLOAD_ERR_NO_FILE => 'Debes seleccionar un archivo para subir.',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido.',
        default => 'No fue posible recibir correctamente el archivo.',
    };
    exit($mensajeArchivo);
}

$nombreOriginal = basename((string)($archivo['name'] ?? ''));
$tmpName = (string)($archivo['tmp_name'] ?? '');
$tamanoBytes = (int)($archivo['size'] ?? 0);

if ($nombreOriginal === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
    exit('El archivo recibido no es válido.');
}

$maxBytes = 20 * 1024 * 1024;
if ($tamanoBytes <= 0 || $tamanoBytes > $maxBytes) {
    exit('El archivo supera el tamaño permitido de 20 MB.');
}

$extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
$extensionesPermitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'mp3', 'mp4'];

if ($extension === '' || !in_array($extension, $extensionesPermitidas, true)) {
    exit('Tipo de archivo no permitido.');
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
} elseif ($extension === 'txt') {
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
    exit('El contenido del archivo no coincide con un tipo permitido.');
}

$baseEvidencias = defined('EVIDENCE_PATH')
    ? rtrim((string)EVIDENCE_PATH, DIRECTORY_SEPARATOR)
    : rtrim(BASE_PATH . '/storage/evidencias', DIRECTORY_SEPARATOR);
$directorio = $baseEvidencias . DIRECTORY_SEPARATOR . 'caso_' . $casoId;

if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
    exit('No fue posible preparar el directorio de evidencias.');
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
$evidenciaId = 0;

try {
    if (!move_uploaded_file($tmpName, $rutaFisica)) {
        throw new RuntimeException('No fue posible guardar el archivo en el servidor.');
    }
    $archivoGuardado = true;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("\n        INSERT INTO caso_evidencias (\n            caso_id,\n            tipo,\n            nombre_archivo,\n            ruta,\n            descripcion,\n            mime_type,\n            tamano_bytes,\n            subido_por\n        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n    ");
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

    $pdo->prepare("\n        INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id, created_at)\n        VALUES (?, 'evidencia', 'Evidencia agregada', ?, ?, NOW())\n    ")->execute([
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

    http_response_code(500);
    exit('Error al guardar evidencia: ' . $e->getMessage());
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

header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=evidencias');
exit;
