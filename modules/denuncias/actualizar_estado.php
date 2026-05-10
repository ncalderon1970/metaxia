<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';
require_once '../../core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();

$casoId = (int)($_POST['caso_id'] ?? 0);
$estadoCasoId = (int)($_POST['estado_caso_id'] ?? 0);
$prioridad = trim((string)($_POST['prioridad'] ?? 'media'));
$semaforo = trim((string)($_POST['semaforo'] ?? 'verde'));

if ($casoId <= 0 || $estadoCasoId <= 0) {
    die('Datos incompletos.');
}

$stmt = $pdo->prepare("
    SELECT c.id, c.numero_caso
    FROM casos c
    WHERE c.id = ? AND c.colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $user['colegio_id']]);
$caso = $stmt->fetch();

if (!$caso) {
    die('Caso no encontrado.');
}

$stmt = $pdo->prepare("
    SELECT nombre
    FROM estado_caso
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$estadoCasoId]);
$estado = $stmt->fetch();

if (!$estado) {
    die('Estado no válido.');
}

$stmt = $pdo->prepare("
    UPDATE casos
    SET estado_caso_id = ?, prioridad = ?, semaforo = ?
    WHERE id = ?
");
$stmt->execute([
    $estadoCasoId,
    $prioridad,
    $semaforo,
    $casoId
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
    'estado',
    'Estado del caso actualizado',
    'Nuevo estado: ' . $estado['nombre'] . '. Prioridad: ' . $prioridad . '. Semáforo: ' . $semaforo . '.',
    $user['id']
]);

registrar_bitacora(
    'denuncias',
    'actualizar_estado',
    'casos',
    $casoId,
    'Se actualiza estado del caso ' . $caso['numero_caso']
);

header('Location: ver.php?id=' . $casoId);
exit;