<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();

$stmt = $pdo->prepare("
    SELECT *
    FROM logs_sistema
    WHERE colegio_id = ?
      AND modulo = 'denuncias'
    ORDER BY id DESC
    LIMIT 100
");
$stmt->execute([$user['colegio_id']]);
$logs = $stmt->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Bitácora · Denuncias</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f8fafc;margin:0;padding:24px}
        .card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.06)}
        .item{padding:12px 0;border-bottom:1px solid #e2e8f0}
        .muted{color:#64748b}
    </style>
</head>
<body>

<p><a href="index.php">← Volver a denuncias</a></p>

<div class="card">
    <h1>Bitácora del módulo Denuncias</h1>

    <?php if (!$logs): ?>
        <p class="muted">Sin registros aún.</p>
    <?php endif; ?>

    <?php foreach ($logs as $log): ?>
        <div class="item">
            <strong><?= htmlspecialchars($log['accion']) ?></strong><br>
            <span class="muted"><?= htmlspecialchars((string)$log['created_at']) ?></span><br>
            <span><?= htmlspecialchars((string)($log['descripcion'] ?? '')) ?></span>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>