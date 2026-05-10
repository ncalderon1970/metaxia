<?php
declare(strict_types=1);

/**
 * Metis · Mi Establecimiento
 *
 * Permite al admin_colegio ver y editar los datos de contacto
 * de su establecimiento. NO muestra ni permite editar campos
 * comerciales (plan, precio, vencimiento, límites).
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$rolCodigo = (string)($user['rol_codigo'] ?? '');
$colegioId = (int)($user['colegio_id']   ?? 0);

// Solo admin_colegio y superadmin (el superadmin usa colegios/index.php normalmente)
if (!in_array($rolCodigo, ['superadmin', 'admin_colegio'], true)
    && !Auth::can('admin_sistema')
    && !Auth::can('gestionar_usuarios')) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

if ($colegioId <= 0) {
    http_response_code(400);
    exit('No tienes un establecimiento asignado.');
}

$pageTitle    = 'Mi Establecimiento';
$pageSubtitle = 'Datos de contacto e información del establecimiento';

$error = '';
$exito = '';

// ── Helpers ──────────────────────────────────────────────────
function me_clean(?string $v): string
{
    return trim(strip_tags((string)$v));
}

function me_email(?string $v): string
{
    $v = trim(strtolower((string)$v));
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
}

function me_col(PDO $pdo, string $col): bool
{
    static $cols = [
        'id', 'rbd', 'rut_entidad', 'nombre', 'logo_url', 'director_nombre', 'firma_url',
        'dependencia', 'comuna', 'region', 'direccion', 'telefono', 'email', 'activo',
        'fecha_vencimiento', 'estado_comercial', 'precio_uf_mensual', 'plan',
        'contacto_comercial', 'email_comercial', 'telefono_comercial', 'created_at', 'updated_at'
    ];

    return in_array($col, $cols, true);
}

// ── POST: guardar datos de contacto ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::requireValid($_POST['_token'] ?? null);

        $director         = me_clean($_POST['director_nombre']    ?? '');
        $contactoNombre   = me_clean($_POST['contacto_nombre']    ?? '');
        $contactoEmail    = me_email($_POST['contacto_email']     ?? '');
        $contactoTelefono = me_clean($_POST['contacto_telefono']  ?? '');
        $direccion        = me_clean($_POST['direccion']          ?? '');
        $comuna           = me_clean($_POST['comuna']             ?? '');
        $region           = me_clean($_POST['region']             ?? '');
        $email            = me_email($_POST['email']              ?? '');
        $telefono         = me_clean($_POST['telefono']           ?? '');
        $dependencia      = me_clean($_POST['dependencia']        ?? '');

        if ($director === '') {
            throw new RuntimeException('El nombre del director/a es obligatorio.');
        }

        // Construir UPDATE solo con columnas que existen
        $setCols = ['updated_at = NOW()'];
        $params  = [];

        $setCols[] = 'director_nombre = ?';   $params[] = $director;
        $setCols[] = 'direccion = ?';         $params[] = $direccion;
        $setCols[] = 'comuna = ?';            $params[] = $comuna;
        $setCols[] = 'region = ?';            $params[] = $region;
        $setCols[] = 'email = ?';             $params[] = $email;
        $setCols[] = 'telefono = ?';          $params[] = $telefono;

        if (me_col($pdo, 'contacto_comercial')) { $setCols[] = 'contacto_comercial = ?'; $params[] = $contactoNombre; }
        if (me_col($pdo, 'email_comercial')) { $setCols[] = 'email_comercial = ?'; $params[] = $contactoEmail; }
        if (me_col($pdo, 'telefono_comercial')) { $setCols[] = 'telefono_comercial = ?'; $params[] = $contactoTelefono; }
        
        if (me_col($pdo, 'dependencia'))       { $setCols[] = 'dependencia = ?';       $params[] = $dependencia; }

        $params[] = $colegioId;

        $stmt = $pdo->prepare(
            'UPDATE colegios SET ' . implode(', ', $setCols) . ' WHERE id = ?'
        );
        $stmt->execute($params);

        $exito = 'Datos del establecimiento actualizados correctamente.';

        // Actualizar colegio_nombre en sesión si cambió
        if (!empty($_SESSION['user'])) {
            // Recargar desde BD
            $stmtNombre = $pdo->prepare("SELECT nombre FROM colegios WHERE id = ? LIMIT 1");
            $stmtNombre->execute([$colegioId]);
            $_SESSION['user']['colegio_nombre'] = (string)($stmtNombre->fetchColumn() ?: '');
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Cargar datos actuales ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM colegios WHERE id = ? LIMIT 1");
$stmt->execute([$colegioId]);
$col = $stmt->fetch() ?: [];

$pageHeaderActions = metis_context_actions([
    metis_context_action('Administración', APP_URL . '/modules/admin/index.php', 'bi-gear', 'secondary'),
    metis_context_action('Usuarios', APP_URL . '/modules/admin/usuarios_colegio.php', 'bi-people-fill', 'secondary'),
    metis_context_action('Dashboard', APP_URL . '/modules/dashboard/index.php', 'bi-speedometer2', 'primary'),
]);

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.me-wrap         { max-width: 820px; margin: 0 auto; padding: 0 0 3rem; }
.me-card         { background: #fff; border: 1px solid #e3e8ef; border-radius: 12px;
                   padding: 1.75rem 2rem; margin-bottom: 1.5rem; }
.me-card-title   { font-size: .95rem; font-weight: 700; color: #1a3a5c;
                   display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem; }
.me-alert-ok     { background: #d4edda; color: #155724; padding: .7rem 1rem;
                   border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
.me-alert-err    { background: #f8d7da; color: #721c24; padding: .7rem 1rem;
                   border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
.me-readonly     { background: #f8fafd; border: 1px solid #e3e8ef; border-radius: 8px;
                   padding: .85rem 1rem; margin-bottom: 1.2rem; }
.me-readonly-lbl { font-size: .72rem; font-weight: 700; color: #888; text-transform: uppercase;
                   letter-spacing: .04em; margin-bottom: .25rem; }
.me-readonly-val { font-size: .9rem; color: #1a3a5c; font-weight: 600; }
.me-readonly-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: .75rem; }
.me-form-grid    { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; }
.me-form-grid .full { grid-column: 1 / -1; }
.me-label        { display: block; font-size: .78rem; font-weight: 600; color: #444; margin-bottom: .3rem; }
.me-control      { width: 100%; padding: .48rem .7rem; border: 1px solid #cdd5e0;
                   border-radius: 7px; font-size: .85rem; box-sizing: border-box; background: #fff; }
.me-control:focus{ outline: none; border-color: #1a3a5c; box-shadow: 0 0 0 3px rgba(26,58,92,.1); }
.me-help         { font-size: .72rem; color: #888; margin-top: .25rem; }
.me-submit       { background: #1a3a5c; color: #fff; border: none; border-radius: 8px;
                   padding: .6rem 1.5rem; font-size: .86rem; font-weight: 700; cursor: pointer; }
.me-submit:hover { background: #14304f; }
.me-section-sep  { border: none; border-top: 1px solid #e8ecf1; margin: 1.25rem 0; }
.me-badge-rbd    { display: inline-block; background: #e8f0fe; color: #1a3a5c;
                   border-radius: 6px; padding: .15rem .6rem; font-size: .75rem;
                   font-weight: 700; margin-left: .4rem; }
</style>

<div class="me-wrap">

    <?php if ($exito !== ''): ?>
        <div class="me-alert-ok"><i class="bi bi-check-circle-fill"></i> <?= e($exito) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="me-alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <!-- ── Datos de solo lectura (no editables por admin_colegio) ── -->
    <div class="me-card">
        <div class="me-card-title">
            <i class="bi bi-building"></i>
            Identificación del establecimiento
            <small style="font-weight:400;color:#888;font-size:.78rem;">Solo lectura · Administrado por el sistema</small>
        </div>

        <div class="me-readonly-row">
            <div class="me-readonly">
                <div class="me-readonly-lbl">Nombre del establecimiento</div>
                <div class="me-readonly-val"><?= e((string)($col['nombre'] ?? '—')) ?></div>
            </div>
            <div class="me-readonly">
                <div class="me-readonly-lbl">RBD</div>
                <div class="me-readonly-val"><?= e((string)($col['rbd'] ?? '—')) ?></div>
            </div>
        </div>

        <div class="me-readonly-row">
            <?php if (!empty($col['rut_entidad'])): ?>
            <div class="me-readonly">
                <div class="me-readonly-lbl">RUT Entidad</div>
                <div class="me-readonly-val"><?= e((string)$col['rut_entidad']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div style="font-size:.75rem;color:#aaa;">
            <i class="bi bi-info-circle"></i>
            Para modificar la razón social, RBD o datos del sostenedor, comunícate con el administrador del sistema.
        </div>
    </div>

    <!-- ── Formulario de datos editables ── -->
    <div class="me-card">
        <div class="me-card-title">
            <i class="bi bi-pencil-square"></i>
            Datos de contacto y ubicación
        </div>

        <form method="post">
            <?= CSRF::field() ?>

            <div class="me-form-grid">

                <div class="full">
                    <label class="me-label" for="meDirector">
                        Director/a <span style="color:#c0392b">*</span>
                    </label>
                    <input class="me-control" type="text" id="meDirector" name="director_nombre"
                           value="<?= e((string)($col['director_nombre'] ?? '')) ?>"
                           placeholder="Nombre completo del Director/a">
                </div>

                <div>
                    <label class="me-label" for="meTelefono">Teléfono del establecimiento</label>
                    <input class="me-control" type="text" id="meTelefono" name="telefono"
                           value="<?= e((string)($col['telefono'] ?? '')) ?>"
                           placeholder="+56 2 2345 6789">
                </div>

                <div>
                    <label class="me-label" for="meEmail">Correo del establecimiento</label>
                    <input class="me-control" type="email" id="meEmail" name="email"
                           value="<?= e((string)($col['email'] ?? '')) ?>"
                           placeholder="contacto@colegio.cl">
                </div>

                <hr class="me-section-sep full">

                <div class="full">
                    <label class="me-label" for="meDireccion">Dirección</label>
                    <input class="me-control" type="text" id="meDireccion" name="direccion"
                           value="<?= e((string)($col['direccion'] ?? '')) ?>"
                           placeholder="Av. Ejemplo 123">
                </div>

                <div>
                    <label class="me-label" for="meComuna">Comuna</label>
                    <input class="me-control" type="text" id="meComuna" name="comuna"
                           value="<?= e((string)($col['comuna'] ?? '')) ?>"
                           placeholder="Rancagua">
                </div>

                <div>
                    <label class="me-label" for="meRegion">Región</label>
                    <input class="me-control" type="text" id="meRegion" name="region"
                           value="<?= e((string)($col['region'] ?? '')) ?>"
                           placeholder="O'Higgins">
                </div>

                <?php if (me_col($pdo, 'dependencia')): ?>
                <div>
                    <label class="me-label" for="meDependencia">Dependencia</label>
                    <select class="me-control" id="meDependencia" name="dependencia">
                        <?php
                        $depActual = (string)($col['dependencia'] ?? '');
                        $deps = ['' => '— Seleccione —', 'municipal' => 'Municipal', 'particular_subvencionado' => 'Particular Subvencionado', 'particular_pagado' => 'Particular Pagado', 'corporacion' => 'Corporación Municipal'];
                        foreach ($deps as $val => $lbl):
                        ?>
                            <option value="<?= e($val) ?>" <?= $depActual === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <hr class="me-section-sep full">

                <?php if (me_col($pdo, 'contacto_comercial')): ?>
                <div class="full" style="font-size:.8rem;font-weight:700;color:#1a3a5c;margin-bottom:-.4rem;">
                    <i class="bi bi-person-lines-fill"></i> Contacto administrativo
                </div>

                <div>
                    <label class="me-label" for="meContactoNombre">Nombre del contacto</label>
                    <input class="me-control" type="text" id="meContactoNombre" name="contacto_nombre"
                           value="<?= e((string)($col['contacto_comercial'] ?? '')) ?>"
                           placeholder="Secretaría, Jefe UTP, etc.">
                </div>
                <?php endif; ?>

                <?php if (me_col($pdo, 'email_comercial')): ?>
                <div>
                    <label class="me-label" for="meContactoEmail">Email del contacto</label>
                    <input class="me-control" type="email" id="meContactoEmail" name="contacto_email"
                           value="<?= e((string)($col['email_comercial'] ?? '')) ?>"
                           placeholder="secretaria@colegio.cl">
                </div>
                <?php endif; ?>

                <?php if (me_col($pdo, 'telefono_comercial')): ?>
                <div>
                    <label class="me-label" for="meContactoTelefono">Teléfono del contacto</label>
                    <input class="me-control" type="text" id="meContactoTelefono" name="contacto_telefono"
                           value="<?= e((string)($col['telefono_comercial'] ?? '')) ?>"
                           placeholder="+56 9 1234 5678">
                </div>
                <?php endif; ?>

            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:1.5rem;">
                <button type="submit" class="me-submit">
                    <i class="bi bi-check-circle-fill"></i> Guardar cambios
                </button>
            </div>
        </form>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
