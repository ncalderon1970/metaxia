<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$casoId = (int)($_GET['caso_id'] ?? $_GET['id'] ?? $_POST['caso_id'] ?? 0);

if ($casoId <= 0) {
    http_response_code(400);
    exit('Debe indicar un caso.');
}

$pageTitle = 'Vincular comunidad · Metis';
$pageSubtitle = 'Agregar alumnos, apoderados, docentes o asistentes como participantes del caso';

function vc_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vc_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vc_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function vc_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function vc_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function vc_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $default;
}

function vc_nombre_completo(array $row): string
{
    $partes = [];

    foreach (['nombres', 'apellido_paterno', 'apellido_materno'] as $key) {
        if (!empty($row[$key])) {
            $partes[] = trim((string)$row[$key]);
        }
    }

    $nombre = trim(implode(' ', $partes));

    return $nombre !== '' ? $nombre : 'Sin nombre';
}

function vc_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!vc_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = vc_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para insertar.');
    }

    $sql = "
        INSERT INTO " . vc_quote($table) . " (
            " . implode(', ', $columns) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function vc_redirect(int $casoId, string $msg = ''): void
{
    $url = APP_URL . '/modules/denuncias/vincular_comunidad.php?caso_id=' . $casoId;

    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }

    header('Location: ' . $url);
    exit;
}

$tipos = [
    'alumnos' => [
        'label' => 'Alumnos',
        'tipo_persona' => 'alumno',
        'icon' => 'bi-mortarboard',
        'extra' => ['curso', 'fecha_nacimiento'],
    ],
    'apoderados' => [
        'label' => 'Apoderados',
        'tipo_persona' => 'apoderado',
        'icon' => 'bi-people',
        'extra' => ['parentesco'],
    ],
    'docentes' => [
        'label' => 'Docentes',
        'tipo_persona' => 'docente',
        'icon' => 'bi-person-video3',
        'extra' => ['especialidad'],
    ],
    'asistentes' => [
        'label' => 'Asistentes',
        'tipo_persona' => 'asistente',
        'icon' => 'bi-person-workspace',
        'extra' => ['cargo'],
    ],
];

$tipo = clean((string)($_GET['tipo'] ?? $_POST['tipo'] ?? 'alumnos'));

if (!array_key_exists($tipo, $tipos)) {
    $tipo = 'alumnos';
}

