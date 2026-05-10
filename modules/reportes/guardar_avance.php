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

$planId = (int)($_POST['plan_id'] ?? 0);
$casoId = (int)($_POST['caso_id'] ?? 0);
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$porcentaje = (int)($_POST['porcentaje_avance'] ?? 0);

if ($planId <= 0 || $casoId <= 0 || $descripcion === '') {
    http_response_code(422);
    exit('Datos incompletos.');
}

$porcentaje = max(0, min(100, $porcentaje));

$stmt = $pdo->prepare("SELECT id FROM casos WHERE id = ? AND colegio_id = ? LIMIT 1");
$stmt->execute([$casoId, $colegioId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Caso no encontrado.');
}

$stmt = $pdo->prepare("
    SELECT id
    FROM caso_plan_intervencion
    WHERE id = ?
      AND caso_id = ?
      AND colegio_id = ?
    LIMIT 1
");
$stmt->execute([$planId, $casoId, $colegioId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Plan no encontrado para este caso.');
}

$nuevoEstado = 'en_curso';
if ($porcentaje >= 100) {
    $nuevoEstado = 'cumplido';
} elseif ($porcentaje <= 0) {
    $nuevoEstado = 'pendiente';
}

try {
    $pdo->beginTransaction();

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
        $userId ?: null,
    ]);

    $stmt = $pdo->prepare("
        UPDATE caso_plan_intervencion
        SET estado = ?,
            updated_at = NOW()
        WHERE id = ?
          AND caso_id = ?
          AND colegio_id = ?
    ");
    $stmt->execute([$nuevoEstado, $planId, $casoId, $colegioId]);

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
        'Se registra avance de plan con porcentaje ' . $porcentaje . '%. ' . $descripcion,
        $userId ?: null,
    ]);

    try {
        $stmtHito = $pdo->prepare("
            INSERT IGNORE INTO caso_hitos (caso_id, colegio_id, codigo, nombre, user_id)
            VALUES (?, ?, 104, 'Sesión de seguimiento registrada', ?)
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
        // El hito no debe impedir guardar el avance legacy.
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('No fue posible registrar el avance de seguimiento.');
}

try {
    registrar_bitacora(
        'seguimiento',
        'registrar_avance',
        'casos',
        $casoId,
        'Se registra avance de seguimiento en el caso ' . $casoId
    );
} catch (Throwable $e) {
    // No bloquea el flujo principal.
}

header('Location: ' . APP_URL . '/modules/seguimiento/abrir.php?caso_id=' . $casoId);
exit;
