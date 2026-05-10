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

$casoId = (int)($_GET['id'] ?? 0);
$tab = clean((string)($_GET['tab'] ?? 'resumen'));

$tabsPermitidos = [
    'resumen',
    'participantes',
    'declaraciones',
    'evidencias',
    'clasificacion',
    'historial',
];

if (!in_array($tab, $tabsPermitidos, true)) {
    $tab = 'resumen';
}

if ($casoId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

function caso_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function caso_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function caso_badge_class(string $value): string
{
    return match (strtolower($value)) {
        'rojo', 'alta', 'pendiente' => 'danger',
        'amarillo', 'media', 'revision', 'investigacion' => 'warn',
        'verde', 'baja', 'cerrado', 'resuelta' => 'ok',
        default => 'soft',
    };
}

function caso_redirect(int $casoId, string $tab): void
{
    header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=' . urlencode($tab));
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
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'actualizar_resumen') {
            $estado = clean((string)($_POST['estado'] ?? 'abierto'));
            $estadoCasoId = (int)($_POST['estado_caso_id'] ?? 0);
            $semaforo = clean((string)($_POST['semaforo'] ?? 'verde'));
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));
            $involucraMoviles = isset($_POST['involucra_moviles']) ? 1 : 0;
            $requiereReanalisis = isset($_POST['requiere_reanalisis_ia']) ? 1 : 0;

            if (!in_array($estado, ['abierto', 'cerrado'], true)) {
                $estado = 'abierto';
            }

            if (!in_array($semaforo, ['verde', 'amarillo', 'rojo'], true)) {
                $semaforo = 'verde';
            }

            if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
                $prioridad = 'media';
            }

            $stmt = $pdo->prepare("
                UPDATE casos
                SET estado = ?,
                    estado_caso_id = ?,
                    semaforo = ?,
                    prioridad = ?,
                    involucra_moviles = ?,
                    requiere_reanalisis_ia = ?
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $stmt->execute([
                $estado,
                $estadoCasoId > 0 ? $estadoCasoId : null,
                $semaforo,
                $prioridad,
                $involucraMoviles,
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
                ) VALUES (?, 'actualizacion', 'Actualización del resumen', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se actualizó estado, semáforo, prioridad o indicadores del caso.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'actualizar_resumen',
                'casos',
                $casoId,
                'Resumen del caso actualizado.'
            );

            caso_redirect($casoId, 'resumen');
        }

        if ($accion === 'agregar_participante') {
            $tipoPersona = clean((string)($_POST['tipo_persona'] ?? 'externo'));
            $nombre = clean((string)($_POST['nombre_referencial'] ?? ''));
            $run = cleanRun((string)($_POST['run'] ?? ''));
            $rolEnCaso = clean((string)($_POST['rol_en_caso'] ?? 'involucrado'));
            $reserva = isset($_POST['solicita_reserva_identidad']) ? 1 : 0;
            $observacion = clean((string)($_POST['observacion'] ?? ''));
            $observacionReserva = clean((string)($_POST['observacion_reserva'] ?? ''));

            if ($nombre === '') {
                throw new RuntimeException('El nombre del participante es obligatorio.');
            }

            if ($run === '') {
                $run = '0-0';
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_participantes (
                    caso_id,
                    tipo_persona,
                    nombre_referencial,
                    run,
                    rol_en_caso,
                    solicita_reserva_identidad,
                    observacion_reserva,
                    observacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $tipoPersona,
                $nombre,
                $run,
                $rolEnCaso,
                $reserva,
                $observacionReserva !== '' ? $observacionReserva : null,
                $observacion !== '' ? $observacion : null,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'participante', 'Participante agregado', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se agregó participante: ' . $nombre . ' (' . $rolEnCaso . ').',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_participante',
                'caso_participantes',
                (int)$pdo->lastInsertId(),
                'Participante agregado al caso.'
            );

            caso_redirect($casoId, 'participantes');
        }

        if ($accion === 'agregar_declaracion') {
            $participanteId = (int)($_POST['participante_id'] ?? 0);
            $nombreDeclarante = clean((string)($_POST['nombre_declarante'] ?? ''));
            $runDeclarante = cleanRun((string)($_POST['run_declarante'] ?? ''));
            $calidadProcesal = clean((string)($_POST['calidad_procesal'] ?? 'declarante'));
            $textoDeclaracion = clean((string)($_POST['texto_declaracion'] ?? ''));
            $observaciones = clean((string)($_POST['observaciones'] ?? ''));

            if ($participanteId > 0 && $nombreDeclarante === '') {
                $stmtP = $pdo->prepare("
                    SELECT nombre_referencial, run
                    FROM caso_participantes
                    WHERE id = ?
                      AND caso_id = ?
                    LIMIT 1
                ");
                $stmtP->execute([$participanteId, $casoId]);
                $participante = $stmtP->fetch();

                if ($participante) {
                    $nombreDeclarante = (string)$participante['nombre_referencial'];
                    $runDeclarante = (string)$participante['run'];
                }
            }

            if ($nombreDeclarante === '') {
                throw new RuntimeException('El nombre del declarante es obligatorio.');
            }

            if ($textoDeclaracion === '') {
                throw new RuntimeException('El texto de la declaración es obligatorio.');
            }

            if ($runDeclarante === '') {
                $runDeclarante = '0-0';
            }

            $stmt = $pdo->prepare("
                INSERT INTO caso_declaraciones (
                    caso_id,
                    participante_id,
                    tipo_declarante,
                    nombre_declarante,
                    run_declarante,
                    calidad_procesal,
                    texto_declaracion,
                    observaciones,
                    requiere_reanalisis_ia,
                    tomada_por
                ) VALUES (?, ?, 'externo', ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->execute([
                $casoId,
                $participanteId > 0 ? $participanteId : null,
                $nombreDeclarante,
                $runDeclarante,
                $calidadProcesal,
                $textoDeclaracion,
                $observaciones !== '' ? $observaciones : null,
                $userId ?: null,
            ]);

            $stmt = $pdo->prepare("
                UPDATE casos
                SET requiere_reanalisis_ia = 1
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $stmt->execute([$casoId, $colegioId]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'declaracion', 'Declaración agregada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se registró declaración de: ' . $nombreDeclarante . '.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_declaracion',
                'caso_declaraciones',
                (int)$pdo->lastInsertId(),
                'Declaración agregada al caso.'
            );

            caso_redirect($casoId, 'declaraciones');
        }

        if ($accion === 'subir_evidencia') {
            $tipo = clean((string)($_POST['tipo'] ?? 'archivo'));
            $descripcion = clean((string)($_POST['descripcion'] ?? ''));

            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Debes seleccionar un archivo válido.');
            }

            $archivo = $_FILES['archivo'];
            $nombreOriginal = basename((string)$archivo['name']);
            $mime = (string)($archivo['type'] ?? 'application/octet-stream');

            $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
            $permitidas = [
                'pdf',
                'jpg',
                'jpeg',
                'png',
                'webp',
                'doc',
                'docx',
                'xls',
                'xlsx',
                'txt',
                'mp3',
                'mp4',
            ];

            if ($extension !== '' && !in_array($extension, $permitidas, true)) {
                throw new RuntimeException('Tipo de archivo no permitido.');
            }

            $directorio = dirname(__DIR__, 2) . '/storage/evidencias/caso_' . $casoId;

            if (!is_dir($directorio)) {
                mkdir($directorio, 0775, true);
            }

            $nombreSeguro = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' .
                preg_replace('/[^a-zA-Z0-9._-]/', '_', $nombreOriginal);

            $rutaFisica = $directorio . '/' . $nombreSeguro;

            if (!move_uploaded_file((string)$archivo['tmp_name'], $rutaFisica)) {
                throw new RuntimeException('No fue posible guardar el archivo.');
            }

            $rutaRelativa = 'storage/evidencias/caso_' . $casoId . '/' . $nombreSeguro;

            $stmt = $pdo->prepare("
                INSERT INTO caso_evidencias (
                    caso_id,
                    tipo,
                    nombre_archivo,
                    ruta,
                    mime_type,
                    descripcion,
                    subido_por
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $casoId,
                $tipo,
                $nombreOriginal,
                $rutaRelativa,
                $mime,
                $descripcion !== '' ? $descripcion : null,
                $userId ?: null,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO caso_historial (
                    caso_id,
                    tipo_evento,
                    titulo,
                    detalle,
                    user_id
                ) VALUES (?, 'evidencia', 'Evidencia agregada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se subió evidencia: ' . $nombreOriginal . '.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'subir_evidencia',
                'caso_evidencias',
                (int)$pdo->lastInsertId(),
                'Evidencia agregada al caso.'
            );

            caso_redirect($casoId, 'evidencias');
        }

        if ($accion === 'actualizar_clasificacion') {
            $clasificacionIa = clean((string)($_POST['clasificacion_ia'] ?? ''));
            $resumenIa = clean((string)($_POST['resumen_ia'] ?? ''));
            $recomendacionIa = clean((string)($_POST['recomendacion_ia'] ?? ''));
            $requiereReanalisis = isset($_POST['requiere_reanalisis_ia']) ? 1 : 0;

            $stmt = $pdo->prepare("
                UPDATE casos
                SET clasificacion_ia = ?,
                    resumen_ia = ?,
                    recomendacion_ia = ?,
                    requiere_reanalisis_ia = ?
                WHERE id = ?
                  AND colegio_id = ?
            ");
            $stmt->execute([
                $clasificacionIa !== '' ? $clasificacionIa : null,
                $resumenIa !== '' ? $resumenIa : null,
                $recomendacionIa !== '' ? $recomendacionIa : null,
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
                ) VALUES (?, 'clasificacion', 'Clasificación actualizada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se actualizó la clasificación o recomendación del caso.',
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'actualizar_clasificacion',
                'casos',
                $casoId,
                'Clasificación del caso actualizada.'
            );

            caso_redirect($casoId, 'clasificacion');
        }

        if ($accion === 'agregar_alerta') {
            $tipo = clean((string)($_POST['tipo'] ?? 'alerta'));
            $mensaje = clean((string)($_POST['mensaje'] ?? ''));
            $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));

            if ($mensaje === '') {
                throw new RuntimeException('El mensaje de la alerta es obligatorio.');
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
                $tipo,
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
                ) VALUES (?, 'alerta', 'Alerta registrada', ?, ?)
            ");
            $stmt->execute([
                $casoId,
                'Se registró alerta: ' . $mensaje,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_alerta',
                'caso_alertas',
                (int)$pdo->lastInsertId(),
                'Alerta agregada al caso.'
            );

            caso_redirect($casoId, 'historial');
        }

        if ($accion === 'agregar_historial') {
            $tipoEvento = clean((string)($_POST['tipo_evento'] ?? 'nota'));
            $titulo = clean((string)($_POST['titulo'] ?? 'Registro'));
            $detalle = clean((string)($_POST['detalle'] ?? ''));

            if ($detalle === '') {
                throw new RuntimeException('El detalle del historial es obligatorio.');
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
                $tipoEvento,
                $titulo !== '' ? $titulo : 'Registro',
                $detalle,
                $userId ?: null,
            ]);

            registrar_bitacora(
                'denuncias',
                'agregar_historial',
                'caso_historial',
                (int)$pdo->lastInsertId(),
                'Registro agregado al historial del caso.'
            );

            caso_redirect($casoId, 'historial');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$stmtCaso->execute([$casoId, $colegioId]);
$caso = $stmtCaso->fetch();

$estadosCaso = $pdo->query("
    SELECT id, codigo, nombre
    FROM estado_caso
    WHERE activo = 1
    ORDER BY orden_visual ASC, id ASC
")->fetchAll();

$stmtParticipantes = $pdo->prepare("
    SELECT *
    FROM caso_participantes
    WHERE caso_id = ?
    ORDER BY id ASC
");
$stmtParticipantes->execute([$casoId]);
$participantes = $stmtParticipantes->fetchAll();

$stmtDeclaraciones = $pdo->prepare("
    SELECT
        d.*,
        p.nombre_referencial AS participante_nombre
    FROM caso_declaraciones d
    LEFT JOIN caso_participantes p ON p.id = d.participante_id
    WHERE d.caso_id = ?
    ORDER BY d.fecha_declaracion DESC, d.id DESC
");
$stmtDeclaraciones->execute([$casoId]);
$declaraciones = $stmtDeclaraciones->fetchAll();

$stmtEvidencias = $pdo->prepare("
    SELECT *
    FROM caso_evidencias
    WHERE caso_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmtEvidencias->execute([$casoId]);
$evidencias = $stmtEvidencias->fetchAll();

$stmtAlertas = $pdo->prepare("
    SELECT *
    FROM caso_alertas
    WHERE caso_id = ?
    ORDER BY id DESC
");
$stmtAlertas->execute([$casoId]);
$alertas = $stmtAlertas->fetchAll();

$stmtHistorial = $pdo->prepare("
    SELECT
        h.*,
        u.nombre AS usuario_nombre
    FROM caso_historial h
    LEFT JOIN usuarios u ON u.id = h.user_id
    WHERE h.caso_id = ?
    ORDER BY h.created_at DESC, h.id DESC
");
$stmtHistorial->execute([$casoId]);
$historial = $stmtHistorial->fetchAll();

$pageTitle = 'Expediente · ' . ($caso['numero_caso'] ?? 'Caso');
$pageSubtitle = 'Revisión integral del caso, intervinientes, declaraciones, evidencias e historial';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.exp-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.24), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 1.8rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.exp-hero h2 {
    margin: 0 0 .4rem;
    font-size: 1.75rem;
    font-weight: 900;
}

.exp-hero p {
    margin: 0;
    color: #bfdbfe;
}

.exp-actions {
    margin-top: 1rem;
    display: flex;
    gap: .6rem;
    flex-wrap: wrap;
}

.exp-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border-radius: 999px;
    padding: .58rem .95rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.exp-btn:hover {
    color: #fff;
}

.exp-btn.green {
    background: #059669;
    border-color: #10b981;
}

.exp-btn.warn {
    background: #f59e0b;
    border-color: #fbbf24;
    color: #111827;
}

.exp-btn.warn:hover {
    color: #111827;
}

.exp-help {
    background: #ecfeff;
    border: 1px solid #99f6e4;
    color: #115e59;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-size: .88rem;
    margin-bottom: 1rem;
}

.exp-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    margin-top: .9rem;
}

.exp-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: .58rem .95rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.exp-link.green {
    background: #ecfdf5;
    color: #047857;
    border-color: #bbf7d0;
}

.exp-link.warn {
    background: #fffbeb;
    color: #92400e;
    border-color: #fde68a;
}

.exp-tabs {
    display: flex;
    gap: .25rem;
    border-bottom: 1px solid #dbe3ef;
    margin-bottom: 1.2rem;
    overflow-x: auto;
}

.exp-tab {
    padding: .8rem 1rem;
    text-decoration: none;
    color: #2563eb;
    font-weight: 900;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
}

.exp-tab.active {
    background: #fff;
    color: #0f172a;
    border: 1px solid #bfdbfe;
    border-bottom: 3px solid #2563eb;
    border-radius: 12px 12px 0 0;
}

.exp-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    padding: 1.3rem;
    margin-bottom: 1.2rem;
}

.exp-title {
    font-size: .78rem;
    color: #2563eb;
    font-weight: 900;
    letter-spacing: .11em;
    text-transform: uppercase;
    padding-bottom: .65rem;
    margin-bottom: 1.15rem;
    border-bottom: 1px solid #dbeafe;
}

.exp-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.exp-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

.exp-label {
    display: block;
    font-size: .76rem;
    font-weight: 900;
    color: #334155;
    margin-bottom: .35rem;
}

.exp-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

textarea.exp-control {
    min-height: 130px;
    resize: vertical;
}

.exp-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37,99,235,.12);
}

.exp-submit {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 0;
    border-radius: 999px;
    background: #0f172a;
    color: #fff;
    padding: .64rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    cursor: pointer;
}

.exp-submit.blue {
    background: #2563eb;
}

.exp-submit.green {
    background: #059669;
}

.exp-submit.red {
    background: #dc2626;
}

.exp-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.exp-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.exp-badge {
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

.exp-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.exp-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.exp-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.exp-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.exp-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: .8rem;
}

.exp-item-title {
    font-weight: 900;
    color: #0f172a;
    margin-bottom: .2rem;
}

.exp-item-meta {
    color: #64748b;
    font-size: .76rem;
    margin-bottom: .45rem;
}

.exp-item-text {
    color: #334155;
    line-height: 1.5;
}

.exp-empty {
    text-align: center;
    color: #94a3b8;
    padding: 2rem 1rem;
}

.exp-summary {
    display: grid;
    grid-template-columns: 1.1fr .9fr;
    gap: 1.2rem;
}

.exp-data {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: .4rem .9rem;
    font-size: .9rem;
}

.exp-data strong {
    color: #334155;
}

.exp-data span {
    color: #0f172a;
}

@media (max-width: 920px) {
    .exp-grid-2,
    .exp-grid-3,
    .exp-summary {
        grid-template-columns: 1fr;
    }

    .exp-data {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="exp-hero">
    <h2><?= e($caso['numero_caso']) ?></h2>
    <p>
        Expediente de convivencia escolar · Estado:
        <?= e($caso['estado_formal'] ?: caso_label($caso['estado'])) ?>
    </p>

    <div class="exp-actions">
        <a class="exp-btn" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-arrow-left"></i>
            Volver al listado
        </a>

        <a class="exp-btn green" href="<?= APP_URL ?>/modules/denuncias/vincular_comunidad.php?caso_id=<?= (int)$casoId ?>">
            <i class="bi bi-person-plus"></i>
            Vincular comunidad educativa
        </a>

        <a class="exp-btn" href="<?= APP_URL ?>/modules/seguimiento/abrir.php?caso_id=<?= (int)$casoId ?>">
            <i class="bi bi-clipboard2-check"></i>
            Seguimiento
        </a>

        <a class="exp-btn" href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&tab=evidencias">
            <i class="bi bi-paperclip"></i>
            Evidencias
        </a>

        <a class="exp-btn warn" href="<?= APP_URL ?>/modules/alertas/index.php">
            <i class="bi bi-bell"></i>
            Alertas
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="exp-msg error">
        <i class="bi bi-exclamation-triangle"></i>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<nav class="exp-tabs">
    <a class="exp-tab <?= $tab === 'resumen' ? 'active' : '' ?>" href="?id=<?= $casoId ?>&tab=resumen">Resumen</a>
    <a class="exp-tab <?= $tab === 'participantes' ? 'active' : '' ?>" href="?id=<?= $casoId ?>&tab=participantes">Participantes (<?= count($participantes) ?>)</a>
    <a class="exp-tab <?= $tab === 'declaraciones' ? 'active' : '' ?>" href="?id=<?= $casoId ?>&tab=declaraciones">Declaraciones (<?= count($declaraciones) ?>)</a>
    <a class="exp-tab <?= $tab === 'evidencias' ? 'active' : '' ?>" href="?id=<?= $casoId ?>&tab=evidencias">Evidencias (<?= count($evidencias) ?>)</a>
    <a class="exp-tab <?= $tab === 'clasificacion' ? 'active' : '' ?>" href="?id=<?= $casoId ?>&tab=clasificacion">Clasificación / Aula Segura</a>
    <a class="exp-tab <?= $tab === 'historial' ? 'active' : '' ?>" href="?id=<?= $casoId ?>&tab=historial">Historial</a>
</nav>

<?php if ($tab === 'resumen'): ?>
    <div class="exp-summary">
        <section class="exp-card">
            <div class="exp-title">Datos generales del caso</div>

            <div class="exp-data">
                <strong>N° caso</strong>
                <span><?= e($caso['numero_caso']) ?></span>

                <strong>Fecha ingreso</strong>
                <span><?= e(caso_fecha((string)$caso['fecha_ingreso'])) ?></span>

                <strong>Estado</strong>
                <span><?= e($caso['estado_formal'] ?: caso_label($caso['estado'])) ?></span>

                <strong>Semáforo</strong>
                <span>
                    <span class="exp-badge <?= e(caso_badge_class((string)$caso['semaforo'])) ?>">
                        <?= e(caso_label((string)$caso['semaforo'])) ?>
                    </span>
                </span>

                <strong>Prioridad</strong>
                <span>
                    <span class="exp-badge <?= e(caso_badge_class((string)$caso['prioridad'])) ?>">
                        <?= e(caso_label((string)$caso['prioridad'])) ?>
                    </span>
                </span>

                <strong>Denunciante</strong>
                <span>
                    <?php if ((int)$caso['es_anonimo'] === 1): ?>
                        Identidad reservada
                    <?php else: ?>
                        <?= e($caso['denunciante_nombre'] ?? 'No informado') ?>
                    <?php endif; ?>
                </span>

                <strong>Contexto</strong>
                <span><?= e($caso['contexto'] ?? 'No informado') ?></span>

                <strong>Lugar hechos</strong>
                <span><?= e($caso['lugar_hechos'] ?? 'No informado') ?></span>

                <strong>Fecha hechos</strong>
                <span><?= e(caso_fecha($caso['fecha_hechos'] ?? null)) ?></span>
            </div>
        </section>

        <section class="exp-card">
            <div class="exp-title">Actualizar control del expediente</div>

            <form method="post">
                <?= CSRF::field() ?>
                <input type="hidden" name="_accion" value="actualizar_resumen">

                <div class="exp-grid-2">
                    <div>
                        <label class="exp-label">Estado operativo</label>
                        <select class="exp-control" name="estado">
                            <option value="abierto" <?= (string)$caso['estado'] === 'abierto' ? 'selected' : '' ?>>Abierto</option>
                            <option value="cerrado" <?= (string)$caso['estado'] === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                        </select>
                    </div>

                    <div>
                        <label class="exp-label">Estado formal</label>
                        <select class="exp-control" name="estado_caso_id">
                            <option value="">Sin estado formal</option>
                            <?php foreach ($estadosCaso as $estado): ?>
                                <option value="<?= (int)$estado['id'] ?>" <?= (int)$caso['estado_caso_id'] === (int)$estado['id'] ? 'selected' : '' ?>>
                                    <?= e($estado['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="exp-label">Semáforo</label>
                        <select class="exp-control" name="semaforo">
                            <option value="verde" <?= (string)$caso['semaforo'] === 'verde' ? 'selected' : '' ?>>Verde</option>
                            <option value="amarillo" <?= (string)$caso['semaforo'] === 'amarillo' ? 'selected' : '' ?>>Amarillo</option>
                            <option value="rojo" <?= (string)$caso['semaforo'] === 'rojo' ? 'selected' : '' ?>>Rojo</option>
                        </select>
                    </div>

                    <div>
                        <label class="exp-label">Prioridad</label>
                        <select class="exp-control" name="prioridad">
                            <option value="baja" <?= (string)$caso['prioridad'] === 'baja' ? 'selected' : '' ?>>Baja</option>
                            <option value="media" <?= (string)$caso['prioridad'] === 'media' ? 'selected' : '' ?>>Media</option>
                            <option value="alta" <?= (string)$caso['prioridad'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top:1rem;">
                    <label>
                        <input type="checkbox" name="involucra_moviles" value="1" <?= (int)$caso['involucra_moviles'] === 1 ? 'checked' : '' ?>>
                        Involucra dispositivos móviles o redes digitales
                    </label>
                </div>

                <div style="margin-top:.5rem;">
                    <label>
                        <input type="checkbox" name="requiere_reanalisis_ia" value="1" <?= (int)$caso['requiere_reanalisis_ia'] === 1 ? 'checked' : '' ?>>
                        Requiere reanálisis IA / revisión especializada
                    </label>
                </div>

                <div style="margin-top:1rem;">
                    <button class="exp-submit blue" type="submit">
                        <i class="bi bi-save"></i>
                        Guardar cambios
                    </button>
                </div>
            </form>
        </section>
    </div>

    <section class="exp-card">
        <div class="exp-title">Relato principal</div>
        <div class="exp-item-text"><?= nl2br(e($caso['relato'])) ?></div>
    </section>
<?php endif; ?>

<?php if ($tab === 'participantes'): ?>
    <section class="exp-card">
        <div class="exp-title">Vincular desde comunidad educativa</div>

        <div class="exp-help">
            Usa esta opción cuando la persona ya exista en la base institucional de alumnos,
            apoderados, docentes o asistentes. El sistema traerá su RUN y nombre desde comunidad educativa
            y evitará doble digitación del expediente.
        </div>

        <div class="exp-card-actions">
            <a class="exp-link green" href="<?= APP_URL ?>/modules/denuncias/vincular_comunidad.php?caso_id=<?= (int)$casoId ?>">
                <i class="bi bi-person-plus"></i>
                Vincular comunidad educativa
            </a>

            <a class="exp-link" href="<?= APP_URL ?>/modules/comunidad/index.php">
                <i class="bi bi-people"></i>
                Revisar comunidad educativa
            </a>

            <a class="exp-link warn" href="<?= APP_URL ?>/modules/importar/pendientes.php">
                <i class="bi bi-exclamation-triangle"></i>
                Pendientes de importación
            </a>
        </div>
    </section>

    <section class="exp-card">
        <div class="exp-title">Agregar participante manual</div>

        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="agregar_participante">

            <div class="exp-grid-3">
                <div>
                    <label class="exp-label">Tipo persona</label>
                    <select class="exp-control" name="tipo_persona">
                        <option value="alumno">Alumno</option>
                        <option value="apoderado">Apoderado</option>
                        <option value="docente">Docente</option>
                        <option value="asistente">Asistente</option>
                        <option value="externo" selected>Externo / no vinculado</option>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Nombre</label>
                    <input class="exp-control" type="text" name="nombre_referencial" required>
                </div>

                <div>
                    <label class="exp-label">RUN</label>
                    <input class="exp-control" type="text" name="run" placeholder="0-0">
                </div>

                <div>
                    <label class="exp-label">Rol en el caso</label>
                    <select class="exp-control" name="rol_en_caso">
                        <option value="victima">Víctima / afectado</option>
                        <option value="denunciante">Denunciante</option>
                        <option value="denunciado">Denunciado</option>
                        <option value="testigo">Testigo</option>
                        <option value="involucrado" selected>Involucrado</option>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Observación</label>
                    <input class="exp-control" type="text" name="observacion">
                </div>

                <div>
                    <label class="exp-label">Reserva identidad</label>
                    <label>
                        <input type="checkbox" name="solicita_reserva_identidad" value="1">
                        Solicita reserva
                    </label>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Observación de reserva</label>
                <input class="exp-control" type="text" name="observacion_reserva">
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit green" type="submit">
                    <i class="bi bi-person-plus"></i>
                    Agregar participante
                </button>
            </div>
        </form>
    </section>

    <section class="exp-card">
        <div class="exp-title">Participantes registrados</div>

        <?php if (!$participantes): ?>
            <div class="exp-empty">No hay participantes registrados.</div>
        <?php else: ?>
            <?php foreach ($participantes as $p): ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e($p['nombre_referencial']) ?></div>
                    <div class="exp-item-meta">
                        RUN <?= e($p['run']) ?> ·
                        <?= e(caso_label($p['tipo_persona'])) ?> ·
                        <?= e(caso_label($p['rol_en_caso'])) ?>
                    </div>

                    <?php if (!empty($p['persona_id'])): ?>
                        <span class="exp-badge ok">
                            <i class="bi bi-link-45deg"></i>
                            Vinculado a comunidad educativa
                        </span>
                    <?php endif; ?>

                    <?php if ((int)$p['solicita_reserva_identidad'] === 1): ?>
                        <span class="exp-badge warn">Solicita reserva de identidad</span>
                    <?php endif; ?>

                    <?php if (!empty($p['observacion'])): ?>
                        <div class="exp-item-text"><?= e($p['observacion']) ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($tab === 'declaraciones'): ?>
    <section class="exp-card">
        <div class="exp-title">Registrar declaración</div>

        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="agregar_declaracion">

            <div class="exp-grid-3">
                <div>
                    <label class="exp-label">Participante vinculado</label>
                    <select class="exp-control" name="participante_id">
                        <option value="">Sin vincular</option>
                        <?php foreach ($participantes as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= e($p['nombre_referencial']) ?> · <?= e(caso_label($p['rol_en_caso'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Nombre declarante</label>
                    <input class="exp-control" type="text" name="nombre_declarante" placeholder="Si seleccionas participante, puede quedar vacío">
                </div>

                <div>
                    <label class="exp-label">RUN declarante</label>
                    <input class="exp-control" type="text" name="run_declarante" placeholder="0-0">
                </div>
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Calidad procesal / rol</label>
                <select class="exp-control" name="calidad_procesal">
                    <option value="victima">Víctima / afectado</option>
                    <option value="denunciante">Denunciante</option>
                    <option value="denunciado">Denunciado</option>
                    <option value="testigo">Testigo</option>
                    <option value="declarante" selected>Declarante</option>
                </select>
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Texto de la declaración</label>
                <textarea class="exp-control" name="texto_declaracion" required></textarea>
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Observaciones internas</label>
                <textarea class="exp-control" name="observaciones"></textarea>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit green" type="submit">
                    <i class="bi bi-chat-square-text"></i>
                    Registrar declaración
                </button>
            </div>
        </form>
    </section>

    <section class="exp-card">
        <div class="exp-title">Declaraciones registradas</div>

        <?php if (!$declaraciones): ?>
            <div class="exp-empty">No hay declaraciones registradas.</div>
        <?php else: ?>
            <?php foreach ($declaraciones as $d): ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e($d['nombre_declarante']) ?></div>
                    <div class="exp-item-meta">
                        <?= e(caso_label($d['calidad_procesal'])) ?> ·
                        <?= e(caso_fecha((string)$d['fecha_declaracion'])) ?>

                        <?php if (!empty($d['participante_nombre'])): ?>
                            · Vinculado a <?= e($d['participante_nombre']) ?>
                        <?php endif; ?>
                    </div>

                    <div class="exp-item-text"><?= nl2br(e($d['texto_declaracion'])) ?></div>

                    <?php if (!empty($d['observaciones'])): ?>
                        <hr>
                        <div class="exp-item-text">
                            <strong>Observaciones:</strong><br>
                            <?= nl2br(e($d['observaciones'])) ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($tab === 'evidencias'): ?>
    <section class="exp-card">
        <div class="exp-title">Subir evidencia</div>

        <form method="post" enctype="multipart/form-data">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="subir_evidencia">

            <div class="exp-grid-3">
                <div>
                    <label class="exp-label">Tipo</label>
                    <select class="exp-control" name="tipo">
                        <option value="archivo">Archivo</option>
                        <option value="imagen">Imagen</option>
                        <option value="documento">Documento</option>
                        <option value="audio">Audio</option>
                        <option value="video">Video</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Archivo</label>
                    <input class="exp-control" type="file" name="archivo" required>
                </div>

                <div>
                    <label class="exp-label">Descripción</label>
                    <input class="exp-control" type="text" name="descripcion">
                </div>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit green" type="submit">
                    <i class="bi bi-upload"></i>
                    Subir evidencia
                </button>
            </div>
        </form>
    </section>

    <section class="exp-card">
        <div class="exp-title">Evidencias registradas</div>

        <?php if (!$evidencias): ?>
            <div class="exp-empty">No hay evidencias registradas.</div>
        <?php else: ?>
            <?php foreach ($evidencias as $ev): ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e($ev['nombre_archivo']) ?></div>
                    <div class="exp-item-meta">
                        <?= e(caso_label($ev['tipo'])) ?> ·
                        <?= e(caso_fecha((string)$ev['created_at'])) ?> ·
                        <?= e($ev['mime_type'] ?? 'sin tipo') ?>
                    </div>

                    <?php if (!empty($ev['descripcion'])): ?>
                        <div class="exp-item-text"><?= e($ev['descripcion']) ?></div>
                    <?php endif; ?>

                    <div style="margin-top:.8rem;">
                        <a
                            class="exp-submit blue"
                            style="text-decoration:none;"
                            href="<?= APP_URL . '/' . e($ev['ruta']) ?>"
                            target="_blank"
                        >
                            <i class="bi bi-box-arrow-up-right"></i>
                            Abrir archivo
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($tab === 'clasificacion'): ?>
    <section class="exp-card">
        <div class="exp-title">Clasificación / Aula Segura / Revisión especializada</div>

        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="actualizar_clasificacion">

            <div>
                <label class="exp-label">Clasificación IA o jurídica-pedagógica</label>
                <input
                    class="exp-control"
                    type="text"
                    name="clasificacion_ia"
                    value="<?= e($caso['clasificacion_ia'] ?? '') ?>"
                    placeholder="Ej: maltrato escolar, acoso, vulneración de derechos, aula segura, otro"
                >
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Resumen técnico</label>
                <textarea class="exp-control" name="resumen_ia"><?= e($caso['resumen_ia'] ?? '') ?></textarea>
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Recomendación de gestión</label>
                <textarea class="exp-control" name="recomendacion_ia"><?= e($caso['recomendacion_ia'] ?? '') ?></textarea>
            </div>

            <div style="margin-top:1rem;">
                <label>
                    <input type="checkbox" name="requiere_reanalisis_ia" value="1" <?= (int)$caso['requiere_reanalisis_ia'] === 1 ? 'checked' : '' ?>>
                    Mantener marcado para reanálisis / revisión especializada
                </label>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit blue" type="submit">
                    <i class="bi bi-stars"></i>
                    Guardar clasificación
                </button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if ($tab === 'historial'): ?>
    <section class="exp-card">
        <div class="exp-title">Agregar alerta</div>

        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="agregar_alerta">

            <div class="exp-grid-3">
                <div>
                    <label class="exp-label">Tipo</label>
                    <input class="exp-control" type="text" name="tipo" value="alerta">
                </div>

                <div>
                    <label class="exp-label">Prioridad</label>
                    <select class="exp-control" name="prioridad">
                        <option value="baja">Baja</option>
                        <option value="media" selected>Media</option>
                        <option value="alta">Alta</option>
                    </select>
                </div>

                <div>
                    <label class="exp-label">Mensaje</label>
                    <input class="exp-control" type="text" name="mensaje" required>
                </div>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit red" type="submit">
                    <i class="bi bi-bell"></i>
                    Agregar alerta
                </button>
            </div>
        </form>
    </section>

    <section class="exp-card">
        <div class="exp-title">Agregar registro al historial</div>

        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="agregar_historial">

            <div class="exp-grid-2">
                <div>
                    <label class="exp-label">Tipo evento</label>
                    <input class="exp-control" type="text" name="tipo_evento" value="nota">
                </div>

                <div>
                    <label class="exp-label">Título</label>
                    <input class="exp-control" type="text" name="titulo" value="Registro manual">
                </div>
            </div>

            <div style="margin-top:1rem;">
                <label class="exp-label">Detalle</label>
                <textarea class="exp-control" name="detalle" required></textarea>
            </div>

            <div style="margin-top:1rem;">
                <button class="exp-submit blue" type="submit">
                    <i class="bi bi-journal-plus"></i>
                    Agregar al historial
                </button>
            </div>
        </form>
    </section>

    <section class="exp-card">
        <div class="exp-title">Alertas del caso</div>

        <?php if (!$alertas): ?>
            <div class="exp-empty">No hay alertas registradas.</div>
        <?php else: ?>
            <?php foreach ($alertas as $a): ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e(caso_label($a['tipo'])) ?></div>
                    <div class="exp-item-meta">
                        <?= e(caso_fecha((string)$a['fecha_alerta'])) ?> ·
                        <span class="exp-badge <?= e(caso_badge_class((string)$a['estado'])) ?>">
                            <?= e(caso_label($a['estado'])) ?>
                        </span>
                        <span class="exp-badge <?= e(caso_badge_class((string)$a['prioridad'])) ?>">
                            Prioridad <?= e(caso_label($a['prioridad'])) ?>
                        </span>
                    </div>
                    <div class="exp-item-text"><?= nl2br(e($a['mensaje'])) ?></div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="exp-card">
        <div class="exp-title">Historial del expediente</div>

        <?php if (!$historial): ?>
            <div class="exp-empty">No hay registros de historial.</div>
        <?php else: ?>
            <?php foreach ($historial as $h): ?>
                <article class="exp-item">
                    <div class="exp-item-title"><?= e($h['titulo']) ?></div>
                    <div class="exp-item-meta">
                        <?= e(caso_label($h['tipo_evento'])) ?> ·
                        <?= e(caso_fecha((string)$h['created_at'])) ?>

                        <?php if (!empty($h['usuario_nombre'])): ?>
                            · <?= e($h['usuario_nombre']) ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($h['detalle'])): ?>
                        <div class="exp-item-text"><?= nl2br(e($h['detalle'])) ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>