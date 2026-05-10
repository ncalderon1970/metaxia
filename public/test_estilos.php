<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
$pageTitle = 'Prueba de estilos';
require_once __DIR__ . '/../core/layout_header.php';
?>

<div class="row g-4">
    <div class="col-12">
        <div class="module-hero">
            <div class="module-hero__content">
                <span class="module-hero__kicker">Metis</span>
                <h1 class="module-hero__title">Prueba de estilos</h1>
                <p class="module-hero__text">Si ves tarjetas, espaciado y estética moderna, la integración quedó bien.</p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card-sgce p-4">
            <div class="sgce-kpi__label">Casos activos</div>
            <div class="sgce-kpi__value">12</div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card-sgce p-4">
            <div class="sgce-kpi__label">Alertas</div>
            <div class="sgce-kpi__value">3</div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card-sgce p-4">
            <div class="sgce-kpi__label">IA pendiente</div>
            <div class="sgce-kpi__value">2</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../core/layout_footer.php'; ?>