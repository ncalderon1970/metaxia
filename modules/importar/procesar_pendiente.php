<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

function pp_redirect(string $status, string $msg): void
{
    header('Location: ' . APP_URL . '/modules/importar/pendientes.php?status=' . urlencode($status) . '&msg=' . urlencode($msg));
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $accion = trim((string)($_POST['_accion'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        throw new RuntimeException('Pendiente no válido.');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS importacion_pendientes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            colegio_id INT UNSIGNED DEFAULT NULL,
            tipo VARCHAR(40) NOT NULL,
            fila INT UNSIGNED DEFAULT NULL,
            run VARCHAR(30) DEFAULT NULL,
            motivo TEXT NOT NULL,
            datos_json LONGTEXT DEFAULT NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'pendiente',
            creado_por INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_importacion_pendientes_tipo (tipo),
            INDEX idx_importacion_pendientes_estado (estado),
            INDEX idx_importacion_pendientes_colegio (colegio_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("SELECT * FROM importacion_pendientes WHERE id = ? AND colegio_id = ? LIMIT 1");
    $stmt->execute([$id, $colegioId]);
    $pendiente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pendiente) {
        throw new RuntimeException('Pendiente no encontrado o fuera del establecimiento activo.');
    }

    if ((string)$pendiente['estado'] !== 'pendiente') {
        throw new RuntimeException('El registro ya no está pendiente.');
    }

    if ($accion !== 'descartar') {
        throw new RuntimeException('Por seguridad, la corrección automática de pendientes se hará desde la pantalla de edición de comunidad educativa. Acción permitida por ahora: descartar.');
    }

    $pdo->beginTransaction();

    $up = $pdo->prepare("UPDATE importacion_pendientes SET estado = 'descartado', updated_at = NOW() WHERE id = ? AND colegio_id = ? AND estado = 'pendiente' LIMIT 1");
    $up->execute([$id, $colegioId]);

    if (function_exists('registrar_bitacora')) {
        try {
            registrar_bitacora('importar', 'descartar_pendiente', 'importacion_pendientes', $id, 'Pendiente de importación descartado.');
        } catch (Throwable $e) {}
    }

    $pdo->commit();
    pp_redirect('ok', 'Pendiente descartado correctamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    pp_redirect('error', $e->getMessage());
}
