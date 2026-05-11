<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/context_actions.php';
require_once __DIR__ . '/_importar_anual_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$anioActual = importar_anual_anio_actual();

$contextActions = [
    metis_context_action('Comunidad educativa', '../comunidad/index.php', 'bi-people', 'secondary'),
    metis_context_action('Pendientes', 'pendientes.php', 'bi-exclamation-triangle', 'warning'),
    metis_context_action('Plantilla', 'plantilla.php', 'bi-file-earmark-spreadsheet', 'secondary'),
    metis_context_action('Plantilla vinculación', 'plantilla_vinculacion.php', 'bi-link-45deg', 'secondary'),
];

include __DIR__ . '/../../core/layout_header.php';
?>

<section class="metis-page">
    <div class="metis-card">
        <div class="metis-card__header">
            <div>
                <h1 class="metis-title">Importación masiva anual</h1>
                <p class="metis-subtitle">Carga comunidad educativa por año escolar sin sobrescribir históricos.</p>
            </div>
        </div>

        <form method="post" action="procesar.php" enctype="multipart/form-data" class="metis-form">
            <?= CSRF::field() ?>

            <div class="metis-grid metis-grid--3">
                <div class="metis-field">
                    <label class="metis-label">Año escolar</label>
                    <input class="metis-input" type="number" name="anio_escolar" min="2020" max="2100" value="<?= (int)$anioActual ?>" required>
                </div>

                <div class="metis-field">
                    <label class="metis-label">Tipo de carga</label>
                    <select class="metis-select" name="tipo" required>
                        <option value="alumnos">Alumnos</option>
                        <option value="apoderados">Apoderados</option>
                        <option value="docentes">Docentes</option>
                        <option value="asistentes">Asistentes</option>
                        <option value="vinculacion">Vinculación alumno-apoderado</option>
                    </select>
                </div>

                <div class="metis-field">
                    <label class="metis-label">Archivo CSV</label>
                    <input class="metis-input" type="file" name="archivo" accept=".csv,text/csv" required>
                </div>
            </div>

            <div class="metis-actions">
                <button class="metis-btn metis-btn--primary" type="submit">Procesar importación</button>
            </div>
        </form>
    </div>
</section>

<?php include __DIR__ . '/../../core/layout_footer.php'; ?>
