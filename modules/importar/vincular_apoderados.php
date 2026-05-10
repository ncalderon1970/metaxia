<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$pageTitle = 'Vincular alumnos y apoderados · Metis';
$pageSubtitle = 'Carga masiva de relaciones por RUN con control de colegio y actualización segura';
$resultado = null;

function vinc_norm_run(string $run): string
{
    $run = mb_strtoupper(trim($run), 'UTF-8');
    $run = str_replace(['.', ' ', "\t"], '', $run);
    $run = preg_replace('/[^0-9Kk\-]/u', '', $run) ?? '';
    if ($run === '') return '';
    if (!str_contains($run, '-') && mb_strlen($run) >= 2) {
        $run = mb_substr($run, 0, -1) . '-' . mb_substr($run, -1);
    }
    [$body, $dv] = array_pad(explode('-', $run, 2), 2, '');
    $body = preg_replace('/\D/u', '', $body) ?? '';
    $dv = mb_strtoupper(preg_replace('/[^0-9K]/u', '', $dv) ?? '', 'UTF-8');
    return $body !== '' && $dv !== '' ? $body . '-' . $dv : '';
}

function vinc_to_bool(string $value, int $default = 0): int
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    if ($value === '') return $default;
    return in_array($value, ['1', 'si', 'sí', 'true', 'x', 'yes'], true) ? 1 : 0;
}

function vinc_detect_delimiter(string $path): string
{
    $line = '';
    $h = fopen($path, 'rb');
    if (!$h) return ';';
    while (($line = fgets($h)) !== false) {
        if (trim($line) !== '') break;
    }
    fclose($h);
    if (str_starts_with(mb_strtolower(trim($line)), 'sep=')) {
        $sep = substr(trim($line), 4, 1);
        return in_array($sep, [';', ',', "\t"], true) ? $sep : ';';
    }
    $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
    arsort($counts);
    return (string)array_key_first($counts);
}

