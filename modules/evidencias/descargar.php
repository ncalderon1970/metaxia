<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

$evidenciaId = (int)($_GET['id'] ?? 0);
$modo = clean((string)($_GET['modo'] ?? 'download'));

if ($evidenciaId <= 0 || $colegioId <= 0) {
    http_response_code(400);
    exit('Evidencia no válida.');
}

$stmt = $pdo->prepare("\n    SELECT\n        e.id,\n        e.caso_id,\n        e.nombre_archivo,\n        e.ruta,\n        e.mime_type,\n        e.tamano_bytes,\n        c.colegio_id,\n        c.numero_caso\n    FROM caso_evidencias e\n    INNER JOIN casos c ON c.id = e.caso_id\n    WHERE e.id = ?\n      AND c.colegio_id = ?\n    LIMIT 1\n");
$stmt->execute([$evidenciaId, $colegioId]);
$evidencia = $stmt->fetch();

if (!$evidencia) {
    http_response_code(404);
    exit('Evidencia no encontrada.');
}

$rutaRelativa = trim((string)$evidencia['ruta']);

if ($rutaRelativa === '') {
    http_response_code(404);
    exit('La evidencia no tiene ruta asociada.');
}

$rutaRelativa = str_replace('\\', '/', $rutaRelativa);

// Compatibilidad con registros antiguos que guardaron URLs absolutas.
if (preg_match('#^https?://#i', $rutaRelativa) === 1) {
    $path = parse_url($rutaRelativa, PHP_URL_PATH);
    $rutaRelativa = is_string($path) ? $path : '';
}

$rutaRelativa = ltrim($rutaRelativa, '/');

$baseProyecto = realpath(dirname(__DIR__, 2));
$baseStorage = realpath(dirname(__DIR__, 2) . '/storage/evidencias');
$baseUploads = realpath(dirname(__DIR__, 2) . '/storage/uploads');

if ($baseProyecto === false) {
    http_response_code(500);
    exit('No fue posible resolver la ruta base del proyecto.');
}

$rutaFisica = $baseProyecto . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa);
$rutaReal = realpath($rutaFisica);

if ($rutaReal === false || !is_file($rutaReal)) {
    http_response_code(404);
    exit('Archivo no encontrado en el servidor.');
}

$rutaRealNormalizada = str_replace('\\', '/', $rutaReal);
$permitida = false;

foreach ([$baseStorage, $baseUploads] as $basePermitida) {
    if ($basePermitida === false) {
        continue;
    }

    $baseNormalizada = rtrim(str_replace('\\', '/', $basePermitida), '/') . '/';
    if (str_starts_with($rutaRealNormalizada, $baseNormalizada)) {
        $permitida = true;
        break;
    }
}

if (!$permitida) {
    http_response_code(403);
    exit('Acceso denegado a la evidencia.');
}

$nombreArchivo = basename((string)$evidencia['nombre_archivo']);
$nombreArchivo = $nombreArchivo !== '' ? $nombreArchivo : ('evidencia_' . $evidenciaId);

$mime = trim((string)($evidencia['mime_type'] ?? ''));
if ($mime === '') {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)($finfo->file($rutaReal) ?: 'application/octet-stream');
}

$inlinePermitido = preg_match('/^(application\/pdf|image\/|text\/|audio\/|video\/)/i', $mime) === 1;
$disposition = ($modo === 'inline' && $inlinePermitido) ? 'inline' : 'attachment';

registrar_bitacora(
    'evidencias',
    $disposition === 'inline' ? 'ver_evidencia' : 'descargar_evidencia',
    'caso_evidencias',
    $evidenciaId,
    'Acceso a evidencia del caso ' . ((string)$evidencia['numero_caso'])
);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($rutaReal));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $nombreArchivo) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($rutaReal);
exit;
