<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';
require_once '../../core/helpers.php';
require_once '../../core/CSRF.php';

Auth::requireLogin();

if (!CSRF::validate($_POST['_token'] ?? null)) {
    exit('CSRF inválido');
}

$pdo = DB::conn();
$user = Auth::user();

$casoId = cleanInt($_POST['caso_id'] ?? 0);
$descripcion = clean($_POST['descripcion'] ?? '');

if ($casoId <= 0 || empty($_FILES['archivo']['name'])) {
    die('Datos incompletos.');
}

$permitidos = [
    'application/pdf',
    'image/jpeg',
    'image/png'
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['archivo']['tmp_name']);

if (!in_array($mime, $permitidos, true)) {
    die('Tipo de archivo no permitido.');
}

if ($_FILES['archivo']['size'] > 5 * 1024 * 1024) {
    die('Archivo demasiado grande.');
}

$ext = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
$nombreSeguro = bin2hex(random_bytes(16)) . '.' . $ext;

$rutaFisica = UPLOAD_PATH . $nombreSeguro;

if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $rutaFisica)) {
    die('Error al subir archivo.');
}

$stmt = $pdo->prepare("
    INSERT INTO caso_evidencias (caso_id, nombre_archivo, ruta, descripcion)
    VALUES (?, ?, ?, ?)
");

$stmt->execute([
    $casoId,
    $_FILES['archivo']['name'],
    UPLOAD_URL . $nombreSeguro,
    $descripcion !== '' ? $descripcion : null
]);

header('Location: ver.php?id=' . $casoId);
exit;