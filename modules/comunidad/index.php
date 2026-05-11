<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/context_actions.php';
require_once __DIR__ . '/_comunidad_anual_view_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$colegioId = (int) Auth::colegioId();
$anioEscolar = metis_anio_escolar_request();
$tipo = (string)($_GET['tipo'] ?? 'alumnos');
$permitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];
if (!in_array($tipo, $permitidos, true)) {
    $tipo = 'alumnos';
}

$q = trim((string)($_GET['q'] ?? ''));
$vigencia = (string)($_GET['vigencia'] ?? 'vigentes');
$tabla = metis_tabla_anual_por_tipo($tipo);

$camposExtra = match ($tipo) {
    'alumnos' => 'curso, nivel, letra, jornada, estado_matricula',
    'apoderados' => 'telefono, email, direccion, relacion_general',
    'docentes' => 'cargo, departamento, jefatura_curso, tipo_contrato',
    'asistentes' => 'cargo, unidad, tipo_contrato',
    default => 'curso'
};

$sql = "
    SELECT
        id,
        colegio_id,
        run,
        nombres,
        apellido_paterno,
        apellido_materno,
        fecha_nacimiento,
        sexo,
        genero,
        nombre_social,
        anio_escolar,
        vigente,
        {$camposExtra},
        created_at,
        updated_at
    FROM {$tabla}
    WHERE colegio_id = :colegio_id
      AND anio_escolar = :anio_escolar
";
$params = [
    'colegio_id' => $colegioId,
    'anio_escolar' => $anioEscolar,
];

if ($vigencia === 'vigentes') {
    $sql .= " AND vigente = 1 ";
} elseif ($vigencia === 'historicos') {
    $sql .= " AND vigente = 0 ";
}

if ($q !== '') {
    $sql .= "
        AND (
            UPPER(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno, nombre_social)) LIKE :q
            OR REPLACE(REPLACE(REPLACE(UPPER(run),'.',''),'-',''),' ','') LIKE :q_run
        )
    ";
    $params['q'] = '%' . mb_strtoupper($q, 'UTF-8') . '%';
    $params['q_run'] = '%' . metis_normalizar_run_busqueda($q) . '%';
}

$sql .= " ORDER BY apellido_paterno, apellido_materno, nombres LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contextActions = [
    metis_context_action('Nuevo registro anual', 'crear.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar, 'bi-plus-lg', 'primary'),
    metis_context_action('Importar año', '../importar/index.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar, 'bi-upload', 'secondary'),
    metis_context_action('Vincular apoderados', 'vincular_apoderado.php?anio_escolar=' . $anioEscolar, 'bi-people', 'secondary'),
];

include __DIR__ . '/../../core/layout_header.php';
?>

<section class="metis-page">
    <div class="metis-card">
        <div class="metis-card__header">
            <div>
                <h1 class="metis-title">Comunidad educativa</h1>
                <p class="metis-subtitle">Gestión anual e histórica de estudiantes, apoderados, docentes y asistentes.</p>
            </div>
        </div>

        <form method="get" class="metis-toolbar">
            <select class="metis-select" name="tipo">
                <option value="alumnos" <?= $tipo === 'alumnos' ? 'selected' : '' ?>>Alumnos</option>
                <option value="apoderados" <?= $tipo === 'apoderados' ? 'selected' : '' ?>>Apoderados</option>
                <option value="docentes" <?= $tipo === 'docentes' ? 'selected' : '' ?>>Docentes</option>
                <option value="asistentes" <?= $tipo === 'asistentes' ? 'selected' : '' ?>>Asistentes</option>
            </select>
            <input class="metis-input" type="number" name="anio_escolar" min="2020" max="2100" value="<?= (int)$anioEscolar ?>" placeholder="Año escolar">
            <select class="metis-select" name="vigencia">
                <option value="vigentes" <?= $vigencia === 'vigentes' ? 'selected' : '' ?>>Vigentes</option>
                <option value="historicos" <?= $vigencia === 'historicos' ? 'selected' : '' ?>>Históricos</option>
                <option value="todos" <?= $vigencia === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
            <input class="metis-input" type="search" name="q" value="<?= metis_e($q) ?>" placeholder="Buscar por RUN, nombre o nombre social">
            <button class="metis-btn metis-btn--primary" type="submit">Filtrar</button>
        </form>

        <div class="metis-table-wrap">
            <table class="metis-table">
                <thead>
                    <tr>
                        <th>RUN</th>
                        <th>Nombre / Nombre social</th>
                        <th>Año</th>
                        <th>Sexo</th>
                        <th>Género</th>
                        <th>Dato funcional</th>
                        <th>Vigencia</th>
                        <th class="metis-text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $row): ?>
                    <?php
                        $datoFuncional = match ($tipo) {
                            'alumnos' => trim((string)($row['curso'] ?? '') . ' ' . (string)($row['letra'] ?? '')),
                            'apoderados' => (string)($row['relacion_general'] ?? $row['telefono'] ?? ''),
                            'docentes' => (string)($row['cargo'] ?? ''),
                            'asistentes' => (string)($row['cargo'] ?? ''),
                            default => '',
                        };
                    ?>
                    <tr>
                        <td><?= metis_e($row['run'] ?? '') ?></td>
                        <td><?= metis_e(metis_nombre_preferente($row)) ?></td>
                        <td><?= (int)($row['anio_escolar'] ?? $anioEscolar) ?></td>
                        <td><?= metis_e($row['sexo'] ?? '') ?></td>
                        <td><?= metis_e($row['genero'] ?? '') ?></td>
                        <td><?= metis_e($datoFuncional) ?></td>
                        <td><?= ((int)($row['vigente'] ?? 0) === 1) ? 'Vigente' : 'Histórico' ?></td>
                        <td class="metis-text-right">
                            <a class="metis-btn metis-btn--sm metis-btn--secondary" href="editar.php?tipo=<?= urlencode($tipo) ?>&id=<?= (int)$row['id'] ?>&anio_escolar=<?= (int)$anioEscolar ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$registros): ?>
                    <tr>
                        <td colspan="8" class="metis-empty">No existen registros para los filtros seleccionados.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../../core/layout_footer.php'; ?>
