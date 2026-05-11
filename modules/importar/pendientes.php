<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/context_actions.php';
Auth::requireLogin();
$pdo = DB::conn();
$colegioId = (int)Auth::colegioId();
$contextActions = [metis_context_action('Volver a importar', 'index.php', 'bi-arrow-left', 'secondary')];
include __DIR__ . '/../../core/layout_header.php';
?>
<section class="metis-page"><div class="metis-card"><h1 class="metis-title">Pendientes de importación</h1><p class="metis-subtitle">Los pendientes se mantienen para revisión manual durante la transición al modelo anual.</p></div></section>
<?php include __DIR__ . '/../../core/layout_footer.php'; ?>
