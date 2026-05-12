<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/../../core/context_actions.php';
require_once __DIR__ . '/_comunidad_anual_view_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user();
$colegioId = (int) Auth::colegioId();
$anioEscolar = metis_anio_escolar_request();
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid();

    $alumnoId = (int)($_POST['alumno_anual_id'] ?? 0);
    $apoderadoId = (int)($_POST['apoderado_anual_id'] ?? 0);
    $relacion = mb_strtoupper(trim((string)($_POST['relacion'] ?? '')), 'UTF-8');
    $principal = isset($_POST['es_principal']) ? 1 : 0;
    $emergencia = isset($_POST['contacto_emergencia']) ? 1 : 0;
    $retiro = isset($_POST['retiro_autorizado']) ? 1 : 0;
    $vive = isset($_POST['vive_con_estudiante']) ? 1 : 0;
    $notifica = isset($_POST['autoriza_notificaciones']) ? 1 : 0;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id FROM alumnos_anual WHERE id=? AND colegio_id=? AND anio_escolar=? LIMIT 1');
        $stmt->execute([$alumnoId, $colegioId, $anioEscolar]);
        $alumnoOk = $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT id FROM apoderados_anual WHERE id=? AND colegio_id=? AND anio_escolar=? LIMIT 1');
        $stmt->execute([$apoderadoId, $colegioId, $anioEscolar]);
        $apoderadoOk = $stmt->fetchColumn();

        if (!$alumnoOk || !$apoderadoOk) {
            throw new RuntimeException('Alumno o apoderado fuera del año escolar activo.');
        }

        if ($principal === 1) {
            $stmt = $pdo->prepare('UPDATE alumno_apoderado_anual SET es_principal=0 WHERE alumno_anual_id=? AND anio_escolar=?');
            $stmt->execute([$alumnoId, $anioEscolar]);
        }

        $stmt = $pdo->prepare('
            INSERT INTO alumno_apoderado_anual
                (alumno_anual_id, apoderado_anual_id, anio_escolar, relacion, es_principal, contacto_emergencia, retiro_autorizado, vive_con_estudiante, autoriza_notificaciones, vigente, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                relacion=VALUES(relacion),
                es_principal=VALUES(es_principal),
                contacto_emergencia=VALUES(contacto_emergencia),
                retiro_autorizado=VALUES(retiro_autorizado),
                vive_con_estudiante=VALUES(vive_con_estudiante),
                autoriza_notificaciones=VALUES(autoriza_notificaciones),
                vigente=1,
                updated_at=NOW()
        ');
        $stmt->execute([$alumnoId, $apoderadoId, $anioEscolar, $relacion, $principal, $emergencia, $retiro, $vive, $notifica]);

        if (function_exists('registrar_bitacora')) {
            registrar_bitacora($pdo, $colegioId, (int)($user['id'] ?? 0), 'comunidad', 'vincular_apoderado_anual', 'alumno_apoderado_anual', $alumnoId, 'Vinculación anual alumno-apoderado');
        }

        $pdo->commit();
        $mensaje = 'Vinculación guardada correctamente.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Metis] Error vincular apoderado anual: ' . $e->getMessage());
        $mensaje = 'No fue posible guardar la vinculación.';
    }
}

$stmt = $pdo->prepare('SELECT id, run, nombres, apellido_paterno, apellido_materno, curso, letra FROM alumnos_anual WHERE colegio_id=? AND anio_escolar=? AND vigente=1 ORDER BY apellido_paterno, apellido_materno, nombres LIMIT 500');
$stmt->execute([$colegioId, $anioEscolar]);
$alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT id, run, nombres, apellido_paterno, apellido_materno, nombre_social FROM apoderados_anual WHERE colegio_id=? AND anio_escolar=? AND vigente=1 ORDER BY apellido_paterno, apellido_materno, nombres LIMIT 500');
$stmt->execute([$colegioId, $anioEscolar]);
$apoderados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contextActions = [
    metis_context_action('Volver a comunidad', 'index.php?tipo=alumnos&anio_escolar=' . $anioEscolar, 'bi-arrow-left', 'secondary'),
];
include __DIR__ . '/../../core/layout_header.php';
?>
<section class="metis-page">
    <div class="metis-card">
        <div class="metis-card__header"><div><h1 class="metis-title">Vincular apoderado anual</h1><p class="metis-subtitle">Relación alumno-apoderado para el año escolar <?= (int)$anioEscolar ?>.</p></div></div>
        <?php if ($mensaje !== ''): ?><div class="metis-alert metis-alert--info"><?= metis_e($mensaje) ?></div><?php endif; ?>
        <form method="post" class="metis-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="anio_escolar" value="<?= (int)$anioEscolar ?>">
            <div class="metis-grid metis-grid--2">
                <div class="metis-form-group"><label>Alumno</label><select class="metis-select" name="alumno_anual_id" required><?php foreach ($alumnos as $a): ?><option value="<?= (int)$a['id'] ?>"><?= metis_e($a['run'] . ' · ' . trim($a['nombres'].' '.$a['apellido_paterno'].' '.$a['apellido_materno']) . ' · ' . trim(($a['curso'] ?? '').' '.($a['letra'] ?? ''))) ?></option><?php endforeach; ?></select></div>
                <div class="metis-form-group"><label>Apoderado</label><select class="metis-select" name="apoderado_anual_id" required><?php foreach ($apoderados as $ap): ?><option value="<?= (int)$ap['id'] ?>"><?= metis_e($ap['run'] . ' · ' . metis_nombre_preferente($ap)) ?></option><?php endforeach; ?></select></div>
                <div class="metis-form-group"><label>Relación</label><input class="metis-input" name="relacion" placeholder="MADRE, PADRE, TUTOR, ABUELA..."></div>
            </div>
            <div class="metis-checks">
                <label><input type="checkbox" name="es_principal"> Principal</label>
                <label><input type="checkbox" name="contacto_emergencia"> Emergencia</label>
                <label><input type="checkbox" name="retiro_autorizado"> Retiro autorizado</label>
                <label><input type="checkbox" name="vive_con_estudiante"> Vive con estudiante</label>
                <label><input type="checkbox" name="autoriza_notificaciones"> Autoriza notificaciones</label>
            </div>
            <div class="metis-actions"><button class="metis-btn metis-btn--primary" type="submit">Guardar vinculación</button></div>
        </form>
    </div>
</section>
<?php include __DIR__ . '/../../core/layout_footer.php'; ?>
