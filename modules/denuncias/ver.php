<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/includes/ver_helpers.php';
require_once __DIR__ . '/includes/ver_queries.php';

Auth::requireLogin();

$pdo = DB::conn();

// ── AJAX: buscar interviniente desde comunidad educativa anual ──────
if (($_GET['ajax'] ?? '') === 'buscar_participante') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/includes/busqueda_anual_intervinientes.php';
}


$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$casoId = (int)($_GET['id'] ?? 0);
$tab = clean((string)($_GET['tab'] ?? 'resumen'));

if ($casoId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

$error = '';
$exito = '';

$caso = ver_cargar_caso($pdo, $casoId, $colegioId);

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado o no pertenece al establecimiento.');
}

$mostrarAulaSegura = (int)($caso['posible_aula_segura'] ?? 0) === 1;

$accion = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);
    $accion = clean((string)($_POST['_accion'] ?? ''));
}

require_once __DIR__ . '/includes/ver_actions.php';

$contexto = ver_cargar_contexto($pdo, $casoId, $colegioId);
extract($contexto, EXTR_OVERWRITE);

// Validar tabs permitidos para evitar path traversal antes de renderizar la navegación contextual.
$tabsPermitidos = [
    'resumen', 'seguimiento', 'clasificacion', 'participantes',
    'declaraciones', 'evidencias', 'gestion', 'aula_segura',
    'historial', 'cierre', 'analisis_ia', 'plan_accion', 'pauta_riesgo',
];

if (!in_array($tab, $tabsPermitidos, true)) {
    $tab = 'resumen';
}


if (!function_exists('metis_exp_topbar_action_visible')) {
    function metis_exp_topbar_action_visible(PDO $pdo, string $key, bool $default = false): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = 'acciones_expediente_topbar' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            $cfg = $raw ? (json_decode((string)$raw, true) ?: []) : [];

            if (!array_key_exists($key, $cfg)) {
                return $default;
            }

            return (int)($cfg[$key]['visible'] ?? 0) === 1;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

$mostrarAlertaTopbarExpediente = metis_exp_topbar_action_visible($pdo, 'alertas', false);

$pageTitle = 'Expediente · ' . ($caso['numero_caso'] ?? 'Caso');
$pageSubtitle = 'Revisión integral del caso, intervinientes, declaraciones, evidencias e historial';

$pageHeaderActions = [
    [
        'label' => 'Volver al listado',
        'icon' => 'bi-arrow-left',
        'url' => APP_URL . '/modules/denuncias/index.php',
        'variant' => 'dark',
    ],
    [
        'label' => 'Reporte ejecutivo',
        'icon' => 'bi-printer',
        'url' => APP_URL . '/modules/denuncias/reporte_ejecutivo.php?id=' . $casoId,
        'target' => '_blank',
        'rel' => 'noopener',
    ],
    [
        'label' => 'Alertas',
        'icon' => 'bi-bell',
        'url' => APP_URL . '/modules/alertas/index.php',
        'variant' => 'danger',
        'visible' => $mostrarAlertaTopbarExpediente,
    ],
];

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
require_once __DIR__ . '/partials/ver_styles.php';
require_once __DIR__ . '/partials/ver_context_nav_styles.php';
require_once __DIR__ . '/partials/ver_header.php';
require_once __DIR__ . '/partials/ver_messages.php';
require_once __DIR__ . '/partials/ver_tabs.php';

$tabFile = __DIR__ . '/partials/tab_' . $tab . '.php';

if (is_file($tabFile)) {
    require $tabFile;
} else {
    require __DIR__ . '/partials/tab_resumen.php';
}

// Cierre del contenedor abierto por partials/ver_tabs.php.
echo "</main></div>";

require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
