<?php
declare(strict_types=1);

if (!defined('APP_URL')) {
    require_once dirname(__DIR__) . '/config/app.php';
}

if (!class_exists('Auth')) {
    require_once dirname(__DIR__) . '/core/Auth.php';
}

if (!function_exists('e')) {
    require_once dirname(__DIR__) . '/core/helpers.php';
}

$currentUser = Auth::user() ?? [];

$pageTitle = $pageTitle ?? 'Metis';
$pageSubtitle = $pageSubtitle ?? 'Sistema de Gestión de Convivencia Escolar';

$usuarioNombre = (string)($currentUser['nombre'] ?? 'Usuario');
$usuarioEmail = (string)($currentUser['email'] ?? '');
$rolNombre     = (string)($currentUser['rol_nombre']     ?? $currentUser['rol_codigo'] ?? 'Usuario');
$colegioNombre = (string)($currentUser['colegio_nombre'] ?? '');
$rolCodigo = (string)($currentUser['rol_codigo'] ?? '');

$puedeAdmin = in_array($rolCodigo, ['superadmin', 'admin_colegio'], true);

if (method_exists('Auth', 'can')) {
    $puedeAdmin = $puedeAdmin
               || Auth::can('admin_sistema')
               || Auth::can('gestionar_usuarios');
}

