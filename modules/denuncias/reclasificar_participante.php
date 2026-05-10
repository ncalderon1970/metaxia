<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/includes/ver_helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

function rp_redirect(int $casoId, string $msg = ''): void
{
    $url = APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=participantes';
    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }

    header('Location: ' . $url);
    exit;
}

function rp_error(int $casoId, string $mensaje): void
{
    if ($casoId > 0) {
        rp_redirect($casoId, $mensaje);
    }

    http_response_code(400);
    exit($mensaje);
}

function rp_roles_permitidos(): array
{
    return [
        'victima'     => 'Víctima',
        'denunciante' => 'Denunciante',
        'denunciado'  => 'Denunciado/a',
        'testigo'     => 'Testigo',
        'involucrado' => 'Otro interviniente',
    ];
}

function rp_normalizar_rol(string $rol): string
{
    $rol = strtolower(trim($rol));

    // Compatibilidad con eventuales valores antiguos o digitados manualmente.
    if ($rol === 'otro' || $rol === 'otros' || $rol === 'otro_interviniente') {
        return 'involucrado';
    }

    return $rol;
}

function rp_label_rol(string $rol): string
{
    $roles = rp_roles_permitidos();
    $rol = rp_normalizar_rol($rol);

    return $roles[$rol] ?? 'Otro interviniente';
}

function rp_puede_reclasificar(): bool
{
    return Auth::canOperate()
        || Auth::can('gestionar_casos')
        || Auth::can('crear_denuncia')
        || Auth::can('gestionar_comunidad');
}

try {
    CSRF::requireValid($_POST['_token'] ?? null);

    if (!rp_puede_reclasificar()) {
        throw new RuntimeException('No tiene permisos para reclasificar participantes.');
    }

    $casoId         = (int)($_POST['caso_id'] ?? 0);
    $participanteId = (int)($_POST['participante_id'] ?? 0);
    $rolNuevo       = rp_normalizar_rol((string)($_POST['rol_nuevo'] ?? ''));
    $motivo         = clean((string)($_POST['motivo_reclasificacion'] ?? ''));

    if ($casoId <= 0 || $participanteId <= 0) {
        throw new RuntimeException('Datos incompletos para reclasificar el participante.');
    }

    $rolesPermitidos = rp_roles_permitidos();
    if (!array_key_exists($rolNuevo, $rolesPermitidos)) {
        throw new RuntimeException('La nueva calidad del participante no es válida.');
    }

    if ($motivo === '') {
        throw new RuntimeException('Debe registrar el motivo de la reclasificación.');
    }

    if (mb_strlen($motivo, 'UTF-8') > 1000) {
        throw new RuntimeException('El motivo de reclasificación excede el largo permitido.');
    }

    $stmtActual = $pdo->prepare("
        SELECT
            cp.id,
            cp.caso_id,
            cp.nombre_referencial,
            cp.run,
            cp.rol_en_caso,
            cp.tipo_persona,
            c.numero_caso
        FROM caso_participantes cp
        INNER JOIN casos c ON c.id = cp.caso_id
        WHERE cp.id = ?
          AND cp.caso_id = ?
          AND c.colegio_id = ?
        LIMIT 1
    ");
    $stmtActual->execute([$participanteId, $casoId, $colegioId]);
    $actual = $stmtActual->fetch(PDO::FETCH_ASSOC);

    if (!$actual) {
        throw new RuntimeException('Participante no encontrado o sin acceso al expediente.');
    }

    $rolAnterior = rp_normalizar_rol((string)($actual['rol_en_caso'] ?? 'involucrado'));
    if (!array_key_exists($rolAnterior, $rolesPermitidos)) {
        $rolAnterior = 'involucrado';
    }

    if ($rolAnterior === $rolNuevo) {
        throw new RuntimeException('La nueva calidad seleccionada es igual a la actual.');
    }

    $pdo->beginTransaction();

    $stmtUpdate = $pdo->prepare("
        UPDATE caso_participantes
        SET rol_en_caso = ?
        WHERE id = ?
          AND caso_id = ?
        LIMIT 1
    ");
    $stmtUpdate->execute([$rolNuevo, $participanteId, $casoId]);

    $nombre = (string)($actual['nombre_referencial'] ?? 'NN');
    $run    = (string)($actual['run'] ?? '0-0');

    $detalle = 'Se reclasificó al participante ' . $nombre . ' (RUN ' . $run . ') ' .
        'desde "' . rp_label_rol($rolAnterior) . '" a "' . rp_label_rol($rolNuevo) . '". ' .
        'Motivo: ' . $motivo;

    $stmtHist = $pdo->prepare("
        INSERT INTO caso_historial (
            caso_id,
            tipo_evento,
            titulo,
            detalle,
            user_id,
            created_at
        ) VALUES (?, 'participante', 'Participante reclasificado', ?, ?, NOW())
    ");
    $stmtHist->execute([$casoId, $detalle, $userId > 0 ? $userId : null]);

    registrar_bitacora(
        'denuncias',
        'reclasificar_participante',
        'caso_participantes',
        $participanteId,
        'Participante reclasificado en caso ' . (string)($actual['numero_caso'] ?? $casoId) .
        ' desde ' . rp_label_rol($rolAnterior) . ' a ' . rp_label_rol($rolNuevo) . '.'
    );

    if (function_exists('invalidar_cache_dashboard')) {
        invalidar_cache_dashboard($colegioId);
    }

    $pdo->commit();

    rp_redirect($casoId, 'participante_reclasificado');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $casoIdError = (int)($_POST['caso_id'] ?? 0);
    rp_error($casoIdError, $e->getMessage());
}
