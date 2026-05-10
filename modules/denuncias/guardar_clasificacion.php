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

function sumarDiasHabiles(?string $fecha, int $dias): ?string
{
    if (!$fecha) {
        return null;
    }

    $dt = new DateTime($fecha);
    $sumados = 0;

    while ($sumados < $dias) {
        $dt->modify('+1 day');
        $dia = (int)$dt->format('N');

        if ($dia < 6) {
            $sumados++;
        }
    }

    return $dt->format('Y-m-d');
}

$pdo = DB::conn();
$user = Auth::user();

$casoId = cleanInt($_POST['caso_id'] ?? 0);
$aspectoId = !empty($_POST['denuncia_aspecto_id']) ? cleanInt($_POST['denuncia_aspecto_id']) : null;

$aplicaAula = isset($_POST['aplica_aula_segura']) ? 1 : 0;
$causal = clean($_POST['causal'] ?? '');
$medidaSuspension = isset($_POST['medida_cautelar_suspension']) ? 1 : 0;
$fechaSuspension = clean($_POST['fecha_notificacion_suspension'] ?? '');
$fechaResolucion = clean($_POST['fecha_notificacion_resolucion'] ?? '');
$reconsideracion = isset($_POST['reconsideracion_presentada']) ? 1 : 0;
$fechaReconsideracion = clean($_POST['fecha_reconsideracion'] ?? '');
$estado = clean($_POST['estado'] ?? 'no_aplica');
$observaciones = cleanText($_POST['observaciones'] ?? '');

if ($casoId <= 0) {
    exit('Caso inválido.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM casos
    WHERE id = ? AND colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $user['colegio_id']]);

if (!$stmt->fetch()) {
    exit('Caso no encontrado.');
}

$stmt = $pdo->prepare("
    UPDATE casos
    SET denuncia_aspecto_id = ?
    WHERE id = ?
");
$stmt->execute([$aspectoId, $casoId]);

$fechaLimiteResolucion = $medidaSuspension && $fechaSuspension !== ''
    ? sumarDiasHabiles($fechaSuspension, 10)
    : null;

$fechaLimiteReconsideracion = $fechaResolucion !== ''
    ? sumarDiasHabiles($fechaResolucion, 5)
    : null;

$stmt = $pdo->prepare("
    INSERT INTO aula_segura_procedimientos (
        caso_id,
        aplica,
        causal,
        medida_cautelar_suspension,
        fecha_notificacion_suspension,
        fecha_limite_resolucion,
        fecha_notificacion_resolucion,
        fecha_limite_reconsideracion,
        reconsideracion_presentada,
        fecha_reconsideracion,
        estado,
        observaciones
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        aplica = VALUES(aplica),
        causal = VALUES(causal),
        medida_cautelar_suspension = VALUES(medida_cautelar_suspension),
        fecha_notificacion_suspension = VALUES(fecha_notificacion_suspension),
        fecha_limite_resolucion = VALUES(fecha_limite_resolucion),
        fecha_notificacion_resolucion = VALUES(fecha_notificacion_resolucion),
        fecha_limite_reconsideracion = VALUES(fecha_limite_reconsideracion),
        reconsideracion_presentada = VALUES(reconsideracion_presentada),
        fecha_reconsideracion = VALUES(fecha_reconsideracion),
        estado = VALUES(estado),
        observaciones = VALUES(observaciones)
");
$stmt->execute([
    $casoId,
    $aplicaAula,
    $causal !== '' ? $causal : null,
    $medidaSuspension,
    $fechaSuspension !== '' ? $fechaSuspension : null,
    $fechaLimiteResolucion,
    $fechaResolucion !== '' ? $fechaResolucion : null,
    $fechaLimiteReconsideracion,
    $reconsideracion,
    $fechaReconsideracion !== '' ? $fechaReconsideracion : null,
    $estado,
    $observaciones !== '' ? $observaciones : null
]);

$stmt = $pdo->prepare("
    INSERT INTO caso_historial (
        caso_id,
        tipo_evento,
        titulo,
        detalle,
        user_id
    ) VALUES (?, ?, ?, ?, ?)
");
$stmt->execute([
    $casoId,
    'clasificacion',
    'Clasificación de denuncia actualizada',
    $aplicaAula ? 'Se actualiza clasificación y procedimiento Aula Segura.' : 'Se actualiza clasificación de denuncia.',
    $user['id']
]);

header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId);
exit;