// ── Módulos contratados por el colegio ──────────────────────
if (!function_exists('layout_tiene_modulo')) {
    function layout_tiene_modulo(PDO $pdo, int $colegioId, string $modulo): bool
    {
        if ($colegioId <= 0) return false;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM colegio_modulos
                WHERE colegio_id = ?
                  AND modulo_codigo = ?
                  AND activo = 1
                  AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
            ");
            $stmt->execute([$colegioId, $modulo]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

$layoutColegioId   = (int)($currentUser['colegio_id'] ?? 0);
$layoutPdo         = class_exists('DB') ? DB::conn() : null;
$esSuperAdminLayout = $rolCodigo === 'superadmin';

// Superadmin ve todo. Los demás dependen de lo que el colegio tenga contratado.
$tieneModuloIA        = $esSuperAdminLayout || ($layoutPdo && layout_tiene_modulo($layoutPdo, $layoutColegioId, 'ia'));
$tieneModuloAula      = $esSuperAdminLayout || ($layoutPdo && layout_tiene_modulo($layoutPdo, $layoutColegioId, 'aula_segura'));
$tieneModuloReportes  = true; // incluido en todos los planes
$tieneModuloAlertas   = $esSuperAdminLayout || ($layoutPdo && layout_tiene_modulo($layoutPdo, $layoutColegioId, 'alertas'));

// Módulos del sidebar controlados desde Panel Desarrollo (solo superadmin puede ocultar)
$sidebarConfig = [];
if ($layoutPdo) {
    try {
        $sSc = $layoutPdo->prepare("SELECT valor FROM sistema_config WHERE clave = 'modulos_sidebar' LIMIT 1");
        $sSc->execute();
        $sSv = $sSc->fetchColumn();
        $sidebarConfig = $sSv ? (json_decode((string)$sSv, true) ?: []) : [];
    } catch (Throwable $e) { $sidebarConfig = []; }
}
// Un módulo es visible si no está explícitamente desactivado (default: visible)
function layout_sidebar_visible(array $cfg, string $key): bool {
    if (!isset($cfg[$key])) return true; // no configurado = visible
    return (int)($cfg[$key]['visible'] ?? 1) === 1;
}
$mostrarSeguimientoSidebar = layout_sidebar_visible($sidebarConfig, 'seguimiento');
$mostrarAlertasSidebar     = layout_sidebar_visible($sidebarConfig, 'alertas');
$mostrarEvidenciasSidebar  = layout_sidebar_visible($sidebarConfig, 'evidencias');

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

if (!function_exists('layout_active')) {
    function layout_active(string $needle, string $currentPath): string
    {
        return str_contains($currentPath, $needle) ? 'active' : '';
    }
}

if (!function_exists('layout_initials')) {
    function layout_initials(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return 'U';
        }

        $parts = preg_split('/\s+/', $name);
        $out = '';

        foreach ($parts as $part) {
            $part = preg_replace('/[^a-zA-ZáéíóúÁÉÍÓÚñÑ]/u', '', $part);

            if ($part !== '') {
                $out .= mb_substr($part, 0, 1);
            }

            if (mb_strlen($out) >= 2) {
                break;
            }
        }

        return mb_strtoupper($out !== '' ? $out : 'U');
    }
}

if (!function_exists('layout_section_has_active')) {
    function layout_section_has_active(array $items, string $currentPath): bool
    {
        foreach ($items as $item) {
            $match = (string)($item['match'] ?? '');

            if ($match !== '' && str_contains($currentPath, $match)) {
                return true;
            }
        }

        return false;
    }
}

// Contar alertas pendientes para badge
$_alertasPendientes = 0;
try {
    if ($layoutColegioId > 0) {
        $_stmtAl = $layoutPdo ? $layoutPdo->prepare("SELECT COUNT(*) FROM caso_alertas WHERE colegio_id = ? AND estado = 'pendiente'") : null;
        if ($_stmtAl) { $_stmtAl->execute([$layoutColegioId]); $_alertasPendientes = (int)$_stmtAl->fetchColumn(); }
    }
} catch (Throwable $e) { $_alertasPendientes = 0; }

$menuPrincipal = [
    [
        'label' => 'Dashboard',
        'icon' => 'bi-speedometer2',
        'url' => APP_URL . '/modules/dashboard/index.php',
        'match' => '/modules/dashboard/',
    ],
    [
        'label' => 'Denuncias',
        'icon' => 'bi-megaphone',
        'url' => APP_URL . '/modules/denuncias/index.php',
        'match' => '/modules/denuncias/',
    ],
    [
        'label'   => 'Seguimiento',
        'icon'    => 'bi-clipboard2-check',
        'url'     => APP_URL . '/modules/seguimiento/index.php',
        'match'   => '/modules/seguimiento/',
        'visible' => $mostrarSeguimientoSidebar,
    ],
    [
        'label'   => 'Alertas',
        'badge_id' => 'alertas-badge',
        'icon'    => 'bi-bell',
        'url'     => APP_URL . '/modules/alertas/index.php',
        'match'   => '/modules/alertas/',
        'visible' => layout_sidebar_visible($sidebarConfig, 'alertas'),
        'badge'   => $_alertasPendientes > 0 ? (string)$_alertasPendientes : null,
        'badge_color' => 'danger',
    ],
    [
        'label'   => 'Evidencias',
        'icon'    => 'bi-paperclip',
        'url'     => APP_URL . '/modules/evidencias/index.php',
        'match'   => '/modules/evidencias/',
        'visible' => layout_sidebar_visible($sidebarConfig, 'evidencias'),
    ],
    [
        'label' => 'Reportes',
        'icon' => 'bi-bar-chart-line',
        'url' => APP_URL . '/modules/reportes/index.php',
        'match' => '/modules/reportes/',
    ],
];

$menuGestion = [
    [
        'label' => 'Comunidad Educativa',
        'icon' => 'bi-people',
        'url' => APP_URL . '/modules/comunidad/index.php',
        'match' => '/modules/comunidad/',
    ],
    [
        'label' => 'Inclusión / NEE',
        'icon'  => 'bi-heart-pulse-fill',
        'url'   => APP_URL . '/modules/inclusion/index.php',
        'match' => '/modules/inclusion/',
        'badge' => $kpis_sin_derivar ?? 0 > 0 ? (string)$kpis_sin_derivar : null,
    ],
    [
        'label' => 'Importar datos',
        'icon' => 'bi-file-earmark-arrow-up',
        'url' => APP_URL . '/modules/importar/index.php',
        'match' => '/modules/importar/index.php',
    ],
    [
        'label' => 'Pendientes importación',
        'icon' => 'bi-exclamation-triangle',
        'url' => APP_URL . '/modules/importar/pendientes.php',
        'match' => '/modules/importar/pendientes.php',
    ],
];

$menuAdmin = [

    // ── Superadmin: acceso único al panel central ─────────────
    [
        'label'   => 'Adm. General',
        'icon'    => 'bi-grid-3x3-gap-fill',
        'url'     => APP_URL . '/modules/admin/administracion_general.php',
        'match'   => '/modules/admin/',
        'permiso' => 'admin_sistema',
    ],

    // ── admin_colegio: gestión de usuarios y datos del establecimiento ──
    [
        'label' => 'Mi Establecimiento',
        'icon'  => 'bi-building-gear',
        'url'   => APP_URL . '/modules/admin/mi_establecimiento.php',
        'match' => '/modules/admin/mi_establecimiento.php',
    ],
    [
        'label' => 'Usuarios',
        'icon'  => 'bi-people-fill',
        'url'   => APP_URL . '/modules/admin/usuarios_colegio.php',
        'match' => '/modules/admin/usuarios_colegio.php',
    ],
    [
        'label'   => 'Respaldo del sistema',
        'icon'    => 'bi-cloud-arrow-down-fill',
        'url'     => APP_URL . '/modules/admin/respaldo.php',
        'match'   => '/modules/admin/respaldo.php',
        'permiso' => 'admin_sistema',
    ],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/bootstrap-icons.min.css"
    >

    <style>
        :root {
            --metis-navy: #0f172a;
            --metis-blue: #2563eb;
            --metis-green: #059669;
            --metis-bg: #f1f5f9;
            --metis-border: #e2e8f0;
            --metis-muted: #64748b;
            --metis-text: #0f172a;
            --sidebar-width: 286px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--metis-bg);
            color: var(--metis-text);
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            color: inherit;
        }

        .metis-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
        }

        .metis-sidebar {
            background:
                radial-gradient(circle at 80% 8%, rgba(16,185,129,.16), transparent 26%),
                linear-gradient(180deg, #0f172a 0%, #111827 48%, #020617 100%);
            color: #fff;
            height: 100vh;
            max-height: 100vh;
            min-height: 100vh;
            position: sticky;
            top: 0;
            align-self: start;
            padding: 1rem .78rem 1rem 1rem;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
            scrollbar-width: thin;
            scrollbar-color: rgba(148,163,184,.55) rgba(255,255,255,.06);
            z-index: 40;
            box-shadow: 18px 0 45px rgba(15,23,42,.12);
            transition: width .22s cubic-bezier(.4,0,.2,1), padding .22s;
        }

        /* ── Colapso a íconos ─────────────────────────────── */
        body.sidebar-collapsed .metis-sidebar {
            width: 68px;
            padding: 1rem .65rem;
            overflow-x: hidden;
        }
        body.sidebar-collapsed { grid-template-columns: 68px minmax(0,1fr); }

        body.sidebar-collapsed .metis-brand-title,
        body.sidebar-collapsed .metis-brand-subtitle,
        body.sidebar-collapsed .metis-user-card > div:last-child,
        body.sidebar-collapsed .metis-colegio-badge > div,
        body.sidebar-collapsed .metis-nav-link span,
        body.sidebar-collapsed .metis-section-title-left span,
        body.sidebar-collapsed .metis-section-chevron,
        body.sidebar-collapsed .metis-sidebar-footer span,
        body.sidebar-collapsed .metis-colegio-badge i ~ div { display: none; }

        body.sidebar-collapsed .metis-brand { justify-content: center; padding: .3rem 0; }
        body.sidebar-collapsed .metis-brand-mark { margin: 0; }
        body.sidebar-collapsed .metis-user-card { justify-content: center; padding: .5rem 0; }
        body.sidebar-collapsed .metis-avatar { width: 34px; height: 34px; font-size: .78rem; }
        body.sidebar-collapsed .metis-colegio-badge { justify-content: center; padding: .4rem; }
        body.sidebar-collapsed .metis-section-toggle { justify-content: center; padding: .5rem; }
        body.sidebar-collapsed .metis-section-title-left { justify-content: center; }
        body.sidebar-collapsed .metis-nav-link {
            justify-content: center; padding: .65rem;
            border-radius: 10px; gap: 0;
        }
        body.sidebar-collapsed .metis-nav-link i { width: auto; font-size: 1.1rem; }
        body.sidebar-collapsed .metis-nav-badge { display: none; }
        body.sidebar-collapsed .metis-logout { justify-content: center; padding: .65rem; }
        body.sidebar-collapsed .metis-sidebar-footer a { justify-content: center; }

        /* Tooltip en modo colapsado */
        body.sidebar-collapsed .metis-nav-link { position: relative; }
        body.sidebar-collapsed .metis-nav-link::after {
            content: attr(data-label);
            position: absolute; left: calc(100% + 10px); top: 50%;
            transform: translateY(-50%);
            background: #1e293b; color: #fff;
            font-size: .76rem; font-weight: 600;
            padding: .35rem .65rem; border-radius: 7px;
            white-space: nowrap; pointer-events: none;
            opacity: 0; transition: opacity .15s;
            box-shadow: 0 4px 12px rgba(0,0,0,.25);
            z-index: 100;
        }
        body.sidebar-collapsed .metis-nav-link:hover::after { opacity: 1; }

        .metis-sidebar::-webkit-scrollbar {
            width: 10px;
        }

        .metis-sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,.06);
            border-radius: 999px;
        }

        .metis-sidebar::-webkit-scrollbar-thumb {
            background: rgba(148,163,184,.55);
            border: 2px solid rgba(15,23,42,.80);
            border-radius: 999px;
        }

        .metis-sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(203,213,225,.72);
        }

        .metis-colegio-badge {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 8px;
            padding: .45rem .8rem;
            margin: .5rem 0 .75rem;
            font-size: .72rem;
            color: rgba(255,255,255,.9);
            display: flex;
            align-items: center;
            gap: .4rem;
            line-height: 1.3;
        }
        .metis-colegio-badge i { font-size: .85rem; opacity: .8; flex-shrink: 0; }
        .metis-colegio-nombre { font-weight: 700; font-size: .76rem; }

        .metis-brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .75rem .6rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,.10);
            margin-bottom: 1rem;
        }

        .metis-brand-mark {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #2563eb, #10b981);
            color: #fff;
            font-weight: 900;
            font-size: 1.1rem;
            box-shadow: 0 10px 22px rgba(16,185,129,.25);
        }

        .metis-brand-title {
            font-size: 1.15rem;
            font-weight: 900;
            letter-spacing: -.03em;
            line-height: 1;
        }

        .metis-brand-subtitle {
            color: #94a3b8;
            font-size: .72rem;
            margin-top: .18rem;
            font-weight: 700;
        }

        .metis-user-card {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 18px;
            padding: .85rem;
            display: flex;
            gap: .75rem;
            align-items: center;
            margin-bottom: 1rem;
        }

        .metis-avatar {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: #10b981;
            color: #fff;
            font-weight: 900;
            flex: 0 0 auto;
        }

        .metis-user-name {
            font-size: .87rem;
            font-weight: 900;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .metis-user-role {
            color: #cbd5e1;
            font-size: .72rem;
            margin-top: .12rem;
        }

        .metis-menu-section {
            margin-top: .75rem;
        }

        .metis-section-toggle {
            width: 100%;
            border: 1px solid rgba(255,255,255,.10);
            background: rgba(255,255,255,.055);
            color: #cbd5e1;
            border-radius: 14px;
            padding: .58rem .68rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .7rem;
            cursor: pointer;
            font-size: .68rem;
            font-weight: 900;
            letter-spacing: .11em;
            text-transform: uppercase;
            transition: .16s ease;
        }

        .metis-section-toggle:hover {
            color: #fff;
            background: rgba(255,255,255,.09);
            border-color: rgba(255,255,255,.16);
        }

        .metis-section-title-left {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            min-width: 0;
        }

        .metis-section-title-left i {
            font-size: .92rem;
            color: #5eead4;
        }

        .metis-section-chevron {
            font-size: .95rem;
            color: #94a3b8;
            transition: transform .18s ease;
        }

        .metis-menu-section.is-collapsed .metis-section-chevron {
            transform: rotate(-90deg);
        }

        .metis-nav {
            display: grid;
            gap: .25rem;
            margin-top: .45rem;
            overflow: hidden;
            max-height: 540px;
            opacity: 1;
            transition: max-height .22s ease, opacity .16s ease, margin-top .16s ease;
        }

        .metis-menu-section.is-collapsed .metis-nav {
            max-height: 0;
            opacity: 0;
            margin-top: 0;
            pointer-events: none;
        }

        .metis-nav-link {
            display: flex;
            align-items: center;
            gap: .7rem;
            text-decoration: none;
            color: #cbd5e1;
            padding: .68rem .75rem;
            border-radius: 14px;
            font-size: .88rem;
            font-weight: 850;
            border: 1px solid transparent;
            transition: .16s ease;
        }

        .metis-nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        .metis-nav-link:hover {
            color: #fff;
            background: rgba(255,255,255,.08);
            border-color: rgba(255,255,255,.10);
        }

        .metis-nav-link.active {
            color: #fff;
            background: linear-gradient(135deg, rgba(37,99,235,.95), rgba(5,150,105,.95));
            border-color: rgba(255,255,255,.18);
            box-shadow: 0 10px 22px rgba(37,99,235,.20);
        }

        .metis-nav-badge-ia {
            margin-left: auto;
            background: #f5c518;
            color: #1a3a5c;
            font-size: .62rem;
            font-weight: 800;
            padding: .1rem .45rem;
            border-radius: 20px;
            letter-spacing: .03em;
            flex-shrink: 0;
        }

        .metis-nav-badge {
            margin-left: auto;
            font-size: .62rem;
            font-weight: 700;
            padding: .12rem .48rem;
            border-radius: 20px;
            flex-shrink: 0;
            letter-spacing: .02em;
        }
        .metis-nav-badge.danger { background: #ef4444; color: #fff;
            box-shadow: 0 0 0 2px rgba(239,68,68,.3); animation: pulse-badge 2s infinite; }
        .metis-nav-badge.info   { background: #f5c518; color: #1a3a5c; }
        @keyframes pulse-badge {
            0%,100% { box-shadow: 0 0 0 2px rgba(239,68,68,.3); }
            50%      { box-shadow: 0 0 0 4px rgba(239,68,68,.15); }
        }

        /* ── Botón colapsar sidebar ───────────────────────── */
        .sidebar-collapse-btn {
            display: flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 8px;
            background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.10);
            color: #94a3b8; cursor: pointer; font-size: .8rem;
            transition: background .15s, color .15s;
            flex-shrink: 0;
        }
        .sidebar-collapse-btn:hover { background: rgba(255,255,255,.14); color: #fff; }
        body.sidebar-collapsed .sidebar-collapse-btn i { transform: rotate(180deg); }

        .metis-sidebar-footer {
            margin-top: 1.2rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255,255,255,.10);
        }

        .metis-logout {
            display: flex;
            align-items: center;
            gap: .6rem;
            text-decoration: none;
            color: #fff;
            font-weight: 600;
            font-size: .84rem;
            padding: .72rem .9rem;
            border-radius: 10px;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            border: 1px solid rgba(248,113,113,.3);
            box-shadow: 0 4px 12px rgba(220,38,38,.3);
            transition: transform .12s, box-shadow .12s, background .15s;
        }

        .metis-logout:hover {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(220,38,38,.4);
            color: #fff;
        }

        .metis-logout:active { transform: translateY(0); }

        .metis-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .metis-topbar {
            height: 74px;
            background: rgba(255,255,255,.86);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--metis-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0 1.4rem;
            position: sticky;
            top: 0;
            z-index: 30;
        }

        .metis-page-heading {
            min-width: 0;
        }

        .metis-page-title {
            margin: 0;
            color: #0f172a;
            font-weight: 900;
            font-size: 1.15rem;
            letter-spacing: -.025em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .metis-page-subtitle {
            margin-top: .12rem;
            color: var(--metis-muted);
            font-size: .78rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .metis-top-actions {
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        /* ── Buscador global ──────────────────────────────── */
        .metis-search-wrap { position: relative; }
        .metis-search-box {
            display: flex; align-items: center; gap: .5rem;
            background: #f1f5f9; border: 1px solid #e2e8f0;
            border-radius: 10px; padding: .38rem .75rem;
            transition: border-color .15s, box-shadow .15s; width: 230px;
        }
        .metis-search-box:focus-within {
            border-color: #2563eb; background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }
        .metis-search-icon { color: #94a3b8; font-size: .88rem; flex-shrink: 0; }
        .metis-search-input {
            border: none; background: transparent; outline: none;
            font-size: .84rem; color: #0f172a; width: 100%; font-family: inherit;
        }
        .metis-search-input::placeholder { color: #94a3b8; }
        .metis-search-kbd {
            font-size: .68rem; color: #94a3b8; background: #e2e8f0;
            border-radius: 4px; padding: .1rem .35rem; flex-shrink: 0;
            font-family: inherit; border: none; cursor: default;
        }
        .metis-search-results {
            position: absolute; top: calc(100% + 6px); right: 0; width: 360px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(15,23,42,.12); z-index: 100;
            max-height: 380px; overflow-y: auto;
        }
        .metis-sr-item {
            display: flex; align-items: center; gap: .65rem;
            padding: .6rem .85rem; border-bottom: 1px solid #f1f5f9;
            text-decoration: none; color: inherit; transition: background .12s;
        }
        .metis-sr-item:last-child { border-bottom: none; }
        .metis-sr-item:hover { background: #f8fafc; }
        .metis-sr-icon {
            width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: .82rem;
        }
        .metis-sr-icon.caso   { background: #eff6ff; color: #2563eb; }
        .metis-sr-icon.alumno { background: #ecfdf5; color: #059669; }
        .metis-sr-main { flex: 1; min-width: 0; }
        .metis-sr-title { font-size: .84rem; font-weight: 600; color: #0f172a;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .metis-sr-sub   { font-size: .74rem; color: #64748b; }
        .metis-sr-empty { padding: 1.1rem; text-align: center; color: #94a3b8; font-size: .84rem; }
        .metis-sr-head  { padding: .4rem .85rem; font-size: .68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em; color: #94a3b8;
            background: #f8fafc; border-bottom: 1px solid #f1f5f9; }
        @media (max-width: 720px) {
            .metis-search-box { width: 140px; }
            .metis-search-kbd { display: none; }
            .metis-search-results { width: 290px; right: -40px; }
        }

        .metis-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #bbf7d0;
            padding: .42rem .75rem;
            font-size: .75rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .metis-menu-toggle {
            display: none;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            width: 40px;
            height: 40px;
            border-radius: 13px;
            cursor: pointer;
            font-size: 1.15rem;
        }

        .metis-content {
            width: 100%;
            max-width: 1580px;
            margin: 0 auto;
            padding: 1.35rem;
            flex: 1;
        }

        .metis-mobile-overlay {
            display: none;
        }

        @media (max-width: 1050px) {
            .metis-shell {
                grid-template-columns: 1fr;
            }

            .metis-sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                width: var(--sidebar-width);
                height: 100dvh;
                max-height: 100dvh;
                transform: translateX(-105%);
                transition: transform .18s ease;
            }

            body.metis-sidebar-open .metis-sidebar {
                transform: translateX(0);
            }

            .metis-menu-toggle {
                display: inline-grid;
                place-items: center;
            }

            .metis-mobile-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(15,23,42,.50);
                z-index: 35;
            }

            body.metis-sidebar-open .metis-mobile-overlay {
                display: block;
            }

            .metis-topbar {
                padding: 0 1rem;
            }

            .metis-chip {
                display: none;
            }
        }

        @media (max-width: 640px) {
            .metis-content {
                padding: 1rem;
            }

            .metis-page-subtitle {
                display: none;
            }
        }
    </style>
</head>

<body>
<div class="metis-mobile-overlay" data-metis-close></div>

<div class="metis-shell">
    <aside class="metis-sidebar" id="metisSidebar">
        <div class="metis-brand">
            <div class="metis-brand-mark">M</div>
            <div>
                <div class="metis-brand-title">Metis</div>
                <div class="metis-brand-subtitle">Convivencia Escolar</div>
            </div>
            <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Colapsar menú" style="margin-left:auto;">
                <i class="bi bi-chevron-left"></i>
            </button>
        </div>

        <div class="metis-user-card">
            <div class="metis-avatar">
                <?= e(layout_initials($usuarioNombre)) ?>
            </div>

            <div style="min-width:0;">
                <div class="metis-user-name"><?= e($usuarioNombre) ?></div>
                <div class="metis-user-role"><?= e($rolNombre) ?></div>
            </div>
        </div>

        <?php if ($colegioNombre !== ''): ?>
        <div class="metis-colegio-badge">
            <i class="bi bi-building"></i>
            <div>
                <div style="opacity:.7;font-size:.67rem;text-transform:uppercase;letter-spacing:.04em;">Establecimiento</div>
                <div class="metis-colegio-nombre"><?= e($colegioNombre) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $operacionActiva = layout_section_has_active($menuPrincipal, $currentPath);
        $gestionActiva = layout_section_has_active($menuGestion, $currentPath);
        $adminActiva = $puedeAdmin && layout_section_has_active($menuAdmin, $currentPath);
        ?>

        <div class="metis-menu-section <?= $operacionActiva || (!$gestionActiva && !$adminActiva) ? '' : 'is-collapsed' ?>" data-menu-section="operacion">
            <button
                class="metis-section-toggle"
                type="button"
                data-menu-toggle-section="operacion"
                aria-expanded="<?= $operacionActiva || (!$gestionActiva && !$adminActiva) ? 'true' : 'false' ?>"
            >
                <span class="metis-section-title-left">
                    <i class="bi bi-layers"></i>
                    <span>Operación</span>
                </span>
                <i class="bi bi-chevron-down metis-section-chevron"></i>
            </button>

            <nav class="metis-nav" aria-label="Menú Operación">
                <?php foreach ($menuPrincipal as $item): ?>
                    <?php if (isset($item['visible']) && !$item['visible']) continue; ?>
                    <a
                        class="metis-nav-link <?= e(layout_active($item['match'], $currentPath)) ?>"
                        href="<?= e($item['url']) ?>"
                        data-label="<?= e($item['label']) ?>"
                    >
                        <i class="bi <?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                        <?php if (!empty($item['badge'])): ?>
                            <span class="metis-nav-badge <?= e($item['badge_color'] ?? 'info') ?>"><?= e($item['badge']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="metis-menu-section <?= $gestionActiva ? '' : 'is-collapsed' ?>" data-menu-section="gestion">
            <button
                class="metis-section-toggle"
                type="button"
                data-menu-toggle-section="gestion"
                aria-expanded="<?= $gestionActiva ? 'true' : 'false' ?>"
            >
                <span class="metis-section-title-left">
                    <i class="bi bi-folder2-open"></i>
                    <span>Gestión</span>
                </span>
                <i class="bi bi-chevron-down metis-section-chevron"></i>
            </button>

            <nav class="metis-nav" aria-label="Menú Gestión">
                <?php foreach ($menuGestion as $item): ?>
                    <a
                        class="metis-nav-link <?= e(layout_active($item['match'], $currentPath)) ?>"
                        href="<?= e($item['url']) ?>"
                        data-label="<?= e($item['label']) ?>"
                    >
                        <i class="bi <?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                        <?php if (!empty($item['badge'])): ?>
                            <span class="metis-nav-badge <?= e($item['badge_color'] ?? 'info') ?>"><?= e($item['badge']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ($puedeAdmin): ?>
            <div class="metis-menu-section <?= $adminActiva ? '' : 'is-collapsed' ?>" data-menu-section="administracion">
                <button
                    class="metis-section-toggle"
                    type="button"
                    data-menu-toggle-section="administracion"
                    aria-expanded="<?= $adminActiva ? 'true' : 'false' ?>"
                >
                    <span class="metis-section-title-left">
                        <i class="bi bi-shield-lock"></i>
                        <span>Administración</span>
                    </span>
                    <i class="bi bi-chevron-down metis-section-chevron"></i>
                </button>

                <nav class="metis-nav" aria-label="Menú Administración">
                    <?php foreach ($menuAdmin as $item):
                        // Respetar permiso individual del ítem
                        if (!empty($item['permiso']) && !Auth::can($item['permiso'])) continue;
                        // Respetar módulo contratado
                        if (!empty($item['requiere_modulo'])) {
                            $modReq = $item['requiere_modulo'];
                            $moduloOk = $esSuperAdminLayout
                                || ($layoutPdo && layout_tiene_modulo($layoutPdo, $layoutColegioId, $modReq));
                            if (!$moduloOk) continue;
                        }
                    ?>
                        <a
                            class="metis-nav-link <?= e(layout_active($item['match'], $currentPath)) ?>"
                            href="<?= e($item['url']) ?>"
                            data-label="<?= e($item['label']) ?>"
                        >
                            <i class="bi <?= e($item['icon']) ?>"></i>
                            <span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="metis-nav-badge-ia"><?= e($item['badge']) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        <?php endif; ?>

        <div class="metis-sidebar-footer">
            <a class="metis-logout" href="<?= APP_URL ?>/logout.php">
                <i class="bi bi-power"></i>
                <span>Cerrar sesión</span>
            </a>
        </div>
    </aside>

    <main class="metis-main">
        <header class="metis-topbar">
            <div style="display:flex;align-items:center;gap:.8rem;min-width:0;">
                <button class="metis-menu-toggle" type="button" data-metis-toggle aria-label="Abrir menú">
                    <i class="bi bi-list"></i>
                </button>

                <div class="metis-page-heading">
                    <h1 class="metis-page-title"><?= e($pageTitle) ?></h1>
                    <div class="metis-page-subtitle"><?= e($pageSubtitle) ?></div>
                </div>
            </div>

            <div class="metis-top-actions">
                <!-- Buscador global -->
                <div class="metis-search-wrap" id="metisSearchWrap">
                    <div class="metis-search-box">
                        <i class="bi bi-search metis-search-icon"></i>
                        <input type="text" id="metisSearchInput" class="metis-search-input"
                               placeholder="Buscar caso, alumno, RUN…" autocomplete="off">
                        <kbd class="metis-search-kbd">ESC</kbd>
                    </div>
                    <div class="metis-search-results" id="metisSearchResults" style="display:none;"></div>
                </div>

                <span class="metis-chip">
                    <i class="bi bi-shield-check"></i>
                    <?= e($rolNombre) ?>
                </span>
            </div>
        </header>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const storageKey = 'metis.sidebar.sections.v1';
                const sections = Array.from(document.querySelectorAll('[data-menu-section]'));

                function readState() {
                    try {
                        return JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
                    } catch (error) {
                        return {};
                    }
                }

                function saveState(state) {
                    try {
                        localStorage.setItem(storageKey, JSON.stringify(state));
                    } catch (error) {
                        // Si el navegador bloquea localStorage, el menú sigue funcionando sin persistencia.
                    }
                }

                function setExpanded(section, expanded) {
                    const button = section.querySelector('[data-menu-toggle-section]');
                    section.classList.toggle('is-collapsed', !expanded);

                    if (button) {
                        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    }
                }

                const savedState = readState();

                sections.forEach(function (section) {
                    const key = section.getAttribute('data-menu-section');
                    const button = section.querySelector('[data-menu-toggle-section]');
                    const hasActiveLink = Boolean(section.querySelector('.metis-nav-link.active'));

                    if (Object.prototype.hasOwnProperty.call(savedState, key) && !hasActiveLink) {
                        setExpanded(section, savedState[key] === true);
                    }

                    if (button) {
                        button.addEventListener('click', function () {
                            const expanded = section.classList.contains('is-collapsed');
                            setExpanded(section, expanded);

                            const currentState = readState();
                            currentState[key] = expanded;
                            saveState(currentState);
                        });
                    }
                });
            });
        </script>

        <!-- ── Buscador global ────────────────────────────────── -->
        <script>
        (function () {
            var input   = document.getElementById('metisSearchInput');
            var results = document.getElementById('metisSearchResults');
            if (!input || !results) return;

            var timer;
            var BASE = '<?= APP_URL ?>';
            var colegioId = <?= $layoutColegioId ?? 0 ?>;

            function escHtml(s) {
                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            function doSearch(q) {
                clearTimeout(timer);
                if (q.length < 2) { results.style.display = 'none'; return; }
                timer = setTimeout(function () {
                    fetch(BASE + '/ajax/buscar_global.php?q=' + encodeURIComponent(q) + '&colegio_id=' + colegioId)
                        .then(function(r){ return r.json(); })
                        .then(function(data){ renderResults(data); })
                        .catch(function(){ results.style.display = 'none'; });
                }, 280);
            }

            function renderResults(data) {
                results.innerHTML = '';
                var total = 0;

                if (data.casos && data.casos.length > 0) {
                    var h = document.createElement('div');
                    h.className = 'metis-sr-head';
                    h.textContent = 'Casos';
                    results.appendChild(h);
                    data.casos.forEach(function(c) {
                        var a = document.createElement('a');
                        a.className = 'metis-sr-item';
                        a.href = BASE + '/modules/denuncias/ver.php?id=' + c.id;
                        a.innerHTML = '<div class="metis-sr-icon caso"><i class="bi bi-folder2-open"></i></div>' +
                            '<div class="metis-sr-main"><div class="metis-sr-title">' + escHtml(c.numero_caso) + '</div>' +
                            '<div class="metis-sr-sub">' + escHtml(c.estado || '') + (c.fecha ? ' · ' + escHtml(c.fecha) : '') + '</div></div>';
                        results.appendChild(a);
                        total++;
                    });
                }

                if (data.alumnos && data.alumnos.length > 0) {
                    var h2 = document.createElement('div');
                    h2.className = 'metis-sr-head';
                    h2.textContent = 'Alumnos';
                    results.appendChild(h2);
                    data.alumnos.forEach(function(a) {
                        var el = document.createElement('a');
                        el.className = 'metis-sr-item';
                        el.href = BASE + '/modules/alumnos/ver.php?id=' + a.id;
                        el.innerHTML = '<div class="metis-sr-icon alumno"><i class="bi bi-person-fill"></i></div>' +
                            '<div class="metis-sr-main"><div class="metis-sr-title">' + escHtml(a.nombre) + '</div>' +
                            '<div class="metis-sr-sub">RUN ' + escHtml(a.run || '-') + (a.curso ? ' · ' + escHtml(a.curso) : '') + '</div></div>';
                        results.appendChild(el);
                        total++;
                    });
                }

                if (total === 0) {
                    results.innerHTML = '<div class="metis-sr-empty"><i class="bi bi-search" style="opacity:.4;"></i> Sin resultados para "' + escHtml(input.value) + '"</div>';
                }
                results.style.display = 'block';
            }

            input.addEventListener('input', function () { doSearch(input.value.trim()); });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { results.style.display = 'none'; input.blur(); }
            });

            document.addEventListener('click', function (e) {
                if (!document.getElementById('metisSearchWrap').contains(e.target)) {
                    results.style.display = 'none';
                }
            });

            // Atajo de teclado: / abre el buscador
            document.addEventListener('keydown', function (e) {
                if (e.key === '/' && document.activeElement.tagName !== 'INPUT' &&
                    document.activeElement.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    input.focus();
                }
            });
        })();
        </script>

        <!-- ── Sidebar colapsar ──────────────────────────────── -->
        <script>
        (function () {
            var btn  = document.getElementById('sidebarCollapseBtn');
            var body = document.body;
            var KEY  = 'metis_sidebar_collapsed';

            // Restaurar estado guardado
            if (localStorage.getItem(KEY) === '1') body.classList.add('sidebar-collapsed');

            if (!btn) return;
            btn.addEventListener('click', function () {
                var collapsed = body.classList.toggle('sidebar-collapsed');
                localStorage.setItem(KEY, collapsed ? '1' : '0');
            });
        })();
        </script>

        <section class="metis-content">
