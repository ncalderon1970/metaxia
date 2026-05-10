<?php
declare(strict_types=1);

/**
 * Metis · Gestión de Usuarios del Establecimiento
 *
 * Accesible para:
 *   - superadmin (ve todos)
 *   - admin_colegio (ve y gestiona SOLO su colegio)
 *
 * Restricciones de admin_colegio:
 *   - No puede crear superadmin
 *   - No puede crear admin_colegio
 *   - Solo crea usuarios dentro de su colegio_id
 *   - No puede editar ni eliminar usuarios de otros colegios
 *   - No puede cambiar colegio_id de un usuario existente
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo           = DB::conn();
$user          = Auth::user() ?? [];
$rolActual     = (string)($user['rol_codigo'] ?? '');
$colegioId     = (int)($user['colegio_id']   ?? 0);
$userId        = (int)($user['id']            ?? 0);

// ── Control de acceso ────────────────────────────────────────
$esSuperAdmin   = $rolActual === 'superadmin';
// Acepta por código de rol O por permiso — doble seguro
$esAdminColegio = $rolActual === 'admin_colegio'
               || Auth::can('gestionar_usuarios')
               || Auth::can('admin_sistema');

if (!$esSuperAdmin && !$esAdminColegio) {
    http_response_code(403);
    exit('Acceso no autorizado. Tu rol (' . e($rolActual) . ') no tiene permiso para gestionar usuarios.');
}

// Roles que el admin_colegio NO puede asignar
$rolesRestringidos = ['superadmin', 'admin_colegio'];

$pageTitle    = 'Usuarios del Establecimiento';
$pageSubtitle = $esSuperAdmin
    ? 'Administración global de usuarios y roles'
    : 'Usuarios del establecimiento · ' . e((string)($user['colegio_nombre'] ?? ''));

$error = '';
$exito = '';

// ── Helpers ──────────────────────────────────────────────────
function ug_col(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $k = "$table.$col";
    if (isset($cache[$k])) return $cache[$k];
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $s->execute([$table, $col]);
        return $cache[$k] = (bool)$s->fetchColumn();
    } catch (Throwable $e) { return $cache[$k] = false; }
}

function ug_puede_gestionar_usuario(array $targetUser, int $miColegioId, bool $esSuperAdmin): bool
{
    if ($esSuperAdmin) return true;
    return (int)($targetUser['colegio_id'] ?? 0) === $miColegioId;
}

// ── Cargar roles disponibles según perfil ───────────────────
if ($esSuperAdmin) {
    $stmtRoles = $pdo->query("SELECT id, codigo, nombre FROM roles ORDER BY id ASC");
    $roles = $stmtRoles->fetchAll();
} else {
    $stmtRoles = $pdo->prepare(
        "SELECT id, codigo, nombre FROM roles WHERE codigo NOT IN ('superadmin','admin_colegio') ORDER BY id ASC"
    );
    $stmtRoles->execute();
    $roles = $stmtRoles->fetchAll();
}
$rolesVacios = empty($roles);

// ── Cargar colegios (solo superadmin puede cambiar colegio) ─
$colegios = [];
if ($esSuperAdmin) {
    $colegios = $pdo->query("SELECT id, nombre FROM colegios WHERE activo=1 ORDER BY nombre ASC")->fetchAll();
}

// ────────────────────────────────────────────────────────────
// POST: procesar acciones
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        // ── Crear usuario ────────────────────────────────────
        if ($accion === 'crear_usuario') {
            $nombre   = clean((string)($_POST['nombre']   ?? ''));
            $email    = mb_strtolower(trim((string)($_POST['email']    ?? '')));
            $rolId    = (int)($_POST['rol_id']   ?? 0);
            $password = (string)($_POST['password'] ?? '');
            $run      = clean((string)($_POST['run'] ?? ''));

            // admin_colegio siempre asigna su propio colegio
            $targetColegioId = $esSuperAdmin
                ? (int)($_POST['colegio_id'] ?? $colegioId)
                : $colegioId;

            if ($nombre === '')
                throw new RuntimeException('El nombre es obligatorio.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                throw new RuntimeException('Email inválido: ' . e($email));
            if ($rolId <= 0)
                throw new RuntimeException('Debes seleccionar un rol. Si no aparecen opciones, ejecuta el SQL de datos semilla (38K2I_semilla_completa.sql).');
            if (strlen($password) < 8)
                throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            if ($targetColegioId <= 0)
                throw new RuntimeException('No se pudo determinar el colegio del usuario. Verifica que tu cuenta tenga colegio_id asignado.');

            // Verificar que el rol existe en BD
            $stmtRolExiste = $pdo->prepare("SELECT id FROM roles WHERE id = ? LIMIT 1");
            $stmtRolExiste->execute([$rolId]);
            if (!$stmtRolExiste->fetchColumn())
                throw new RuntimeException("El rol seleccionado (id={$rolId}) no existe en la base de datos.");

            // Verificar que el colegio existe
            $stmtColExiste = $pdo->prepare("SELECT id FROM colegios WHERE id = ? AND activo = 1 LIMIT 1");
            $stmtColExiste->execute([$targetColegioId]);
            if (!$stmtColExiste->fetchColumn())
                throw new RuntimeException("El establecimiento (id={$targetColegioId}) no existe o está inactivo.");

            // Validar que admin_colegio no asigne roles restringidos
            if (!$esSuperAdmin) {
                $stmtRol = $pdo->prepare("SELECT codigo FROM roles WHERE id = ? LIMIT 1");
                $stmtRol->execute([$rolId]);
                $rolCodigo = (string)($stmtRol->fetchColumn() ?: '');
                if (in_array($rolCodigo, $rolesRestringidos, true)) {
                    throw new RuntimeException('No tienes permiso para asignar ese rol.');
                }
            }

            // Verificar email único
            $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetchColumn()) {
                throw new RuntimeException('Ya existe un usuario con ese email.');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $cols   = ['colegio_id','rol_id','nombre','email','password_hash','activo','created_at','updated_at'];
            $vals   = ['?','?','?','?','?','1','NOW()','NOW()'];
            $params = [$targetColegioId, $rolId, $nombre, $email, $hash];

            if ($run !== '' && ug_col($pdo, 'usuarios', 'run')) {
                $cols[]   = 'run';
                $vals[]   = '?';
                $params[] = $run;
            }

            $sql = 'INSERT INTO usuarios (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
            $stmt = $pdo->prepare($sql);
            if (!$stmt->execute($params)) {
                $info = $stmt->errorInfo();
                throw new RuntimeException('Error SQL al crear usuario: ' . ($info[2] ?? 'desconocido'));
            }

            $exito = 'Usuario <strong>' . e($nombre) . '</strong> creado correctamente.';
        }

        // ── Editar usuario ───────────────────────────────────
        if ($accion === 'editar_usuario') {
            $targetId = (int)($_POST['usuario_id'] ?? 0);

            // Cargar usuario target para validar colegio
            $stmtT = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
            $stmtT->execute([$targetId]);
            $target = $stmtT->fetch();

            if (!$target) throw new RuntimeException('Usuario no encontrado.');
            if (!ug_puede_gestionar_usuario($target, $colegioId, $esSuperAdmin)) {
                throw new RuntimeException('No puedes editar usuarios de otro establecimiento.');
            }

            $nombre  = clean((string)($_POST['nombre']  ?? ''));
            $rolId   = (int)($_POST['rol_id']  ?? 0);
            $activo  = (int)($_POST['activo']  ?? 1);
            $run     = clean((string)($_POST['run'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($nombre === '') throw new RuntimeException('El nombre es obligatorio.');
            if ($rolId <= 0)   throw new RuntimeException('Debes seleccionar un rol.');

            // Validar que admin_colegio no asigne roles restringidos
            if (!$esSuperAdmin) {
                $stmtRol = $pdo->prepare("SELECT codigo FROM roles WHERE id = ? LIMIT 1");
                $stmtRol->execute([$rolId]);
                $rolCodigo = (string)($stmtRol->fetchColumn() ?: '');
                if (in_array($rolCodigo, $rolesRestringidos, true)) {
                    throw new RuntimeException('No tienes permiso para asignar ese rol.');
                }
            }

            $setCols  = ['nombre = ?', 'rol_id = ?', 'activo = ?', 'updated_at = NOW()'];
            $params   = [$nombre, $rolId, $activo];

            if ($run !== '' && ug_col($pdo, 'usuarios', 'run')) {
                $setCols[] = 'run = ?';
                $params[]  = $run;
            }

            if ($password !== '') {
                if (strlen($password) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
                $setCols[] = 'password_hash = ?';
                $params[]  = password_hash($password, PASSWORD_BCRYPT);
            }

            // superadmin puede cambiar de colegio
            if ($esSuperAdmin && !empty($_POST['colegio_id'])) {
                $setCols[] = 'colegio_id = ?';
                $params[]  = (int)$_POST['colegio_id'];
            }

            $params[] = $targetId;
            $stmt = $pdo->prepare(
                'UPDATE usuarios SET ' . implode(', ', $setCols) . ' WHERE id = ?'
            );
            $stmt->execute($params);

            $exito = 'Usuario actualizado correctamente.';
        }

        // ── Activar / desactivar usuario ─────────────────────
        if ($accion === 'toggle_usuario') {
            $targetId = (int)($_POST['usuario_id'] ?? 0);
            if ($targetId === $userId) throw new RuntimeException('No puedes desactivarte a ti mismo.');

            $stmtT = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
            $stmtT->execute([$targetId]);
            $target = $stmtT->fetch();

            if (!$target) throw new RuntimeException('Usuario no encontrado.');
            if (!ug_puede_gestionar_usuario($target, $colegioId, $esSuperAdmin)) {
                throw new RuntimeException('No puedes modificar usuarios de otro establecimiento.');
            }

            $nuevoEstado = ((int)$target['activo'] === 1) ? 0 : 1;
            $pdo->prepare("UPDATE usuarios SET activo = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$nuevoEstado, $targetId]);

            $exito = 'Estado del usuario actualizado.';
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Cargar usuarios ──────────────────────────────────────────
$whereUsers = $esSuperAdmin ? '' : 'AND u.colegio_id = ' . $colegioId;
$hasUltAcceso = ug_col($pdo, 'usuarios', 'ultimo_acceso');
$selUltAcceso = $hasUltAcceso ? 'u.ultimo_acceso,' : '';

$usuarios = $pdo->query("
    SELECT
        u.id, u.colegio_id, u.rol_id, u.nombre, u.email, u.activo,
        u.run,
        {$selUltAcceso}
        u.created_at,
        r.codigo  AS rol_codigo,
        r.nombre  AS rol_nombre,
        c.nombre  AS colegio_nombre
    FROM usuarios u
    INNER JOIN roles r   ON r.id = u.rol_id
    LEFT JOIN  colegios c ON c.id = u.colegio_id
    WHERE 1=1 {$whereUsers}
    ORDER BY u.colegio_id ASC, r.id ASC, u.nombre ASC
")->fetchAll();

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.ug-wrap          { padding: 0 0 3rem; }
.ug-toolbar       { display:flex; justify-content:space-between; align-items:center;
                    margin-bottom:1.25rem; flex-wrap:wrap; gap:.75rem; }
.ug-toolbar-title { font-size:.95rem; font-weight:700; color:#1a3a5c; }
.ug-btn-new       { background:#1a3a5c; color:#fff; border:none; border-radius:8px;
                    padding:.55rem 1.2rem; font-size:.84rem; font-weight:700;
                    cursor:pointer; display:flex; align-items:center; gap:.4rem; }
.ug-btn-new:hover { background:#14304f; }

/* Tabla */
.ug-table-wrap    { overflow-x:auto; }
.ug-table         { width:100%; border-collapse:collapse; font-size:.83rem; }
.ug-table th      { background:#1a3a5c; color:#fff; padding:.55rem .8rem;
                    text-align:left; font-size:.78rem; font-weight:700; white-space:nowrap; }
