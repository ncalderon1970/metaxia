<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/context_actions.php';
require_once __DIR__ . '/_comunidad_anual_view_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$colegioId = (int) Auth::colegioId();
$tipo = (string)($_GET['tipo'] ?? 'alumnos');
$permitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];
if (!in_array($tipo, $permitidos, true)) {
    $tipo = 'alumnos';
}
$id = (int)($_GET['id'] ?? 0);
$anioEscolar = metis_anio_escolar_request();
$tabla = metis_tabla_anual_por_tipo($tipo);

$stmt = $pdo->prepare("SELECT * FROM {$tabla} WHERE id = ? AND colegio_id = ? LIMIT 1");
$stmt->execute([$id, $colegioId]);
$registro = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$registro) {
    http_response_code(404);
    exit('Registro no encontrado');
}

$contextActions = [
    metis_context_action('Volver', 'index.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . (int)$registro['anio_escolar'], 'bi-arrow-left', 'secondary'),
];

include __DIR__ . '/../../core/layout_header.php';
?>

<section class="metis-page">
    <div class="metis-card">
        <div class="metis-card__header">
            <div>
                <h1 class="metis-title">Editar registro anual</h1>
                <p class="metis-subtitle">Actualización acotada al año escolar <?= (int)$registro['anio_escolar'] ?>.</p>
            </div>
        </div>

        <form method="post" action="actualizar.php" class="metis-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="tipo" value="<?= metis_e($tipo) ?>">
            <input type="hidden" name="id" value="<?= (int)$registro['id'] ?>">
            <input type="hidden" name="anio_escolar" value="<?= (int)$registro['anio_escolar'] ?>">

            <div class="metis-grid metis-grid--2">
                <div class="metis-form-group">
                    <label>RUN</label>
                    <input class="metis-input" name="run" value="<?= metis_e($registro['run'] ?? '') ?>" required>
                </div>
                <div class="metis-form-group">
                    <label>Fecha de nacimiento</label>
                    <input class="metis-input" type="date" name="fecha_nacimiento" value="<?= metis_e($registro['fecha_nacimiento'] ?? '') ?>">
                </div>
                <div class="metis-form-group">
                    <label>Nombres</label>
                    <input class="metis-input" name="nombres" value="<?= metis_e($registro['nombres'] ?? '') ?>" required>
                </div>
                <div class="metis-form-group">
                    <label>Apellido paterno</label>
                    <input class="metis-input" name="apellido_paterno" value="<?= metis_e($registro['apellido_paterno'] ?? '') ?>" required>
                </div>
                <div class="metis-form-group">
                    <label>Apellido materno</label>
                    <input class="metis-input" name="apellido_materno" value="<?= metis_e($registro['apellido_materno'] ?? '') ?>">
                </div>
                <div class="metis-form-group">
                    <label>Nombre social</label>
                    <input class="metis-input" name="nombre_social" value="<?= metis_e($registro['nombre_social'] ?? '') ?>">
                </div>
                <div class="metis-form-group">
                    <label>Sexo</label>
                    <input class="metis-input" name="sexo" value="<?= metis_e($registro['sexo'] ?? '') ?>">
                </div>
                <div class="metis-form-group">
                    <label>Género</label>
                    <input class="metis-input" name="genero" value="<?= metis_e($registro['genero'] ?? '') ?>">
                </div>

                <?php if ($tipo === 'alumnos'): ?>
                    <div class="metis-form-group"><label>Curso</label><input class="metis-input" name="curso" value="<?= metis_e($registro['curso'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Nivel</label><input class="metis-input" name="nivel" value="<?= metis_e($registro['nivel'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Letra</label><input class="metis-input" name="letra" value="<?= metis_e($registro['letra'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Jornada</label><input class="metis-input" name="jornada" value="<?= metis_e($registro['jornada'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Estado matrícula</label><input class="metis-input" name="estado_matricula" value="<?= metis_e($registro['estado_matricula'] ?? '') ?>"></div>
                <?php elseif ($tipo === 'apoderados'): ?>
                    <div class="metis-form-group"><label>Teléfono</label><input class="metis-input" name="telefono" value="<?= metis_e($registro['telefono'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Email</label><input class="metis-input" type="email" name="email" value="<?= metis_e($registro['email'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Dirección</label><input class="metis-input" name="direccion" value="<?= metis_e($registro['direccion'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Relación general</label><input class="metis-input" name="relacion_general" value="<?= metis_e($registro['relacion_general'] ?? '') ?>"></div>
                <?php else: ?>
                    <div class="metis-form-group"><label>Cargo</label><input class="metis-input" name="cargo" value="<?= metis_e($registro['cargo'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Unidad / Departamento</label><input class="metis-input" name="unidad_departamento" value="<?= metis_e($registro['unidad'] ?? $registro['departamento'] ?? '') ?>"></div>
                    <div class="metis-form-group"><label>Tipo contrato</label><input class="metis-input" name="tipo_contrato" value="<?= metis_e($registro['tipo_contrato'] ?? '') ?>"></div>
                <?php endif; ?>

                <div class="metis-form-group">
                    <label>Vigente</label>
                    <select class="metis-select" name="vigente">
                        <option value="1" <?= ((int)$registro['vigente'] === 1) ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= ((int)$registro['vigente'] === 0) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>

            <div class="metis-actions">
                <button class="metis-btn metis-btn--primary" type="submit">Guardar cambios</button>
            </div>
        </form>
    </div>
</section>

<?php include __DIR__ . '/../../core/layout_footer.php'; ?>
