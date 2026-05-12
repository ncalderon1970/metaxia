<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';
require_once __DIR__ . '/_comunidad_anual_view_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? Auth::colegioId());
$puedeGestionar = method_exists(Auth::class, 'canOperate') ? Auth::canOperate() : true;

$tipos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];
$tipo = (string)($_GET['tipo'] ?? 'alumnos');
if (!in_array($tipo, $tipos, true)) {
    $tipo = 'alumnos';
}

$anioEscolar = metis_anio_escolar_request();
$vigencia = (string)($_GET['vigencia'] ?? 'vigentes');
if (!in_array($vigencia, ['vigentes', 'historicos', 'todos'], true)) {
    $vigencia = 'vigentes';
}

$q = trim((string)($_GET['q'] ?? ''));
$tabla = metis_tabla_anual_por_tipo($tipo);

$metaMap = [
    'alumnos' => [
        'label' => 'Alumnos',
        'icon' => 'bi-mortarboard',
        'desc' => 'Estudiantes registrados para el año escolar seleccionado.',
        'dato_label' => 'Curso',
        'select_extra' => 'curso, nivel, letra, jornada, estado_matricula',
        'order' => 'apellido_paterno, apellido_materno, nombres',
    ],
    'apoderados' => [
        'label' => 'Apoderados',
        'icon' => 'bi-people',
        'desc' => 'Apoderados y contactos asociados al año escolar seleccionado.',
        'dato_label' => 'Contacto / Relación',
        'select_extra' => 'telefono, email, direccion, relacion_general',
        'order' => 'apellido_paterno, apellido_materno, nombres',
    ],
    'docentes' => [
        'label' => 'Docentes',
        'icon' => 'bi-person-badge',
        'desc' => 'Docentes vigentes o históricos por año escolar.',
        'dato_label' => 'Cargo',
        'select_extra' => 'cargo, departamento, jefatura_curso, tipo_contrato',
        'order' => 'apellido_paterno, apellido_materno, nombres',
    ],
    'asistentes' => [
        'label' => 'Asistentes',
        'icon' => 'bi-person-workspace',
        'desc' => 'Asistentes de la educación por año escolar.',
        'dato_label' => 'Cargo',
        'select_extra' => 'cargo, unidad, tipo_contrato',
        'order' => 'apellido_paterno, apellido_materno, nombres',
    ],
];
$meta = $metaMap[$tipo];

$pageTitle = 'Comunidad Educativa · Metis';
$pageSubtitle = 'Gestión anual e histórica de estudiantes, apoderados, docentes y asistentes';
$pageHeaderActions = metis_context_actions([
    metis_context_action('Nuevo registro anual', APP_URL . '/modules/comunidad/crear.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar, 'bi-plus-circle', 'primary', $puedeGestionar),
    metis_context_action('Importar año', APP_URL . '/modules/importar/index.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar, 'bi-upload', 'secondary', $puedeGestionar),
    metis_context_action('Vincular apoderados', APP_URL . '/modules/comunidad/vincular_apoderado.php?anio_escolar=' . $anioEscolar, 'bi-diagram-3', 'secondary', $puedeGestionar),
]);

$kpis = [];
foreach ($tipos as $t) {
    $tTabla = metis_tabla_anual_por_tipo($t);
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM {$tTabla} WHERE colegio_id = ? AND anio_escolar = ?");
        $s->execute([$colegioId, $anioEscolar]);
        $kpis[$t] = (int)$s->fetchColumn();
    } catch (Throwable $e) {
        $kpis[$t] = 0;
    }
}

$where = ['colegio_id = :colegio_id', 'anio_escolar = :anio_escolar'];
$params = [
    ':colegio_id' => $colegioId,
    ':anio_escolar' => $anioEscolar,
];

if ($vigencia === 'vigentes') {
    $where[] = 'vigente = 1';
} elseif ($vigencia === 'historicos') {
    $where[] = 'vigente = 0';
}

if ($q !== '') {
    $where[] = "(
        UPPER(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno, nombre_social)) COLLATE utf8mb4_unicode_ci LIKE :q COLLATE utf8mb4_unicode_ci
        OR UPPER(REPLACE(REPLACE(REPLACE(run,'.',''),'-',''),' ','')) LIKE :q_run
    )";
    $params[':q'] = '%' . mb_strtoupper($q, 'UTF-8') . '%';
    $params[':q_run'] = '%' . metis_normalizar_run_busqueda($q) . '%';
}

$registros = [];
$totalTipo = 0;
$error = '';

