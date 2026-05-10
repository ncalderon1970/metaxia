<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';

Auth::requireLogin();

if (!CSRF::validate($_POST['_token'] ?? null)) {
    exit('CSRF inválido');
}

$pdo = DB::conn();
$user = Auth::user();

$casoId = cleanInt($_POST['caso_id'] ?? 0);
$participanteId = !empty($_POST['participante_id']) ? cleanInt($_POST['participante_id']) : null;
$personaId = !empty($_POST['persona_id']) ? cleanInt($_POST['persona_id']) : null;
$tipoDeclarante = clean($_POST['tipo_declarante'] ?? '');
$nombreDeclarante = clean($_POST['nombre_declarante'] ?? '');
$runDeclarante = cleanRun($_POST['run_declarante'] ?? '');
$calidadProcesal = clean($_POST['calidad_procesal'] ?? '');
$fechaDeclaracion = clean($_POST['fecha_declaracion'] ?? '');
$textoDeclaracion = cleanText($_POST['texto_declaracion'] ?? '');
$observaciones = cleanText($_POST['observaciones'] ?? '');

if ($casoId <= 0 || $tipoDeclarante === '' || $nombreDeclarante === '' || $calidadProcesal === '' || $fechaDeclaracion === '' || $textoDeclaracion === '') {
    die('Datos incompletos para registrar la declaración.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM casos
    WHERE id = ? AND colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $user['colegio_id']]);
$caso = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caso) {
    die('Caso no encontrado.');
}

$stmt = $pdo->prepare("
    INSERT INTO caso_declaraciones (
        caso_id,
        participante_id,
        tipo_declarante,
        nombre_declarante,
        run_declarante,
        calidad_procesal,
        fecha_declaracion,
        texto_declaracion,
        observaciones,
        tomada_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $casoId,
    $participanteId,
    $tipoDeclarante,
    $nombreDeclarante,
    $runDeclarante !== '' ? $runDeclarante : null,
    $calidadProcesal,
    $fechaDeclaracion,
    $textoDeclaracion,
    $observaciones !== '' ? $observaciones : null,
    $user['id']
]);

$stmt = $pdo->prepare("
    UPDATE casos
    SET requiere_reanalisis_ia = 1
    WHERE id = ?
");
$stmt->execute([$casoId]);

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
    'declaracion',
    'Declaración registrada',
    'Se incorpora declaración de ' . $nombreDeclarante . ' en calidad de ' . $calidadProcesal . '.',
    $user['id']
]);

header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=declaraciones');
exit;