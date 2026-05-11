<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/context_actions.php';

Auth::requireLogin();

$pdo = DB::conn();
$colegioId = (int) Auth::colegioId();

$tipo = $_GET['tipo'] ?? 'alumnos';
$permitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];
if (!in_array($tipo, $permitidos, true)) {
    $tipo = 'alumnos';
}

$anioEscolar = (int)($_GET['anio_escolar'] ?? date('Y'));
$vigencia = $_GET['vigencia'] ?? 'vigentes';
$q = trim((string)($_GET['q'] ?? ''));

$tabla = $tipo . '_anual';
$datoExpr = $tipo === 'alumnos'
    ? "CONCAT_WS(' ', curso, nivel, letra)"
    : "COALESCE(cargo, '')";

$sql = "
    SELECT
        id, colegio_id, run, nombres, apellido_paterno, apellido_materno,
        fecha_nacimiento, sexo, genero, nombre_social, anio_escolar, vigente,
        {$datoExpr} AS dato_funcional
    FROM {$tabla}
    WHERE colegio_id = :colegio_id
      AND anio_escolar = :anio_escolar
";
$params = [
    ':colegio_id' => $colegioId,
    ':anio_escolar' => $anioEscolar,
];

if ($vigencia === 'vigentes') {
    $sql .= " AND vigente = 1";
} elseif ($vigencia === 'historicos') {
    $sql .= " AND vigente = 0";
}

if ($q !== '') {
    $sql .= " AND (run LIKE :q OR UPPER(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno, nombre_social)) LIKE :q_upper)";
    $params[':q'] = '%' . $q . '%';
    $params[':q_upper'] = '%' . mb_strtoupper($q, 'UTF-8') . '%';
}

$sql .= " ORDER BY apellido_paterno, apellido_materno, nombres LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contextActions = [
    metis_context_action('Nuevo registro', 'crear.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar, 'bi-plus-lg', 'primary'),
    metis_context_action('Importar', '../importar/index.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar, 'bi-upload', 'secondary'),
];

include __DIR__ . '/../../core/layout_header.php';
?>
<link rel="stylesheet" href="assets/comunidad.css">

<section class="metis-page comunidad-page">
    <div class="metis-card">
        <div class="metis-card__header">
            <div>
                <h1 class="metis-title">Comunidad educativa</h1>
                <p class="metis-subtitle">Gestión anual e histórica de estudiantes, apoderados, docentes y asistentes.</p>
            </div>
        </div>
        <?php include __DIR__ . '/partials/comunidad_toolbar.php'; ?>
        <?php include __DIR__ . '/partials/comunidad_table.php'; ?>
    </div>
</section>

<?php include __DIR__ . '/../../core/layout_footer.php'; ?>
