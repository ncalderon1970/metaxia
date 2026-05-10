<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/Run.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Nuevo apoderado vinculado · Metis';
$pageSubtitle = 'Crear apoderado y asociarlo directamente al estudiante';

function caa_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function caa_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function caa_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function caa_clean(?string $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function caa_upper(?string $value): ?string
{
    $value = caa_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function caa_email(?string $value): ?string
{
    $value = caa_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtolower($value, 'UTF-8');
}

function caa_old(array $old, string $key, string $default = ''): string
{
    return isset($old[$key]) ? (string)$old[$key] : $default;
}

function caa_pick(array $row, array $keys, string $default = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }

    return $default;
}

function caa_nombre(array $row): string
{
    $partes = [];

    foreach (['nombres', 'nombre', 'nombre_completo', 'apellido_paterno', 'paterno', 'apellido_materno', 'materno'] as $key) {
        if (!empty($row[$key])) {
            $partes[] = trim((string)$row[$key]);
        }
    }

    $nombre = trim(implode(' ', $partes));

    return $nombre !== '' ? $nombre : 'Sin nombre';
}

function caa_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!caa_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = caa_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para insertar.');
    }

    $sql = "
        INSERT INTO " . caa_quote($table) . " (
            " . implode(', ', $columns) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function caa_redirect_vincular(int $alumnoId, string $status, string $msg): void
{
    $url = APP_URL . '/modules/comunidad/vincular_apoderado.php?alumno_id=' . $alumnoId;
    $url .= '&status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    header('Location: ' . $url);
    exit;
}

function caa_flash_back(int $alumnoId, string $message): void
{
    $old = $_POST;
    unset($old['_token']);

    $_SESSION['crear_apoderado_alumno_old'] = $old;
    $_SESSION['crear_apoderado_alumno_error'] = $message;

    header('Location: ' . APP_URL . '/modules/comunidad/crear_apoderado_alumno.php?alumno_id=' . $alumnoId);
    exit;
}

function caa_obtener_alumno(PDO $pdo, int $alumnoId, int $colegioId): array
{
    $whereColegio = caa_column_exists($pdo, 'alumnos', 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $params = caa_column_exists($pdo, 'alumnos', 'colegio_id')
        ? [$alumnoId, $colegioId]
        : [$alumnoId];

    $stmt = $pdo->prepare("
        SELECT *
        FROM alumnos
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmt->execute($params);

    $alumno = $stmt->fetch();

    if (!$alumno) {
        throw new RuntimeException('Alumno no encontrado o no pertenece al establecimiento.');
    }

    return $alumno;
}

function caa_buscar_apoderado_por_run(PDO $pdo, string $run, int $colegioId): ?array
{
    if (!caa_column_exists($pdo, 'apoderados', 'run')) {
        return null;
    }

    $whereColegio = caa_column_exists($pdo, 'apoderados', 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $params = caa_column_exists($pdo, 'apoderados', 'colegio_id')
        ? [$run, $colegioId]
        : [$run];

    $stmt = $pdo->prepare("
        SELECT *
        FROM apoderados
        WHERE run = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmt->execute($params);

    $row = $stmt->fetch();

    return $row ?: null;
}

function caa_marcar_principal_unico(PDO $pdo, int $colegioId, int $alumnoId): void
{
    $stmt = $pdo->prepare("
        UPDATE alumno_apoderado
        SET es_principal = 0,
            updated_at = NOW()
        WHERE colegio_id = ?
          AND alumno_id = ?
    ");
    $stmt->execute([$colegioId, $alumnoId]);
}

function caa_vincular(PDO $pdo, int $colegioId, int $alumnoId, int $apoderadoId, array $dataRelacion, int $userId): int
{
    $stmtExiste = $pdo->prepare("
        SELECT *
        FROM alumno_apoderado
        WHERE colegio_id = ?
          AND alumno_id = ?
          AND apoderado_id = ?
        LIMIT 1
    ");
    $stmtExiste->execute([$colegioId, $alumnoId, $apoderadoId]);
    $existente = $stmtExiste->fetch();

    if ((int)$dataRelacion['es_principal'] === 1) {
        caa_marcar_principal_unico($pdo, $colegioId, $alumnoId);
    }

    if ($existente) {
        $stmt = $pdo->prepare("
            UPDATE alumno_apoderado
            SET parentesco = ?,
                es_principal = ?,
                vive_con_estudiante = ?,
                autorizado_retirar = ?,
                contacto_emergencia = ?,
                observacion = ?,
                activo = 1,
                updated_at = NOW()
            WHERE id = ?
              AND colegio_id = ?
            LIMIT 1
        ");
        $stmt->execute([
            $dataRelacion['parentesco'],
            $dataRelacion['es_principal'],
            $dataRelacion['vive_con_estudiante'],
            $dataRelacion['autorizado_retirar'],
            $dataRelacion['contacto_emergencia'],
            $dataRelacion['observacion'],
            (int)$existente['id'],
            $colegioId,
        ]);

        return (int)$existente['id'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO alumno_apoderado (
            colegio_id,
            alumno_id,
            apoderado_id,
            parentesco,
            es_principal,
            vive_con_estudiante,
            autorizado_retirar,
            contacto_emergencia,
            observacion,
            activo,
            creado_por,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW()
        )
    ");
    $stmt->execute([
        $colegioId,
        $alumnoId,
        $apoderadoId,
        $dataRelacion['parentesco'],
        $dataRelacion['es_principal'],
        $dataRelacion['vive_con_estudiante'],
        $dataRelacion['autorizado_retirar'],
        $dataRelacion['contacto_emergencia'],
        $dataRelacion['observacion'],
        $userId > 0 ? $userId : null,
    ]);

    return (int)$pdo->lastInsertId();
}

$alumnoId = (int)($_GET['alumno_id'] ?? $_POST['alumno_id'] ?? 0);
$old = $_SESSION['crear_apoderado_alumno_old'] ?? [];
$errorFormulario = (string)($_SESSION['crear_apoderado_alumno_error'] ?? '');

unset($_SESSION['crear_apoderado_alumno_old'], $_SESSION['crear_apoderado_alumno_error']);

$error = '';
$alumno = [];

try {
    foreach (['alumnos', 'apoderados', 'alumno_apoderado'] as $tabla) {
        if (!caa_table_exists($pdo, $tabla)) {
            throw new RuntimeException('Falta la tabla requerida: ' . $tabla);
        }
    }

    if ($alumnoId <= 0) {
        throw new RuntimeException('Debe indicar un alumno.');
    }

    $alumno = caa_obtener_alumno($pdo, $alumnoId, $colegioId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $run = Run::formatOrFail((string)($_POST['run'] ?? ''));
        $nombres = caa_upper((string)($_POST['nombres'] ?? ''));

        if ($nombres === null) {
            throw new RuntimeException('Debe ingresar nombres del apoderado.');
        }

        $pdo->beginTransaction();

        $apoderadoExistente = caa_buscar_apoderado_por_run($pdo, $run, $colegioId);

        if ($apoderadoExistente) {
            $apoderadoId = (int)$apoderadoExistente['id'];
        } else {
            $apoderadoData = [
                'colegio_id' => $colegioId,
                'run' => $run,
                'nombres' => $nombres,
                'apellido_paterno' => caa_upper((string)($_POST['apellido_paterno'] ?? '')),
                'apellido_materno' => caa_upper((string)($_POST['apellido_materno'] ?? '')),
                'parentesco' => caa_upper((string)($_POST['parentesco'] ?? '')),
                'email' => caa_email((string)($_POST['email'] ?? '')),
                'telefono' => caa_upper((string)($_POST['telefono'] ?? '')),
                'direccion' => caa_upper((string)($_POST['direccion'] ?? '')),
                'activo' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $apoderadoId = caa_insert_dynamic($pdo, 'apoderados', $apoderadoData);

            registrar_bitacora(
                'comunidad',
                'crear_apoderado_desde_alumno',
                'apoderados',
                $apoderadoId,
                'Apoderado creado desde ficha de alumno: ' . $run
            );
        }

        $relacion = [
            'parentesco' => caa_upper((string)($_POST['parentesco'] ?? '')),
            'es_principal' => isset($_POST['es_principal']) ? 1 : 0,
            'vive_con_estudiante' => isset($_POST['vive_con_estudiante']) ? 1 : 0,
            'autorizado_retirar' => isset($_POST['autorizado_retirar']) ? 1 : 0,
            'contacto_emergencia' => isset($_POST['contacto_emergencia']) ? 1 : 0,
            'observacion' => caa_upper((string)($_POST['observacion'] ?? '')),
        ];

        $relacionId = caa_vincular($pdo, $colegioId, $alumnoId, $apoderadoId, $relacion, $userId);

        registrar_bitacora(
            'comunidad',
            'crear_y_vincular_apoderado_alumno',
            'alumno_apoderado',
            $relacionId,
            'Apoderado ' . $run . ' creado/vinculado al alumno ' . caa_pick($alumno, ['run'], '')
        );

        $pdo->commit();

        caa_redirect_vincular($alumnoId, 'ok', 'Apoderado creado/vinculado correctamente.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        caa_flash_back($alumnoId > 0 ? $alumnoId : 0, $e->getMessage());
    }

    $error = $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.caa-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.caa-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.caa-hero p {
    margin: 0;
    color: #ccfbf1;
    max-width: 900px;
    line-height: 1.55;
}

.caa-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.caa-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.caa-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.caa-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
}

.caa-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.caa-panel-body {
    padding: 1.2rem;
}

.caa-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.caa-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .25rem;
}

.caa-meta {
    color: #64748b;
    font-size: .8rem;
}

.caa-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.caa-field.full {
    grid-column: 1 / -1;
}

.caa-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.caa-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .68rem .8rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.caa-checks {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
    margin-top: .2rem;
}

.caa-check {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    padding: .42rem .7rem;
    color: #334155;
    font-size: .78rem;
    font-weight: 900;
}

.caa-submit,
.caa-link {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 0;
    border-radius: 999px;
    background: #059669;
    color: #fff;
    padding: .75rem 1.1rem;
    font-size: .9rem;
    font-weight: 900;
    cursor: pointer;
    text-decoration: none;
}

.caa-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.caa-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.caa-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.caa-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-size: .88rem;
    margin-bottom: 1rem;
}

@media (max-width: 760px) {
    .caa-form {
        grid-template-columns: 1fr;
    }

    .caa-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="caa-hero">
    <h2>Crear apoderado y vincular</h2>
    <p>
        Registra un apoderado nuevo y déjalo asociado inmediatamente al alumno,
        definiendo contacto principal, emergencia y autorización de retiro.
    </p>

    <div class="caa-actions">
        <a class="caa-btn" href="<?= APP_URL ?>/modules/comunidad/vincular_apoderado.php?alumno_id=<?= (int)$alumnoId ?>">
            <i class="bi bi-arrow-left"></i>
            Volver a vinculación
        </a>

        <a class="caa-btn" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=alumnos">
            <i class="bi bi-mortarboard"></i>
            Alumnos
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="caa-msg error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($errorFormulario !== ''): ?>
    <div class="caa-msg error"><?= e($errorFormulario) ?></div>
<?php endif; ?>

<section class="caa-panel">
    <div class="caa-panel-head">
        <h3 class="caa-panel-title">Alumno asociado</h3>
    </div>

    <div class="caa-panel-body">
        <?php if ($alumno): ?>
            <div class="caa-card">
                <div class="caa-card-title"><?= e(caa_nombre($alumno)) ?></div>
                <div class="caa-meta">
                    RUN: <?= e(caa_pick($alumno, ['run'], '-')) ?> ·
                    Curso: <?= e(caa_pick($alumno, ['curso'], '-')) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="caa-note">
            Si el RUN del apoderado ya existe en la base, el sistema no duplicará el registro:
            usará el apoderado existente y solo creará o actualizará la relación con el alumno.
        </div>
    </div>
</section>

<section class="caa-panel">
    <div class="caa-panel-head">
        <h3 class="caa-panel-title">Datos del apoderado</h3>
    </div>

    <div class="caa-panel-body">
        <form method="post" class="caa-form" autocomplete="off">
            <?= CSRF::field() ?>
            <input type="hidden" name="alumno_id" value="<?= (int)$alumnoId ?>">

            <div>
                <label class="caa-label">RUN *</label>
                <input class="caa-control" type="text" name="run" value="<?= e(caa_old($old, 'run')) ?>" required>
            </div>

            <div>
                <label class="caa-label">Nombres *</label>
                <input class="caa-control" type="text" name="nombres" value="<?= e(caa_old($old, 'nombres')) ?>" required>
            </div>

            <div>
                <label class="caa-label">Apellido paterno</label>
                <input class="caa-control" type="text" name="apellido_paterno" value="<?= e(caa_old($old, 'apellido_paterno')) ?>">
            </div>

            <div>
                <label class="caa-label">Apellido materno</label>
                <input class="caa-control" type="text" name="apellido_materno" value="<?= e(caa_old($old, 'apellido_materno')) ?>">
            </div>

            <div>
                <label class="caa-label">Parentesco</label>
                <input class="caa-control" type="text" name="parentesco" value="<?= e(caa_old($old, 'parentesco')) ?>" placeholder="MADRE, PADRE, TUTOR, ABUELA">
            </div>

            <div>
                <label class="caa-label">Email</label>
                <input class="caa-control" type="email" name="email" value="<?= e(caa_old($old, 'email')) ?>">
            </div>

            <div>
                <label class="caa-label">Teléfono</label>
                <input class="caa-control" type="text" name="telefono" value="<?= e(caa_old($old, 'telefono')) ?>">
            </div>

            <div>
                <label class="caa-label">Dirección</label>
                <input class="caa-control" type="text" name="direccion" value="<?= e(caa_old($old, 'direccion')) ?>">
            </div>

            <div class="caa-field full">
                <label class="caa-label">Observación relación</label>
                <input class="caa-control" type="text" name="observacion" value="<?= e(caa_old($old, 'observacion')) ?>">
            </div>

            <div class="caa-field full">
                <label class="caa-label">Características de la relación</label>

                <div class="caa-checks">
                    <label class="caa-check">
                        <input type="checkbox" name="es_principal" value="1" <?= caa_old($old, 'es_principal') === '1' ? 'checked' : '' ?>>
                        Apoderado principal
                    </label>

                    <label class="caa-check">
                        <input type="checkbox" name="vive_con_estudiante" value="1" <?= caa_old($old, 'vive_con_estudiante') === '1' ? 'checked' : '' ?>>
                        Vive con estudiante
                    </label>

                    <label class="caa-check">
                        <input type="checkbox" name="autorizado_retirar" value="1" <?= caa_old($old, 'autorizado_retirar') === '1' ? 'checked' : '' ?>>
                        Autorizado a retirar
                    </label>

                    <label class="caa-check">
                        <input type="checkbox" name="contacto_emergencia" value="1" <?= caa_old($old, 'contacto_emergencia') === '1' ? 'checked' : '' ?>>
                        Contacto emergencia
                    </label>
                </div>
            </div>

            <div class="caa-field full">
                <button class="caa-submit" type="submit">
                    <i class="bi bi-save"></i>
                    Crear y vincular apoderado
                </button>

                <a class="caa-link" href="<?= APP_URL ?>/modules/comunidad/vincular_apoderado.php?alumno_id=<?= (int)$alumnoId ?>">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>