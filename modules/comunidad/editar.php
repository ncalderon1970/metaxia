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

$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = Auth::canOperate();

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function ce_table_exists(PDO $pdo, string $table): bool
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

function ce_column_exists(PDO $pdo, string $table, string $column): bool
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

function ce_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ce_pick(array $row, string $key, string $default = ''): string
{
    return isset($row[$key]) ? (string)$row[$key] : $default;
}

function ce_date_value(?string $value): string
{
    if (!$value) {
        return '';
    }

    $ts = strtotime($value);

    return $ts ? date('Y-m-d', $ts) : '';
}

$tipos = [
    'alumnos' => [
        'label' => 'Alumno',
        'plural' => 'Alumnos',
        'icon' => 'bi-mortarboard',
    ],
    'apoderados' => [
        'label' => 'Apoderado',
        'plural' => 'Apoderados',
        'icon' => 'bi-people',
    ],
    'docentes' => [
        'label' => 'Docente',
        'plural' => 'Docentes',
        'icon' => 'bi-person-video3',
    ],
    'asistentes' => [
        'label' => 'Asistente',
        'plural' => 'Asistentes',
        'icon' => 'bi-person-workspace',
    ],
];

$tipo = clean((string)($_GET['tipo'] ?? 'alumnos'));
$id = (int)($_GET['id'] ?? 0);

if (!array_key_exists($tipo, $tipos)) {
    $tipo = 'alumnos';
}

$pageTitle = 'Editar ' . $tipos[$tipo]['label'] . ' · Metis';
$pageSubtitle = 'Corrección manual de comunidad educativa';

$error = '';
$registro = [];

