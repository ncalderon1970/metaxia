<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function cm_table_exists(PDO $pdo, string $table): bool
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

function cm_label(string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

$tipos = [
    'alumnos' => [
        'label' => 'Alumno',
        'plural' => 'Alumnos',
        'icon' => 'bi-mortarboard',
        'descripcion' => 'Registrar manualmente un estudiante nuevo en la comunidad educativa.',
    ],
    'apoderados' => [
        'label' => 'Apoderado',
        'plural' => 'Apoderados',
        'icon' => 'bi-people',
        'descripcion' => 'Registrar manualmente un adulto responsable o contacto familiar.',
    ],
    'docentes' => [
        'label' => 'Docente',
        'plural' => 'Docentes',
        'icon' => 'bi-person-video3',
        'descripcion' => 'Registrar manualmente un profesor o profesional docente.',
    ],
    'asistentes' => [
        'label' => 'Asistente',
        'plural' => 'Asistentes',
        'icon' => 'bi-person-workspace',
        'descripcion' => 'Registrar manualmente un asistente de la educación o funcionario de apoyo.',
    ],
];

$tipo = clean((string)($_GET['tipo'] ?? 'alumnos'));

if (!array_key_exists($tipo, $tipos)) {
    $tipo = 'alumnos';
}

$tablaExiste = cm_table_exists($pdo, $tipo);

$pageTitle = 'Nuevo ' . $tipos[$tipo]['label'] . ' · Metis';
$pageSubtitle = 'Ingreso manual de comunidad educativa';

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.cm-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.cm-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.cm-hero p {
    margin: 0;
    color: #ccfbf1;
    max-width: 900px;
    line-height: 1.55;
}

.cm-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.cm-btn {
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

.cm-btn:hover {
    color: #fff;
}

.cm-tabs {
    display: flex;
    gap: .4rem;
    flex-wrap: wrap;
    margin-bottom: 1.2rem;
}

.cm-tab {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .65rem .9rem;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    font-weight: 900;
    font-size: .84rem;
    text-decoration: none;
}

.cm-tab.active {
    background: #0f172a;
    color: #fff;
    border-color: #0f172a;
}

.cm-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.cm-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.cm-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.cm-panel-body {
    padding: 1.2rem;
}

.cm-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.cm-field.full {
    grid-column: 1 / -1;
}

.cm-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.cm-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .68rem .8rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.cm-control:focus {
    border-color: #0f766e;
    box-shadow: 0 0 0 4px rgba(15,118,110,.12);
}

.cm-submit {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    border: 0;
    border-radius: 999px;
    background: #059669;
    color: #fff;
    padding: .75rem 1.1rem;
    font-size: .9rem;
    font-weight: 900;
    cursor: pointer;
}

.cm-submit:hover {
    background: #047857;
}

.cm-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: .6rem .95rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.cm-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-size: .88rem;
    margin-bottom: 1rem;
}

.cm-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-weight: 800;
}

