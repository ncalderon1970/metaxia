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

function seg_abrir_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function seg_abrir_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function seg_abrir_badge(string $value): string
{
    return match (strtolower($value)) {
        'alta', 'pendiente' => 'danger',
        'media', 'seguimiento', 'investigacion', 'revision_inicial' => 'warn',
        'baja', 'cerrado', 'resuelta' => 'ok',
        default => 'soft',
    };
}

function seg_abrir_redirect(int $casoId): void
{
    header('Location: ' . APP_URL . '/modules/seguimiento/abrir.php?caso_id=' . $casoId);
    exit;
}

$error = '';
$exito = '';

$stmtCaso = $pdo->prepare("
    SELECT
        c.*,
        ec.nombre AS estado_formal,
        ec.codigo AS estado_codigo
    FROM casos c
    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    WHERE c.id = ?
      AND c.colegio_id = ?
    LIMIT 1
");
$stmtCaso->execute([$casoId, $colegioId]);
$caso = $stmtCaso->fetch();

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado o no pertenece al establecimiento.');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Auth::canOperate()) {
            http_response_code(403);
            exit('Acceso no autorizado para modificar seguimiento.');
        }

        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'guardar_gestion') {
            $tipoEvento = clean((string)($_POST['tipo_evento'] ?? 'gestion'));
            $titulo = clean((string)($_POST['titulo'] ?? 'Gestión de seguimiento'));
            $detalle = clean((string)($_POST['detalle'] ?? ''));

            if ($detalle === '') {
                throw new RuntimeException('Debe ingresar el detalle de la gestión.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $tipoEvento !== '' ? $tipoEvento : 'gestion',
                $titulo !== '' ? $titulo : 'Gestión de seguimiento',
                $detalle,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'seguimiento',
                'guardar_gestion',
                'caso_historial',
                (int)$pdo->lastInsertId(),
                'Gestión registrada en seguimiento.'
            );

            $exito = 'Gestión registrada correctamente.';
            seg_abrir_redirect($casoId);
        }

        if ($accion === 'actualizar_control') {
            $estado = clean((string)($_POST['estado'] ?? 'abierto'));
            $estadoCasoId = (int)($_POST['estado_caso_id'] ?? 0);
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));
            $requiereReanalisis = isset($_POST['requiere_reanalisis_ia']) ? 1 : 0;

            if (!in_array($estado, ['abierto', 'cerrado'], true)) {
                $estado = 'abierto';
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
                $prioridad = 'media';
            }

            if ($estadoCasoId > 0) {
                $chkEstado = $pdo->prepare("SELECT id FROM estado_caso WHERE id = ? AND activo = 1 LIMIT 1");
                $chkEstado->execute([$estadoCasoId]);
                if (!$chkEstado->fetchColumn()) {
                    throw new RuntimeException('Estado formal no válido.');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE casos
                SET estado = ?,
                    estado_caso_id = ?,
                    prioridad = ?,
                    requiere_reanalisis_ia = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $stmt->execute([
                $estado,
                $estadoCasoId > 0 ? $estadoCasoId : null,
                $prioridad,
                $requiereReanalisis,
                $casoId,
                $colegioId,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'actualizacion', 'Control de seguimiento actualizado', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se actualizó estado operativo, estado formal, prioridad o reanálisis del caso.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'seguimiento',
                'actualizar_control',
                'casos',
                $casoId,
                'Control de seguimiento actualizado.'
            );

            seg_abrir_redirect($casoId);
        }

        if ($accion === 'crear_alerta') {
            $tipo = clean((string)($_POST['tipo'] ?? 'seguimiento'));
            $mensaje = clean((string)($_POST['mensaje'] ?? ''));
            $prioridad = clean((string)($_POST['prioridad_alerta'] ?? 'media'));

            if ($mensaje === '') {
                throw new RuntimeException('Debe ingresar el mensaje de la alerta.');
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
                $prioridad = 'media';
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_alertas (
                    caso_id,
                    tipo,
                    mensaje,
                    prioridad,
                    estado,
                    fecha_alerta
                ) VALUES (?, ?, ?, ?, 'pendiente', NOW())
            ");
            $stmt->execute([
                $casoId,
                $tipo !== '' ? $tipo : 'seguimiento',
                $mensaje,
                $prioridad,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'alerta', 'Alerta creada desde seguimiento', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $mensaje,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'seguimiento',
                'crear_alerta',
                'caso_alertas',
                (int)$pdo->lastInsertId(),
                'Alerta creada desde seguimiento.'
            );

            seg_abrir_redirect($casoId);
        }

        if ($accion === 'resolver_alerta') {
            $alertaId = (int)($_POST['alerta_id'] ?? 0);

            if ($alertaId <= 0) {
                throw new RuntimeException('Alerta no válida.');
            }

            $stmt = $pdo->prepare("
                UPDATE caso_alertas
                SET estado = 'resuelta',
                    resuelta_por = ?,
                    resuelta_at = NOW()
                WHERE id = ?
                  AND caso_id = ?
            ");
            $stmt->execute([
                $userId ?: null,
                $alertaId,
                $casoId,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'alerta', 'Alerta resuelta', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se marcó como resuelta la alerta ID ' . $alertaId . '.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'seguimiento',
                'resolver_alerta',
                'caso_alertas',
                $alertaId,
                'Alerta resuelta desde seguimiento.'
            );

            seg_abrir_redirect($casoId);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$stmtCaso->execute([$casoId, $colegioId]);
$caso = $stmtCaso->fetch();

$estadosCaso = [];

try {
    $estadosCaso = $pdo->query("
        SELECT id, codigo, nombre
        FROM estado_caso
        WHERE activo = 1
          AND codigo != 'borrador'
        ORDER BY orden_visual ASC, id ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $estadosCaso = [];
}

$stmtHistorial = $pdo->prepare("
    SELECT
        h.*,
        u.nombre AS usuario_nombre
    FROM caso_historial h
    INNER JOIN casos c ON c.id = h.caso_id AND c.colegio_id = ?
    LEFT JOIN usuarios u ON u.id = h.user_id
    WHERE h.caso_id = ?
    ORDER BY h.created_at DESC, h.id DESC
");
$stmtHistorial->execute([$colegioId, $casoId]);
$historial = $stmtHistorial->fetchAll();

$stmtAlertas = $pdo->prepare("
    SELECT *
    FROM caso_alertas
    WHERE caso_id = ?
    ORDER BY
        CASE estado
            WHEN 'pendiente' THEN 1
            WHEN 'resuelta' THEN 2
            ELSE 3
        END,
        CASE prioridad
            WHEN 'alta' THEN 1
            WHEN 'media' THEN 2
            WHEN 'baja' THEN 3
            ELSE 4
        END,
        fecha_alerta DESC,
        id DESC
");
$stmtAlertas->execute([$casoId]);
$alertas = $stmtAlertas->fetchAll();

$stmtContadores = $pdo->prepare("
    SELECT
        (
            SELECT COUNT(*)
            FROM caso_participantes cp
            INNER JOIN casos c2 ON c2.id = cp.caso_id
            WHERE cp.caso_id = ?
              AND c2.colegio_id = ?
        ) AS participantes,
        (SELECT COUNT(*) FROM caso_declaraciones WHERE caso_id = ?) AS declaraciones,
        (SELECT COUNT(*) FROM caso_evidencias WHERE caso_id = ? AND colegio_id = ?) AS evidencias,
        (SELECT COUNT(*) FROM caso_alertas WHERE caso_id = ? AND estado = 'pendiente') AS alertas_pendientes,
        (SELECT COUNT(*) FROM caso_historial WHERE caso_id = ?) AS gestiones
");
$stmtContadores->execute([$casoId, $colegioId, $casoId, $casoId, $colegioId, $casoId, $casoId]);
$contadores = $stmtContadores->fetch() ?: [
    'participantes' => 0,
    'declaraciones' => 0,
    'evidencias' => 0,
    'alertas_pendientes' => 0,
    'gestiones' => 0,
];

$pageTitle = 'Seguimiento · ' . ($caso['numero_caso'] ?? 'Caso');
$pageSubtitle = 'Gestión operativa del expediente de convivencia escolar';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.seg-open-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.24), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #064e3b 58%, #059669 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.seg-open-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.8rem;
    font-weight: 900;
}

.seg-open-hero p {
    margin: 0;
    color: #d1fae5;
}

.seg-open-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.seg-open-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.seg-open-btn:hover {
    color: #fff;
}

.seg-open-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.seg-open-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.seg-open-kpi span {
    color: #64748b;
    display: block;
    font-size: .7rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.seg-open-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 2rem;
    line-height: 1;
    margin-top: .35rem;
}

.seg-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(360px, .85fr);
    gap: 1.2rem;
    align-items: start;
}

.seg-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    padding: 1.3rem;
    margin-bottom: 1.2rem;
}

.seg-title {
    font-size: .78rem;
    color: #059669;
    font-weight: 900;
    letter-spacing: .11em;
    text-transform: uppercase;
    padding-bottom: .65rem;
    margin-bottom: 1.15rem;
    border-bottom: 1px solid #bbf7d0;
}

.seg-label {
    display: block;
    font-size: .78rem;
    font-weight: 900;
    color: #334155;
    margin-bottom: .35rem;
}

.seg-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

textarea.seg-control {
    min-height: 140px;
    resize: vertical;
}

.seg-control:focus {
    border-color: #059669;
    box-shadow: 0 0 0 4px rgba(5,150,105,.12);
}

.seg-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.seg-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

.seg-submit {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 0;
    border-radius: 999px;
    background: #059669;
    color: #fff;
    padding: .64rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    cursor: pointer;
}

.seg-submit.dark {
    background: #0f172a;
}

.seg-submit.red {
    background: #dc2626;
}

.seg-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    border-radius: 999px;
    padding: .25rem .65rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    margin: .15rem .2rem .15rem 0;
}

.seg-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.seg-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.seg-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.seg-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.seg-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .8rem;
}

.seg-item-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .2rem;
}

.seg-item-meta {
    color: #64748b;
    font-size: .76rem;
    margin-bottom: .45rem;
}

.seg-item-text {
    color: #334155;
    line-height: 1.5;
}

.seg-data {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: .45rem .85rem;
    font-size: .9rem;
}

.seg-data strong {
    color: #334155;
}

.seg-data span {
    color: #0f172a;
}

.seg-empty {
    text-align: center;
    color: #94a3b8;
    padding: 2rem 1rem;
}

.seg-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.seg-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.seg-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

@media (max-width: 1050px) {
    .seg-layout,
    .seg-grid-2,
    .seg-grid-3 {
        grid-template-columns: 1fr;
    }

    .seg-open-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .seg-data {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="seg-open-hero">
    <h2><?= e($caso['numero_caso']) ?></h2>
    <p>
        Seguimiento operativo del caso ·
        <?= e($caso['estado_formal'] ?: seg_abrir_label($caso['estado'])) ?>
    </p>

    <div class="seg-open-actions">
        <a class="seg-open-btn" href="<?= APP_URL ?>/modules/seguimiento/index.php">
            <i class="bi bi-arrow-left"></i>
            Volver a seguimiento
        </a>

        <a class="seg-open-btn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>">
            <i class="bi bi-folder2-open"></i>
            Ver expediente
        </a>

        <a class="seg-open-btn" href="<?= APP_URL ?>/modules/alertas/index.php">
            <i class="bi bi-bell"></i>
            Alertas
        </a>
    </div>
</section>

<?php if ($exito !== ''): ?>
    <div class="seg-msg ok">
        <?= e($exito) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="seg-msg error">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="seg-open-kpis">
    <div class="seg-open-kpi">
        <span>Participantes</span>
        <strong><?= (int)$contadores['participantes'] ?></strong>
    </div>

    <div class="seg-open-kpi">
        <span>Declaraciones</span>
        <strong><?= (int)$contadores['declaraciones'] ?></strong>
    </div>

    <div class="seg-open-kpi">
        <span>Evidencias</span>
        <strong><?= (int)$contadores['evidencias'] ?></strong>
    </div>

    <div class="seg-open-kpi">
        <span>Alertas pendientes</span>
        <strong><?= (int)$contadores['alertas_pendientes'] ?></strong>
    </div>

    <div class="seg-open-kpi">
        <span>Gestiones</span>
        <strong><?= (int)$contadores['gestiones'] ?></strong>
    </div>
</section>

<div class="seg-layout">
    <section>
        <div class="seg-card">
            <div class="seg-title">
                <i class="bi bi-journal-plus"></i>
                Registrar gestión
            </div>

            <form method="post">
                <?= CSRF::field() ?>
                <input type="hidden" name="_accion" value="guardar_gestion">
                <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">

                <div class="seg-grid-2">
                    <div>
                        <label class="seg-label">Tipo de gestión</label>
                        <select class="seg-control" name="tipo_evento">
                            <option value="gestion">Gestión general</option>
                            <option value="entrevista">Entrevista</option>
                            <option value="contacto_apoderado">Contacto apoderado</option>
                            <option value="derivacion">Derivación</option>
                            <option value="medida_resguardo">Medida de resguardo</option>
                            <option value="seguimiento">Seguimiento</option>
                            <option value="cierre">Cierre</option>
                        </select>
                    </div>

                    <div>
                        <label class="seg-label">Título</label>
                        <input
                            class="seg-control"
                            type="text"
                            name="titulo"
                            value="Gestión de seguimiento"
                            required
                        >
                    </div>
                </div>

                <div style="margin-top:1rem;">
                    <label class="seg-label">Detalle de la gestión</label>
                    <textarea
                        class="seg-control"
                        name="detalle"
                        required
                        placeholder="Describe la acción realizada, acuerdos, responsables, próximos pasos o antecedentes relevantes."
                    ></textarea>
                </div>

                <div style="margin-top:1rem;">
                    <button class="seg-submit" type="submit">
                        <i class="bi bi-save"></i>
                        Guardar gestión
                    </button>
                </div>
            </form>
        </div>

        <div class="seg-card">
            <div class="seg-title">
                <i class="bi bi-clock-history"></i>
                Historial de gestiones
            </div>

            <?php if (!$historial): ?>
                <div class="seg-empty">
                    No hay gestiones registradas.
                </div>
            <?php else: ?>
                <?php foreach ($historial as $h): ?>
                    <article class="seg-item">
                        <div class="seg-item-title"><?= e($h['titulo']) ?></div>

                        <div class="seg-item-meta">
                            <?= e(seg_abrir_label($h['tipo_evento'])) ?> ·
                            <?= e(seg_abrir_fecha((string)$h['created_at'])) ?>

                            <?php if (!empty($h['usuario_nombre'])): ?>
                                · <?= e($h['usuario_nombre']) ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($h['detalle'])): ?>
                            <div class="seg-item-text">
                                <?= nl2br(e($h['detalle'])) ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <aside>
        <div class="seg-card">
            <div class="seg-title">
                <i class="bi bi-sliders"></i>
                Control del caso
            </div>

            <div class="seg-data">
                <strong>Estado actual</strong>
                <span><?= e($caso['estado_formal'] ?: seg_abrir_label($caso['estado'])) ?></span>

                <strong>Prioridad</strong>
                <span>
                    <span class="seg-badge <?= e(seg_abrir_badge((string)$caso['prioridad'])) ?>">
                        <?= e(seg_abrir_label($caso['prioridad'])) ?>
                    </span>
                </span>

                <strong>Ingreso</strong>
                <span><?= e(seg_abrir_fecha((string)$caso['fecha_ingreso'])) ?></span>

                <strong>Contexto</strong>
                <span><?= e($caso['contexto'] ?? 'No informado') ?></span>
            </div>

            <hr>

            <form method="post">
                <?= CSRF::field() ?>
                <input type="hidden" name="_accion" value="actualizar_control">
                <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">

                <div>
                    <label class="seg-label">Estado operativo</label>
                    <select class="seg-control" name="estado">
                        <option value="abierto" <?= (string)$caso['estado'] === 'abierto' ? 'selected' : '' ?>>Abierto</option>
                        <option value="cerrado" <?= (string)$caso['estado'] === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                    </select>
                </div>

                <div style="margin-top:1rem;">
                    <label class="seg-label">Estado formal</label>
                    <select class="seg-control" name="estado_caso_id">
                        <option value="">Sin estado formal</option>
                        <?php foreach ($estadosCaso as $estado): ?>
                            <option value="<?= (int)$estado['id'] ?>" <?= (int)$caso['estado_caso_id'] === (int)$estado['id'] ? 'selected' : '' ?>>
                                <?= e($estado['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-top:1rem;">
                    <label class="seg-label">Prioridad</label>
                    <select class="seg-control" name="prioridad">
                        <option value="baja" <?= (string)$caso['prioridad'] === 'baja' ? 'selected' : '' ?>>Baja</option>
                        <option value="media" <?= (string)$caso['prioridad'] === 'media' ? 'selected' : '' ?>>Media</option>
                        <option value="alta" <?= (string)$caso['prioridad'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                    </select>
                </div>

                <div style="margin-top:1rem;">
                    <label>
                        <input type="checkbox" name="requiere_reanalisis_ia" value="1" <?= (int)$caso['requiere_reanalisis_ia'] === 1 ? 'checked' : '' ?>>
                        Requiere reanálisis IA / revisión especializada
                    </label>
                </div>

                <div style="margin-top:1rem;">
                    <button class="seg-submit dark" type="submit">
                        <i class="bi bi-check-circle"></i>
                        Actualizar control
                    </button>
                </div>
            </form>
        </div>

        <div class="seg-card">
            <div class="seg-title">
                <i class="bi bi-bell"></i>
                Crear alerta
            </div>

            <form method="post">
                <?= CSRF::field() ?>
                <input type="hidden" name="_accion" value="crear_alerta">
                <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">

                <div>
                    <label class="seg-label">Tipo</label>
                    <input class="seg-control" type="text" name="tipo" value="seguimiento">
                </div>

                <div style="margin-top:1rem;">
                    <label class="seg-label">Prioridad</label>
                    <select class="seg-control" name="prioridad_alerta">
                        <option value="baja">Baja</option>
                        <option value="media" selected>Media</option>
                        <option value="alta">Alta</option>
                    </select>
                </div>

                <div style="margin-top:1rem;">
                    <label class="seg-label">Mensaje</label>
                    <textarea class="seg-control" name="mensaje" required></textarea>
                </div>

                <div style="margin-top:1rem;">
                    <button class="seg-submit red" type="submit">
                        <i class="bi bi-bell"></i>
                        Crear alerta
                    </button>
                </div>
            </form>
        </div>

        <div class="seg-card">
            <div class="seg-title">
                <i class="bi bi-bell-fill"></i>
                Alertas del caso
            </div>

            <?php if (!$alertas): ?>
                <div class="seg-empty">
                    No hay alertas registradas.
                </div>
            <?php else: ?>
                <?php foreach ($alertas as $a): ?>
                    <article class="seg-item">
                        <div class="seg-item-title">
                            <?= e(seg_abrir_label($a['tipo'])) ?>
                        </div>

                        <div class="seg-item-meta">
                            <?= e(seg_abrir_fecha((string)$a['fecha_alerta'])) ?>
                        </div>

                        <span class="seg-badge <?= e(seg_abrir_badge((string)$a['estado'])) ?>">
                            <?= e(seg_abrir_label($a['estado'])) ?>
                        </span>

                        <span class="seg-badge <?= e(seg_abrir_badge((string)$a['prioridad'])) ?>">
                            Prioridad <?= e(seg_abrir_label($a['prioridad'])) ?>
                        </span>

                        <div class="seg-item-text" style="margin-top:.5rem;">
                            <?= nl2br(e($a['mensaje'])) ?>
                        </div>

                        <?php if ((string)$a['estado'] === 'pendiente'): ?>
                            <form method="post" style="margin-top:.8rem;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="_accion" value="resolver_alerta">
                                <input type="hidden" name="caso_id" value="<?= (int)$casoId ?>">
                                <input type="hidden" name="alerta_id" value="<?= (int)$a['id'] ?>">

                                <button class="seg-submit" type="submit">
                                    <i class="bi bi-check2-circle"></i>
                                    Marcar resuelta
                                </button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>