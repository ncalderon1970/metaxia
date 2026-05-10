<?php
declare(strict_types=1);

/**
 * Dashboard · Metis
 *
 * Estructura modular:
 *   includes/helpers.php       — funciones auxiliares
 *   includes/data.php          — carga de KPIs desde BD
 *   partials/styles.php        — CSS del dashboard
 *   partials/hero.php          — banner principal
 *   partials/kpis.php          — tarjetas de indicadores
 *   partials/layout_bottom.php — salud técnica, accesos rápidos y actividad
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';

Auth::requireLogin();

$pdo          = DB::conn();
$user         = Auth::user() ?? [];
$colegioId    = (int)($user['colegio_id'] ?? 0);
$esSuperAdmin = ($user['rol_codigo'] ?? '') === 'superadmin';

$pageTitle    = 'Dashboard · Metis';
$pageSubtitle = 'Vista ejecutiva, salud del sistema y actividad operacional';

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/data.php';

$pageHeaderActions = metis_context_actions([
    metis_context_action('Nueva denuncia', APP_URL . '/modules/denuncias/crear.php', 'bi-plus-circle', 'success'),
    metis_context_action('Denuncias', APP_URL . '/modules/denuncias/index.php', 'bi-megaphone', 'primary'),
    metis_context_action('Seguimiento', APP_URL . '/modules/seguimiento/index.php', 'bi-clipboard2-check', 'secondary'),
    metis_context_action('Comunidad', APP_URL . '/modules/comunidad/index.php', 'bi-people', 'secondary'),
    metis_context_action('Importar', APP_URL . '/modules/importar/index.php', 'bi-file-earmark-arrow-up', 'secondary'),
    metis_context_action('Pendientes ' . number_format((int)($totalPendientesImportacion ?? 0), 0, ',', '.'), APP_URL . '/modules/importar/pendientes.php', 'bi-exclamation-triangle', 'warning', (int)($totalPendientesImportacion ?? 0) > 0),
    metis_context_action('Reportes', APP_URL . '/modules/reportes/index.php', 'bi-bar-chart', 'secondary'),
]);

require_once dirname(__DIR__, 2) . '/core/layout_header.php';

require __DIR__ . '/partials/styles.php';
require __DIR__ . '/partials/hero.php';
require __DIR__ . '/partials/kpis.php';
require __DIR__ . '/partials/layout_bottom.php';

require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
