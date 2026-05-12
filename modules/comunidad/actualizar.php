<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/_comunidad_anual_view_helpers.php';

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

CSRF::requireValid();

$pdo = DB::conn();
$user = Auth::user();
$colegioId = (int) Auth::colegioId();
$tipo = (string)($_POST['tipo'] ?? 'alumnos');
$permitidos = ['alumnos', 'apoderados', 'docentes', 'asistentes'];
if (!in_array($tipo, $permitidos, true)) {
    $tipo = 'alumnos';
}
$id = (int)($_POST['id'] ?? 0);
$anioEscolar = (int)($_POST['anio_escolar'] ?? metis_anio_escolar_actual());
$tabla = metis_tabla_anual_por_tipo($tipo);

$base = [
    'run' => strtoupper(trim((string)($_POST['run'] ?? ''))),
    'nombres' => mb_strtoupper(trim((string)($_POST['nombres'] ?? '')), 'UTF-8'),
    'apellido_paterno' => mb_strtoupper(trim((string)($_POST['apellido_paterno'] ?? '')), 'UTF-8'),
    'apellido_materno' => mb_strtoupper(trim((string)($_POST['apellido_materno'] ?? '')), 'UTF-8'),
    'fecha_nacimiento' => trim((string)($_POST['fecha_nacimiento'] ?? '')) ?: null,
    'sexo' => trim((string)($_POST['sexo'] ?? '')),
    'genero' => trim((string)($_POST['genero'] ?? '')),
    'nombre_social' => trim((string)($_POST['nombre_social'] ?? '')),
    'vigente' => (int)($_POST['vigente'] ?? 1),
];

if ($base['run'] === '' || $base['nombres'] === '' || $base['apellido_paterno'] === '') {
    header('Location: editar.php?tipo=' . urlencode($tipo) . '&id=' . $id . '&anio_escolar=' . $anioEscolar . '&error=obligatorios');
    exit;
}

try {
    $pdo->beginTransaction();

    if ($tipo === 'alumnos') {
        $stmt = $pdo->prepare("UPDATE {$tabla} SET run=?, nombres=?, apellido_paterno=?, apellido_materno=?, fecha_nacimiento=?, sexo=?, genero=?, nombre_social=?, curso=?, nivel=?, letra=?, jornada=?, estado_matricula=?, vigente=?, updated_at=NOW() WHERE id=? AND colegio_id=? AND anio_escolar=?");
        $stmt->execute([$base['run'], $base['nombres'], $base['apellido_paterno'], $base['apellido_materno'], $base['fecha_nacimiento'], $base['sexo'], $base['genero'], $base['nombre_social'], mb_strtoupper(trim((string)($_POST['curso'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['nivel'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['letra'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['jornada'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['estado_matricula'] ?? '')), 'UTF-8'), $base['vigente'], $id, $colegioId, $anioEscolar]);
    } elseif ($tipo === 'apoderados') {
        $stmt = $pdo->prepare("UPDATE {$tabla} SET run=?, nombres=?, apellido_paterno=?, apellido_materno=?, fecha_nacimiento=?, sexo=?, genero=?, nombre_social=?, telefono=?, email=?, direccion=?, relacion_general=?, vigente=?, updated_at=NOW() WHERE id=? AND colegio_id=? AND anio_escolar=?");
        $stmt->execute([$base['run'], $base['nombres'], $base['apellido_paterno'], $base['apellido_materno'], $base['fecha_nacimiento'], $base['sexo'], $base['genero'], $base['nombre_social'], trim((string)($_POST['telefono'] ?? '')), mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['direccion'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['relacion_general'] ?? '')), 'UTF-8'), $base['vigente'], $id, $colegioId, $anioEscolar]);
    } elseif ($tipo === 'docentes') {
        $stmt = $pdo->prepare("UPDATE {$tabla} SET run=?, nombres=?, apellido_paterno=?, apellido_materno=?, fecha_nacimiento=?, sexo=?, genero=?, nombre_social=?, cargo=?, departamento=?, tipo_contrato=?, vigente=?, updated_at=NOW() WHERE id=? AND colegio_id=? AND anio_escolar=?");
        $stmt->execute([$base['run'], $base['nombres'], $base['apellido_paterno'], $base['apellido_materno'], $base['fecha_nacimiento'], $base['sexo'], $base['genero'], $base['nombre_social'], mb_strtoupper(trim((string)($_POST['cargo'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['unidad_departamento'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['tipo_contrato'] ?? '')), 'UTF-8'), $base['vigente'], $id, $colegioId, $anioEscolar]);
    } else {
        $stmt = $pdo->prepare("UPDATE {$tabla} SET run=?, nombres=?, apellido_paterno=?, apellido_materno=?, fecha_nacimiento=?, sexo=?, genero=?, nombre_social=?, cargo=?, unidad=?, tipo_contrato=?, vigente=?, updated_at=NOW() WHERE id=? AND colegio_id=? AND anio_escolar=?");
        $stmt->execute([$base['run'], $base['nombres'], $base['apellido_paterno'], $base['apellido_materno'], $base['fecha_nacimiento'], $base['sexo'], $base['genero'], $base['nombre_social'], mb_strtoupper(trim((string)($_POST['cargo'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['unidad_departamento'] ?? '')), 'UTF-8'), mb_strtoupper(trim((string)($_POST['tipo_contrato'] ?? '')), 'UTF-8'), $base['vigente'], $id, $colegioId, $anioEscolar]);
    }

    if (function_exists('registrar_bitacora')) {
        registrar_bitacora($pdo, $colegioId, (int)($user['id'] ?? 0), 'comunidad', 'actualizar_registro_anual', $tabla, $id, 'Actualización de registro anual de comunidad educativa');
    }

    $pdo->commit();
    header('Location: index.php?tipo=' . urlencode($tipo) . '&anio_escolar=' . $anioEscolar . '&ok=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Metis] Error actualizar comunidad anual: ' . $e->getMessage());
    header('Location: editar.php?tipo=' . urlencode($tipo) . '&id=' . $id . '&anio_escolar=' . $anioEscolar . '&error=bd');
    exit;
}
