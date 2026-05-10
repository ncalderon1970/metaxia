<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/DB.php';
require_once dirname(__DIR__) . '/core/helpers.php';
require_once dirname(__DIR__) . '/core/CSRF.php';

$pdo   = DB::conn();
$token = trim((string)($_GET['token'] ?? ''));
$error = '';
$done  = false;

// Validar token
$registro = null;
if ($token !== '') {
    $st = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE token = ? AND used = 0 AND expires_at > NOW()
        LIMIT 1
    ");
    $st->execute([$token]);
    $registro = $st->fetch();
}

if (!$registro) {
    $error = 'El enlace es inválido o ya expiró. Solicita uno nuevo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registro) {
    CSRF::requireValid($_POST['_token'] ?? null);

    $nueva    = (string)($_POST['password']   ?? '');
    $confirma = (string)($_POST['confirmar']  ?? '');

    if (strlen($nueva) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $confirma) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $hash  = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 10]);
        $email = (string)$registro['email'];

        // Actualizar contraseña
        $pdo->prepare("UPDATE usuarios SET password_hash = ?, updated_at = NOW() WHERE LOWER(email) = ? AND activo = 1")
            ->execute([$hash, strtolower($email)]);

        // Invalidar token
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")
            ->execute([$token]);

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva contraseña · Metis</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #f1f5f9; font-family: system-ui, -apple-system, sans-serif;
               min-height: 100vh; display: flex; align-items: center; justify-content: center;
               padding: 1.5rem; }
        .rp-card { background: #fff; border-radius: 16px; padding: 2rem 2.25rem;
                   width: 100%; max-width: 420px;
                   box-shadow: 0 4px 24px rgba(15,23,42,.10); }
        .rp-logo { text-align: center; margin-bottom: 1.5rem; }
        .rp-logo-badge { display: inline-flex; align-items: center; justify-content: center;
            width: 52px; height: 52px; background: #ecfdf5; border-radius: 14px;
            color: #059669; font-size: 1.5rem; margin-bottom: .75rem; }
        .rp-logo h1 { font-size: 1.2rem; font-weight: 700; color: #0f172a; }
        h2 { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: .35rem; }
        .rp-sub { font-size: .84rem; color: #64748b; margin-bottom: 1.25rem; line-height: 1.5; }
        label { display: block; font-size: .78rem; font-weight: 600; color: #334155;
                margin-bottom: .3rem; margin-top: .85rem; }
        input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: .58rem .85rem;
                font-size: .9rem; font-family: inherit; color: #0f172a; outline: none;
                transition: border-color .15s, box-shadow .15s; }
        input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .rp-hint { font-size: .74rem; color: #94a3b8; margin-top: .25rem; }
        .rp-btn { width: 100%; margin-top: 1.1rem; border: none; background: #059669;
                  color: #fff; border-radius: 8px; padding: .7rem; font-size: .9rem;
                  font-weight: 600; cursor: pointer; font-family: inherit;
                  display: flex; align-items: center; justify-content: center; gap: .4rem; }
        .rp-btn:hover { background: #047857; }
        .rp-alert { border-radius: 8px; padding: .75rem .9rem; font-size: .84rem;
                    margin-bottom: 1rem; display: flex; align-items: flex-start; gap: .5rem; }
        .rp-alert.ok  { background: #ecfdf5; border: 1px solid #bbf7d0; color: #047857; }
        .rp-alert.err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .rp-back { display: block; text-align: center; margin-top: 1.1rem;
                   font-size: .82rem; color: #64748b; text-decoration: none; }
        .rp-back:hover { color: #2563eb; }
        .rp-btn-login { display: block; text-align: center; margin-top: .9rem;
            background: #1e3a8a; color: #fff; border-radius: 8px; padding: .7rem;
            font-size: .9rem; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
<div class="rp-card">
    <div class="rp-logo">
        <div class="rp-logo-badge"><i class="bi bi-key-fill"></i></div>
        <h1>Metis SGCE</h1>
    </div>

    <?php if ($done): ?>
        <div class="rp-alert ok">
            <i class="bi bi-check-circle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            <span>¡Contraseña actualizada correctamente! Ya puedes iniciar sesión.</span>
        </div>
        <a class="rp-btn-login" href="<?= APP_URL ?>/public/login.php">
            <i class="bi bi-box-arrow-in-right"></i> Ir al inicio de sesión
        </a>

    <?php elseif ($error && !$registro): ?>
        <div class="rp-alert err">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            <?= e($error) ?>
        </div>
        <a class="rp-back" href="<?= APP_URL ?>/public/recuperar_password.php">
            <i class="bi bi-arrow-left"></i> Solicitar nuevo enlace
        </a>

    <?php else: ?>
        <h2>Crear nueva contraseña</h2>
        <p class="rp-sub">Ingresa y confirma tu nueva contraseña para la cuenta <strong><?= e((string)($registro['email'] ?? '')) ?></strong>.</p>

        <?php if ($error): ?>
            <div class="rp-alert err">
                <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= CSRF::field() ?>
            <label for="rp-pass">Nueva contraseña</label>
            <input id="rp-pass" name="password" type="password"
                   required autofocus autocomplete="new-password">
            <span class="rp-hint">Mínimo 8 caracteres.</span>

            <label for="rp-confirm">Confirmar contraseña</label>
            <input id="rp-confirm" name="confirmar" type="password"
                   required autocomplete="new-password">

            <button type="submit" class="rp-btn">
                <i class="bi bi-check-lg"></i> Guardar nueva contraseña
            </button>
        </form>

        <a class="rp-back" href="<?= APP_URL ?>/public/login.php">
            <i class="bi bi-arrow-left"></i> Volver al inicio de sesión
        </a>
    <?php endif; ?>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
