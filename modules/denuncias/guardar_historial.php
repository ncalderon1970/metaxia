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

function gh_redirect(int $casoId, string $msg = ''): void
{
    $url = APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=historial';

    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }

    header('Location: ' . $url);
    exit;
}

function gh_error(int $casoId, string $mensaje): void
{
    if ($casoId > 0) {
        gh_redirect($casoId, $mensaje);
    }

    http_response_code(400);
    exit($mensaje);
}

function gh_tipo_evento_valido(string $tipoEvento): string
{
    $tipoEvento = trim(mb_strtolower($tipoEvento, 'UTF-8'));

    $permitidos = [
        'creacion',
        'cambio_estado',
        'declaracion',
        'participante',
        'alerta',
        'evidencia',
        'plan_accion',
        'seguimiento',
        'gestion',
        'gestion_ejecutiva',
        'comunicacion_apoderado',
        'cierre',
        'reapertura',
        'aula_segura',
        'analisis_ia',
        'borrador',
        'actualizacion',
        'registro_desde_borrador',
        'actualizacion_borrador',
        'nota',
        'manual',
    ];

    return in_array($tipoEvento, $permitidos, true) ? $tipoEvento : 'actualizacion';
}

try {
    CSRF::requireValid($_POST['_token'] ?? null);

    $casoId = (int)($_POST['caso_id'] ?? $_POST['id'] ?? 0);
    $tipoEvento = gh_tipo_evento_valido((string)($_POST['tipo_evento'] ?? 'actualizacion'));
    $titulo = clean((string)($_POST['titulo'] ?? ''));
    $detalle = clean((string)($_POST['detalle'] ?? ''));

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

    if ($titulo === '') {
        throw new RuntimeException('El título del registro es obligatorio.');
    }

    if ($detalle === '') {
        throw new RuntimeException('El detalle del historial es obligatorio.');
    }

    $titulo = mb_substr($titulo, 0, 150, 'UTF-8');

    $stmtInsert = $pdo->prepare("
        INSERT INTO caso_historial (
            caso_id,
            tipo_evento,
            titulo,
            detalle,
            user_id,
            created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsert->execute([
        $casoId,
        $tipoEvento,
        $titulo,
        $detalle,
        $userId > 0 ? $userId : null,
    ]);

    $historialId = (int)$pdo->lastInsertId();

    registrar_bitacora(
        'denuncias',
        'guardar_historial',
        'caso_historial',
        $historialId,
        'Registro manual agregado al historial del caso ' . (string)($caso['numero_caso'] ?? $casoId)
    );

    gh_redirect($casoId, 'historial_guardado');
} catch (Throwable $e) {
    $casoIdError = (int)($_POST['caso_id'] ?? $_POST['id'] ?? 0);
    gh_error($casoIdError, $e->getMessage());
}
