<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();

$casoId = (int)($_POST['caso_id'] ?? 0);
$tipoPersona = trim((string)($_POST['tipo_persona'] ?? ''));
$nombreReferencial = trim((string)($_POST['nombre_referencial'] ?? ''));
$run = trim((string)($_POST['run'] ?? ''));
$rolEnCaso = trim((string)($_POST['rol_en_caso'] ?? ''));
$observacion = trim((string)($_POST['observacion'] ?? ''));

if ($casoId <= 0 || $tipoPersona === '' || $nombreReferencial === '' || $rolEnCaso === '') {
    die('Datos incompletos.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM casos
    WHERE id = ? AND colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $user['colegio_id']]);
$caso = $stmt->fetch();

if (!$caso) {
    die('Caso no encontrado.');
}

$stmt = $pdo->prepare("
    INSERT INTO caso_participantes (
        caso_id,
        tipo_persona,
        persona_id,
        nombre_referencial,
        run,
        rol_en_caso,
        observacion
    ) VALUES (?, ?, NULL, ?, ?, ?, ?)
");
$stmt->execute([
    $casoId,
    $tipoPersona,
    $nombreReferencial,
    $run !== '' ? $run : null,
    $rolEnCaso,
    $observacion !== '' ? $observacion : null
]);

$stmtHist = $pdo->prepare("
    INSERT INTO caso_historial (
        caso_id,
        tipo_evento,
        titulo,
        detalle,
        user_id
    ) VALUES (?, ?, ?, ?, ?)
");
$stmtHist->execute([
    $casoId,
    'participante',
    'Participante agregado',
    'Se agrega participante: ' . $nombreReferencial . ' (' . $rolEnCaso . ')',
    $user['id']
]);

header('Location: ver.php?id=' . $casoId);
exit;