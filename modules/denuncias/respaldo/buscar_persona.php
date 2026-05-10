<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$pdo = DB::conn();
$user = Auth::user();
$colegioId = (int)$user['colegio_id'];
$like = '%' . $q . '%';

$resultados = [];

/* ALUMNOS */
if ($pdo->query("SHOW TABLES LIKE 'alumnos'")->fetchColumn()) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            run,
            CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno) AS nombre,
            'alumno' AS tipo,
            curso AS extra
        FROM alumnos
        WHERE colegio_id = ?
          AND (
              run LIKE ?
              OR nombres LIKE ?
              OR apellido_paterno LIKE ?
              OR apellido_materno LIKE ?
          )
        LIMIT 8
    ");
    $stmt->execute([$colegioId, $like, $like, $like, $like]);
    $resultados = array_merge($resultados, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* DOCENTES */
if ($pdo->query("SHOW TABLES LIKE 'docentes'")->fetchColumn()) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            run,
            nombre,
            'docente' AS tipo,
            NULL AS extra
        FROM docentes
        WHERE colegio_id = ?
          AND (run LIKE ? OR nombre LIKE ?)
        LIMIT 8
    ");
    $stmt->execute([$colegioId, $like, $like]);
    $resultados = array_merge($resultados, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* ASISTENTES */
if ($pdo->query("SHOW TABLES LIKE 'asistentes'")->fetchColumn()) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            run,
            nombre,
            'asistente' AS tipo,
            cargo AS extra
        FROM asistentes
        WHERE colegio_id = ?
          AND (run LIKE ? OR nombre LIKE ? OR cargo LIKE ?)
        LIMIT 8
    ");
    $stmt->execute([$colegioId, $like, $like, $like]);
    $resultados = array_merge($resultados, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* APODERADOS */
if ($pdo->query("SHOW TABLES LIKE 'apoderados'")->fetchColumn()) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            run,
            nombre,
            'apoderado' AS tipo,
            NULL AS extra
        FROM apoderados
        WHERE colegio_id = ?
          AND (run LIKE ? OR nombre LIKE ?)
        LIMIT 8
    ");
    $stmt->execute([$colegioId, $like, $like]);
    $resultados = array_merge($resultados, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

usort($resultados, function (array $a, array $b): int {
    return strcmp((string)$a['nombre'], (string)$b['nombre']);
});

echo json_encode(array_values($resultados));