<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

CSRF::requireValid($_POST['_token'] ?? null);

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
$casoId = (int)($_POST['caso_id'] ?? 0);
$titulo = trim((string)($_POST['titulo'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
$fechaVencimiento = trim((string)($_POST['fecha_vencimiento'] ?? ''));
$prioridad = trim((string)($_POST['prioridad'] ?? 'media'));

if ($seguimientoId <= 0 || $casoId <= 0 || $titulo === '') {
    http_response_code(422);
    exit('Datos incompletos.');
}

if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
    $prioridad = 'media';
}

$validDate = static function (string $value): ?string {
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
};

$fechaInicioDb = $validDate($fechaInicio);
$fechaVencimientoDb = $validDate($fechaVencimiento);

if ($fechaInicio !== '' && $fechaInicioDb === null) {
    http_response_code(422);
    exit('Fecha de inicio no válida.');
}

if ($fechaVencimiento !== '' && $fechaVencimientoDb === null) {
    http_response_code(422);
    exit('Fecha de vencimiento no válida.');
}

$stmt = $pdo->prepare("SELECT id FROM casos WHERE id = ? AND colegio_id = ? LIMIT 1");
$stmt->execute([$casoId, $colegioId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Caso no encontrado.');
}

$stmt = $pdo->prepare("SELECT id FROM caso_seguimiento WHERE id = ? AND caso_id = ? AND colegio_id = ? LIMIT 1");
$stmt->execute([$seguimientoId, $casoId, $colegioId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Seguimiento no encontrado para este caso.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO caso_plan_intervencion (
            caso_id,
            colegio_id,
            seguimiento_id,
            titulo,
            descripcion,
            responsable_id,
            fecha_inicio,
            fecha_vencimiento,
            estado,
            prioridad
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)
    ");
    $stmt->execute([
        $casoId,
        $colegioId,
        $seguimientoId,
        $titulo,
        $descripcion !== '' ? $descripcion : null,
        $userId ?: null,
        $fechaInicioDb,
        $fechaVencimientoDb,
        $prioridad,
    ]);

    $planId = (int)$pdo->lastInsertId();

    if ($fechaVencimientoDb !== null) {
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
            'Plan "' . $titulo . '" con vencimiento programado para ' . $fechaVencimientoDb . '.',
            $prioridad,
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
        'Se incorpora plan de intervención desde módulo Seguimiento: ' . $titulo,
        $userId ?: null,
    ]);

    try {
        $stmtHito = $pdo->prepare("
            INSERT IGNORE INTO caso_hitos (caso_id, colegio_id, codigo, nombre, user_id)
            VALUES (?, ?, 103, 'Plan de acción creado', ?)
        ");
        $stmtHito->execute([$casoId, $colegioId, $userId ?: null]);

        $pdo->prepare("
            UPDATE casos
            SET estado_caso_id = 2,
                updated_at = NOW()
            WHERE id = ?
              AND colegio_id = ?
              AND estado_caso_id = 1
        ")->execute([$casoId, $colegioId]);
    } catch (Throwable $e) {
        // El hito no debe impedir guardar el plan legacy.
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('No fue posible guardar el plan de intervención.');
}

try {
    registrar_bitacora(
        'seguimiento',
        'crear_plan',
        'casos',
        $casoId,
        'Se agrega plan de intervención al caso ' . $casoId
    );
} catch (Throwable $e) {
    // No bloquea el flujo principal.
}

header('Location: ' . APP_URL . '/modules/seguimiento/abrir.php?caso_id=' . $casoId);
exit;
