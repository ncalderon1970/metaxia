<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';
require_once dirname(__DIR__, 3) . '/core/helpers.php';
require_once dirname(__DIR__, 3) . '/core/context_actions.php';

$loggerPath = dirname(__DIR__, 3) . '/core/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
}

require_once __DIR__ . '/../services/gestion_ejecutiva_dashboard_service.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeVerGestion = in_array($rolCodigo, ['superadmin', 'admin_colegio', 'director', 'convivencia'], true);
if (method_exists('Auth', 'can')) {
    $puedeVerGestion = $puedeVerGestion || Auth::can('admin_sistema') || Auth::can('ver_gestion_ejecutiva') || Auth::can('gestionar_denuncias');
}

if (!$puedeVerGestion) {
    http_response_code(403);
    exit('No tienes permisos para acceder a Gestión Ejecutiva.');
}

try {
    $gestion = metis_ge_dashboard($pdo, $colegioId);
} catch (Throwable $e) {
    $gestion = [
        'kpis' => [],
        'alertas_criticas' => [],
        'acciones_pendientes' => [],
        'casos_prioritarios' => [],
    ];
    if (function_exists('metis_log_exception')) {
        metis_log_exception($e, [
            'modulo' => 'gestion_ejecutiva',
            'accion' => 'cargar_dashboard',
            'colegio_id' => $colegioId,
            'usuario_id' => (int)($user['id'] ?? 0),
        ], 'error');
    }
}

$kpis = $gestion['kpis'] ?? [];
$alertasCriticas = $gestion['alertas_criticas'] ?? [];
$accionesPendientes = $gestion['acciones_pendientes'] ?? [];
$casosPrioritarios = $gestion['casos_prioritarios'] ?? [];

$pageTitle = 'Gestión Ejecutiva · Metis';
$pageSubtitle = 'Bandeja institucional de alertas, acciones y expedientes prioritarios';
$pageHeaderActions = metis_context_actions([
    metis_context_action('Dashboard', APP_URL . '/modules/dashboard/index.php', 'bi-speedometer2', 'secondary'),
    metis_context_action('Denuncias', APP_URL . '/modules/denuncias/index.php', 'bi-megaphone', 'secondary'),
    metis_context_action('Seguimiento', APP_URL . '/modules/seguimiento/index.php', 'bi-clipboard2-check', 'secondary'),
]);
