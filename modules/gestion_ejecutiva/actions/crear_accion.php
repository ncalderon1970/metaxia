<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';
require_once dirname(__DIR__, 3) . '/core/CSRF.php';
require_once dirname(__DIR__, 3) . '/core/helpers.php';

$loggerPath = dirname(__DIR__, 3) . '/core/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
}

require_once __DIR__ . '/../repositories/gestion_ejecutiva_repository.php';

Auth::requireLogin();
CSRF::requireValid($_POST['_token'] ?? null);

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeCrear = in_array($rolCodigo, ['superadmin', 'admin_colegio', 'director', 'convivencia'], true);
if (method_exists('Auth', 'can')) {
    $puedeCrear = $puedeCrear || Auth::can('admin_sistema') || Auth::can('crear_gestion_ejecutiva') || Auth::can('gestionar_denuncias');
}

if (!$puedeCrear) {
    http_response_code(403);
    exit('No tienes permisos para crear acciones ejecutivas.');
}

$casoId = (int)($_POST['caso_id'] ?? 0);
$titulo = trim((string)($_POST['titulo'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$responsableNombre = trim((string)($_POST['responsable_nombre'] ?? ''));
$responsableRol = trim((string)($_POST['responsable_rol'] ?? ''));
$prioridad = strtolower(trim((string)($_POST['prioridad'] ?? 'media')));
$fechaCompromiso = trim((string)($_POST['fecha_compromiso'] ?? ''));

if (!in_array($prioridad, ['baja', 'media', 'alta', 'critica'], true)) {
    $prioridad = 'media';
}

if ($titulo === '' || $casoId <= 0) {
    redirect(APP_URL . '/modules/gestion_ejecutiva/index.php?error=datos_incompletos');
}

if ($fechaCompromiso !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCompromiso)) {
    redirect(APP_URL . '/modules/gestion_ejecutiva/index.php?error=fecha_invalida');
}

$caso = metis_ge_validar_caso($pdo, $colegioId, $casoId);
if (!$caso) {
    redirect(APP_URL . '/modules/gestion_ejecutiva/index.php?error=caso_no_valido');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(" 
        INSERT INTO caso_gestion_ejecutiva (
            colegio_id,
            caso_id,
            titulo,
            descripcion,
            responsable_nombre,
            responsable_rol,
            prioridad,
            estado,
            fecha_compromiso,
            creado_por,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $colegioId,
        $casoId,
        $titulo,
        $descripcion !== '' ? $descripcion : null,
        $responsableNombre !== '' ? $responsableNombre : null,
        $responsableRol !== '' ? $responsableRol : null,
        $prioridad,
        $fechaCompromiso !== '' ? $fechaCompromiso : null,
        $userId > 0 ? $userId : null,
    ]);
    $accionId = (int)$pdo->lastInsertId();

    $detalle = 'Gestión Ejecutiva: ' . $titulo;
    $stmtHist = $pdo->prepare(" 
        INSERT INTO caso_historial (
            caso_id,
            tipo_evento,
            titulo,
            detalle,
            user_id
        ) VALUES (?, 'gestion_ejecutiva', 'Acción ejecutiva registrada desde bandeja', ?, ?)
    ");
    $stmtHist->execute([$casoId, $detalle, $userId > 0 ? $userId : null]);

    if (function_exists('registrar_bitacora')) {
        registrar_bitacora(
            $pdo,
            $colegioId,
            $userId,
            'gestion_ejecutiva',
            'crear_accion_desde_bandeja',
            'caso_gestion_ejecutiva',
            $accionId,
            'Acción ejecutiva creada desde la Bandeja Ejecutiva'
        );
    }

    $pdo->commit();
    redirect(APP_URL . '/modules/gestion_ejecutiva/index.php?ok=accion_creada');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (function_exists('metis_log_exception')) {
        metis_log_exception($e, [
            'modulo' => 'gestion_ejecutiva',
            'accion' => 'crear_accion',
            'colegio_id' => $colegioId,
            'caso_id' => $casoId,
            'usuario_id' => $userId,
        ], 'error');
    }
    redirect(APP_URL . '/modules/gestion_ejecutiva/index.php?error=no_se_pudo_crear');
}
