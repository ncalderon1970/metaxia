<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$alumnoId  = (int)($_GET['alumno_id'] ?? 0);

if ($alumnoId <= 0 || $colegioId <= 0) {
    echo json_encode(['ok' => false, 'condiciones' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT ace.tipo_condicion,
               ace.estado_diagnostico,
               ace.nivel_apoyo,
               ace.derivado_salud,
               COALESCE(cat.nombre, ace.tipo_condicion) AS condicion_nombre
        FROM alumno_condicion_especial ace
        LEFT JOIN catalogo_condicion_especial cat ON cat.codigo = ace.tipo_condicion
        WHERE ace.alumno_id = ?
          AND ace.colegio_id = ?
          AND ace.activo = 1
        ORDER BY ace.created_at DESC
    ");
    $stmt->execute([$alumnoId, $colegioId]);
    $condiciones = $stmt->fetchAll();

    echo json_encode([
        'ok'          => true,
        'condiciones' => $condiciones,
        'tiene_tea'   => !empty(array_filter($condiciones,
            fn($c) => str_starts_with((string)($c['tipo_condicion'] ?? ''), 'tea')
        )),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'condiciones' => [], 'error' => $e->getMessage()]);
}