try {
    if ($id <= 0) {
        throw new RuntimeException('Registro no válido.');
    }

    if (!ce_table_exists($pdo, $tipo)) {
        throw new RuntimeException('La tabla ' . $tipo . ' no existe.');
    }

    $whereColegio = ce_column_exists($pdo, $tipo, 'colegio_id')
        ? 'AND colegio_id = ?'
        : '';

    $params = ce_column_exists($pdo, $tipo, 'colegio_id')
        ? [$id, $colegioId]
        : [$id];

    $stmt = $pdo->prepare("
        SELECT *
        FROM " . ce_quote($tipo) . "
        WHERE id = ?
        {$whereColegio}
        LIMIT 1
    ");
    $stmt->execute($params);

    $registro = $stmt->fetch() ?: [];

    if (!$registro) {
        throw new RuntimeException('Registro no encontrado o no pertenece al establecimiento.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.ce-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.ce-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.ce-hero p {
    margin: 0;
    color: #ccfbf1;
    max-width: 900px;
    line-height: 1.55;
}

.ce-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.ce-btn {
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

.ce-btn:hover {
    color: #fff;
}

.ce-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.ce-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
}

.ce-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.ce-panel-body {
    padding: 1.2rem;
}

.ce-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.ce-field.full {
    grid-column: 1 / -1;
}

.ce-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.ce-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .68rem .8rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.ce-control:focus {
    border-color: #0f766e;
    box-shadow: 0 0 0 4px rgba(15,118,110,.12);
}

.ce-submit {
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

.ce-link {
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

.ce-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-size: .88rem;
    margin-bottom: 1rem;
}

.ce-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    border-radius: 14px;
    padding: .9rem 1rem;
    line-height: 1.5;
    font-weight: 800;
}

@media (max-width: 760px) {
    .ce-form {
        grid-template-columns: 1fr;
    }

    .ce-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="ce-hero">
    <h2>
        <i class="bi <?= e($tipos[$tipo]['icon']) ?>"></i>
        Editar <?= e($tipos[$tipo]['label']) ?>
    </h2>

    <p>
        Actualiza los datos del registro seleccionado. Los cambios quedarán trazados en la bitácora del sistema.
    </p>

    <div class="ce-actions">
        <a class="ce-btn" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= e($tipo) ?>">
            <i class="bi bi-arrow-left"></i>
            Volver a comunidad
        </a>

        <a class="ce-btn" href="<?= APP_URL ?>/modules/comunidad/crear.php?tipo=<?= e($tipo) ?>">
            <i class="bi bi-plus-circle"></i>
            Nuevo <?= e($tipos[$tipo]['label']) ?>
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <section class="ce-panel">
        <div class="ce-panel-body">
            <div class="ce-error">
                <?= e($error) ?>
            </div>
        </div>
    </section>
<?php else: ?>

<section class="ce-panel">
    <div class="ce-panel-head">
        <h3 class="ce-panel-title">
            Datos de <?= e($tipos[$tipo]['label']) ?>
        </h3>
    </div>

    <div class="ce-panel-body">
        <div class="ce-note">
            Los campos <strong>RUN</strong> y <strong>Nombres</strong> son obligatorios.
            Si cambias el RUN, el sistema verificará que no exista duplicado dentro del mismo establecimiento.
        </div>

        <form method="post" action="<?= APP_URL ?>/modules/comunidad/actualizar.php" class="ce-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="actualizar">
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <input type="hidden" name="id" value="<?= (int)$id ?>">

            <div class="ce-field">
                <label class="ce-label">RUN *</label>
                <input class="ce-control" type="text" name="run" value="<?= e(ce_pick($registro, 'run')) ?>" required>
            </div>

            <div class="ce-field">
                <label class="ce-label">Nombres *</label>
                <input class="ce-control" type="text" name="nombres" value="<?= e(ce_pick($registro, 'nombres')) ?>" required>
            </div>

            <div class="ce-field">
                <label class="ce-label">Apellido paterno</label>
                <input class="ce-control" type="text" name="apellido_paterno" value="<?= e(ce_pick($registro, 'apellido_paterno')) ?>">
            </div>

            <div class="ce-field">
                <label class="ce-label">Apellido materno</label>
                <input class="ce-control" type="text" name="apellido_materno" value="<?= e(ce_pick($registro, 'apellido_materno')) ?>">
            </div>

            <?php if ($tipo === 'alumnos'): ?>
                <div class="ce-field">
                    <label class="ce-label">Curso</label>
                    <input class="ce-control" type="text" name="curso" value="<?= e(ce_pick($registro, 'curso')) ?>">
                </div>

                <div class="ce-field">
                    <label class="ce-label">Fecha de nacimiento</label>
                    <input class="ce-control" type="date" name="fecha_nacimiento" value="<?= e(ce_date_value(ce_pick($registro, 'fecha_nacimiento'))) ?>">
                </div>

                <!-- ══ INCLUSIÓN / NEE ══════════════════════════════════ -->
                <div class="ce-field full" style="grid-column:1/-1;border-top:1px solid #e2e8f0;padding-top:1rem;margin-top:.5rem;">
                    <div style="font-size:.78rem;font-weight:700;color:#0369a1;margin-bottom:.85rem;">
                        <i class="bi bi-heart-pulse-fill"></i>
                        Inclusión, NEE y condición especial
                        <span style="font-weight:400;color:#94a3b8;font-size:.72rem;">
                            — Ley 21.545 / Decreto 170/2009
                        </span>
                    </div>
                </div>

                <div class="ce-field">
                    <label class="ce-label">Condición especial</label>
                    <select class="ce-control" name="condicion_especial">
                        <option value="">— Sin condición registrada —</option>
                        <optgroup label="TEA (Ley 21.545)">
                            <option value="tea"          <?= ce_pick($registro,'condicion_especial')==='tea'          ?'selected':''?>>Trastorno del Espectro Autista (TEA)</option>
                            <option value="tea_nivel_1"  <?= ce_pick($registro,'condicion_especial')==='tea_nivel_1'  ?'selected':''?>>TEA Nivel 1 — Necesita apoyo</option>
                            <option value="tea_nivel_2"  <?= ce_pick($registro,'condicion_especial')==='tea_nivel_2'  ?'selected':''?>>TEA Nivel 2 — Necesita apoyo sustancial</option>
                            <option value="tea_nivel_3"  <?= ce_pick($registro,'condicion_especial')==='tea_nivel_3'  ?'selected':''?>>TEA Nivel 3 — Necesita apoyo muy sustancial</option>
                        </optgroup>
                        <optgroup label="NEE (Decreto 170)">
                            <option value="dificultad_aprendizaje" <?= ce_pick($registro,'condicion_especial')==='dificultad_aprendizaje'?'selected':''?>>Dificultad específica de aprendizaje</option>
                            <option value="tda"   <?= ce_pick($registro,'condicion_especial')==='tda'  ?'selected':''?>>TDA</option>
                            <option value="tdah"  <?= ce_pick($registro,'condicion_especial')==='tdah' ?'selected':''?>>TDAH</option>
                            <option value="discapacidad_intelectual" <?= ce_pick($registro,'condicion_especial')==='discapacidad_intelectual'?'selected':''?>>Discapacidad intelectual</option>
                            <option value="discapacidad_visual"      <?= ce_pick($registro,'condicion_especial')==='discapacidad_visual'?'selected':''?>>Discapacidad visual</option>
                            <option value="discapacidad_auditiva"    <?= ce_pick($registro,'condicion_especial')==='discapacidad_auditiva'?'selected':''?>>Discapacidad auditiva</option>
                        </optgroup>
                        <optgroup label="Otros">
                            <option value="superdotacion"        <?= ce_pick($registro,'condicion_especial')==='superdotacion'?'selected':''?>>Altas capacidades</option>
                            <option value="condicion_salud_mental" <?= ce_pick($registro,'condicion_especial')==='condicion_salud_mental'?'selected':''?>>Condición de salud mental</option>
                            <option value="otro"  <?= ce_pick($registro,'condicion_especial')==='otro' ?'selected':''?>>Otra condición</option>
                        </optgroup>
                    </select>
                </div>

                <div class="ce-field">
                    <label class="ce-label">Diagnóstico TEA</label>
                    <select class="ce-control" name="diagnostico_tea">
                        <option value="">— No aplica —</option>
                        <option value="sospecha"   <?= ce_pick($registro,'diagnostico_tea')==='sospecha'   ?'selected':''?>>Sospecha / detección establecimiento</option>
                        <option value="en_proceso" <?= ce_pick($registro,'diagnostico_tea')==='en_proceso' ?'selected':''?>>En proceso diagnóstico</option>
                        <option value="confirmado" <?= ce_pick($registro,'diagnostico_tea')==='confirmado' ?'selected':''?>>Confirmado</option>
                        <option value="descartado" <?= ce_pick($registro,'diagnostico_tea')==='descartado' ?'selected':''?>>Descartado</option>
                    </select>
                </div>

                <div class="ce-field" style="display:flex;flex-direction:column;gap:.6rem;justify-content:flex-end;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.84rem;">
                        <input type="checkbox" name="tiene_pie" value="1"
                               <?= ce_pick($registro,'tiene_pie') === '1' ? 'checked' : '' ?>>
                        <span><strong>PIE</strong> — Programa de Integración Escolar</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.84rem;">
                        <input type="checkbox" name="requiere_ajustes_razonables" value="1"
                               <?= ce_pick($registro,'requiere_ajustes_razonables') === '1' ? 'checked' : '' ?>>
                        <span>Requiere <strong>ajustes razonables</strong> (Art. 18 Ley 21.545)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.84rem;">
                        <input type="checkbox" name="tiene_certificado_discapacidad" value="1"
                               <?= ce_pick($registro,'tiene_certificado_discapacidad') === '1' ? 'checked' : '' ?>>
                        <span>Certificado discapacidad (Ley 20.422)</span>
                    </label>
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'apoderados'): ?>
                <div class="ce-field">
                    <label class="ce-label">Parentesco</label>
                    <input class="ce-control" type="text" name="parentesco" value="<?= e(ce_pick($registro, 'parentesco')) ?>">
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'docentes'): ?>
                <div class="ce-field">
                    <label class="ce-label">Especialidad</label>
                    <input class="ce-control" type="text" name="especialidad" value="<?= e(ce_pick($registro, 'especialidad')) ?>">
                </div>
            <?php endif; ?>

            <?php if ($tipo === 'asistentes'): ?>
                <div class="ce-field">
                    <label class="ce-label">Cargo</label>
                    <input class="ce-control" type="text" name="cargo" value="<?= e(ce_pick($registro, 'cargo')) ?>">
                </div>
            <?php endif; ?>

            <div class="ce-field">
                <label class="ce-label">Email</label>
                <input class="ce-control" type="email" name="email" value="<?= e(ce_pick($registro, 'email')) ?>">
            </div>

            <div class="ce-field">
                <label class="ce-label">Teléfono</label>
                <input class="ce-control" type="text" name="telefono" value="<?= e(ce_pick($registro, 'telefono')) ?>">
            </div>

            <div class="ce-field full">
                <label class="ce-label">Dirección</label>
                <input class="ce-control" type="text" name="direccion" value="<?= e(ce_pick($registro, 'direccion')) ?>">
            </div>

            <div class="ce-field">
                <label class="ce-label">Estado</label>
                <select class="ce-control" name="activo">
                    <option value="1" <?= (int)($registro['activo'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option>
                    <option value="0" <?= (int)($registro['activo'] ?? 1) === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
            </div>

            <div class="ce-field full">
                <button class="ce-submit" type="submit">
                    <i class="bi bi-save"></i>
                    Guardar cambios
                </button>

                <a class="ce-link" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= e($tipo) ?>">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</section>

<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>