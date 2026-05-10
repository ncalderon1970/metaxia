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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

function aiu_redirect(int $casoId, string $msg = ''): void
{
    $url = APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=participantes';
    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }

    header('Location: ' . $url);
    exit;
}

function aiu_nombre(array $row): string
{
    $nombreCompuesto = trim(implode(' ', array_filter([
        trim((string)($row['apellido_paterno'] ?? '')),
        trim((string)($row['apellido_materno'] ?? '')),
        trim((string)($row['nombres'] ?? '')),
    ], static fn($v): bool => $v !== '')));

    if ($nombreCompuesto === '') {
        $nombreCompuesto = trim((string)($row['nombre'] ?? ''));
    }

    return $nombreCompuesto !== '' ? mb_strtoupper($nombreCompuesto, 'UTF-8') : 'NN';
}

function aiu_tabla_por_tipo(string $tipoPersona): ?string
{
    return match ($tipoPersona) {
        'alumno'    => 'alumnos',
        'apoderado' => 'apoderados',
        'docente'   => 'docentes',
        'asistente' => 'asistentes',
        default     => null,
    };
}

function aiu_obtener_persona(PDO $pdo, string $tipoPersona, int $personaId, int $colegioId): ?array
{
    $tabla = aiu_tabla_por_tipo($tipoPersona);
    if ($tabla === null || $personaId <= 0 || $colegioId <= 0) {
        return null;
    }

    $sql = match ($tabla) {
        'alumnos' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, NULL AS nombre
            FROM alumnos
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        'apoderados' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre
            FROM apoderados
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        'docentes' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre
            FROM docentes
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
        'asistentes' => "
            SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre
            FROM asistentes
            WHERE id = ? AND colegio_id = ? AND activo = 1
            LIMIT 1
        ",
    };

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$personaId, $colegioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function aiu_error(int $casoId, string $mensaje): void
{
    if ($casoId > 0) {
        aiu_redirect($casoId, $mensaje);
    }

    http_response_code(400);
    exit($mensaje);
}

try {
    CSRF::requireValid($_POST['_token'] ?? null);

    $participanteId = (int)($_POST['participante_id'] ?? 0);
    $casoId         = (int)($_POST['caso_id'] ?? 0);
    $tipoPersona    = clean((string)($_POST['tipo_persona'] ?? ''));
    $personaId      = (int)($_POST['persona_id'] ?? 0);
    $nombre         = clean((string)($_POST['nombre_referencial'] ?? ''));
    $run            = cleanRun((string)($_POST['run'] ?? ''));
    $observacionId  = clean((string)($_POST['observacion_identificacion'] ?? ''));

    if ($participanteId <= 0 || $casoId <= 0) {
        throw new RuntimeException('Datos incompletos.');
    }

    if (!in_array($tipoPersona, ['alumno', 'apoderado', 'docente', 'asistente', 'externo'], true)) {
        throw new RuntimeException('Tipo de persona no válido.');
    }

    // Validar que el caso y el participante correspondan al establecimiento activo.
    $stmtActual = $pdo->prepare(" 
        SELECT
            cp.id,
            cp.caso_id,
            cp.tipo_persona,
            cp.persona_id,
            cp.nombre_referencial,
            cp.run,
            cp.rol_en_caso,
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
        throw new RuntimeException('Interviniente no encontrado o sin acceso.');
    }

    $personaIdSql = null;
    $identidadConfirmada = 0;
    $fechaIdentificacion = null;
    $identificadoPor = null;

    // Si viene una persona vinculada, la información oficial se toma desde la comunidad educativa.
    if ($personaId > 0 && $tipoPersona !== 'externo') {
        $persona = aiu_obtener_persona($pdo, $tipoPersona, $personaId, $colegioId);
        if (!$persona) {
            throw new RuntimeException('La persona seleccionada no pertenece al establecimiento o no está activa.');
        }

        $nombre = aiu_nombre($persona);
        $run = cleanRun((string)($persona['run'] ?? '0-0'));
        $personaIdSql = $personaId;
        $identidadConfirmada = 1;
        $fechaIdentificacion = date('Y-m-d H:i:s');
        $identificadoPor = $userId > 0 ? $userId : null;

        if ($observacionId === '') {
            $observacionId = 'Identidad actualizada desde comunidad educativa.';
        }
    } else {
        $tipoPersona = 'externo';
        $personaIdSql = null;
        $nombre = $nombre !== '' ? mb_strtoupper($nombre, 'UTF-8') : 'NN';
        $run = $run !== '' ? $run : '0-0';

        if ($nombre !== 'NN' || $run !== '0-0') {
            $identidadConfirmada = 1;
            $fechaIdentificacion = date('Y-m-d H:i:s');
            $identificadoPor = $userId > 0 ? $userId : null;
        }
    }

    if ($nombre === '') {
        $nombre = 'NN';
    }
    if ($run === '') {
        $run = '0-0';
    }

    $pdo->beginTransaction();

    $stmtUpdate = $pdo->prepare("
        UPDATE caso_participantes
        SET
            tipo_persona = ?,
            persona_id = ?,
            nombre_referencial = ?,
            run = ?,
            identidad_confirmada = ?,
            fecha_identificacion = ?,
            identificado_por = ?,
            observacion_identificacion = ?
        WHERE id = ?
          AND caso_id = ?
        LIMIT 1
    ");
    $stmtUpdate->execute([
        $tipoPersona,
        $personaIdSql,
        $nombre,
        $run,
        $identidadConfirmada,
        $fechaIdentificacion,
        $identificadoPor,
        $observacionId !== '' ? $observacionId : null,
        $participanteId,
        $casoId,
    ]);

    try {
        $detalle = 'Se actualiza interviniente desde ['
            . (string)($actual['nombre_referencial'] ?? 'NN') . ' / '
            . (string)($actual['run'] ?? '0-0') . '] a ['
            . $nombre . ' / ' . $run . '].';

        $stmtHist = $pdo->prepare("
            INSERT INTO caso_historial
                (caso_id, tipo_evento, titulo, detalle, user_id, created_at)
            VALUES
                (?, 'identificacion', 'Identidad de interviniente actualizada', ?, ?, NOW())
        ");
        $stmtHist->execute([$casoId, $detalle, $userId ?: null]);
    } catch (Throwable $e) {
        // La bitácora del caso no debe bloquear la actualización principal.
    }

    registrar_bitacora(
        'denuncias',
        'actualizar_interviniente',
        'caso_participantes',
        $participanteId,
        'Interviniente actualizado en caso ' . (string)($actual['numero_caso'] ?? $casoId)
    );

    $pdo->commit();

    aiu_redirect($casoId, 'interviniente_actualizado');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $casoIdError = (int)($_POST['caso_id'] ?? 0);
    aiu_error($casoIdError, $e->getMessage());
}
