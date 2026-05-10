<?php
// Validación de Seguridad CSRF
require_once dirname(__DIR__, 2) . '/core/CSRF.php';

if (!CSRF::validate($_POST['_token'] ?? null)) {
    exit('CSRF inválido');
}

// Rutas absolutas para dependencias
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();

$planId = (int)($_POST['plan_id'] ?? 0);
$casoId = (int)($_POST['caso_id'] ?? 0);
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$porcentaje = (int)($_POST['porcentaje_avance'] ?? 0);

if ($planId <= 0 || $casoId <= 0 || $descripcion === '') {
    die('Datos incompletos.');
}

$stmt = $pdo->prepare("
    INSERT INTO caso_seguimiento_avances (
        plan_id,
        descripcion,
        porcentaje_avance,
        registrado_por
    ) VALUES (?, ?, ?, ?)
");
$stmt->execute([
    $planId,
    $descripcion,
    $porcentaje,
    $user['id']
]);

$nuevoEstado = 'en_curso';
if ($porcentaje >= 100) {
    $nuevoEstado = 'cumplido';
} elseif ($porcentaje <= 0) {
    $nuevoEstado = 'pendiente';
}

$stmt = $pdo->prepare("
    UPDATE caso_plan_intervencion
    SET estado = ?
    WHERE id = ?
");
$stmt->execute([$nuevoEstado, $planId]);

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
    'avance',
    'Avance de seguimiento registrado',
    'Se registra avance de plan con porcentaje ' . $porcentaje . '%.',
    $user['id']
]);

registrar_bitacora(
    'seguimiento',
    'registrar_avance',
    'casos',
    $casoId,
    'Se registra avance de seguimiento en el caso ' . $casoId
);

header('Location: index.php?caso_id=' . $casoId);
exit;