try {
    $whereSql = implode(' AND ', $where);

    $c = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE {$whereSql}");
    $c->execute($params);
    $totalTipo = (int)$c->fetchColumn();

    $selectExtra = $meta['select_extra'];
    $order = $meta['order'];

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
            {$selectExtra},
            created_at,
            updated_at
        FROM {$tabla}
        WHERE {$whereSql}
        ORDER BY vigente DESC, {$order} ASC
        LIMIT 500
    ";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $registros = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Error al cargar comunidad educativa anual: ' . $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.com-hero{background:radial-gradient(circle at 90% 16%,rgba(16,185,129,.22),transparent 28%),linear-gradient(135deg,#0f172a 0%,#1e3a8a 58%,#2563eb 100%);color:#fff;border-radius:22px;padding:2rem;margin-bottom:1.2rem;box-shadow:0 18px 45px rgba(15,23,42,.18)}
.com-hero h2{margin:0 0 .45rem;font-size:1.85rem;font-weight:900}.com-hero p{margin:0;color:#bfdbfe;max-width:900px;line-height:1.55}
.com-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.9rem;margin-bottom:1.2rem}.com-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1rem;box-shadow:0 12px 28px rgba(15,23,42,.06)}.com-kpi span{color:#64748b;display:block;font-size:.68rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.com-kpi strong{display:block;color:#0f172a;font-size:2rem;line-height:1;margin-top:.35rem}
.com-tabs{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.2rem}.com-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem .9rem;border-radius:7px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-weight:900;font-size:.84rem;text-decoration:none}.com-tab.active{background:#0f172a;color:#fff;border-color:#0f172a}
.com-panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 28px rgba(15,23,42,.06);overflow:hidden;margin-bottom:1.2rem}.com-panel-head{padding:1rem 1.2rem;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.com-panel-title{margin:0;color:#0f172a;font-size:1rem;font-weight:900}.com-panel-body{padding:1.2rem}.com-filter{display:grid;grid-template-columns:.85fr .55fr .75fr 1.35fr auto auto;gap:.8rem;align-items:end}.com-label{display:block;font-size:.76rem;font-weight:900;color:#334155;margin-bottom:.35rem}.com-control{width:100%;border:1px solid #cbd5e1;border-radius:13px;padding:.65rem .78rem;outline:none;background:#fff;font-size:.9rem}.com-submit,.com-link,.com-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:.28rem;border:1px solid #e2e8f0;background:#f8fafc;color:#374151;border-radius:6px;padding:.36rem .7rem;font-weight:700;font-size:.73rem;text-decoration:none;white-space:nowrap;cursor:pointer;line-height:1.4}.com-link{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.com-link.green{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.com-action-btn.red{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.com-action-btn.green{background:#ecfdf5;color:#047857;border-color:#bbf7d0}
.com-table-scroll{width:100%;overflow:auto;max-height:620px;border:1px solid #e2e8f0;border-radius:14px}.com-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.86rem}.com-table th{background:#f8fafc;color:#64748b;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;padding:.75rem;border-bottom:1px solid #e2e8f0;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:2}.com-table td{padding:.85rem .75rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}.com-main{color:#0f172a;font-weight:900}.com-muted{color:#64748b;font-size:.78rem}.com-social{display:block;color:#0f766e;font-size:.78rem;font-weight:800;margin-top:.15rem}.com-badge{display:inline-flex;align-items:center;border-radius:99px;padding:.2rem .55rem;font-size:.68rem;font-weight:900}.com-badge.ok{background:#dcfce7;color:#166534}.com-badge.off{background:#fee2e2;color:#991b1b}.com-alert{border-radius:14px;padding:.85rem 1rem;margin-bottom:1rem;font-weight:800}.com-alert.ok{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.com-alert.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:1100px){.com-filter{grid-template-columns:1fr 1fr}}@media(max-width:900px){.com-kpis{grid-template-columns:repeat(2,1fr)}.com-filter{grid-template-columns:1fr}.com-table{min-width:1100px}}
</style>

<section class="com-hero">
    <h2>Comunidad educativa</h2>
    <p>Gestión anual e histórica de estudiantes, apoderados, docentes y asistentes del establecimiento activo.</p>
</section>

<div class="com-kpis">
    <?php foreach ($tipos as $t): $m = $metaMap[$t]; ?>
        <div class="com-kpi"><span><?= metis_e($m['label']) ?> · <?= (int)$anioEscolar ?></span><strong><?= (int)$kpis[$t] ?></strong></div>
    <?php endforeach; ?>
</div>

<?php if ($error !== ''): ?><div class="com-alert err"><?= metis_e($error) ?></div><?php endif; ?>

<nav class="com-tabs">
    <?php foreach ($tipos as $t): $m = $metaMap[$t]; ?>
        <a class="com-tab <?= $tipo === $t ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= urlencode($t) ?>&anio_escolar=<?= (int)$anioEscolar ?>&vigencia=<?= urlencode($vigencia) ?>"><i class="bi <?= metis_e($m['icon']) ?>"></i><?= metis_e($m['label']) ?></a>
    <?php endforeach; ?>
</nav>

<section class="com-panel">
    <div class="com-panel-head">
        <div>
            <h3 class="com-panel-title"><i class="bi <?= metis_e($meta['icon']) ?>"></i> <?= metis_e($meta['label']) ?></h3>
            <div class="com-muted"><?= metis_e($meta['desc']) ?> · Año <?= (int)$anioEscolar ?> · <?= (int)$totalTipo ?> resultado(s)</div>
        </div>
    </div>
    <div class="com-panel-body">
        <form method="get" class="com-filter">
            <div>
                <label class="com-label">Tipo</label>
                <select class="com-control" name="tipo">
                    <?php foreach ($tipos as $t): ?>
                        <option value="<?= metis_e($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= metis_e($metaMap[$t]['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="com-label">Año escolar</label>
                <input class="com-control" type="number" name="anio_escolar" min="2020" max="2100" value="<?= (int)$anioEscolar ?>">
            </div>
            <div>
                <label class="com-label">Vigencia</label>
                <select class="com-control" name="vigencia">
                    <option value="vigentes" <?= $vigencia === 'vigentes' ? 'selected' : '' ?>>Vigentes</option>
                    <option value="historicos" <?= $vigencia === 'historicos' ? 'selected' : '' ?>>Históricos</option>
                    <option value="todos" <?= $vigencia === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div>
                <label class="com-label">Buscar</label>
                <input class="com-control" type="search" name="q" value="<?= metis_e($q) ?>" placeholder="RUN, nombre o nombre social">
            </div>
            <button class="com-submit" type="submit"><i class="bi bi-search"></i> Filtrar</button>
            <a class="com-link" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= urlencode($tipo) ?>&anio_escolar=<?= (int)$anioEscolar ?>">Limpiar</a>
        </form>
    </div>
</section>

<section class="com-panel">
    <div class="com-table-scroll">
        <table class="com-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>RUN</th>
                    <th>Año</th>
                    <th><?= metis_e($meta['dato_label']) ?></th>
                    <th>Sexo</th>
                    <th>Género</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$registros): ?>
                <tr><td colspan="8" class="com-muted" style="text-align:center;padding:2rem;">No hay registros para los filtros seleccionados.</td></tr>
            <?php endif; ?>
            <?php foreach ($registros as $r): ?>
                <?php
                    $nombreLegal = trim(implode(' ', array_filter([
                        (string)($r['nombres'] ?? ''),
                        (string)($r['apellido_paterno'] ?? ''),
                        (string)($r['apellido_materno'] ?? ''),
                    ])));
                    $nombreSocial = trim((string)($r['nombre_social'] ?? ''));
                    $datoFuncional = match ($tipo) {
                        'alumnos' => trim((string)($r['curso'] ?? '') . ' ' . (string)($r['letra'] ?? '')) ?: trim((string)($r['nivel'] ?? '')),
                        'apoderados' => trim((string)($r['relacion_general'] ?? '')) ?: trim((string)($r['telefono'] ?? '')),
                        'docentes' => trim((string)($r['cargo'] ?? '')),
                        'asistentes' => trim((string)($r['cargo'] ?? '')),
                        default => '',
                    };
                    $vigente = (int)($r['vigente'] ?? 0) === 1;
                ?>
                <tr>
                    <td>
                        <div class="com-main"><?= metis_e($nombreLegal) ?></div>
                        <?php if ($nombreSocial !== ''): ?>
                            <span class="com-social">Nombre social: <?= metis_e($nombreSocial) ?></span>
                        <?php endif; ?>
                        <div class="com-muted">ID anual <?= (int)$r['id'] ?></div>
                    </td>
                    <td><?= metis_e((string)($r['run'] ?? '')) ?></td>
                    <td><?= (int)($r['anio_escolar'] ?? $anioEscolar) ?></td>
                    <td><?= metis_e($datoFuncional !== '' ? $datoFuncional : '-') ?></td>
                    <td><?= metis_e((string)($r['sexo'] ?? '-')) ?></td>
                    <td><?= metis_e((string)($r['genero'] ?? '-')) ?></td>
                    <td><span class="com-badge <?= $vigente ? 'ok' : 'off' ?>"><?= $vigente ? 'Vigente' : 'Histórico' ?></span></td>
                    <td>
                        <a class="com-link" href="<?= APP_URL ?>/modules/comunidad/editar.php?tipo=<?= urlencode($tipo) ?>&id=<?= (int)$r['id'] ?>&anio_escolar=<?= (int)$anioEscolar ?>"><i class="bi bi-pencil"></i> Editar</a>
                        <?php if ($tipo === 'alumnos' && $puedeGestionar): ?>
                            <a class="com-link green" href="<?= APP_URL ?>/modules/comunidad/vincular_apoderado.php?alumno_anual_id=<?= (int)$r['id'] ?>&anio_escolar=<?= (int)$anioEscolar ?>"><i class="bi bi-people"></i> Apoderados</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
