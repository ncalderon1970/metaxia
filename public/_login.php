<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../core/helpers.php';

Auth::startSession();

if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: ' . APP_URL . '/public/login.php?closed=1');
    exit;
}

$defaultNext = APP_URL . '/public/index.php';
$next = (string)($_GET['next'] ?? $_POST['next'] ?? $defaultNext);
if ($next === '' || str_contains($next, 'login.php') || !str_starts_with($next, APP_URL)) {
    $next = $defaultNext;
}
if (Auth::check()) { header('Location: ' . $defaultNext); exit; }

$error  = '';
$closed = isset($_GET['closed']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Recarga la página.';
    } else {
        $email    = clean((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            $error = 'Ingresa correo y contraseña.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo no tiene un formato válido.';
        } elseif (!Auth::attempt($email, $password)) {
            $error = 'Credenciales inválidas o usuario inactivo.';
            sleep(1);
        } else {
            CSRF::regenerate();
            header('Location: ' . $next);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Metaxia - SGCE &middot; Iniciar sesi&oacute;n</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/bootstrap-icons.min.css">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{
            --navy:#060d1f;--navy2:#0d1a35;--blue:#1a56db;--blue2:#2563eb;
            --teal:#0d9488;--teal2:#14b8a6;--slate:#334155;--muted:#64748b;
            --border:#e2e8f0;
        }
        body{
            font-family:'DM Sans',sans-serif;min-height:100vh;
            background:var(--navy);display:flex;align-items:stretch;overflow:hidden;
        }

        /* ── Brand panel ───────────────────────────────────────── */
        .lg-brand{
            flex:1;position:relative;display:flex;flex-direction:column;
            justify-content:space-between;padding:3rem 3.5rem;overflow:hidden;
            background:linear-gradient(160deg,#060d1f 0%,#0a1628 50%,#071525 100%);
        }
        .lg-bg-orb{position:absolute;border-radius:50%;filter:blur(80px);
            animation:orb-drift 12s ease-in-out infinite alternate;}
        .lg-bg-orb.o1{width:500px;height:500px;
            background:radial-gradient(circle,rgba(13,148,136,.28),transparent 70%);
            top:-120px;left:-80px;}
        .lg-bg-orb.o2{width:380px;height:380px;
            background:radial-gradient(circle,rgba(26,86,219,.22),transparent 70%);
            bottom:60px;right:-60px;animation-delay:-5s;}
        .lg-bg-orb.o3{width:260px;height:260px;
            background:radial-gradient(circle,rgba(20,184,166,.18),transparent 70%);
            top:50%;left:55%;animation-delay:-9s;}
        .lg-brand::before{
            content:'';position:absolute;inset:0;
            background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
                linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
            background-size:48px 48px;
        }
        @keyframes orb-drift{
            from{transform:translate(0,0) scale(1);}
            to{transform:translate(24px,16px) scale(1.06);}
        }
        .lg-brand-top,.lg-brand-mid,.lg-brand-bot{position:relative;z-index:1;}
        .lg-brand-mid{flex:1;display:flex;flex-direction:column;justify-content:center;padding:2rem 0;}

        .lg-logo-mark{display:inline-flex;align-items:center;gap:.75rem;
            animation:fade-up .6s ease both;}
        .lg-logo-icon{width:48px;height:48px;border-radius:14px;
            background:linear-gradient(135deg,var(--teal) 0%,var(--blue) 100%);
            display:grid;place-items:center;box-shadow:0 8px 24px rgba(13,148,136,.35);}
        .lg-logo-icon i{font-size:1.35rem;color:#fff;}
        .lg-logo-text{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;
            color:#fff;letter-spacing:-.02em;}

        .lg-headline{
            font-family:'Syne',sans-serif;
            font-size:clamp(2.4rem,4.5vw,3.75rem);font-weight:800;
            line-height:1.02;letter-spacing:-.04em;color:#fff;
            margin-bottom:1.25rem;animation:fade-up .7s ease .1s both;
        }
        .lg-headline .hl{
            background:linear-gradient(90deg,var(--teal2),#67e8f9);
            -webkit-background-clip:text;-webkit-text-fill-color:transparent;
            background-clip:text;
        }
        .lg-desc{font-size:1rem;font-weight:300;line-height:1.65;
            color:#94a3b8;max-width:400px;animation:fade-up .7s ease .2s both;}
        .lg-features{display:flex;flex-direction:column;gap:.6rem;margin-top:2rem;
            animation:fade-up .7s ease .3s both;}
        .lg-feature{display:flex;align-items:center;gap:.65rem;
            font-size:.82rem;font-weight:400;color:#64748b;}
        .lg-feature i{width:22px;height:22px;border-radius:6px;
            background:rgba(13,148,136,.15);display:grid;place-items:center;
            font-size:.72rem;color:var(--teal2);flex-shrink:0;}
        .lg-brand-bot{font-size:.75rem;color:#334155;letter-spacing:.03em;
            animation:fade-up .7s ease .4s both;}

        /* ── Form panel ────────────────────────────────────────── */
        .lg-form-wrap{
            width:460px;flex-shrink:0;background:#fff;
            display:flex;align-items:center;justify-content:center;
            padding:3rem 3.25rem;position:relative;
            animation:slide-in .55s cubic-bezier(.22,1,.36,1) both;
        }
        .lg-form-wrap::before{
            content:'';position:absolute;left:0;top:15%;bottom:15%;width:3px;
            background:linear-gradient(to bottom,transparent,var(--teal),var(--blue),transparent);
            border-radius:999px;
        }
        @keyframes slide-in{
            from{transform:translateX(40px);opacity:0;}
            to{transform:translateX(0);opacity:1;}
        }
        @keyframes fade-up{
            from{transform:translateY(14px);opacity:0;}
            to{transform:translateY(0);opacity:1;}
        }
        .lg-form{width:100%;}
        .lg-form-title{
            font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;
            color:var(--navy);letter-spacing:-.03em;margin-bottom:.35rem;
        }
        .lg-form-sub{font-size:.88rem;font-weight:300;color:var(--muted);
            margin-bottom:1.75rem;line-height:1.5;}

        .lg-alert{border-radius:10px;padding:.7rem .9rem;margin-bottom:1.25rem;
            font-size:.84rem;display:flex;align-items:center;gap:.5rem;border:1px solid;}
        .lg-alert.ok {background:#ecfdf5;border-color:#bbf7d0;color:#047857;}
        .lg-alert.err{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}

        .lg-field{margin-bottom:1.1rem;}
        .lg-label{display:flex;align-items:center;gap:.35rem;font-size:.78rem;
            font-weight:600;color:var(--slate);margin-bottom:.38rem;letter-spacing:.02em;}
        .lg-label i{font-size:.78rem;color:var(--muted);}

        .lg-input-wrap{position:relative;}
        .lg-input{
            width:100%;border:1.5px solid var(--border);border-radius:10px;
            padding:.72rem 1rem .72rem 2.75rem;font-size:.92rem;font-family:inherit;
            color:var(--navy);outline:none;background:#f8fafc;
            transition:border-color .15s,box-shadow .15s,background .15s;
        }
        .lg-input:focus{border-color:var(--blue2);background:#fff;
            box-shadow:0 0 0 4px rgba(37,99,235,.08);}
        .lg-input-icon{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);
            color:#94a3b8;font-size:.88rem;pointer-events:none;}
        .lg-eye{position:absolute;right:.9rem;top:50%;transform:translateY(-50%);
            color:#94a3b8;cursor:pointer;font-size:.9rem;
            background:none;border:none;padding:0;outline:none;}
        .lg-eye:hover{color:var(--slate);}

        .lg-btn{
            width:100%;border:none;margin-top:.25rem;
            background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 100%);
            color:#fff;border-radius:10px;padding:.8rem;font-size:.92rem;
            font-weight:600;font-family:inherit;cursor:pointer;letter-spacing:.01em;
            display:flex;align-items:center;justify-content:center;gap:.45rem;
            transition:transform .12s,box-shadow .12s;
            box-shadow:0 4px 16px rgba(6,13,31,.22);
        }
        .lg-btn:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(6,13,31,.3);}
        .lg-btn:active{transform:translateY(0);}

        .lg-forgot{display:block;text-align:center;margin-top:1rem;
            font-size:.8rem;color:var(--muted);text-decoration:none;transition:color .15s;}
        .lg-forgot:hover{color:var(--blue2);}

        .lg-footer{text-align:center;margin-top:2rem;font-size:.72rem;color:#94a3b8;
            padding-top:1.25rem;border-top:1px solid var(--border);letter-spacing:.02em;}

        @media(max-width:860px){
            body{flex-direction:column;overflow:auto;}
            .lg-brand{padding:2rem;min-height:220px;}
            .lg-brand-mid{padding:.75rem 0;}
            .lg-headline{font-size:1.9rem;}
            .lg-features{display:none;}
            .lg-form-wrap{width:100%;padding:2rem 1.75rem 2.5rem;}
            .lg-form-wrap::before{display:none;}
        }
    </style>
</head>
<body>

<section class="lg-brand">
    <div class="lg-bg-orb o1"></div>
    <div class="lg-bg-orb o2"></div>
    <div class="lg-bg-orb o3"></div>

    <div class="lg-brand-top">
        <div class="lg-logo-mark">
            <div class="lg-logo-icon"><i class="bi bi-shield-fill-check"></i></div>
            <span class="lg-logo-text">Metaxia</span>
        </div>
    </div>

    <div class="lg-brand-mid">
        <h1 class="lg-headline">
            Sistema de<br>
            <span class="hl">Gesti&oacute;n de</span><br>
            Convivencia<br>Escolar
        </h1>
        <p class="lg-desc">
            
        </p>
        <div class="lg-features">
            <p class="slogan-text">La inteligencia del equilibrio</p>
        </div>
    </div>

    <div class="lg-brand-bot">

    </div>
</section>

<section class="lg-form-wrap">
    <div class="lg-form">
        <h2 class="lg-form-title">Bienvenido</h2>
        <p class="lg-form-sub">Ingresa con tu cuenta institucional para continuar.</p>

        <?php if ($closed): ?>
            <div class="lg-alert ok">
                <i class="bi bi-check-circle-fill"></i> Sesi&oacute;n cerrada correctamente.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="lg-alert err">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <?= CSRF::field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">

            <div class="lg-field">
                <label class="lg-label" for="lg-email">
                    <i class="bi bi-envelope"></i> Correo electr&oacute;nico
                </label>
                <div class="lg-input-wrap">
                    <i class="bi bi-envelope lg-input-icon"></i>
                    <input class="lg-input" id="lg-email" name="email" type="email"
                           value="<?= e($_POST['email'] ?? '') ?>"
                           placeholder="usuario@establecimiento.cl"
                           required autofocus autocomplete="username">
                </div>
            </div>

            <div class="lg-field">
                <label class="lg-label" for="lg-pass">
                    <i class="bi bi-lock"></i> Contrase&ntilde;a
                </label>
                <div class="lg-input-wrap">
                    <i class="bi bi-lock lg-input-icon"></i>
                    <input class="lg-input" id="lg-pass" name="password" type="password"
                           placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                           required autocomplete="current-password" style="padding-right:2.75rem;">
                    <button type="button" class="lg-eye" id="eyeBtn" title="Mostrar contrase&ntilde;a">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="lg-btn">
                <i class="bi bi-box-arrow-in-right"></i> Entrar al sistema
            </button>
        </form>

        <a class="lg-forgot" href="<?= APP_URL ?>/public/recuperar_password.php">
            <i class="bi bi-key"></i> &iquest;Olvidaste tu contrase&ntilde;a?
        </a>

        <div class="lg-footer">
            Metaxia &mdash; Sistemas de Gesti&oacute;n Integral &nbsp;&middot;&nbsp; V<?= e(APP_VERSION) ?>
        </div>
    </div>
</section>

<script>
(function(){
    var btn=document.getElementById('eyeBtn');
    var inp=document.getElementById('lg-pass');
    var ico=document.getElementById('eyeIcon');
    if(!btn)return;
    btn.addEventListener('click',function(){
        var show=inp.type==='password';
        inp.type=show?'text':'password';
        ico.className=show?'bi bi-eye-slash':'bi bi-eye';
    });
})();
</script>
</body>
</html>
