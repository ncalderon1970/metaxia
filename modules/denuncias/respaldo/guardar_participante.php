<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();

$casoId = (int)($_POST['caso_id'] ?? 0);
$tipoPersona = trim((string)($_POST['tipo_persona'] ?? ''));
$personaId = !empty($_POST['persona_id']) ? (int)$_POST['persona_id'] : null;
$nombreReferencial = trim((string)($_POST['nombre_referencial'] ?? ''));
$run = trim((string)($_POST['run'] ?? ''));
$rolEnCaso = trim((string)($_POST['rol_en_caso'] ?? ''));
$observacion = trim((string)($_POST['observacion'] ?? ''));
$identidadConfirmada = $personaId ? 1 : 0;

if ($casoId <= 0 || $tipoPersona === '' || $rolEnCaso === '') {
    die('Datos incompletos.');
}

if ($nombreReferencial === '') {
    $nombreReferencial = 'NN';
}

if ($run === '') {
    $run = '0-0';
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
        identidad_confirmada,
        fecha_identificacion,
        identificado_por,
        rol_en_caso,
        observacion,
        observacion_identificacion
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $casoId,
    $tipoPersona,
    $personaId,
    $nombreReferencial,
    $run,
    $identidadConfirmada,
    $identidadConfirmada ? date('Y-m-d H:i:s') : null,
    $identidadConfirmada ? $user['id'] : null,
    $rolEnCaso,
    $observacion !== '' ? $observacion : null,
    $identidadConfirmada ? 'Interviniente identificado al momento del registro.' : 'Interviniente pendiente de identificación.'
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
    'Se agrega participante: ' . $nombreReferencial . ' (' . $rolEnCaso . ')' . ($identidadConfirmada ? ' con identidad confirmada.' : ' pendiente de identificación.'),
    $user['id']
]);

header('Location: ver.php?id=' . $casoId);
exit;