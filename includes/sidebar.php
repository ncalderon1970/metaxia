<?php
declare(strict_types=1);

$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');
$nombreUsuario = (string)($user['nombre'] ?? 'Usuario');
$colegioNombre = (string)($user['colegio_nombre'] ?? 'Metis');

$esAdmin = in_array($rolCodigo, ['superadmin', 'director'], true);

$totalAlertasSidebar = 0;

try {
    $pdoSidebar = DB::conn();

    $existeAlertas = $pdoSidebar
        ->query("SHOW TABLES LIKE 'caso_alertas'")
        ->fetchColumn();

    if ($existeAlertas && !empty($user['colegio_id'])) {
        $stmtAlertas = $pdoSidebar->prepare("
            SELECT COUNT(*)
            FROM caso_alertas a
            INNER JOIN casos c ON c.id = a.caso_id
            WHERE c.colegio_id = ?
              AND a.estado = 'pendiente'
        ");

        $stmtAlertas->execute([(int)$user['colegio_id']]);
        $totalAlertasSidebar = (int)$stmtAlertas->fetchColumn();
    }
} catch (Throwable $e) {
    $totalAlertasSidebar = 0;
}

$grupoComunidadAbierto = metis_open_group([
    '/modules/alumnos/',
    '/modules/docentes/',
    '/modules/asistentes/',
    '/modules/apoderados/',
]);

$grupoAdminAbierto = metis_open_group([
    '/modules/admin/',
    '/modules/importar/',
    '/modules/roles/',
    '/modules/denuncias/bitacora.php',
]);
?>

<aside class="metis-sidebar">

    <div class="metis-brand">
        <div class="metis-brand__mark">M</div>
        <div>
            <div class="metis-brand__name">Metis</div>
            <div class="metis-brand__sub">Convivencia escolar</div>
        </div>
    </div>

    <nav class="metis-nav">

        <span class="metis-nav__section">Principal</span>

        <a class="metis-nav__link<?= metis_active('/modules/dashboard/') ?>"
           href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <span class="metis-nav__section">Convivencia</span>

        <a class="metis-nav__link<?= metis_active('/modules/denuncias/') ?>"
           href="<?= APP_URL ?>/modules/denuncias/index.php">
            <i class="bi bi-megaphone"></i>
            <span>Denuncias</span>
        </a>

        <a class="metis-nav__link<?= metis_active('/modules/seguimiento/') ?>"
           href="<?= APP_URL ?>/modules/seguimiento/index.php">
            <i class="bi bi-clipboard2-check"></i>
            <span>Seguimiento</span>
        </a>

        <a class="metis-nav__link<?= metis_active('/modules/alertas/') ?>"
           href="<?= APP_URL ?>/modules/alertas/index.php">
            <i class="bi bi-bell"></i>
            <span>Alertas</span>

            <?php if ($totalAlertasSidebar > 0): ?>
                <span class="metis-nav__badge"><?= (int)$totalAlertasSidebar ?></span>
            <?php endif; ?>
        </a>

        <hr class="metis-nav__divider">

        <div class="metis-nav__group">
            <button
                type="button"
                class="metis-nav__group-toggle<?= $grupoComunidadAbierto ?>"
                data-metis-group-toggle="grupo-comunidad"
            >
                <i class="bi bi-people"></i>
                <span>Comunidad</span>
                <i class="bi bi-chevron-right"></i>
            </button>

            <div id="grupo-comunidad" class="metis-nav__group-items<?= $grupoComunidadAbierto ?>">
                <a class="metis-nav__link<?= metis_active('/modules/alumnos/') ?>"
                   href="<?= APP_URL ?>/modules/alumnos/index.php">
                    <i class="bi bi-mortarboard"></i>
                    <span>Alumnos</span>
                </a>

                <a class="metis-nav__link<?= metis_active('/modules/apoderados/') ?>"
                   href="<?= APP_URL ?>/modules/apoderados/index.php">
                    <i class="bi bi-house-heart"></i>
                    <span>Apoderados</span>
                </a>

                <a class="metis-nav__link<?= metis_active('/modules/docentes/') ?>"
                   href="<?= APP_URL ?>/modules/docentes/index.php">
                    <i class="bi bi-person-workspace"></i>
                    <span>Docentes</span>
                </a>

                <a class="metis-nav__link<?= metis_active('/modules/asistentes/') ?>"
                   href="<?= APP_URL ?>/modules/asistentes/index.php">
                    <i class="bi bi-person-gear"></i>
                    <span>Asistentes</span>
                </a>
            </div>
        </div>

        <span class="metis-nav__section">Gestión</span>

        <a class="metis-nav__link<?= metis_active('/modules/reportes/') ?>"
           href="<?= APP_URL ?>/modules/reportes/index.php">
            <i class="bi bi-bar-chart"></i>
            <span>Reportes</span>
        </a>

        <a class="metis-nav__link<?= metis_active('/modules/informes/') ?>"
           href="<?= APP_URL ?>/modules/informes/informe_caso.php">
            <i class="bi bi-file-earmark-text"></i>
            <span>Informes</span>
        </a>

        <a class="metis-nav__link<?= metis_active('/modules/financiero/') ?>"
           href="<?= APP_URL ?>/modules/financiero/index.php">
            <i class="bi bi-kanban"></i>
            <span>Módulos / Plan</span>
        </a>

        <?php if ($esAdmin): ?>
            <hr class="metis-nav__divider">

            <div class="metis-nav__group">
                <button
                    type="button"
                    class="metis-nav__group-toggle<?= $grupoAdminAbierto ?>"
                    data-metis-group-toggle="grupo-admin"
                >
                    <i class="bi bi-gear"></i>
                    <span>Administración</span>
                    <i class="bi bi-chevron-right"></i>
                </button>

                <div id="grupo-admin" class="metis-nav__group-items<?= $grupoAdminAbierto ?>">
                    <a class="metis-nav__link<?= metis_active('/modules/admin/') ?>"
                       href="<?= APP_URL ?>/modules/admin/index.php">
                        <i class="bi bi-sliders"></i>
                        <span>Configuración</span>
                    </a>

                    <a class="metis-nav__link<?= metis_active('/modules/importar/') ?>"
                       href="<?= APP_URL ?>/modules/importar/index.php">
                        <i class="bi bi-file-earmark-arrow-up"></i>
                        <span>Importar datos</span>
                    </a>

                    <a class="metis-nav__link<?= metis_active('/modules/roles/') ?>"
                       href="<?= APP_URL ?>/modules/roles/index.php">
                        <i class="bi bi-shield-lock"></i>
                        <span>Roles</span>
                    </a>

                    <a class="metis-nav__link<?= metis_active('/modules/denuncias/bitacora.php') ?>"
                       href="<?= APP_URL ?>/modules/denuncias/bitacora.php">
                        <i class="bi bi-journal-text"></i>
                        <span>Bitácora</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </nav>

    <div class="metis-sidebar-footer">
        <div class="metis-user-card">
            <div class="metis-user-card__avatar">
                <?= e(metis_user_initials($nombreUsuario)) ?>
            </div>

            <div class="metis-user-card__body">
                <div class="metis-user-card__name"><?= e($nombreUsuario) ?></div>
                <div class="metis-user-card__role">
                    <?= e($rolCodigo !== '' ? $rolCodigo : 'usuario') ?>
                </div>
            </div>
        </div>

        <div class="metis-logout">
            <a href="<?= APP_URL ?>/logout.php">
                <i class="bi bi-box-arrow-left"></i>
                <span>Cerrar sesión</span>
            </a>
        </div>
    </div>

</aside>