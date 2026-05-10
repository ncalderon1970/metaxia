<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/DB.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/helpers.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$q         = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($q) < 2 || $colegioId <= 0) {
    echo json_encode(['casos' => [], 'alumnos' => []]);
    exit;
}

$qUp  = mb_strtoupper($q, 'UTF-8');
$like = '%' . $qUp . '%';

$out = ['casos' => [], 'alumnos' => []];

// ── Buscar casos ──────────────────────────────────────────
try {
    $sc = $pdo->prepare("
        SELECT c.id, c.numero_caso, c.fecha_ingreso, c.estado,
               ec.nombre AS estado_formal
        FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE c.colegio_id = ?
          AND c.estado <> 'borrador'
          AND (UPPER(c.numero_caso) LIKE ?
               OR UPPER(c.relato) LIKE ?
               OR UPPER(c.denunciante_nombre) LIKE ?)
        ORDER BY c.updated_at DESC
        LIMIT 6
    ");
    $sc->execute([$colegioId, $like, $like, $like]);
    foreach ($sc->fetchAll() as $row) {
        $out['casos'][] = [
            'id'          => (int)$row['id'],
            'numero_caso' => (string)($row['numero_caso'] ?? ''),
            'estado'      => (string)($row['estado_formal'] ?: ucfirst((string)($row['estado'] ?? ''))),
            'fecha'       => $row['fecha_ingreso']
                ? date('d/m/Y', strtotime((string)$row['fecha_ingreso']))
                : '',
        ];
    }
} catch (Throwable $e) {}

// ── Buscar alumnos ────────────────────────────────────────
try {
    $sa = $pdo->prepare("
        SELECT a.id, a.run,
               CONCAT(COALESCE(a.nombres,''), ' ', COALESCE(a.apellido_paterno,''), ' ', COALESCE(a.apellido_materno,'')) AS nombre,
               c.nombre AS curso
        FROM alumnos a
        LEFT JOIN cursos c ON c.id = a.curso_id
        WHERE a.colegio_id = ?
          AND a.activo = 1
          AND (a.run LIKE ?
               OR UPPER(CONCAT(a.nombres,' ',a.apellido_paterno,' ',a.apellido_materno)) LIKE ?)
        ORDER BY a.apellido_paterno, a.nombres
        LIMIT 5
    ");
    $sa->execute([$colegioId, '%' . $q . '%', $like]);
    foreach ($sa->fetchAll() as $row) {
        $out['alumnos'][] = [
            'id'     => (int)$row['id'],
            'nombre' => trim((string)($row['nombre'] ?? '')),
            'run'    => (string)($row['run'] ?? ''),
            'curso'  => (string)($row['curso'] ?? ''),
        ];
    }
} catch (Throwable $e) {}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