.ug-table td      { padding:.6rem .8rem; border-bottom:1px solid #edf0f5; vertical-align:middle; }
.ug-table tr:hover td { background:#f5f8fc; }

/* Badges rol */
.ug-rol           { display:inline-block; padding:.12rem .55rem; border-radius:20px;
                    font-size:.7rem; font-weight:700; }
.ug-rol-superadmin   { background:#fde8d8; color:#7a3b0a; }
.ug-rol-admin_colegio{ background:#e0e7ff; color:#3730a3; }
.ug-rol-director     { background:#cce5ff; color:#004085; }
.ug-rol-convivencia  { background:#d4edda; color:#155724; }
.ug-rol-consulta     { background:#e2e3e5; color:#383d41; }
.ug-rol-default      { background:#f1f3f5; color:#555; }

/* Estado */
.ug-activo   { color:#27ae60; font-weight:700; font-size:.78rem; }
.ug-inactivo { color:#c0392b; font-weight:700; font-size:.78rem; }

/* Acciones fila */
.ug-row-actions  { display:flex; gap:.35rem; }
.ug-btn-edit     { background:#e8f0fe; color:#1a3a5c; border:none; border-radius:6px;
                   padding:.28rem .6rem; font-size:.72rem; cursor:pointer; font-weight:600; }
.ug-btn-edit:hover { background:#c8d9f5; }
.ug-btn-toggle   { background:#fff3cd; color:#856404; border:none; border-radius:6px;
                   padding:.28rem .6rem; font-size:.72rem; cursor:pointer; font-weight:600; }
.ug-btn-toggle.inactivo { background:#fdecea; color:#c0392b; }
.ug-btn-toggle:hover { opacity:.85; }

/* Modal */
.ug-modal-bg     { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
                   z-index:1050; align-items:center; justify-content:center; }
.ug-modal-bg.open{ display:flex; }
.ug-modal        { background:#fff; border-radius:14px; padding:2rem;
                   width:min(560px,96vw); max-height:90vh; overflow-y:auto;
                   box-shadow:0 8px 40px rgba(0,0,0,.22); }
.ug-modal-title  { font-size:1rem; font-weight:700; color:#1a3a5c; margin-bottom:1.2rem;
                   display:flex; justify-content:space-between; align-items:center; }
.ug-modal-close  { background:none; border:none; font-size:1.4rem; color:#888;
                   cursor:pointer; line-height:1; }
.ug-form-grid    { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
.ug-form-grid .full { grid-column:1/-1; }
.ug-label        { display:block; font-size:.78rem; font-weight:600; color:#444; margin-bottom:.3rem; }
.ug-control      { width:100%; padding:.45rem .65rem; border:1px solid #cdd5e0;
                   border-radius:7px; font-size:.83rem; box-sizing:border-box; }
.ug-control:focus{ outline:none; border-color:#1a3a5c; box-shadow:0 0 0 3px rgba(26,58,92,.1); }
.ug-form-footer  { display:flex; justify-content:flex-end; gap:.65rem; margin-top:1.25rem; }
.ug-btn-cancel   { background:#f1f3f5; border:1px solid #dee2e6; color:#555;
                   border-radius:7px; padding:.5rem 1rem; font-size:.83rem; cursor:pointer; }
.ug-btn-save     { background:#1a3a5c; color:#fff; border:none; border-radius:7px;
                   padding:.5rem 1.2rem; font-size:.83rem; font-weight:700; cursor:pointer; }
.ug-btn-save:hover { background:#14304f; }
.ug-help         { font-size:.73rem; color:#888; margin-top:.25rem; }

/* Alerta */
.ug-alert-ok  { background:#d4edda; color:#155724; padding:.7rem 1rem;
                border-radius:8px; font-size:.85rem; margin-bottom:1rem; }
.ug-alert-err { background:#f8d7da; color:#721c24; padding:.7rem 1rem;
                border-radius:8px; font-size:.85rem; margin-bottom:1rem; }
.ug-scope-info { background:#e8f0fe; color:#1a3a5c; padding:.65rem 1rem;
                 border-radius:8px; font-size:.82rem; margin-bottom:1rem;
                 display:flex; gap:.5rem; align-items:center; }
</style>

<div class="ug-wrap">

    <?php if ($exito !== ''): ?>
        <div class="ug-alert-ok"><i class="bi bi-check-circle-fill"></i> <?= $exito ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="ug-alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$esSuperAdmin): ?>
        <div class="ug-scope-info">
            <i class="bi bi-building"></i>
            Estás gestionando únicamente los usuarios de
            <strong>&nbsp;<?= e((string)($user['colegio_nombre'] ?? '')) ?></strong>.
            No puedes ver ni modificar usuarios de otros establecimientos.
        </div>
    <?php endif; ?>

    <?php if ($rolesVacios): ?>
        <div class="ug-alert-err">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong>No hay roles disponibles para asignar.</strong>
            Ejecuta el SQL <code>38K2I_semilla_completa.sql</code> en phpMyAdmin para cargar los roles del sistema
            (Director, Encargado de Convivencia, Consulta).
        </div>
    <?php endif; ?>

    <div class="ug-toolbar">
        <span class="ug-toolbar-title">
            <i class="bi bi-people-fill"></i>&nbsp;
            <?= count($usuarios) ?> usuario(s) registrado(s)
        </span>
        <button type="button" class="ug-btn-new" id="ugBtnNuevo">
            <i class="bi bi-person-plus-fill"></i> Nuevo usuario
        </button>
    </div>

    <div class="ug-table-wrap">
        <table class="ug-table">
            <thead>
                <tr>
                    <?php if ($esSuperAdmin): ?><th>Establecimiento</th><?php endif; ?>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>RUN</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <?php if ($hasUltAcceso): ?><th>Último acceso</th><?php endif; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u):
                $esYo      = (int)$u['id'] === $userId;
                $rolCls    = 'ug-rol-' . ($u['rol_codigo'] ?? 'default');
                $puedeEdit = ug_puede_gestionar_usuario($u, $colegioId, $esSuperAdmin);
            ?>
                <tr>
                    <?php if ($esSuperAdmin): ?>
                        <td><?= e((string)($u['colegio_nombre'] ?? '—')) ?></td>
                    <?php endif; ?>
                    <td>
                        <strong><?= e((string)$u['nombre']) ?></strong>
                        <?php if ($esYo): ?>
                            <span style="font-size:.7rem;color:#888;"> (tú)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string)$u['email']) ?></td>
                    <td><?= e((string)($u['run'] ?? '—')) ?></td>
                    <td>
                        <span class="ug-rol <?= e(isset($u['rol_codigo']) ? 'ug-rol-'.$u['rol_codigo'] : 'ug-rol-default') ?>">
                            <?= e((string)$u['rol_nombre']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="<?= (int)$u['activo'] === 1 ? 'ug-activo' : 'ug-inactivo' ?>">
                            <?= (int)$u['activo'] === 1 ? '● Activo' : '● Inactivo' ?>
                        </span>
                    </td>
                    <?php if ($hasUltAcceso): ?>
                        <td style="font-size:.75rem;color:#888;">
                            <?= !empty($u['ultimo_acceso']) ? date('d-m-Y H:i', strtotime((string)$u['ultimo_acceso'])) : '—' ?>
                        </td>
                    <?php endif; ?>
                    <td>
                        <?php if ($puedeEdit): ?>
                            <div class="ug-row-actions">
                                <button type="button" class="ug-btn-edit"
                                    data-id="<?= (int)$u['id'] ?>"
                                    data-nombre="<?= e(htmlspecialchars((string)$u['nombre'], ENT_QUOTES)) ?>"
                                    data-email="<?= e(htmlspecialchars((string)$u['email'], ENT_QUOTES)) ?>"
                                    data-run="<?= e(htmlspecialchars((string)($u['run'] ?? ''), ENT_QUOTES)) ?>"
                                    data-rol="<?= (int)$u['rol_id'] ?>"
                                    data-colegio="<?= (int)$u['colegio_id'] ?>"
                                    data-activo="<?= (int)$u['activo'] ?>"
                                ><i class="bi bi-pencil"></i> Editar</button>

                                <?php if (!$esYo): ?>
                                    <form method="post" style="display:inline">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="_accion"    value="toggle_usuario">
                                        <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit"
                                            class="ug-btn-toggle <?= (int)$u['activo'] === 0 ? 'inactivo' : '' ?>">
                                            <?= (int)$u['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span style="font-size:.75rem;color:#aaa;">Sin acceso</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modal Nuevo / Editar usuario ──────────────────────── -->
<div class="ug-modal-bg" id="ugModalBg">
    <div class="ug-modal">
        <div class="ug-modal-title">
            <span id="ugModalTitle">Nuevo usuario</span>
            <button type="button" class="ug-modal-close" id="ugModalClose">&times;</button>
        </div>

        <form method="post" id="ugForm">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion"    id="ugAccion"    value="crear_usuario">
            <input type="hidden" name="usuario_id" id="ugUsuarioId" value="0">

            <div class="ug-form-grid">

                <div class="full">
                    <label class="ug-label" for="ugNombre">Nombre completo <span style="color:#c0392b">*</span></label>
                    <input class="ug-control" type="text" id="ugNombre" name="nombre"
                           placeholder="Ej: María González Pérez" autocomplete="off">
                </div>

                <div>
                    <label class="ug-label" for="ugEmail">Email <span style="color:#c0392b">*</span></label>
                    <input class="ug-control" type="email" id="ugEmail" name="email"
                           placeholder="usuario@colegio.cl" autocomplete="off">
                </div>

                <div>
                    <label class="ug-label" for="ugRun">RUN</label>
                    <input class="ug-control" type="text" id="ugRun" name="run"
                           placeholder="12.345.678-9" autocomplete="off">
                </div>

                <div>
                    <label class="ug-label" for="ugRol">Rol <span style="color:#c0392b">*</span></label>
                    <select class="ug-control" id="ugRol" name="rol_id">
                        <option value="">— Seleccione —</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"><?= e((string)$r['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($esSuperAdmin && $colegios): ?>
                <div>
                    <label class="ug-label" for="ugColegio">Establecimiento</label>
                    <select class="ug-control" id="ugColegio" name="colegio_id">
                        <?php foreach ($colegios as $col): ?>
                            <option value="<?= (int)$col['id'] ?>"><?= e((string)$col['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <label class="ug-label" for="ugActivo">Estado</label>
                    <select class="ug-control" id="ugActivo" name="activo">
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </select>
                </div>

                <div class="full">
                    <label class="ug-label" for="ugPassword">
                        Contraseña <span id="ugPassReq" style="color:#c0392b">*</span>
                    </label>
                    <input class="ug-control" type="password" id="ugPassword" name="password"
                           placeholder="Mínimo 8 caracteres" autocomplete="new-password">
                    <div class="ug-help" id="ugPassHelp">
                        Al editar, déjala en blanco para no cambiarla.
                    </div>
                </div>

            </div>

            <div class="ug-form-footer">
                <button type="button" class="ug-btn-cancel" id="ugBtnCancel">Cancelar</button>
                <button type="submit" class="ug-btn-save">
                    <i class="bi bi-check-circle"></i> Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';
    var bg    = document.getElementById('ugModalBg');
    var title = document.getElementById('ugModalTitle');

    function open(datos) {
        var editando = datos.id > 0;
        title.textContent = editando ? 'Editar usuario' : 'Nuevo usuario';
        document.getElementById('ugAccion').value    = editando ? 'editar_usuario' : 'crear_usuario';
        document.getElementById('ugUsuarioId').value = datos.id || 0;
        document.getElementById('ugNombre').value    = datos.nombre || '';
        document.getElementById('ugEmail').value     = datos.email  || '';
        document.getElementById('ugEmail').readOnly  = editando;
        document.getElementById('ugRun').value       = datos.run    || '';
        document.getElementById('ugRol').value       = datos.rol    || '';
        document.getElementById('ugActivo').value    = datos.activo != null ? datos.activo : 1;
        document.getElementById('ugPassword').value  = '';

        var passReq  = document.getElementById('ugPassReq');
        var passHelp = document.getElementById('ugPassHelp');
        if (passReq)  passReq.style.display  = editando ? 'none' : '';
        if (passHelp) passHelp.style.display  = editando ? ''     : 'none';

        var ugColegio = document.getElementById('ugColegio');
        if (ugColegio) ugColegio.value = datos.colegio || '';

        bg.classList.add('open');
        document.getElementById('ugNombre').focus();
    }

    function close() {
        bg.classList.remove('open');
    }

    document.getElementById('ugBtnNuevo').addEventListener('click', function () { open({}); });
    document.getElementById('ugBtnCancel').addEventListener('click', close);
    document.getElementById('ugModalClose').addEventListener('click', close);
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

    document.querySelectorAll('.ug-btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            open({
                id:      parseInt(btn.dataset.id, 10),
                nombre:  btn.dataset.nombre,
                email:   btn.dataset.email,
                run:     btn.dataset.run,
                rol:     parseInt(btn.dataset.rol, 10),
                colegio: parseInt(btn.dataset.colegio, 10),
                activo:  parseInt(btn.dataset.activo, 10),
            });
        });
    });
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