$q = clean((string)($_GET['q'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));

$error = '';
$caso = null;
$resultados = [];
$participantes = [];

try {
    if (!vc_table_exists($pdo, 'casos')) {
        throw new RuntimeException('La tabla casos no existe.');
    }

    if (!vc_table_exists($pdo, 'caso_participantes')) {
        throw new RuntimeException('La tabla caso_participantes no existe.');
    }

    $stmt = $pdo->prepare("
        SELECT
            c.*,
            ec.nombre AS estado_formal
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE c.id = ?
          AND c.colegio_id = ?
        LIMIT 1
    ");
    $stmt->execute([$casoId, $colegioId]);
    $caso = $stmt->fetch();

    if (!$caso) {
        throw new RuntimeException('Caso no encontrado o no pertenece al establecimiento.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion !== 'vincular') {
            throw new RuntimeException('Acción no válida.');
        }

        $personaId = (int)($_POST['persona_id'] ?? 0);
        $rolEnCaso = clean((string)($_POST['rol_en_caso'] ?? 'involucrado'));
        $observacion = clean((string)($_POST['observacion'] ?? ''));

        if ($personaId <= 0) {
            throw new RuntimeException('Persona no válida.');
        }

        if (!vc_table_exists($pdo, $tipo)) {
            throw new RuntimeException('La tabla ' . $tipo . ' no existe.');
        }

        if (!in_array($rolEnCaso, ['victima', 'denunciante', 'denunciado', 'testigo', 'involucrado'], true)) {
            $rolEnCaso = 'involucrado';
        }

        $stmtPersona = $pdo->prepare("
            SELECT *
            FROM " . vc_quote($tipo) . "
            WHERE id = ?
              " . (vc_column_exists($pdo, $tipo, 'colegio_id') ? "AND colegio_id = ?" : "") . "
            LIMIT 1
        ");

        $paramsPersona = vc_column_exists($pdo, $tipo, 'colegio_id')
            ? [$personaId, $colegioId]
            : [$personaId];

        $stmtPersona->execute($paramsPersona);
        $persona = $stmtPersona->fetch();

        if (!$persona) {
            throw new RuntimeException('La persona seleccionada no existe.');
        }

        $tipoPersona = $tipos[$tipo]['tipo_persona'];
        $nombreReferencial = vc_nombre_completo($persona);
        $run = vc_pick($persona, ['run'], '0-0');

        $stmtExiste = $pdo->prepare("
            SELECT COUNT(*)
            FROM caso_participantes
            WHERE caso_id = ?
              AND tipo_persona = ?
              AND persona_id = ?
        ");
        $stmtExiste->execute([$casoId, $tipoPersona, $personaId]);

        if ((int)$stmtExiste->fetchColumn() > 0) {
            throw new RuntimeException('Esta persona ya está vinculada al caso.');
        }

        $pdo->beginTransaction();

        $participanteId = vc_insert_dynamic($pdo, 'caso_participantes', [
            'caso_id' => $casoId,
            'tipo_persona' => $tipoPersona,
            'persona_id' => $personaId,
            'nombre_referencial' => $nombreReferencial,
            'run' => $run,
            'rol_en_caso' => $rolEnCaso,
            'solicita_reserva_identidad' => 0,
            'observacion_reserva' => null,
            'identidad_confirmada' => 1,
            'fecha_identificacion' => date('Y-m-d H:i:s'),
            'identificado_por' => $userId > 0 ? $userId : null,
            'observacion_identificacion' => 'Vinculado desde comunidad educativa.',
            'observacion' => $observacion !== '' ? $observacion : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (vc_table_exists($pdo, 'caso_historial')) {
            vc_insert_dynamic($pdo, 'caso_historial', [
                'caso_id' => $casoId,
                'tipo_evento' => 'participante',
                'titulo' => 'Participante vinculado desde comunidad educativa',
                'detalle' => $nombreReferencial . ' fue vinculado como ' . vc_label($rolEnCaso) . '.',
                'user_id' => $userId > 0 ? $userId : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        registrar_bitacora(
            'denuncias',
            'vincular_comunidad',
            'caso_participantes',
            $participanteId,
            'Se vinculó participante desde comunidad educativa al caso ' . ((string)$caso['numero_caso'])
        );

        $pdo->commit();

        vc_redirect($casoId, 'Participante vinculado correctamente.');
    }

    if ($q !== '' && vc_table_exists($pdo, $tipo)) {
        $where = [];
        $params = [];

        if (vc_column_exists($pdo, $tipo, 'colegio_id')) {
            $where[] = 'colegio_id = ?';
            $params[] = $colegioId;
        }

        if (vc_column_exists($pdo, $tipo, 'activo')) {
            $where[] = 'activo = 1';
        }

        $searchParts = [];

        foreach (['run', 'nombres', 'apellido_paterno', 'apellido_materno', 'email', 'telefono', 'curso', 'especialidad', 'cargo'] as $col) {
            if (vc_column_exists($pdo, $tipo, $col)) {
                $searchParts[] = vc_quote($col) . ' LIKE ?';
                $params[] = '%' . $q . '%';
            }
        }

        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtBuscar = $pdo->prepare("
            SELECT *
            FROM " . vc_quote($tipo) . "
            {$whereSql}
            ORDER BY
                " . (vc_column_exists($pdo, $tipo, 'apellido_paterno') ? "apellido_paterno ASC," : "") . "
                " . (vc_column_exists($pdo, $tipo, 'apellido_materno') ? "apellido_materno ASC," : "") . "
                " . (vc_column_exists($pdo, $tipo, 'nombres') ? "nombres ASC," : "") . "
                id DESC
            LIMIT 80
        ");
        $stmtBuscar->execute($params);
        $resultados = $stmtBuscar->fetchAll();
    }

    $stmtPart = $pdo->prepare("
        SELECT *
        FROM caso_participantes
        WHERE caso_id = ?
        ORDER BY id DESC
    ");
    $stmtPart->execute([$casoId]);
    $participantes = $stmtPart->fetchAll();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.vc-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.vc-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.vc-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 900px;
    line-height: 1.55;
}

.vc-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.vc-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.vc-btn:hover {
    color: #fff;
}

.vc-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(360px, .85fr);
    gap: 1.2rem;
    align-items: start;
}

.vc-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.vc-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.vc-panel-title {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
    font-weight: 900;
}

.vc-panel-body {
    padding: 1.2rem;
}

.vc-tabs {
    display: flex;
    gap: .35rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.vc-tab {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    text-decoration: none;
    border-radius: 999px;
    padding: .58rem .85rem;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    font-weight: 900;
    font-size: .82rem;
}

.vc-tab.active {
    background: #0f172a;
    border-color: #0f172a;
    color: #fff;
}

.vc-filter {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: .8rem;
    align-items: end;
}

.vc-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.vc-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.vc-submit,
.vc-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 999px;
    padding: .66rem 1rem;
    font-weight: 900;
    font-size: .84rem;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
}

.vc-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.vc-submit.green {
    background: #059669;
}

.vc-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .75rem;
}

.vc-item-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .2rem;
}

.vc-meta {
    color: #64748b;
    font-size: .76rem;
    margin-top: .25rem;
    line-height: 1.35;
}

.vc-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .8rem;
}

.vc-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .62rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .12rem;
}

.vc-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.vc-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.vc-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.vc-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.vc-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.vc-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}

@media (max-width: 1100px) {
    .vc-layout,
    .vc-grid-2,
    .vc-filter {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="vc-hero">
    <h2>Vincular comunidad educativa</h2>
    <p>
        Agrega personas registradas en la comunidad educativa como participantes del expediente:
        víctima, denunciante, denunciado, testigo o involucrado.
    </p>

    <div class="vc-actions">
        <a class="vc-btn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>">
            <i class="bi bi-arrow-left"></i>
            Volver al expediente
        </a>

        <a class="vc-btn" href="<?= APP_URL ?>/modules/comunidad/index.php">
            <i class="bi bi-people"></i>
            Comunidad educativa
        </a>

        <a class="vc-btn" href="<?= APP_URL ?>/modules/seguimiento/abrir.php?caso_id=<?= (int)$casoId ?>">
            <i class="bi bi-clipboard2-check"></i>
            Seguimiento
        </a>
    </div>
</section>

<?php if ($msg !== ''): ?>
    <div class="vc-msg ok">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="vc-msg error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="vc-layout">
    <section>
        <div class="vc-panel">
            <div class="vc-panel-head">
                <h3 class="vc-panel-title">
                    <i class="bi bi-search"></i>
                    Buscar persona
                </h3>
            </div>

            <div class="vc-panel-body">
                <nav class="vc-tabs">
                    <?php foreach ($tipos as $key => $info): ?>
                        <a
                            class="vc-tab <?= $tipo === $key ? 'active' : '' ?>"
                            href="<?= APP_URL ?>/modules/denuncias/vincular_comunidad.php?caso_id=<?= (int)$casoId ?>&tipo=<?= e($key) ?>"
                        >
                            <i class="bi <?= e($info['icon']) ?>"></i>
                            <?= e($info['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <form method="get" class="vc-filter">
                    <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">
                    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">

                    <div>
                        <label class="vc-label">Buscar por RUN, nombre, correo, teléfono, curso, cargo o especialidad</label>
                        <input
                            class="vc-control"
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Ej: 11111111-1, Juan, 7° Básico, Inspectoría"
                            required
                        >
                    </div>

                    <div>
                        <button class="vc-submit" type="submit">
                            <i class="bi bi-search"></i>
                            Buscar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="vc-panel">
            <div class="vc-panel-head">
                <h3 class="vc-panel-title">
                    Resultados
                </h3>

                <span class="vc-badge">
                    <?= number_format(count($resultados), 0, ',', '.') ?> resultado(s)
                </span>
            </div>

            <div class="vc-panel-body">
                <?php if (!vc_table_exists($pdo, $tipo)): ?>
                    <div class="vc-empty">
                        La tabla <?= e($tipo) ?> no existe. Crea la estructura desde el SQL opcional de comunidad educativa.
                    </div>
                <?php elseif ($q === ''): ?>
                    <div class="vc-empty">
                        Ingresa un criterio de búsqueda para listar personas.
                    </div>
                <?php elseif (!$resultados): ?>
                    <div class="vc-empty">
                        No se encontraron registros.
                    </div>
                <?php else: ?>
                    <?php foreach ($resultados as $persona): ?>
                        <article class="vc-item">
                            <div class="vc-item-title">
                                <?= e(vc_nombre_completo($persona)) ?>
                            </div>

                            <div>
                                <span class="vc-badge ok"><?= e($tipos[$tipo]['label']) ?></span>
                                <span class="vc-badge"><?= e(vc_pick($persona, ['run'], 'Sin RUN')) ?></span>

                                <?php foreach ($tipos[$tipo]['extra'] as $extra): ?>
                                    <?php if (!empty($persona[$extra])): ?>
                                        <span class="vc-badge">
                                            <?= e(vc_label($extra)) ?>: <?= e((string)$persona[$extra]) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="vc-meta">
                                <?= e(vc_pick($persona, ['email'], 'Sin correo')) ?>
                                ·
                                <?= e(vc_pick($persona, ['telefono'], 'Sin teléfono')) ?>
                            </div>

                            <form method="post" style="margin-top:1rem;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="_accion" value="vincular">
                                <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">
                                <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
                                <input type="hidden" name="persona_id" value="<?= (int)$persona['id'] ?>">

                                <div class="vc-grid-2">
                                    <div>
                                        <label class="vc-label">Rol en el caso</label>
                                        <select class="vc-control" name="rol_en_caso">
                                            <option value="victima">Víctima / afectado</option>
                                            <option value="denunciante">Denunciante</option>
                                            <option value="denunciado">Denunciado</option>
                                            <option value="testigo">Testigo</option>
                                            <option value="involucrado" selected>Involucrado</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="vc-label">Observación</label>
                                        <input
                                            class="vc-control"
                                            type="text"
                                            name="observacion"
                                            placeholder="Ej: estudiante mencionado en relato"
                                        >
                                    </div>
                                </div>

                                <div style="margin-top:.8rem;">
                                    <button class="vc-submit green" type="submit">
                                        <i class="bi bi-person-plus"></i>
                                        Vincular al caso
                                    </button>
                                </div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="vc-panel">
            <div class="vc-panel-head">
                <h3 class="vc-panel-title">
                    <i class="bi bi-folder2-open"></i>
                    Caso
                </h3>
            </div>

            <div class="vc-panel-body">
                <?php if ($caso): ?>
                    <article class="vc-item">
                        <div class="vc-item-title">
                            <?= e($caso['numero_caso']) ?>
                        </div>

                        <div>
                            <span class="vc-badge"><?= e($caso['estado_formal'] ?: vc_label((string)$caso['estado'])) ?></span>
                            <span class="vc-badge"><?= e(vc_label((string)$caso['semaforo'])) ?></span>
                            <span class="vc-badge"><?= e(vc_label((string)$caso['prioridad'])) ?></span>
                        </div>

                        <div class="vc-meta">
                            Ingreso: <?= e(vc_fecha((string)$caso['fecha_ingreso'])) ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </div>

        <div class="vc-panel">
            <div class="vc-panel-head">
                <h3 class="vc-panel-title">
                    <i class="bi bi-people"></i>
                    Participantes actuales
                </h3>

                <span class="vc-badge"><?= number_format(count($participantes), 0, ',', '.') ?></span>
            </div>

            <div class="vc-panel-body">
                <?php if (!$participantes): ?>
                    <div class="vc-empty">
                        Aún no hay participantes vinculados.
                    </div>
                <?php else: ?>
                    <?php foreach ($participantes as $p): ?>
                        <article class="vc-item">
                            <div class="vc-item-title">
                                <?= e(vc_pick($p, ['nombre_referencial'], 'Participante')) ?>
                            </div>

                            <div>
                                <span class="vc-badge"><?= e(vc_label((string)($p['tipo_persona'] ?? ''))) ?></span>
                                <span class="vc-badge"><?= e(vc_label((string)($p['rol_en_caso'] ?? ''))) ?></span>
                            </div>

                            <div class="vc-meta">
                                RUN: <?= e(vc_pick($p, ['run'], '-')) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>