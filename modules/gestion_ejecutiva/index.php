<?php
declare(strict_types=1);

require_once __DIR__ . '/controllers/dashboard_controller.php';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';

require __DIR__ . '/partials/styles.php';
require __DIR__ . '/partials/hero.php';
require __DIR__ . '/partials/kpis.php';
require __DIR__ . '/partials/casos_prioritarios.php';
require __DIR__ . '/partials/alertas_criticas.php';
require __DIR__ . '/partials/acciones_pendientes.php';

require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
