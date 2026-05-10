<?php
declare(strict_types=1);

define('APP_INIT', true);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/../ia_analisis/helpers.php';
require_once __DIR__ . '/../ia_analisis/service.php';

Auth::requireLogin();
$pdo = DB::conn();
$usuario = Auth::user();
$colegioId = (int)($usuario['colegio_id'] ?? 0);
$casoId = (int)($_GET['caso_id'] ?? 0);
$error = null;
$success = null;
$caso = null;
$analisisIA = null;
$iaModuloActivo = ia_modulo_activo($pdo, $colegioId, 'IA_ANALISIS');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::can('gestionar_casos')) {
        http_response_code(403);
        exit('No tienes permisos para usar este módulo.');
    }

    if (!CSRF::validate($_POST['_token'] ?? null)) {
        $error = 'Token CSRF inválido.';
    } elseif (($_POST['_accion'] ?? '') === 'analizar_caso') {
        $casoId = (int)($_POST['caso_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM casos WHERE id = ? AND colegio_id = ? LIMIT 1');
        $stmt->execute([$casoId, $colegioId]);
        $caso = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$caso) {
            $error = 'No se encontró el caso.';
        } elseif (!$iaModuloActivo) {
            $error = 'El módulo premium IA no está activo para este colegio.';
        } else {
            try {
                $analisisId = IAAnalisisService::analizarCaso($pdo, $caso, $usuario);
                header('Location: ' . APP_URL . '/modules/denuncias_ai/index.php?caso_id=' . $casoId . '&ok=1&analisis_id=' . $analisisId);
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if ($casoId > 0 && !$caso) {
    $stmt = $pdo->prepare('SELECT * FROM casos WHERE id = ? AND colegio_id = ? LIMIT 1');
    $stmt->execute([$casoId, $colegioId]);
    $caso = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($casoId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM caso_analisis_ia WHERE caso_id = ? AND colegio_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$casoId, $colegioId]);
    $analisisIA = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$error && isset($_GET['ok'])) {
    $success = 'Análisis IA generado correctamente.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asistente IA · Sistema Gestiona</title>
    <?php require dirname(__DIR__) . '/denuncias/_assets.php'; ?>
</head>
<body class="gestiona-body">
<div class="gestiona-shell">
    <header class="gestiona-header">
        <div>
            <div class="gestiona-kicker">Sistema Gestiona · Premium</div>
            <h1 class="gestiona-title">Asistente IA de convivencia</h1>
            <p class="gestiona-subtitle">Analiza casos y orienta al equipo de convivencia en intervenciones, medidas y protocolos.</p>
        </div>
        <div class="gestiona-header-actions">
            <a class="btn btn-light" href="<?= e(APP_URL) ?>/modules/denuncias/index.php<?= $casoId > 0 ? '?caso_id=' . $casoId . '&tab=expediente' : '' ?>">Volver a denuncias</a>
        </div>
    </header>

    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <?php require __DIR__ . '/_panel_ia.php'; ?>
</div>
</body>
</html>
