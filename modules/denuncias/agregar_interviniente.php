<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);

// ── Helpers locales ──────────────────────────────────────────────────────────
function ai_nombre(array $row): string
{
    $partes = [];
    foreach (['apellido_paterno', 'apellido_materno', 'nombres', 'nombre'] as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            $partes[] = trim((string)$row[$k]);
        }
    }

    $nombre = trim(implode(' ', $partes));
    return $nombre !== '' ? mb_strtoupper($nombre, 'UTF-8') : 'Sin nombre';
}

function ai_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            return (string)$row[$k];
        }
    }

    return $default;
}

function ai_label(?string $v): string
{
    return ucwords(str_replace(['_', '-'], ' ', trim((string)$v)));
}

function ai_tablas_personas(): array
{
    return [
        'alumnos' => [
            'tipo_persona' => 'alumno',
            'extra' => ['curso'],
            'sql_busqueda' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       NULL AS nombre, curso, NULL AS cargo, NULL AS email
                FROM alumnos
                WHERE colegio_id = ?
                  AND activo = 1
                  AND (
                        CONVERT(COALESCE(run, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(COALESCE(curso, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR REPLACE(REPLACE(REPLACE(COALESCE(run, ''), '.', ''), '-', ''), ' ', '') LIKE ?
                  )
                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC
                LIMIT 30
            ",
            'sql_uno' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       NULL AS nombre, curso, NULL AS cargo, NULL AS email
                FROM alumnos
                WHERE id = ? AND colegio_id = ? AND activo = 1
                LIMIT 1
            ",
        ],
        'apoderados' => [
            'tipo_persona' => 'apoderado',
            'extra' => [],
            'sql_busqueda' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       nombre, NULL AS curso, NULL AS cargo, email
                FROM apoderados
                WHERE colegio_id = ?
                  AND activo = 1
                  AND (
                        CONVERT(COALESCE(run, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres, nombre) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(COALESCE(email, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR REPLACE(REPLACE(REPLACE(COALESCE(run, ''), '.', ''), '-', ''), ' ', '') LIKE ?
                  )
                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC
                LIMIT 30
            ",
            'sql_uno' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       nombre, NULL AS curso, NULL AS cargo, email
                FROM apoderados
                WHERE id = ? AND colegio_id = ? AND activo = 1
                LIMIT 1
            ",
        ],
        'docentes' => [
            'tipo_persona' => 'docente',
            'extra' => ['cargo'],
            'sql_busqueda' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       nombre, NULL AS curso, cargo, email
                FROM docentes
                WHERE colegio_id = ?
                  AND activo = 1
                  AND (
                        CONVERT(COALESCE(run, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres, nombre) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(COALESCE(cargo, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(COALESCE(email, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR REPLACE(REPLACE(REPLACE(COALESCE(run, ''), '.', ''), '-', ''), ' ', '') LIKE ?
                  )
                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC
                LIMIT 30
            ",
            'sql_uno' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       nombre, NULL AS curso, cargo, email
                FROM docentes
                WHERE id = ? AND colegio_id = ? AND activo = 1
                LIMIT 1
            ",
        ],
        'asistentes' => [
            'tipo_persona' => 'asistente',
            'extra' => ['cargo'],
            'sql_busqueda' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       nombre, NULL AS curso, cargo, email
                FROM asistentes
                WHERE colegio_id = ?
                  AND activo = 1
                  AND (
                        CONVERT(COALESCE(run, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres, nombre) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(COALESCE(cargo, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR CONVERT(COALESCE(email, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
                     OR REPLACE(REPLACE(REPLACE(COALESCE(run, ''), '.', ''), '-', ''), ' ', '') LIKE ?
                  )
                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC
                LIMIT 30
            ",
            'sql_uno' => "
                SELECT id, run, nombres, apellido_paterno, apellido_materno,
                       nombre, NULL AS curso, cargo, email
                FROM asistentes
                WHERE id = ? AND colegio_id = ? AND activo = 1
                LIMIT 1
            ",
        ],
    ];
}

function ai_buscar_personas(PDO $pdo, string $tipo, int $colegioId, string $q): array
{
    $mapa = ai_tablas_personas();
    if (!isset($mapa[$tipo])) {
        return [];
    }

    $like = '%' . $q . '%';
    $runLike = '%' . preg_replace('/[^0-9Kk]/', '', $q) . '%';
    if ($runLike === '%%') {
        $runLike = $like;
    }

    $params = match ($tipo) {
        'alumnos', 'apoderados' => [$colegioId, $like, $like, $like, $runLike],
        'docentes', 'asistentes' => [$colegioId, $like, $like, $like, $like, $runLike],
        default => [],
    };

    if (!$params) {
        return [];
    }

    $stmt = $pdo->prepare($mapa[$tipo]['sql_busqueda']);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function ai_obtener_persona(PDO $pdo, string $tipoTabla, int $personaId, int $colegioId): ?array
{
    $mapa = ai_tablas_personas();
    if (!isset($mapa[$tipoTabla]) || $personaId <= 0 || $colegioId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare($mapa[$tipoTabla]['sql_uno']);
    $stmt->execute([$personaId, $colegioId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

// ── AJAX: buscar causa ────────────────────────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'buscar_causa') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q) < 2) {
        echo json_encode(['items' => []]); exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.numero_caso, c.descripcion,
                   ec.nombre AS estado_nombre
            FROM casos c
            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
            WHERE c.colegio_id = ?
              AND (c.numero_caso LIKE ? OR c.descripcion LIKE ?)
            ORDER BY c.id DESC
            LIMIT 15
        ");
        $stmt->execute([$colegioId, "%$q%", "%$q%"]);
        $rows = $stmt->fetchAll();
        $items = array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'numero_caso' => (string)$r['numero_caso'],
            'descripcion' => mb_strimwidth((string)($r['descripcion'] ?? ''), 0, 60, '…', 'UTF-8'),
            'estado'      => (string)($r['estado_nombre'] ?? ''),
        ], $rows);
        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['items' => []]);
    }
    exit;
}

// ── AJAX: buscar persona en BD ────────────────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'buscar_persona') {
    header('Content-Type: application/json; charset=utf-8');

    $tipo = trim((string)($_GET['tipo'] ?? 'alumnos'));
    $q    = trim((string)($_GET['q'] ?? ''));
    $mapa = ai_tablas_personas();

    if (!isset($mapa[$tipo]) || mb_strlen($q, 'UTF-8') < 2 || $colegioId <= 0) {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $rows = ai_buscar_personas($pdo, $tipo, $colegioId, $q);

        $items = array_map(function (array $r) use ($tipo, $mapa): array {
            $extra = [];
            foreach ($mapa[$tipo]['extra'] as $k) {
                if (isset($r[$k]) && trim((string)$r[$k]) !== '') {
                    $extra[] = (string)$r[$k];
                }
            }

            return [
                'id'           => (int)$r['id'],
                'nombre'       => ai_nombre($r),
                'run'          => ai_pick($r, ['run'], '0-0'),
                'extra'        => implode(' · ', $extra),
                'tipo_persona' => $mapa[$tipo]['tipo_persona'],
                'tipo_tabla'   => $tipo,
            ];
        }, $rows);

        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── POST: guardar interviniente ───────────────────────────────────────────────
$error = '';
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::requireValid($_POST['_token'] ?? null);

        $casoIdPost   = (int)($_POST['caso_id']    ?? 0);
        $tipoPersona  = clean((string)($_POST['tipo_persona']       ?? 'externo'));
        $personaId    = (int)($_POST['persona_id']  ?? 0);
        $nombre       = clean((string)($_POST['nombre_referencial'] ?? ''));
        $run          = cleanRun((string)($_POST['run']             ?? ''));
        $rolEnCaso    = clean((string)($_POST['rol_en_caso']        ?? 'involucrado'));
        $observacion  = clean((string)($_POST['observacion']        ?? ''));
        $tipoTabla    = clean((string)($_POST['tipo_tabla']         ?? ''));

        if ($casoIdPost <= 0) throw new RuntimeException('Debe seleccionar una causa.');
        if ($nombre === '' && $personaId <= 0) throw new RuntimeException('El nombre es obligatorio.');

        // Verificar que el caso pertenece al colegio
        $stmt = $pdo->prepare("SELECT id, numero_caso FROM casos
                               WHERE id = ? AND colegio_id = ? LIMIT 1");
        $stmt->execute([$casoIdPost, $colegioId]);
        $caso = $stmt->fetch();
        if (!$caso) throw new RuntimeException('Causa no encontrada.');

        $mapaPersonas = ai_tablas_personas();
        $personaIdSql = null;
        $identidadConfirmada = 0;
        $fechaIdentificacion = null;
        $identificadoPor = null;
        $observacionIdentificacion = null;

        // Si viene de la BD, obtener nombre, RUN y tipo real desde el esquema estable.
        if ($personaId > 0) {
            if (!isset($mapaPersonas[$tipoTabla])) {
                throw new RuntimeException('Tipo de persona no válido.');
            }

            $persona = ai_obtener_persona($pdo, $tipoTabla, $personaId, $colegioId);
            if (!$persona) {
                throw new RuntimeException('La persona seleccionada no fue encontrada en este establecimiento.');
            }

            $tipoPersona = $mapaPersonas[$tipoTabla]['tipo_persona'];
            $nombre = ai_nombre($persona);
            $run = cleanRun(ai_pick($persona, ['run'], '0-0'));
            $personaIdSql = $personaId;
            $identidadConfirmada = 1;
            $fechaIdentificacion = date('Y-m-d H:i:s');
            $identificadoPor = $userId > 0 ? $userId : null;
            $observacionIdentificacion = 'Vinculado desde comunidad educativa.';

            // Verificar duplicado para personas vinculadas desde comunidad educativa.
            $sd = $pdo->prepare("
                SELECT COUNT(*)
                FROM caso_participantes
                WHERE caso_id = ?
                  AND tipo_persona = ?
                  AND persona_id = ?
            ");
            $sd->execute([$casoIdPost, $tipoPersona, $personaId]);
            if ((int)$sd->fetchColumn() > 0) {
                throw new RuntimeException('Esta persona ya está vinculada a la causa.');
            }
        } else {
            $tipoPersona = 'externo';
            $run = cleanRun($run);
        }

        if ($nombre === '') {
            throw new RuntimeException('El nombre es obligatorio.');
        }

        if (!in_array($tipoPersona, ['alumno','apoderado','docente','asistente','externo'], true)) {
            $tipoPersona = 'externo';
        }

        if (!in_array($rolEnCaso, ['victima','denunciante','denunciado','testigo','involucrado'], true)) {
            $rolEnCaso = 'involucrado';
        }

        $pdo->beginTransaction();

        // Insertar participante conforme al esquema estable de caso_participantes.
        $si = $pdo->prepare("
            INSERT INTO caso_participantes (
                caso_id,
                tipo_persona,
                persona_id,
                nombre_referencial,
                run,
                identidad_confirmada,
                fecha_identificacion,
                identificado_por,
                rol_en_caso,
                solicita_reserva_identidad,
                observacion_reserva,
                observacion,
                observacion_identificacion,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, ?)
        ");
        $si->execute([
            $casoIdPost,
            $tipoPersona,
            $personaIdSql,
            $nombre,
            $run,
            $identidadConfirmada,
            $fechaIdentificacion,
            $identificadoPor,
            $rolEnCaso,
            $observacion !== '' ? $observacion : null,
            $observacionIdentificacion,
            date('Y-m-d H:i:s'),
        ]);
        $participanteId = (int)$pdo->lastInsertId();

        // Historial: esquema estable, con fallback silencioso para no bloquear el alta.
        try {
            $sh = $pdo->prepare("
                INSERT INTO caso_historial
                    (caso_id, tipo_evento, titulo, detalle, user_id, created_at)
                VALUES
                    (?, 'participante', 'Nuevo interviniente agregado', ?, ?, ?)
            ");
            $sh->execute([
                $casoIdPost,
                $nombre . ' fue agregado como ' . ai_label($rolEnCaso) . '.',
                $userId ?: null,
                date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // No interrumpir el registro del interviniente por una falla no crítica de historial.
        }

        registrar_bitacora('denuncias', 'agregar_interviniente',
            'caso_participantes', $participanteId,
            "Nuevo interviniente: $nombre ($rolEnCaso) en causa {$caso['numero_caso']}");

        $pdo->commit();

        header('Location: ' . APP_URL . '/modules/denuncias/ver.php'
             . '?id=' . $casoIdPost . '&tab=declaraciones&msg=interviniente_agregado');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ── GET: parámetros de pantalla ───────────────────────────────────────────────
$casoId    = (int)($_GET['caso_id'] ?? 0);
$tipoTab   = clean((string)($_GET['tipo'] ?? 'alumnos'));
$tiposValidos = ['alumnos','apoderados','docentes','asistentes','externo'];
if (!in_array($tipoTab, $tiposValidos, true)) $tipoTab = 'alumnos';

$caso = null;
if ($casoId > 0) {
    $stmt = $pdo->prepare("SELECT c.*, ec.nombre AS estado_nombre
        FROM casos c LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE c.id = ? AND c.colegio_id = ? LIMIT 1");
    $stmt->execute([$casoId, $colegioId]);
    $caso = $stmt->fetch() ?: null;
    if (!$caso) $casoId = 0;
}

// Participantes actuales de la causa seleccionada
$participantes = [];
if ($casoId > 0) {
    $sp = $pdo->prepare("
        SELECT cp.*
        FROM caso_participantes cp
        INNER JOIN casos c ON c.id = cp.caso_id
        WHERE cp.caso_id = ?
          AND c.colegio_id = ?
        ORDER BY cp.id ASC
    ");
    $sp->execute([$casoId, $colegioId]);
    $participantes = $sp->fetchAll();
}

$pageTitle    = 'Agregar interviniente · Metis';
$pageSubtitle = 'Incorporar un nuevo participante a una causa existente';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.ai-hero {
    background: linear-gradient(135deg,#0f172a 0%,#1e3a8a 60%,#2563eb 100%);
    color:#fff; border-radius:22px; padding:2rem; margin-bottom:1.2rem;
    box-shadow:0 18px 45px rgba(15,23,42,.18);
}
.ai-hero h2 { margin:0 0 .4rem; font-size:1.8rem; font-weight:900; }
.ai-hero p  { margin:0; color:#bfdbfe; max-width:860px; line-height:1.55; }
.ai-actions { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:1rem; }
.ai-btn {
    display:inline-flex; align-items:center; gap:.4rem;
    border-radius:999px; padding:.6rem 1rem; font-size:.84rem; font-weight:900;
    text-decoration:none; border:1px solid rgba(255,255,255,.28);
    color:#fff; background:rgba(255,255,255,.12);
}
.ai-btn:hover { color:#fff; background:rgba(255,255,255,.22); }
.ai-layout {
    display:grid;
    grid-template-columns: minmax(0,1.2fr) minmax(320px,.8fr);
    gap:1.2rem; align-items:start;
}
.ai-card {
    background:#fff; border:1px solid #e2e8f0; border-radius:20px;
    box-shadow:0 8px 24px rgba(15,23,42,.06); overflow:hidden; margin-bottom:1.2rem;
}
.ai-card-head {
    padding:.9rem 1.2rem; border-bottom:1px solid #e2e8f0;
    display:flex; justify-content:space-between; align-items:center; gap:.8rem; flex-wrap:wrap;
}
.ai-card-title { margin:0; font-size:1rem; font-weight:900; color:#0f172a; }
.ai-card-body  { padding:1.2rem; }
.ai-label { display:block; font-size:.76rem; font-weight:900; color:#334155; margin-bottom:.35rem; }
.ai-control {
    width:100%; border:1px solid #cbd5e1; border-radius:13px;
    padding:.66rem .78rem; font-size:.9rem; background:#fff; outline:none;
}
.ai-control:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.ai-btn-submit {
    display:inline-flex; align-items:center; gap:.4rem; border:0;
    background:#0f172a; color:#fff; border-radius:999px; padding:.68rem 1.1rem;
    font-weight:900; font-size:.84rem; cursor:pointer; text-decoration:none;
}
.ai-btn-submit.green { background:#059669; }
.ai-btn-submit.blue  { background:#2563eb; }
.ai-btn-submit.gray  { background:#64748b; }
.ai-tabs { display:flex; flex-wrap:wrap; gap:.35rem; margin-bottom:1rem; }
.ai-tab {
    display:inline-flex; align-items:center; gap:.35rem; text-decoration:none;
    border-radius:999px; padding:.55rem .85rem; border:1px solid #cbd5e1;
    background:#fff; color:#334155; font-weight:900; font-size:.82rem; cursor:pointer;
}
.ai-tab.active, .ai-tab:hover { background:#0f172a; border-color:#0f172a; color:#fff; }
.ai-item {
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px;
    padding:1rem; margin-bottom:.75rem;
}
.ai-item-title  { font-weight:900; color:#0f172a; margin-bottom:.25rem; }
.ai-item-meta   { font-size:.76rem; color:#64748b; margin-top:.2rem; }
.ai-badge {
    display:inline-flex; align-items:center; border-radius:999px;
    padding:.22rem .58rem; font-size:.72rem; font-weight:900;
    border:1px solid #e2e8f0; background:#fff; color:#475569; margin:.1rem;
}
.ai-badge.ok   { background:#ecfdf5; border-color:#bbf7d0; color:#047857; }
.ai-badge.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }
.ai-badge.blue { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
.ai-msg {
    border-radius:14px; padding:.9rem 1rem; margin-bottom:1rem; font-weight:800;
}
.ai-msg.ok    { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
.ai-msg.error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.ai-empty  { text-align:center; padding:2rem 1rem; color:#94a3b8; }
.ai-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
.ai-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.8rem; }
.ai-results {
    position:absolute; top:100%; left:0; right:0; background:#fff;
    border:1px solid #cbd5e1; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,.1);
    z-index:100; max-height:260px; overflow-y:auto; margin-top:.2rem;
}
.ai-result-item {
    padding:.6rem .9rem; cursor:pointer; border-bottom:1px solid #f1f5f9;
    font-size:.83rem; line-height:1.4;
}
.ai-result-item:last-child  { border-bottom:0; }
.ai-result-item:hover       { background:#f0f9ff; }
.ai-caso-badge {
    background:#0f172a; color:#fff; border-radius:12px;
    padding:.75rem 1rem; display:flex; align-items:center; gap:.75rem;
    margin-bottom:1rem;
}
@media(max-width:1000px) {
    .ai-layout,.ai-grid-2,.ai-grid-3 { grid-template-columns:1fr; }
}
</style>

<!-- Hero -->
<section class="ai-hero">
    <h2><i class="bi bi-person-plus-fill"></i> Agregar interviniente</h2>
    <p>Incorpora un nuevo participante a una causa existente, buscándolo en la comunidad educativa o registrándolo manualmente si es externo al establecimiento.</p>
    <div class="ai-actions">
        <?php if ($casoId > 0): ?>
            <a class="ai-btn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= $casoId ?>&tab=declaraciones">
                <i class="bi bi-arrow-left"></i> Volver al expediente
            </a>
            <a class="ai-btn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= $casoId ?>&tab=participantes">
                <i class="bi bi-people"></i> Ver participantes
            </a>
        <?php else: ?>
            <a class="ai-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
                <i class="bi bi-arrow-left"></i> Volver a causas
            </a>
        <?php endif; ?>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="ai-msg error"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
<?php endif; ?>

<div class="ai-layout">

    <!-- ── Columna principal ──────────────────────────────────────────────── -->
    <section>

        <!-- PASO 1: Seleccionar causa -->
        <div class="ai-card">
            <div class="ai-card-head">
                <h3 class="ai-card-title">
                    <span style="background:#0f172a;color:#fff;border-radius:50%;width:24px;height:24px;
                                 display:inline-flex;align-items:center;justify-content:center;
                                 font-size:.75rem;margin-right:.4rem;">1</span>
                    Seleccionar causa
                </h3>
                <?php if ($caso): ?>
                    <span class="ai-badge ok"><i class="bi bi-check-circle-fill"></i> Causa seleccionada</span>
                <?php endif; ?>
            </div>
            <div class="ai-card-body">
                <?php if ($caso): ?>
                    <div class="ai-caso-badge">
                        <i class="bi bi-folder2-open" style="font-size:1.4rem;flex-shrink:0;"></i>
                        <div>
                            <div style="font-weight:900;font-size:1rem;"><?= e($caso['numero_caso']) ?></div>
                            <div style="font-size:.8rem;color:#94a3b8;margin-top:.1rem;">
                                <?= e(mb_strimwidth((string)($caso['descripcion'] ?? ''), 0, 80, '…', 'UTF-8')) ?>
                                · <?= e($caso['estado_nombre'] ?? '') ?>
                            </div>
                        </div>
                        <a href="<?= APP_URL ?>/modules/denuncias/agregar_interviniente.php"
                           class="ai-btn-submit gray" style="margin-left:auto;font-size:.75rem;padding:.45rem .75rem;">
                            <i class="bi bi-x"></i> Cambiar
                        </a>
                    </div>
                <?php else: ?>
                    <label class="ai-label">Buscar causa por número o descripción</label>
                    <div style="position:relative;">
                        <input class="ai-control" type="text" id="buscarCausa"
                               placeholder="Ej: CASO-2026-001 o nombre de la víctima…"
                               autocomplete="off" oninput="buscarCausa(this.value)">
                        <div id="resultadosCausa" class="ai-results" style="display:none;"></div>
                    </div>
                    <div style="margin-top:.6rem;font-size:.76rem;color:#94a3b8;">
                        <i class="bi bi-info-circle"></i>
                        Escribe mínimo 2 caracteres para buscar.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($casoId > 0): ?>
        <!-- PASO 2: Buscar y agregar interviniente -->
        <div class="ai-card">
            <div class="ai-card-head">
                <h3 class="ai-card-title">
                    <span style="background:#0f172a;color:#fff;border-radius:50%;width:24px;height:24px;
                                 display:inline-flex;align-items:center;justify-content:center;
                                 font-size:.75rem;margin-right:.4rem;">2</span>
                    Buscar en comunidad educativa o ingresar externo
                </h3>
            </div>
            <div class="ai-card-body">

                <!-- Tabs de tipo -->
                <nav class="ai-tabs">
                    <?php
                    $tabsConfig = [
                        'alumnos'    => ['icon'=>'bi-mortarboard',    'label'=>'Alumnos'],
                        'apoderados' => ['icon'=>'bi-people',         'label'=>'Apoderados'],
                        'docentes'   => ['icon'=>'bi-person-video3',  'label'=>'Docentes'],
                        'asistentes' => ['icon'=>'bi-person-workspace','label'=>'Asistentes'],
                        'externo'    => ['icon'=>'bi-person-plus',    'label'=>'Externo (manual)'],
                    ];
                    foreach ($tabsConfig as $key => $cfg): ?>
                        <button type="button"
                                class="ai-tab <?= $tipoTab === $key ? 'active' : '' ?>"
                                onclick="cambiarTab('<?= $key ?>')">
                            <i class="bi <?= $cfg['icon'] ?>"></i>
                            <?= $cfg['label'] ?>
                        </button>
                    <?php endforeach; ?>
                </nav>

                <!-- Buscador (visible para todos excepto externo) -->
                <div id="panelBuscador" <?= $tipoTab === 'externo' ? 'style="display:none;"' : '' ?>>
                    <label class="ai-label">Buscar por RUN, nombre, curso, correo…</label>
                    <div style="position:relative;">
                        <input class="ai-control" type="text" id="buscarPersona"
                               placeholder="Mínimo 2 caracteres…"
                               autocomplete="off" oninput="buscarPersona(this.value)">
                        <div id="resultadosPersona" class="ai-results" style="display:none;"></div>
                    </div>
                    <div id="panelPersonaSeleccionada" style="display:none;margin-top:1rem;">
                        <!-- Se llena con JS al seleccionar -->
                    </div>
                </div>

                <!-- Formulario externo (manual) -->
                <div id="panelExterno" <?= $tipoTab !== 'externo' ? 'style="display:none;"' : '' ?>>
                    <form method="post" id="formExterno">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="caso_id"     value="<?= $casoId ?>">
                        <input type="hidden" name="tipo_persona" value="externo">
                        <input type="hidden" name="persona_id"  value="0">
                        <input type="hidden" name="tipo_tabla"  value="">

                        <div class="ai-grid-2" style="margin-bottom:.85rem;">
                            <div style="grid-column:1/-1;">
                                <label class="ai-label">Nombre completo *</label>
                                <input class="ai-control" type="text" name="nombre_referencial"
                                       required placeholder="Nombre y apellidos completos">
                            </div>
                            <div>
                                <label class="ai-label">RUN</label>
                                <input class="ai-control" type="text" name="run" placeholder="0-0">
                            </div>
                            <div>
                                <label class="ai-label">Rol en la causa *</label>
                                <select class="ai-control" name="rol_en_caso">
                                    <option value="victima">Víctima / afectado</option>
                                    <option value="denunciante">Denunciante</option>
                                    <option value="denunciado">Denunciado</option>
                                    <option value="testigo">Testigo</option>
                                    <option value="involucrado" selected>Otro interviniente</option>
                                </select>
                            </div>
                            <div style="grid-column:1/-1;">
                                <label class="ai-label">Observación</label>
                                <input class="ai-control" type="text" name="observacion"
                                       placeholder="Ej: persona mencionada en declaración de…">
                            </div>
                        </div>

                        <button class="ai-btn-submit green" type="submit">
                            <i class="bi bi-person-check-fill"></i>
                            Agregar interviniente externo
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php endif; ?>

    </section>

    <!-- ── Columna lateral ──────────────────────────────────────────────────── -->
    <aside>

        <?php if ($caso): ?>
        <!-- Resumen de la causa -->
        <div class="ai-card">
            <div class="ai-card-head">
                <h3 class="ai-card-title"><i class="bi bi-folder2-open"></i> Causa</h3>
            </div>
            <div class="ai-card-body">
                <div class="ai-item">
                    <div class="ai-item-title"><?= e($caso['numero_caso']) ?></div>
                    <div>
                        <span class="ai-badge blue"><?= e($caso['estado_nombre'] ?? '') ?></span>
                        <span class="ai-badge"><?= e(ai_label((string)($caso['semaforo'] ?? ''))) ?></span>
                    </div>
                    <?php if (!empty($caso['descripcion'])): ?>
                        <div class="ai-item-meta" style="margin-top:.4rem;">
                            <?= e(mb_strimwidth((string)$caso['descripcion'], 0, 100, '…', 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Participantes actuales -->
        <div class="ai-card">
            <div class="ai-card-head">
                <h3 class="ai-card-title"><i class="bi bi-people"></i> Intervinientes actuales</h3>
                <span class="ai-badge"><?= count($participantes) ?></span>
            </div>
            <div class="ai-card-body">
                <?php if (!$participantes): ?>
                    <div class="ai-empty">
                        <?= $casoId > 0 ? 'Aún no hay participantes.' : 'Selecciona una causa primero.' ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($participantes as $p): ?>
                        <div class="ai-item" style="padding:.75rem;">
                            <div class="ai-item-title" style="font-size:.88rem;">
                                <?= e($p['nombre_referencial'] ?? 'Sin nombre') ?>
                            </div>
                            <div>
                                <span class="ai-badge"><?= e(ai_label((string)($p['tipo_persona']??''))) ?></span>
                                <span class="ai-badge <?= ($p['rol_en_caso']??'') === 'victima' ? 'warn' : '' ?>">
                                    <?= e(ai_label((string)($p['rol_en_caso']??''))) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </aside>
</div>

<!-- ══ JAVASCRIPT ════════════════════════════════════════════════════════════ -->
<script>
const CASO_ID   = <?= (int)$casoId ?>;
const APP_URL_JS = <?= json_encode(APP_URL) ?>;
let   tipoActual = <?= json_encode($tipoTab) ?>;
let   personaSeleccionada = null;
let   timerCausa = null;
let   timerPersona = null;

// ── Buscar causa ──────────────────────────────────────────────────────────────
function buscarCausa(q) {
    clearTimeout(timerCausa);
    const box = document.getElementById('resultadosCausa');
    if (q.trim().length < 2) { box.style.display = 'none'; return; }
    timerCausa = setTimeout(() => {
        fetch(APP_URL_JS + '/modules/denuncias/agregar_interviniente.php'
            + '?ajax=buscar_causa&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            box.innerHTML = '';
            if (!data.items || !data.items.length) {
                box.innerHTML = '<div class="ai-result-item" style="color:#94a3b8;">Sin resultados</div>';
            } else {
                data.items.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'ai-result-item';
                    div.innerHTML = '<strong>' + item.numero_caso + '</strong>'
                        + (item.descripcion ? ' <span style="color:#64748b;">— ' + item.descripcion + '</span>' : '')
                        + (item.estado ? ' <span style="font-size:.72rem;color:#0369a1;">[' + item.estado + ']</span>' : '');
                    div.addEventListener('click', () => {
                        window.location.href = APP_URL_JS
                            + '/modules/denuncias/agregar_interviniente.php?caso_id=' + item.id;
                    });
                    box.appendChild(div);
                });
            }
            box.style.display = 'block';
        })
        .catch(() => {});
    }, 280);
}

// ── Cambiar tab de tipo de persona ────────────────────────────────────────────
function cambiarTab(tipo) {
    tipoActual = tipo;
    document.querySelectorAll('.ai-tab').forEach((btn, i) => {
        const tipos = ['alumnos','apoderados','docentes','asistentes','externo'];
        btn.classList.toggle('active', tipos[i] === tipo);
    });

    const esExterno = tipo === 'externo';
    document.getElementById('panelBuscador').style.display  = esExterno ? 'none' : 'block';
    document.getElementById('panelExterno').style.display   = esExterno ? 'block' : 'none';

    // Limpiar búsqueda anterior
    const inp = document.getElementById('buscarPersona');
    if (inp) inp.value = '';
    const res = document.getElementById('resultadosPersona');
    if (res) res.style.display = 'none';
    const sel = document.getElementById('panelPersonaSeleccionada');
    if (sel) sel.style.display = 'none';
    personaSeleccionada = null;
}

// ── Buscar persona en BD ──────────────────────────────────────────────────────
function buscarPersona(q) {
    clearTimeout(timerPersona);
    const box = document.getElementById('resultadosPersona');
    if (q.trim().length < 2) { box.style.display = 'none'; return; }

    timerPersona = setTimeout(() => {
        fetch(APP_URL_JS + '/modules/denuncias/agregar_interviniente.php'
            + '?ajax=buscar_persona&tipo=' + encodeURIComponent(tipoActual)
            + '&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            box.innerHTML = '';
            if (!data.items || !data.items.length) {
                box.innerHTML = '<div class="ai-result-item" style="color:#94a3b8;">Sin resultados. Usa la pestaña "Externo" para ingresar manualmente.</div>';
            } else {
                data.items.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'ai-result-item';
                    div.innerHTML = '<strong>' + item.nombre + '</strong>'
                        + (item.run && item.run !== '0-0' ? ' <span style="color:#64748b;">· ' + item.run + '</span>' : '')
                        + (item.extra ? ' <em style="color:#94a3b8;">· ' + item.extra + '</em>' : '');
                    div.addEventListener('click', () => seleccionarPersona(item));
                    box.appendChild(div);
                });
            }
            box.style.display = 'block';
        })
        .catch(() => {});
    }, 280);
}

// ── Seleccionar persona del resultado ────────────────────────────────────────
function seleccionarPersona(item) {
    personaSeleccionada = item;
    document.getElementById('resultadosPersona').style.display = 'none';
    document.getElementById('buscarPersona').value = item.nombre;

    const panel = document.getElementById('panelPersonaSeleccionada');
    panel.style.display = 'block';
    panel.innerHTML = `
        <div class="ai-item" style="background:#f0fdf4;border-color:#bbf7d0;">
            <div class="ai-item-title">
                <i class="bi bi-person-check-fill" style="color:#059669;"></i>
                ${item.nombre}
            </div>
            <div>
                ${item.run && item.run !== '0-0'
                    ? '<span class="ai-badge">RUN: ' + item.run + '</span>' : ''}
                ${item.extra ? '<span class="ai-badge">' + item.extra + '</span>' : ''}
            </div>
        </div>
        <form method="post" style="margin-top:.85rem;">
            <input type="hidden" name="_token" value="${document.querySelector('[name=_token]')?.value ?? ''}">
            <input type="hidden" name="caso_id"     value="${CASO_ID}">
            <input type="hidden" name="tipo_persona" value="${item.tipo_persona}">
            <input type="hidden" name="persona_id"   value="${item.id}">
            <input type="hidden" name="tipo_tabla"   value="${item.tipo_tabla}">
            <input type="hidden" name="nombre_referencial" value="${item.nombre}">
            <input type="hidden" name="run"          value="${item.run}">

            <div class="ai-grid-2">
                <div>
                    <label class="ai-label">Rol en la causa *</label>
                    <select class="ai-control" name="rol_en_caso">
                        <option value="victima">Víctima / afectado</option>
                        <option value="denunciante">Denunciante</option>
                        <option value="denunciado">Denunciado</option>
                        <option value="testigo">Testigo</option>
                        <option value="involucrado" selected>Otro interviniente</option>
                    </select>
                </div>
                <div>
                    <label class="ai-label">Observación</label>
                    <input class="ai-control" type="text" name="observacion"
                           placeholder="Ej: mencionado en declaración de…">
                </div>
            </div>
            <div style="margin-top:.85rem;display:flex;gap:.6rem;">
                <button class="ai-btn-submit green" type="submit">
                    <i class="bi bi-person-check-fill"></i>
                    Agregar al caso
                </button>
                <button type="button" class="ai-btn-submit gray" onclick="limpiarSeleccion()">
                    <i class="bi bi-x"></i> Cambiar
                </button>
            </div>
        </form>
    `;
}

function limpiarSeleccion() {
    personaSeleccionada = null;
    document.getElementById('buscarPersona').value = '';
    document.getElementById('panelPersonaSeleccionada').style.display = 'none';
}

// Cerrar resultados al hacer clic fuera
document.addEventListener('click', e => {
    ['resultadosCausa','resultadosPersona'].forEach(id => {
        const box = document.getElementById(id);
        if (box && !box.contains(e.target)
            && e.target.id !== 'buscarCausa'
            && e.target.id !== 'buscarPersona') {
            box.style.display = 'none';
        }
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