@media (max-width: 760px) {
    .cm-form {
        grid-template-columns: 1fr;
    }

    .cm-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="cm-hero">
    <h2>
        <i class="bi <?= e($tipos[$tipo]['icon']) ?>"></i>
        Nuevo <?= e($tipos[$tipo]['label']) ?>
    </h2>

    <p>
        <?= e($tipos[$tipo]['descripcion']) ?>
    </p>

    <div class="cm-actions">
        <a class="cm-btn" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= e($tipo) ?>">
            <i class="bi bi-arrow-left"></i>
            Volver a comunidad
        </a>

        <a class="cm-btn" href="<?= APP_URL ?>/modules/importar/index.php">
            <i class="bi bi-file-earmark-arrow-up"></i>
            Importar CSV
        </a>
    </div>
</section>

<nav class="cm-tabs">
    <?php foreach ($tipos as $key => $info): ?>
        <a
            class="cm-tab <?= $tipo === $key ? 'active' : '' ?>"
            href="<?= APP_URL ?>/modules/comunidad/crear.php?tipo=<?= e($key) ?>"
        >
            <i class="bi <?= e($info['icon']) ?>"></i>
            <?= e($info['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (!$tablaExiste): ?>
    <section class="cm-panel">
        <div class="cm-panel-head">
            <h3 class="cm-panel-title">Tabla no disponible</h3>
        </div>

        <div class="cm-panel-body">
            <div class="cm-error">
                La tabla <strong><?= e($tipo) ?></strong> no existe.
                Primero debes ejecutar el SQL opcional de comunidad educativa.
            </div>
        </div>
    </section>
<?php else: ?>

<section class="cm-panel">
    <div class="cm-panel-head">
        <h3 class="cm-panel-title">
            Datos de <?= e($tipos[$tipo]['label']) ?>
        </h3>
    </div>

    <div class="cm-panel-body">
        <div class="cm-note">
            Los campos <strong>RUN</strong> y <strong>Nombres</strong> son obligatorios.
            El sistema evitará duplicados por colegio y RUN.
        </div>

        <form method="post" action="<?= APP_URL ?>/modules/comunidad/guardar.php" class="cm-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="crear">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">

            <div class="cm-field">
                <label class="cm-label">RUN *</label>
                <input class="cm-control" type="text" name="run" placeholder="11111111-1" required>
            </div>

            <div class="cm-field">
                <label class="cm-label">Nombres *</label>
                <input class="cm-control" type="text" name="nombres" placeholder="Nombres" required>
            </div>

            <div class="cm-field">
                <label class="cm-label">Apellido paterno</label>
                <input class="cm-control" type="text" name="apellido_paterno" placeholder="Apellido paterno">
            </div>

            <div class="cm-field">
                <label class="cm-label">Apellido materno</label>
                <input class="cm-control" type="text" name="apellido_materno" placeholder="Apellido materno">
            </div>

            <?php if ($tipo === 'alumnos'): ?>
                <div class="cm-field">
                    <label class="cm-label">Curso</label>
                    <input class="cm-control" type="text" name="curso" placeholder="7° Básico A">
                </div>

                <div class="cm-field">
                    <label class="cm-label">Fecha de nacimiento</label>
                    <input class="cm-control" type="date" name="fecha_nacimiento">
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'apoderados'): ?>
                <div class="cm-field">
                    <label class="cm-label">Parentesco</label>
                    <input class="cm-control" type="text" name="parentesco" placeholder="Madre, padre, tutor, otro">
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'docentes'): ?>
                <div class="cm-field">
                    <label class="cm-label">Especialidad</label>
                    <input class="cm-control" type="text" name="especialidad" placeholder="Lenguaje, Matemática, PIE, etc.">
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'asistentes'): ?>
                <div class="cm-field">
                    <label class="cm-label">Cargo</label>
                    <input class="cm-control" type="text" name="cargo" placeholder="Inspectoría, convivencia, administración, etc.">
                </div>
            <?php endif; ?>

            <div class="cm-field">
                <label class="cm-label">Email</label>
                <input class="cm-control" type="email" name="email" placeholder="correo@dominio.cl">
            </div>

            <div class="cm-field">
                <label class="cm-label">Teléfono</label>
                <input class="cm-control" type="text" name="telefono" placeholder="+56912345678">
            </div>

            <div class="cm-field full">
                <label class="cm-label">Dirección</label>
                <input class="cm-control" type="text" name="direccion" placeholder="Dirección">
            </div>

            <div class="cm-field">
                <label class="cm-label">Estado</label>
                <select class="cm-control" name="activo">
                    <option value="1" selected>Activo</option>
                    <option value="0">Inactivo</option>
                </select>
            </div>

            <div class="cm-field full">
                <button class="cm-submit" type="submit">
                    <i class="bi bi-save"></i>
                    Guardar <?= e($tipos[$tipo]['label']) ?>
                </button>

                <a class="cm-link" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= e($tipo) ?>">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</section>

<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>