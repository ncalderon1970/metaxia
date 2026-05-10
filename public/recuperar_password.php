<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/DB.php';
require_once dirname(__DIR__) . '/core/helpers.php';
require_once dirname(__DIR__) . '/core/CSRF.php';

$pdo   = DB::conn();
$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);

    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido.';
    } else {
        // Buscar usuario
        $su = $pdo->prepare("SELECT id, nombre, email FROM usuarios WHERE LOWER(email) = ? AND activo = 1 LIMIT 1");
        $su->execute([$email]);
        $usuario = $su->fetch();

        if ($usuario) {
            // Generar token
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Invalidar tokens anteriores
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ?")->execute([$email]);

            // Guardar nuevo token
            $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$email, $token, $expiresAt]);

            // Enviar email
            $link    = APP_URL . '/public/restablecer_password.php?token=' . $token;
            $nombre  = (string)$usuario['nombre'];
            $asunto  = 'Recuperación de contraseña — Metis SGCE';
            $cuerpo  = "Hola {$nombre},\n\n";
            $cuerpo .= "Recibimos una solicitud para restablecer la contraseña de tu cuenta en Metis SGCE.\n\n";
            $cuerpo .= "Haz clic en el siguiente enlace para crear una nueva contraseña:\n";
            $cuerpo .= "{$link}\n\n";
            $cuerpo .= "Este enlace expira en 1 hora.\n\n";
            $cuerpo .= "Si no solicitaste este cambio, ignora este mensaje. Tu contraseña actual sigue siendo válida.\n\n";
            $cuerpo .= "— Equipo Metis SGCE\n";

            $headers  = "From: no-reply@metis.saberser.cl\r\n";
            $headers .= "Reply-To: no-reply@metis.saberser.cl\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "X-Mailer: Metis-SGCE\r\n";

            mail($email, $asunto, $cuerpo, $headers);
        }

        // Siempre mostrar el mismo mensaje (seguridad anti-enumeración)
        $msg = 'Si el correo está registrado, recibirás un enlace en los próximos minutos. Revisa también tu carpeta de spam.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar contraseña · Metis</title>
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
            width: 52px; height: 52px; background: #eff6ff; border-radius: 14px;
            color: #2563eb; font-size: 1.5rem; margin-bottom: .75rem; }
        .rp-logo h1 { font-size: 1.2rem; font-weight: 700; color: #0f172a; }
        .rp-logo p  { font-size: .82rem; color: #64748b; margin-top: .2rem; }
        h2 { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: .35rem; }
        .rp-sub { font-size: .84rem; color: #64748b; margin-bottom: 1.25rem; line-height: 1.5; }
        label { display: block; font-size: .78rem; font-weight: 600; color: #334155; margin-bottom: .3rem; }
        input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: .58rem .85rem;
                font-size: .9rem; font-family: inherit; color: #0f172a; outline: none;
                transition: border-color .15s, box-shadow .15s; }
        input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .rp-btn { width: 100%; margin-top: .9rem; border: none; background: #1e3a8a;
                  color: #fff; border-radius: 8px; padding: .7rem; font-size: .9rem;
                  font-weight: 600; cursor: pointer; font-family: inherit;
                  display: flex; align-items: center; justify-content: center; gap: .4rem; }
        .rp-btn:hover { background: #1e40af; }
        .rp-alert { border-radius: 8px; padding: .75rem .9rem; font-size: .84rem;
                    margin-bottom: 1rem; display: flex; align-items: flex-start; gap: .5rem; }
        .rp-alert.ok  { background: #ecfdf5; border: 1px solid #bbf7d0; color: #047857; }
        .rp-alert.err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .rp-back { display: block; text-align: center; margin-top: 1.1rem;
                   font-size: .82rem; color: #64748b; text-decoration: none; }
        .rp-back:hover { color: #2563eb; }
    </style>
</head>
<body>
<div class="rp-card">
    <div class="rp-logo">
        <div class="rp-logo-badge"><i class="bi bi-shield-lock-fill"></i></div>
        <h1>Metis SGCE</h1>
        <p>Sistema de Gestión de Convivencia Escolar</p>
    </div>

    <h2>Recuperar contraseña</h2>
    <p class="rp-sub">Ingresa el correo de tu cuenta y te enviaremos un enlace para crear una nueva contraseña.</p>

    <?php if ($msg): ?>
        <div class="rp-alert ok">
            <i class="bi bi-check-circle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            <?= e($msg) ?>
        </div>
    <?php elseif ($error): ?>
        <div class="rp-alert err">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$msg): ?>
    <form method="post" novalidate>
        <?= CSRF::field() ?>
        <label for="rp-email">Correo electrónico</label>
        <input id="rp-email" name="email" type="email"
               value="<?= e((string)($_POST['email'] ?? '')) ?>"
               placeholder="usuario@establecimiento.cl"
               required autofocus>
        <button type="submit" class="rp-btn">
            <i class="bi bi-send-fill"></i> Enviar enlace de recuperación
        </button>
    </form>
    <?php endif; ?>

    <a class="rp-back" href="<?= APP_URL ?>/public/login.php">
        <i class="bi bi-arrow-left"></i> Volver al inicio de sesión
    </a>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
