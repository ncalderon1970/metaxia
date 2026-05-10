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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

function gp_redirect(int $casoId, string $msg = ''): void
{
    $url = APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=participantes';
    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }

    header('Location: ' . $url);
    exit;
}

function gp_error(int $casoId, string $mensaje): void
{
    if ($casoId > 0) {
        gp_redirect($casoId, $mensaje);
    }

    http_response_code(400);
    exit($mensaje);
}

function gp_normalizar_nombre(array $row): string
{
    $partes = array_filter([
        trim((string)($row['apellido_paterno'] ?? '')),
        trim((string)($row['apellido_materno'] ?? '')),
        trim((string)($row['nombres'] ?? '')),
    ], static fn($value): bool => $value !== '');

    $nombre = trim(implode(' ', $partes));

    if ($nombre === '') {
        $nombre = trim((string)($row['nombre'] ?? ''));
    }

    return $nombre !== '' ? mb_strtoupper($nombre, 'UTF-8') : 'NN';
}

function gp_sql_persona(string $tipoPersona): ?string
{
    return match ($tipoPersona) {
        'alumno' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, NULL AS nombre
            FROM alumnos
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        'apoderado' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre
            FROM apoderados
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        'docente' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre
            FROM docentes
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        'asistente' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre
            FROM asistentes
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        default => null,
    };
}

try {
    CSRF::requireValid($_POST['_token'] ?? null);

    $casoId = (int)($_POST['caso_id'] ?? $_POST['id'] ?? 0);
    $tipoPersona = clean((string)($_POST['tipo_persona'] ?? 'externo'));
    $personaId = (int)($_POST['persona_id'] ?? 0);
    $nombre = clean((string)($_POST['nombre_referencial'] ?? ''));
    $run = cleanRun((string)($_POST['run'] ?? ''));
    $rolEnCaso = clean((string)($_POST['rol_en_caso'] ?? 'involucrado'));
    $reserva = isset($_POST['solicita_reserva_identidad']) ? 1 : 0;
    $observacion = clean((string)($_POST['observacion'] ?? ''));
    $observacionReserva = clean((string)($_POST['observacion_reserva'] ?? ''));

    if ($casoId <= 0) {
        throw new RuntimeException('Debe indicar un caso válido.');
    }

    $stmtCaso = $pdo->prepare("
        SELECT id, numero_caso
        FROM casos
        WHERE id = ?
          AND colegio_id = ?
        LIMIT 1
    ");
    $stmtCaso->execute([$casoId, $colegioId]);
    $caso = $stmtCaso->fetch(PDO::FETCH_ASSOC);

    if (!$caso) {
        throw new RuntimeException('Caso no encontrado o sin acceso.');
    }

    $tiposPermitidos = ['alumno', 'apoderado', 'docente', 'asistente', 'externo'];
    if (!in_array($tipoPersona, $tiposPermitidos, true)) {
        $tipoPersona = 'externo';
    }

    $rolesPermitidos = ['victima', 'denunciante', 'denunciado', 'testigo', 'involucrado'];
    if (!in_array($rolEnCaso, $rolesPermitidos, true)) {
        $rolEnCaso = 'involucrado';
    }

    $personaIdSql = null;
    $identidadConfirmada = 0;
    $fechaIdentificacion = null;
    $identificadoPor = null;
    $observacionIdentificacion = null;

    if ($personaId > 0 && $tipoPersona !== 'externo') {
        $sqlPersona = gp_sql_persona($tipoPersona);

        if ($sqlPersona === null) {
            throw new RuntimeException('Tipo de persona no válido.');
        }

        $stmtPersona = $pdo->prepare($sqlPersona);
        $stmtPersona->execute([$personaId, $colegioId]);
        $persona = $stmtPersona->fetch(PDO::FETCH_ASSOC);

        if (!$persona) {
            throw new RuntimeException('La persona seleccionada no pertenece al establecimiento o no está activa.');
        }

        $nombre = gp_normalizar_nombre($persona);
        $run = cleanRun((string)($persona['run'] ?? '0-0'));
        $personaIdSql = $personaId;
        $identidadConfirmada = 1;
        $fechaIdentificacion = date('Y-m-d H:i:s');
        $identificadoPor = $userId > 0 ? $userId : null;
        $observacionIdentificacion = 'Vinculado desde comunidad educativa.';

        $stmtDuplicado = $pdo->prepare("
            SELECT COUNT(*)
            FROM caso_participantes cp
            INNER JOIN casos c ON c.id = cp.caso_id
            WHERE cp.caso_id = ?
              AND cp.tipo_persona = ?
              AND cp.persona_id = ?
              AND cp.rol_en_caso = ?
              AND c.colegio_id = ?
        ");
        $stmtDuplicado->execute([$casoId, $tipoPersona, $personaId, $rolEnCaso, $colegioId]);

        if ((int)$stmtDuplicado->fetchColumn() > 0) {
            throw new RuntimeException('Esta persona ya está registrada con el mismo rol en el caso.');
        }
    } else {
        $personaIdSql = null;
        $nombre = $nombre !== '' ? mb_strtoupper($nombre, 'UTF-8') : 'NN';
        $run = $run !== '' ? $run : '0-0';
        $observacionIdentificacion = 'Participante pendiente de vinculación con comunidad educativa.';
    }

    if ($nombre === '') {
        $nombre = 'NN';
    }

    if ($run === '') {
        $run = '0-0';
    }

    if ($nombre === 'NN' && $run === '0-0') {
        throw new RuntimeException('Debe ingresar nombre o RUN del participante.');
    }

    $pdo->beginTransaction();

    $stmtInsert = $pdo->prepare("
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsert->execute([
        $casoId,
        $tipoPersona,
        $personaIdSql,
        $nombre,
        $run,
        $identidadConfirmada,
        $fechaIdentificacion,
        $identificadoPor,
        $rolEnCaso,
        $reserva,
        $observacionReserva !== '' ? $observacionReserva : null,
        $observacion !== '' ? $observacion : null,
        $observacionIdentificacion,
    ]);

    $participanteId = (int)$pdo->lastInsertId();

    try {
        $stmtHistorial = $pdo->prepare("
            INSERT INTO caso_historial (
                caso_id,
                tipo_evento,
                titulo,
                detalle,
                user_id
            ) VALUES (?, 'participante', 'Participante agregado', ?, ?)
        ");
        $stmtHistorial->execute([
            $casoId,
            'Se agregó participante: ' . $nombre . ' (' . $rolEnCaso . ')' . ($identidadConfirmada ? ' con identidad confirmada.' : ' pendiente de vinculación.'),
            $userId > 0 ? $userId : null,
        ]);
    } catch (Throwable $e) {
        // El historial no debe impedir el registro principal del participante.
    }

    registrar_bitacora(
        'denuncias',
        'guardar_participante',
        'caso_participantes',
        $participanteId,
        'Participante agregado al caso ' . (string)($caso['numero_caso'] ?? $casoId)
    );

    $pdo->commit();

    gp_redirect($casoId, 'participante_agregado');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $casoIdError = (int)($_POST['caso_id'] ?? $_POST['id'] ?? 0);
    gp_error($casoIdError, $e->getMessage());
}