function vinc_find_id(PDO $pdo, string $table, string $run, int $colegioId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE colegio_id = ? AND run = ? AND activo = 1 LIMIT 1");
    $stmt->execute([$colegioId, vinc_norm_run($run)]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

$pageHeaderActions = [
    metis_context_action('Volver a importar', APP_URL . '/modules/importar/index.php', 'bi-arrow-left', 'secondary'),
    metis_context_action('Plantilla vinculación', APP_URL . '/modules/importar/plantilla_vinculacion.php', 'bi-download', 'primary'),
    metis_context_action('Comunidad', APP_URL . '/modules/comunidad/index.php', 'bi-people', 'soft'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::requireValid($_POST['_token'] ?? null);

        if ($colegioId <= 0) {
            throw new RuntimeException('No se pudo determinar el establecimiento activo.');
        }

        if (empty($_FILES['archivo']['tmp_name']) || !is_uploaded_file((string)$_FILES['archivo']['tmp_name'])) {
            throw new RuntimeException('No se recibió ningún archivo.');
        }

        $file = $_FILES['archivo'];
        $size = (int)($file['size'] ?? 0);
        $name = (string)($file['name'] ?? '');
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new RuntimeException('El archivo debe pesar entre 1 byte y 10 MB.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Solo se permiten archivos CSV.');
        }

        $tmpFile = (string)$file['tmp_name'];
        $sep = vinc_detect_delimiter($tmpFile);
        $handle = fopen($tmpFile, 'rb');
        if (!$handle) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        $filaNum = 0;
        $procesadas = 0;
        $insertadas = 0;
        $actualizadas = 0;
        $omitidas = 0;
        $errores = [];
        $cacheAlumnos = [];
        $cacheApoderados = [];

        $stmtInsert = $pdo->prepare("
            INSERT INTO alumno_apoderado
                (alumno_id, apoderado_id, tipo_relacion, parentesco, es_titular,
                 puede_retirar, recibe_notificaciones, vive_con_estudiante, observacion, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                tipo_relacion = VALUES(tipo_relacion),
                parentesco = VALUES(parentesco),
                es_titular = VALUES(es_titular),
                puede_retirar = VALUES(puede_retirar),
                recibe_notificaciones = VALUES(recibe_notificaciones),
                vive_con_estudiante = VALUES(vive_con_estudiante),
                observacion = VALUES(observacion),
                activo = 1,
                updated_at = NOW()
        ");

        $pdo->beginTransaction();

        while (($fila = fgetcsv($handle, 0, $sep)) !== false) {
            $filaNum++;

            if ($filaNum === 1 && isset($fila[0]) && str_starts_with(mb_strtolower(trim((string)$fila[0])), 'sep=')) {
                continue;
            }
            if ($filaNum === 1) {
                continue;
            }
            if (empty($fila[0]) || str_starts_with(trim((string)($fila[0] ?? '')), '#')) {
                $omitidas++;
                continue;
            }

            $runAlumno = vinc_norm_run((string)($fila[0] ?? ''));
            $runApoderado = vinc_norm_run((string)($fila[1] ?? ''));
            $tipoRelacion = mb_strtolower(trim((string)($fila[2] ?? 'otro')), 'UTF-8') ?: 'otro';
            $esTitular = vinc_to_bool((string)($fila[3] ?? '0'));
            $puedeRetirar = vinc_to_bool((string)($fila[4] ?? '0'));
            $recibeNot = vinc_to_bool((string)($fila[5] ?? '1'), 1);
            $viveCon = vinc_to_bool((string)($fila[6] ?? '0'));
            $observacion = trim((string)($fila[7] ?? ''));

            if ($runAlumno === '' || $runApoderado === '') {
                $omitidas++;
                continue;
            }

            $alumnoId = $cacheAlumnos[$runAlumno] ?? null;
            if ($alumnoId === null) {
                $alumnoId = vinc_find_id($pdo, 'alumnos', $runAlumno, $colegioId);
                $cacheAlumnos[$runAlumno] = $alumnoId;
            }
            if (!$alumnoId) {
                $errores[] = "Fila {$filaNum}: alumno RUN [{$runAlumno}] no encontrado en el establecimiento.";
                continue;
            }

            $apoderadoId = $cacheApoderados[$runApoderado] ?? null;
            if ($apoderadoId === null) {
                $apoderadoId = vinc_find_id($pdo, 'apoderados', $runApoderado, $colegioId);
                $cacheApoderados[$runApoderado] = $apoderadoId;
            }
            if (!$apoderadoId) {
                $errores[] = "Fila {$filaNum}: apoderado RUN [{$runApoderado}] no encontrado en el establecimiento.";
                continue;
            }

            $stmtInsert->execute([
                $alumnoId,
                $apoderadoId,
                $tipoRelacion,
                $tipoRelacion,
                $esTitular,
                $puedeRetirar,
                $recibeNot,
                $viveCon,
                $observacion !== '' ? mb_strtoupper($observacion, 'UTF-8') : null,
            ]);

            $affected = $stmtInsert->rowCount();
            if ($affected === 1) {
                $insertadas++;
            } elseif ($affected >= 2) {
                $actualizadas++;
            }
            $procesadas++;
        }

        fclose($handle);
        $pdo->commit();

        if (function_exists('registrar_bitacora')) {
            try {
                registrar_bitacora('importar', 'vincular_apoderados', 'alumno_apoderado', null, 'Vinculación CSV: insertadas=' . $insertadas . ', actualizadas=' . $actualizadas . ', errores=' . count($errores));
            } catch (Throwable $e) {}
        }

        $resultado = [
            'ok' => true,
            'filas_csv' => max(0, $filaNum - 1),
            'procesadas' => $procesadas,
            'insertadas' => $insertadas,
            'actualizadas' => $actualizadas,
            'omitidas' => $omitidas,
            'errores' => $errores,
        ];
    } catch (Throwable $e) {
        if (isset($handle) && is_resource($handle)) fclose($handle);
        if ($pdo->inTransaction()) $pdo->rollBack();
        $resultado = ['error' => $e->getMessage()];
    }
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.vinc-hero{background:linear-gradient(135deg,#0c4a6e,#0369a1);color:#fff;border-radius:14px;padding:2rem 2.5rem;margin-bottom:1.5rem;}
.vinc-grid{display:grid;grid-template-columns:1fr 380px;gap:1.25rem;align-items:start;}
.vinc-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;}
.vinc-title{font-size:.92rem;font-weight:700;color:#0f172a;margin-bottom:1.1rem;display:flex;align-items:center;gap:.5rem;}
.vinc-label{display:block;font-size:.78rem;font-weight:600;color:#374151;margin-bottom:.3rem;}
.vinc-control{width:100%;padding:.48rem .7rem;border:1px solid #cdd5e0;border-radius:7px;font-size:.84rem;box-sizing:border-box;}
.vinc-control:focus{outline:none;border-color:#0369a1;box-shadow:0 0 0 3px rgba(3,105,161,.1);}
.vinc-submit{background:#0369a1;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;font-size:.86rem;font-weight:700;cursor:pointer;width:100%;margin-top:.75rem;}
.vinc-submit:hover{background:#075985;}
.vinc-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:.85rem 1rem;font-size:.82rem;color:#0c4a6e;margin-bottom:1rem;}
.vinc-info ul{margin:.4rem 0 0 1rem;padding:0;}
.vinc-info li{margin-bottom:.25rem;}
.result-ok{background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem;}
.result-err{background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem;}
.result-kpi{display:flex;gap:.75rem;flex-wrap:wrap;margin:.75rem 0;}
.kpi-box{text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:.65rem .9rem;min-width:90px;}
.kpi-num{font-size:1.5rem;font-weight:800;color:#0369a1;line-height:1;}
.kpi-lbl{font-size:.68rem;color:#64748b;margin-top:.2rem;}
.kpi-box.warn .kpi-num{color:#b91c1c;}.kpi-box.ok2 .kpi-num{color:#059669;}
.err-list{max-height:220px;overflow-y:auto;background:#fff;border:1px solid #fca5a5;border-radius:8px;padding:.65rem 1rem;margin-top:.75rem;font-size:.78rem;font-family:monospace;}
.err-list li{margin-bottom:.25rem;color:#991b1b;}
@media(max-width:860px){.vinc-grid{grid-template-columns:1fr;}}
</style>

<div class="vinc-hero">
    <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#bae6fd;display:block;margin-bottom:.4rem;">
        <i class="bi bi-file-earmark-arrow-up"></i> Metis · Importar datos
    </span>
    <h1 style="font-size:1.7rem;font-weight:800;margin-bottom:.3rem;">Vincular Alumnos ↔ Apoderados</h1>
    <p style="color:#bae6fd;font-size:.87rem;margin:0;">Carga masiva de relaciones por RUN · Optimizado para colegios con alto volumen de datos</p>
</div>

<?php if ($resultado): ?>
    <?php if (!empty($resultado['error'])): ?>
        <div class="result-err"><strong><i class="bi bi-exclamation-triangle-fill"></i> Error:</strong> <?= e($resultado['error']) ?></div>
    <?php elseif (!empty($resultado['ok'])): ?>
        <div class="result-ok">
            <strong><i class="bi bi-check-circle-fill" style="color:#059669;"></i> Proceso completado</strong>
            <div class="result-kpi">
                <div class="kpi-box"><div class="kpi-num"><?= (int)$resultado['filas_csv'] ?></div><div class="kpi-lbl">Filas en CSV</div></div>
                <div class="kpi-box"><div class="kpi-num"><?= (int)$resultado['procesadas'] ?></div><div class="kpi-lbl">Procesadas</div></div>
                <div class="kpi-box ok2"><div class="kpi-num"><?= (int)$resultado['insertadas'] ?></div><div class="kpi-lbl">Vínculos nuevos</div></div>
                <div class="kpi-box"><div class="kpi-num"><?= (int)$resultado['actualizadas'] ?></div><div class="kpi-lbl">Actualizadas</div></div>
                <div class="kpi-box"><div class="kpi-num"><?= (int)$resultado['omitidas'] ?></div><div class="kpi-lbl">Omitidas</div></div>
                <div class="kpi-box warn"><div class="kpi-num"><?= count($resultado['errores']) ?></div><div class="kpi-lbl">Errores RUN</div></div>
            </div>
            <?php if ($resultado['errores']): ?>
                <strong style="font-size:.82rem;color:#991b1b;">RUNs no encontrados:</strong>
                <ul class="err-list">
                    <?php foreach (array_slice($resultado['errores'], 0, 120) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="vinc-grid">
    <div class="vinc-card">
        <div class="vinc-title"><i class="bi bi-upload" style="color:#0369a1;"></i> Cargar archivo CSV</div>
        <div class="vinc-info">
            <strong>Formato requerido:</strong>
            <ul>
                <li><code>run_alumno</code>; <code>run_apoderado</code>; <code>tipo_relacion</code></li>
                <li><code>es_titular</code>; <code>puede_retirar</code>; <code>recibe_notificaciones</code>; <code>vive_con_estudiante</code>; <code>observacion</code></li>
            </ul>
            <strong>Requisito previo:</strong> alumnos y apoderados deben existir en el mismo colegio activo.
        </div>
        <form method="post" enctype="multipart/form-data">
            <?= CSRF::field() ?>
            <div style="margin-bottom:.85rem;">
                <label class="vinc-label" for="archivo">Archivo CSV de vinculación *</label>
                <input class="vinc-control" type="file" id="archivo" name="archivo" accept=".csv,text/csv" required>
                <small style="color:#64748b;font-size:.73rem;">Máx. 10 MB · Separador punto y coma, coma o tabulación</small>
            </div>
            <button type="submit" class="vinc-submit"><i class="bi bi-cloud-upload-fill"></i> Procesar CSV</button>
        </form>
    </div>
    <div>
        <div class="vinc-card" style="margin-bottom:1rem;">
            <div class="vinc-title"><i class="bi bi-info-circle" style="color:#0369a1;"></i> Cómo funciona</div>
            <ol style="font-size:.82rem;color:#374151;margin:0;padding-left:1.2rem;line-height:1.8;">
                <li>Descarga la plantilla CSV.</li>
                <li>Completa RUNs de alumnos y apoderados.</li>
                <li>Un alumno puede tener múltiples apoderados.</li>
                <li>El sistema actualiza vínculos existentes sin duplicar.</li>
                <li>Los RUN no encontrados se reportan al finalizar.</li>
            </ol>
        </div>
        <div class="vinc-card">
            <div class="vinc-title"><i class="bi bi-exclamation-triangle" style="color:#f59e0b;"></i> Antes de cargar</div>
            <ul style="font-size:.82rem;color:#374151;margin:0;padding-left:1.2rem;line-height:1.8;">
                <li>Importa primero alumnos.</li>
                <li>Importa luego apoderados.</li>
                <li>Después carga este CSV de vínculos.</li>
                <li>El archivo es seguro de volver a cargar.</li>
            </ul>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
