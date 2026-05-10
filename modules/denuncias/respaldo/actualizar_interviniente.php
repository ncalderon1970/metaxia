<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();

$participanteId = (int)($_POST['participante_id'] ?? 0);
$casoId = (int)($_POST['caso_id'] ?? 0);
$tipoPersona = trim((string)($_POST['tipo_persona'] ?? ''));
$personaId = !empty($_POST['persona_id']) ? (int)$_POST['persona_id'] : null;
$nombreReferencial = trim((string)($_POST['nombre_referencial'] ?? ''));
$run = trim((string)($_POST['run'] ?? ''));
$observacionIdentificacion = trim((string)($_POST['observacion_identificacion'] ?? ''));

if ($participanteId <= 0 || $casoId <= 0 || $tipoPersona === '') {
    die('Datos incompletos.');
}

if ($nombreReferencial === '') {
    $nombreReferencial = 'NN';
}

if ($run === '') {
    $run = '0-0';
}

$stmt = $pdo->prepare("
    SELECT cp.id, cp.nombre_referencial, cp.run
    FROM caso_participantes cp
    INNER JOIN casos c ON c.id = cp.caso_id
    WHERE cp.id = ? AND cp.caso_id = ? AND c.colegio_id = ?
    LIMIT 1
");
$stmt->execute([$participanteId, $casoId, $user['colegio_id']]);
$actual = $stmt->fetch();

if (!$actual) {
    die('Participante no encontrado.');
}

$stmt = $pdo->prepare("
    UPDATE caso_participantes
    SET
        tipo_persona = ?,
        persona_id = ?,
        nombre_referencial = ?,
        run = ?,
        identidad_confirmada = ?,
        fecha_identificacion = ?,
        identificado_por = ?,
        observacion_identificacion = ?
    WHERE id = ?
");
$stmt->execute([
    $tipoPersona,
    $personaId,
    $nombreReferencial,
    $run,
    ($nombreReferencial !== 'NN' || $run !== '0-0') ? 1 : 0,
    ($nombreReferencial !== 'NN' || $run !== '0-0') ? date('Y-m-d H:i:s') : null,
    ($nombreReferencial !== 'NN' || $run !== '0-0') ? $user['id'] : null,
    $observacionIdentificacion !== '' ? $observacionIdentificacion : null,
    $participanteId
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
    'identificacion',
    'Identidad de interviniente actualizada',
    'Se actualiza interviniente desde [' . $actual['nombre_referencial'] . ' / ' . $actual['run'] . '] a [' . $nombreReferencial . ' / ' . $run . '].',
    $user['id']
]);

header('Location: ver.php?id=' . $casoId);
exit;