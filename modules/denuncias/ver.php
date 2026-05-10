<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/includes/ver_helpers.php';
require_once __DIR__ . '/includes/ver_queries.php';

Auth::requireLogin();

$pdo = DB::conn();

// ── AJAX: buscar participante desde comunidad educativa ──────
if (($_GET['ajax'] ?? '') === 'buscar_participante') {
    header('Content-Type: application/json; charset=utf-8');

    $user      = Auth::user() ?? [];
    $colegioId = (int)($user['colegio_id'] ?? 0);
    $tipo      = trim((string)($_GET['tipo'] ?? 'alumno'));
    $q         = trim((string)($_GET['q'] ?? ''));

    if (!in_array($tipo, ['alumno', 'apoderado', 'funcionario', 'todos', 'externo'], true)) {
        $tipo = 'alumno';
    }

    if ($tipo === 'externo' || mb_strlen($q, 'UTF-8') < 2 || $colegioId <= 0) {
        echo json_encode(['ok' => true, 'items' => [], 'message' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Búsqueda directa sobre el esquema estable de Metis.
     * Se evita la introspección dinámica del esquema para prevenir fallas en cPanel.
     */
    function vp_buscar_alumnos(PDO $pdo, string $q, int $colegioId): array
    {
        $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
        $qRun   = '%' . preg_replace('/[^0-9kK]/', '', $q) . '%';

        try {
            $stmt = $pdo->prepare("\n                SELECT\n                    id,\n                    UPPER(TRIM(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres))) AS nombre,\n                    COALESCE(NULLIF(run, ''), '0-0') AS run,\n                    COALESCE(curso, '') AS curso\n                FROM alumnos\n                WHERE colegio_id = ?\n                  AND activo = 1\n                  AND (\n                        UPPER(CONVERT(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres) USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                     OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                     OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n                  )\n                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC\n                LIMIT 10\n            ");
            $stmt->execute([$colegioId, $qTexto, '%' . $q . '%', $qRun]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'id'         => (int)$r['id'],
                'nombre'     => trim((string)$r['nombre']) !== '' ? (string)$r['nombre'] : 'N/N',
                'run'        => (string)($r['run'] ?: '0-0'),
                'curso'      => (string)($r['curso'] ?? ''),
                'tipo'       => 'alumno',
                'tipo_label' => 'Alumno/a',
            ];
        }, $rows);
    }

    function vp_buscar_apoderados(PDO $pdo, string $q, int $colegioId): array
    {
        $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
        $qRun   = '%' . preg_replace('/[^0-9kK]/', '', $q) . '%';

        try {
            $stmt = $pdo->prepare("\n                SELECT\n                    id,\n                    UPPER(TRIM(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, 'N/N'))) AS nombre,\n                    COALESCE(NULLIF(run, ''), '0-0') AS run,\n                    '' AS curso\n                FROM apoderados\n                WHERE colegio_id = ?\n                  AND activo = 1\n                  AND (\n                        UPPER(CONVERT(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                     OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                     OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n                  )\n                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC\n                LIMIT 10\n            ");
            $stmt->execute([$colegioId, $qTexto, '%' . $q . '%', $qRun]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'id'         => (int)$r['id'],
                'nombre'     => trim((string)$r['nombre']) !== '' ? (string)$r['nombre'] : 'N/N',
                'run'        => (string)($r['run'] ?: '0-0'),
                'curso'      => '',
                'tipo'       => 'apoderado',
                'tipo_label' => 'Apoderado/a',
            ];
        }, $rows);
    }

    function vp_buscar_funcionarios_tabla(PDO $pdo, string $tabla, string $tipoPersona, string $tipoLabel, string $q, int $colegioId): array
    {
        if (!in_array($tabla, ['docentes', 'asistentes'], true)) {
            return [];
        }

        $qTexto = '%' . mb_strtoupper($q, 'UTF-8') . '%';
        $qRun   = '%' . preg_replace('/[^0-9kK]/', '', $q) . '%';

        try {
            $stmt = $pdo->prepare("\n                SELECT\n                    id,\n                    UPPER(TRIM(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, 'N/N'))) AS nombre,\n                    COALESCE(NULLIF(run, ''), '0-0') AS run,\n                    COALESCE(cargo, '') AS curso\n                FROM {$tabla}\n                WHERE colegio_id = ?\n                  AND activo = 1\n                  AND (\n                        UPPER(CONVERT(COALESCE(NULLIF(CONCAT_WS(' ', apellido_paterno, apellido_materno, nombres), ''), nombre, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                     OR CONVERT(run USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?\n                     OR REPLACE(REPLACE(REPLACE(CONVERT(run USING utf8mb4), '.', ''), '-', ''), ' ', '') LIKE ?\n                     OR UPPER(CONVERT(COALESCE(cargo, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE ?\n                  )\n                ORDER BY apellido_paterno ASC, apellido_materno ASC, nombres ASC, nombre ASC\n                LIMIT 10\n            ");
            $stmt->execute([$colegioId, $qTexto, '%' . $q . '%', $qRun, $qTexto]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        return array_map(static function (array $r) use ($tipoPersona, $tipoLabel): array {
            return [
                'id'         => (int)$r['id'],
                'nombre'     => trim((string)$r['nombre']) !== '' ? (string)$r['nombre'] : 'N/N',
                'run'        => (string)($r['run'] ?: '0-0'),
                'curso'      => (string)($r['curso'] ?? ''),
                'tipo'       => $tipoPersona,
                'tipo_label' => $tipoLabel,
            ];
        }, $rows);
    }

    function vp_buscar_por_tipo(PDO $pdo, string $tipo, string $q, int $colegioId): array
    {
        return match ($tipo) {
            'alumno'      => vp_buscar_alumnos($pdo, $q, $colegioId),
            'apoderado'   => vp_buscar_apoderados($pdo, $q, $colegioId),
            'funcionario' => array_merge(
                vp_buscar_funcionarios_tabla($pdo, 'docentes', 'docente', 'Docente', $q, $colegioId),
                vp_buscar_funcionarios_tabla($pdo, 'asistentes', 'asistente', 'Asistente', $q, $colegioId)
            ),
            'todos'       => array_merge(
                vp_buscar_alumnos($pdo, $q, $colegioId),
                vp_buscar_apoderados($pdo, $q, $colegioId),
                vp_buscar_funcionarios_tabla($pdo, 'docentes', 'docente', 'Docente', $q, $colegioId),
                vp_buscar_funcionarios_tabla($pdo, 'asistentes', 'asistente', 'Asistente', $q, $colegioId)
            ),
            default       => [],
        };
    }

    $items = vp_buscar_por_tipo($pdo, $tipo, $q, $colegioId);

    $message = '';
    if (empty($items) && $tipo !== 'todos' && $q !== '') {
        $items = vp_buscar_por_tipo($pdo, 'todos', $q, $colegioId);
        $message = $items
            ? 'Se muestran coincidencias de otros estamentos.'
            : 'Sin coincidencias. Usa "Externo" para ingresar manualmente.';
    }

    echo json_encode([
        'ok'      => true,
        'items'   => array_slice($items, 0, 20),
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

$casoId = (int)($_GET['id'] ?? 0);
$tab = clean((string)($_GET['tab'] ?? 'resumen'));

if ($casoId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

$error = '';
$exito = '';

$caso = ver_cargar_caso($pdo, $casoId, $colegioId);

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado o no pertenece al establecimiento.');
}

$mostrarAulaSegura = (int)($caso['posible_aula_segura'] ?? 0) === 1;

$accion = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);
    $accion = clean((string)($_POST['_accion'] ?? ''));
}

require_once __DIR__ . '/includes/ver_actions.php';

$contexto = ver_cargar_contexto($pdo, $casoId, $colegioId);
extract($contexto, EXTR_OVERWRITE);

// Validar tabs permitidos para evitar path traversal antes de renderizar la navegación contextual.
$tabsPermitidos = [
    'resumen', 'seguimiento', 'clasificacion', 'participantes',
    'declaraciones', 'evidencias', 'gestion', 'aula_segura',
    'historial', 'cierre', 'analisis_ia', 'plan_accion', 'pauta_riesgo',
];

if (!in_array($tab, $tabsPermitidos, true)) {
    $tab = 'resumen';
}


if (!function_exists('metis_exp_topbar_action_visible')) {
    function metis_exp_topbar_action_visible(PDO $pdo, string $key, bool $default = false): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = 'acciones_expediente_topbar' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            $cfg = $raw ? (json_decode((string)$raw, true) ?: []) : [];

            if (!array_key_exists($key, $cfg)) {
                return $default;
            }

            return (int)($cfg[$key]['visible'] ?? 0) === 1;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

$mostrarAlertaTopbarExpediente = metis_exp_topbar_action_visible($pdo, 'alertas', false);

$pageTitle = 'Expediente · ' . ($caso['numero_caso'] ?? 'Caso');
$pageSubtitle = 'Revisión integral del caso, intervinientes, declaraciones, evidencias e historial';

$pageHeaderActions = [
    [
        'label' => 'Volver al listado',
        'icon' => 'bi-arrow-left',
        'url' => APP_URL . '/modules/denuncias/index.php',
        'variant' => 'dark',
    ],
    [
        'label' => 'Reporte ejecutivo',
        'icon' => 'bi-printer',
        'url' => APP_URL . '/modules/denuncias/reporte_ejecutivo.php?id=' . $casoId,
        'target' => '_blank',
        'rel' => 'noopener',
    ],
    [
        'label' => 'Alertas',
        'icon' => 'bi-bell',
        'url' => APP_URL . '/modules/alertas/index.php',
        'variant' => 'danger',
        'visible' => $mostrarAlertaTopbarExpediente,
    ],
];

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
require_once __DIR__ . '/partials/ver_styles.php';
require_once __DIR__ . '/partials/ver_context_nav_styles.php';
require_once __DIR__ . '/partials/ver_header.php';
require_once __DIR__ . '/partials/ver_messages.php';
require_once __DIR__ . '/partials/ver_tabs.php';

$tabFile = __DIR__ . '/partials/tab_' . $tab . '.php';

if (is_file($tabFile)) {
    require $tabFile;
} else {
    require __DIR__ . '/partials/tab_resumen.php';
}

// Cierre del contenedor abierto por partials/ver_tabs.php.
echo "</main></div>";

require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
