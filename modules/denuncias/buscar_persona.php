<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));

if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

if ($colegioId <= 0) {
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

function bp_normalizar_run_busqueda(string $valor): string
{
    return preg_replace('/[^0-9kK]/', '', $valor) ?? '';
}

function bp_buscar_alumnos(PDO $pdo, string $q, int $colegioId): array
{
    $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
    $qRun   = '%' . bp_normalizar_run_busqueda($q) . '%';

    try {
        $stmt = $pdo->prepare("\n            SELECT\n                id,\n                COALESCE(NULLIF(run, ''), '0-0') AS run,\n                UPPER(TRIM(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres))) AS nombre,\n                'alumno' AS tipo,\n                COALESCE(curso, '') AS extra\n            FROM alumnos\n            WHERE colegio_id = ?\n              AND activo = 1\n              AND (\n                    UPPER(CONVERT(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres) USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                 OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                 OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n              )\n            ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC\n            LIMIT 8\n        ");
        $stmt->execute([$colegioId, $qTexto, '%' . $q . '%', $qRun]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function bp_buscar_apoderados(PDO $pdo, string $q, int $colegioId): array
{
    $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
    $qRun   = '%' . bp_normalizar_run_busqueda($q) . '%';

    try {
        $stmt = $pdo->prepare("\n            SELECT\n                id,\n                COALESCE(NULLIF(run, ''), '0-0') AS run,\n                UPPER(TRIM(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, 'N/N'))) AS nombre,\n                'apoderado' AS tipo,\n                '' AS extra\n            FROM apoderados\n            WHERE colegio_id = ?\n              AND activo = 1\n              AND (\n                    UPPER(CONVERT(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                 OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                 OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n              )\n            ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC\n            LIMIT 8\n        ");
        $stmt->execute([$colegioId, $qTexto, '%' . $q . '%', $qRun]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function bp_buscar_funcionarios(PDO $pdo, string $tabla, string $tipo, string $q, int $colegioId): array
{
    if (!in_array($tabla, ['docentes', 'asistentes'], true)) {
        return [];
    }

    $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
    $qRun   = '%' . bp_normalizar_run_busqueda($q) . '%';

    try {
        $stmt = $pdo->prepare("\n            SELECT\n                id,\n                COALESCE(NULLIF(run, ''), '0-0') AS run,\n                UPPER(TRIM(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, 'N/N'))) AS nombre,\n                '{$tipo}' AS tipo,\n                COALESCE(cargo, '') AS extra\n            FROM {$tabla}\n            WHERE colegio_id = ?\n              AND activo = 1\n              AND (\n                    UPPER(CONVERT(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                 OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                 OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n                 OR UPPER(CONVERT(COALESCE(cargo, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n              )\n            ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC\n            LIMIT 8\n        ");
        $stmt->execute([$colegioId, $qTexto, '%' . $q . '%', $qRun, $qTexto]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}


$resultados = array_merge(
    bp_buscar_alumnos($pdo, $q, $colegioId),
    bp_buscar_apoderados($pdo, $q, $colegioId),
    bp_buscar_funcionarios($pdo, 'docentes', 'docente', $q, $colegioId),
    bp_buscar_funcionarios($pdo, 'asistentes', 'asistente', $q, $colegioId)
);

$resultados = array_map(static function (array $r): array {
    $nombre = trim((string)($r['nombre'] ?? ''));

    return [
        'id'     => (int)($r['id'] ?? 0),
        'run'    => (string)(($r['run'] ?? '') !== '' ? $r['run'] : '0-0'),
        'nombre' => $nombre !== '' ? $nombre : 'N/N',
        'tipo'   => (string)($r['tipo'] ?? ''),
        'extra'  => (string)($r['extra'] ?? ''),
    ];
}, $resultados);

usort($resultados, static function (array $a, array $b): int {
    return strcmp((string)$a['nombre'], (string)$b['nombre']);
});

echo json_encode(array_values($resultados), JSON_UNESCAPED_UNICODE);
