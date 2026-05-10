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

$seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
$casoId = (int)($_POST['caso_id'] ?? 0);
$titulo = trim((string)($_POST['titulo'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
$fechaVencimiento = trim((string)($_POST['fecha_vencimiento'] ?? ''));
$prioridad = trim((string)($_POST['prioridad'] ?? 'media'));

if ($seguimientoId <= 0 || $casoId <= 0 || $titulo === '') {
    die('Datos incompletos.');
}

$stmt = $pdo->prepare("
    INSERT INTO caso_plan_intervencion (
        seguimiento_id,
        titulo,
        descripcion,
        responsable_id,
        fecha_inicio,
        fecha_vencimiento,
        estado,
        prioridad
    ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?)
");
$stmt->execute([
    $seguimientoId,
    $titulo,
    $descripcion !== '' ? $descripcion : null,
    $user['id'],
    $fechaInicio !== '' ? $fechaInicio : null,
    $fechaVencimiento !== '' ? $fechaVencimiento : null,
    $prioridad !== '' ? $prioridad : 'media'
]);

$planId = (int)$pdo->lastInsertId();

if ($fechaVencimiento !== '') {
    $stmt = $pdo->prepare("
        INSERT INTO caso_alertas (
            caso_id,
            seguimiento_id,
            plan_id,
            tipo,
            mensaje,
            prioridad,
            estado,
            fecha_alerta
        ) VALUES (?, ?, ?, ?, ?, ?, 'pendiente', NOW())
    ");
    $stmt->execute([
        $casoId,
        $seguimientoId,
        $planId,
        'vencimiento_programado',
        'Plan "' . $titulo . '" con vencimiento programado para ' . $fechaVencimiento . '.',
        $prioridad !== '' ? $prioridad : 'media'
    ]);
}

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
    'plan',
    'Plan de intervención agregado',
    'Se incorpora plan: ' . $titulo,
    $user['id']
]);

registrar_bitacora(
    'seguimiento',
    'crear_plan',
    'casos',
    $casoId,
    'Se agrega plan de intervención al caso ' . $casoId
);

header('Location: index.php?caso_id=' . $casoId);
exit;