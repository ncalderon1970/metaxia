<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/ia.php'; // solo para constantes de path
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
// Acceso: roles operacionales del establecimiento (director, convivencia, admin_colegio, superadmin)
Auth::requireOperate();

// ── Verificar módulo IA contratado ──────────────────────────
$_pdoReg   = DB::conn();
$_colegioReg = (int)((Auth::user() ?? [])['colegio_id'] ?? 0);
$_rolReg     = (string)((Auth::user() ?? [])['rol_codigo'] ?? '');

if ($_rolReg !== 'superadmin') {
    try {
        $stmtModReg = $_pdoReg->prepare("
            SELECT COUNT(*) FROM colegio_modulos
            WHERE colegio_id = ? AND modulo_codigo = 'ia'
              AND activo = 1
              AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
        ");
        $stmtModReg->execute([$_colegioReg]);
        if (!(bool)$stmtModReg->fetchColumn()) {
            http_response_code(403);
            require_once dirname(__DIR__, 2) . '/core/layout_header.php';
            echo '<div style="max-width:600px;margin:3rem auto;background:#fff3cd;border:1px solid #ffc107;
                              border-radius:12px;padding:2rem;font-family:sans-serif;text-align:center;">
                    <div style="font-size:2.5rem;">🔒</div>
                    <h2 style="color:#1a3a5c;margin:.5rem 0;">Módulo IA no contratado</h2>
                    <p style="color:#555;margin:.5rem 0;">
                        El módulo de <strong>Inteligencia Artificial</strong> no está activo para este establecimiento.<br>
                        Contacta al administrador del sistema para activarlo.
                    </p>
                    <a href="javascript:history.back()" style="display:inline-block;margin-top:1rem;
                       background:#1a3a5c;color:#fff;padding:.6rem 1.5rem;border-radius:8px;
                       text-decoration:none;font-weight:700;">← Volver</a>
                  </div>';
            require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
            exit;
        }
    } catch (Throwable $e) { /* Si la tabla no existe, dejar pasar */ }
}

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);
$iaCfg     = require dirname(__DIR__, 2) . '/config/ia.php';

$pageTitle    = 'Reglamento Interno · Configuración IA';
$pageSubtitle = 'Suba el Reglamento Interno del establecimiento para que la IA lo use como referencia normativa en el análisis de casos.';

$error  = '';
$exito  = '';

// ────────────────────────────────────────────────────────────
// Helpers locales
// ────────────────────────────────────────────────────────────
function reg_table_exists(PDO $pdo, string $table): bool
{
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function reg_storage_path(array $cfg): string
{
    $path = rtrim((string)($cfg['reglamentos_path'] ?? BASE_PATH . '/storage/reglamentos'), '/\\');
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    return $path;
}

function reg_extraer_texto_pdf(string $rutaAbsoluta): string
{
    // Intenta pdftotext (disponible en muchos servidores Linux y algunos XAMPP)
    if (function_exists('exec')) {
        $out = [];
        @exec('pdftotext ' . escapeshellarg($rutaAbsoluta) . ' -', $out, $code);
        if ($code === 0 && $out) {
            return implode("\n", $out);
        }
    }
    // Extracción básica: lee strings legibles del binario PDF
    $raw = @file_get_contents($rutaAbsoluta);
    if ($raw === false) { return ''; }
    preg_match_all('/\(([^\)]{3,})\)/', $raw, $matches);
    $textos = array_filter($matches[1] ?? [], fn($s) => mb_strlen(trim($s)) > 2);
    return trim(implode(' ', $textos));
}

// ────────────────────────────────────────────────────────────
// POST: subir nuevo reglamento
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        CSRF::requireValid($_POST['_token'] ?? null);

        if (!reg_table_exists($pdo, 'colegio_reglamentos')) {
            throw new RuntimeException('La tabla colegio_reglamentos no existe. Ejecuta primero el SQL de migración 38K2I_ia_reglamento.sql.');
        }

        $accion = (string)($_POST['_accion'] ?? '');

        // ── Subir archivo + texto ──────────────────────────
        if ($accion === 'subir_reglamento') {
            $textoManual  = trim((string)($_POST['texto_manual'] ?? ''));
            $rutaArchivo  = null;
            $nombreOriginal = 'Texto ingresado manualmente';
            $textoCombinado = $textoManual;

            // Si se subió archivo
            if (!empty($_FILES['archivo']['name'])) {
                $file = $_FILES['archivo'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Error al subir el archivo (código ' . $file['error'] . ').');
                }
                if ($file['size'] > $iaCfg['max_file_size']) {
                    throw new RuntimeException('El archivo supera el tamaño máximo de 5 MB.');
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $iaCfg['extensiones_permitidas'], true)) {
                    throw new RuntimeException('Extensión no permitida. Use PDF, TXT, DOC o DOCX.');
                }

                $storagePath = reg_storage_path($iaCfg);
                $nombreUnico = $colegioId . '_' . time() . '_reglamento.' . $ext;
                $rutaAbsoluta = $storagePath . '/' . $nombreUnico;

                if (!move_uploaded_file($file['tmp_name'], $rutaAbsoluta)) {
                    throw new RuntimeException('No se pudo guardar el archivo. Verifique permisos en storage/reglamentos/.');
                }

                $nombreOriginal = $file['name'];
                $rutaArchivo    = 'reglamentos/' . $nombreUnico;

                // Extraer texto del PDF
                if ($ext === 'pdf') {
                    $textoPdf = reg_extraer_texto_pdf($rutaAbsoluta);
                    if ($textoPdf !== '') {
                        $textoCombinado = $textoPdf . ($textoManual !== '' ? "\n\n---\n\n" . $textoManual : '');
                    }
                } elseif (in_array($ext, ['txt'], true)) {
                    $textoCombinado = @file_get_contents($rutaAbsoluta) ?: $textoManual;
                }
            }

            if (trim($textoCombinado) === '') {
                throw new RuntimeException('Debes subir un archivo o pegar el texto del reglamento.');
            }

            // Límite de seguridad: 10 MB de texto (~10 millones de caracteres)
            // Evita el error "Got a packet bigger than max_allowed_packet"
            $limiteChars = 10_000_000;
            $totalChars  = mb_strlen($textoCombinado, 'UTF-8');
            if ($totalChars > $limiteChars) {
                $textoCombinado = mb_substr($textoCombinado, 0, $limiteChars, 'UTF-8');
                $textoCombinado .= "\n\n[TEXTO TRUNCADO — el archivo superó el límite de 10 MB de texto. Sube una versión más pequeña o pega solo las secciones relevantes del reglamento.]";
            }

            // Ajustar max_allowed_packet para esta conexión
            try {
                $pdo->exec("SET SESSION max_allowed_packet = 67108864"); // 64 MB
            } catch (Throwable $e) { /* puede fallar si no tiene permiso, no bloquear */ }

            // Desactivar reglamentos anteriores del colegio
            $pdo->prepare('UPDATE colegio_reglamentos SET activo = 0 WHERE colegio_id = ?')
                ->execute([$colegioId]);

            // Insertar nuevo
            $stmt = $pdo->prepare("
                INSERT INTO colegio_reglamentos
                    (colegio_id, nombre_original, ruta_archivo, texto_contenido, caracteres, activo, subido_por, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $colegioId,
                $nombreOriginal,
                $rutaArchivo,
                $textoCombinado,
                mb_strlen($textoCombinado, 'UTF-8'),
                $userId,
            ]);

            $exito = 'Reglamento guardado correctamente. La IA lo usará en el próximo análisis de casos.';
        }

        // ── Eliminar reglamento ────────────────────────────
        if ($accion === 'eliminar_reglamento') {
            $regId = (int)($_POST['reglamento_id'] ?? 0);
            if ($regId > 0) {
                $pdo->prepare('DELETE FROM colegio_reglamentos WHERE id = ? AND colegio_id = ?')
                    ->execute([$regId, $colegioId]);
                $exito = 'Reglamento eliminado.';
            }
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ────────────────────────────────────────────────────────────
// Cargar reglamento activo del colegio
// ────────────────────────────────────────────────────────────
$reglamentoActivo = null;
$historialReglamentos = [];

if (reg_table_exists($pdo, 'colegio_reglamentos')) {
    $stmt = $pdo->prepare("
        SELECT r.*, u.nombre AS subido_por_nombre
        FROM colegio_reglamentos r
        LEFT JOIN usuarios u ON u.id = r.subido_por
        WHERE r.colegio_id = ? AND r.activo = 1
        ORDER BY r.id DESC LIMIT 1
    ");
    $stmt->execute([$colegioId]);
    $reglamentoActivo = $stmt->fetch() ?: null;

    $stmt2 = $pdo->prepare("
        SELECT r.*, u.nombre AS subido_por_nombre
        FROM colegio_reglamentos r
        LEFT JOIN usuarios u ON u.id = r.subido_por
        WHERE r.colegio_id = ?
        ORDER BY r.id DESC LIMIT 10
    ");
    $stmt2->execute([$colegioId]);
    $historialReglamentos = $stmt2->fetchAll();
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.reg-wrap         { max-width: 860px; margin: 0 auto; padding: 1.5rem 0 3rem; }
.reg-card         { background: #fff; border: 1px solid #e3e8ef; border-radius: 12px;
                    padding: 1.75rem 2rem; margin-bottom: 1.5rem; }
.reg-card-title   { font-size: 1rem; font-weight: 700; color: #1a3a5c;
                    display: flex; align-items: center; gap: .5rem; margin-bottom: 1.2rem; }
.reg-status-ok    { background: #d4edda; color: #155724; border-radius: 8px;
                    padding: .85rem 1rem; display: flex; gap: .75rem; align-items: flex-start;
                    margin-bottom: 1.2rem; font-size: .88rem; }
.reg-status-none  { background: #fff3cd; color: #856404; border-radius: 8px;
                    padding: .85rem 1rem; display: flex; gap: .75rem; align-items: flex-start;
                    margin-bottom: 1.2rem; font-size: .88rem; }
.reg-status-icon  { font-size: 1.3rem; margin-top: .1rem; }
.reg-label        { display: block; font-size: .8rem; font-weight: 600;
                    color: #444; margin-bottom: .35rem; }
.reg-control      { width: 100%; padding: .5rem .75rem; border: 1px solid #cdd5e0;
                    border-radius: 7px; font-size: .85rem; background: #fff;
                    transition: border-color .15s; box-sizing: border-box; }
.reg-control:focus{ outline: none; border-color: #1a3a5c; box-shadow: 0 0 0 3px rgba(26,58,92,.1); }
textarea.reg-control { resize: vertical; font-family: monospace; font-size: .78rem; }
.reg-form-grid    { display: grid; gap: 1.1rem; }
.reg-help         { font-size: .75rem; color: #888; margin-top: .3rem; }
.reg-submit       { background: #1a3a5c; color: #fff; border: none; border-radius: 8px;
                    padding: .6rem 1.4rem; font-size: .88rem; font-weight: 600;
                    cursor: pointer; transition: background .18s; }
.reg-submit:hover { background: #14304f; }
.reg-btn-del      { background: #fdecea; color: #c0392b; border: none; border-radius: 7px;
                    padding: .35rem .75rem; font-size: .78rem; font-weight: 600; cursor: pointer; }
.reg-hist-table   { width: 100%; border-collapse: collapse; font-size: .82rem; }
.reg-hist-table th{ background: #f1f4f8; color: #444; padding: .5rem .7rem;
                    text-align: left; font-weight: 600; border-bottom: 2px solid #e3e8ef; }
.reg-hist-table td{ padding: .5rem .7rem; border-bottom: 1px solid #f0f3f7; }
.reg-badge-activo { background: #d4edda; color: #155724; border-radius: 20px;
                    padding: .1rem .55rem; font-size: .72rem; font-weight: 600; }
.reg-badge-inactivo{ background: #e2e3e5; color: #555; border-radius: 20px;
                     padding: .1rem .55rem; font-size: .72rem; }
.reg-chars        { font-size: .75rem; color: #888; }
.reg-alert-ok     { background: #d4edda; color: #155724; padding: .65rem 1rem;
                    border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
.reg-alert-err    { background: #f8d7da; color: #721c24; padding: .65rem 1rem;
                    border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
.reg-divider      { border: none; border-top: 1px solid #e8ecf1; margin: 1.2rem 0; }
.reg-tabs         { display: flex; gap: 0; border-bottom: 2px solid #e3e8ef; margin-bottom: 1.2rem; }
.reg-tab          { padding: .55rem 1.1rem; font-size: .83rem; font-weight: 600;
                    cursor: pointer; color: #666; border-bottom: 2px solid transparent;
                    margin-bottom: -2px; user-select: none; transition: all .15s; }
.reg-tab.active   { color: #1a3a5c; border-bottom-color: #1a3a5c; }
.reg-tab-panel    { display: none; }
.reg-tab-panel.active { display: block; }
</style>

<div class="reg-wrap">
    <?php if ($exito !== ''): ?>
        <div class="reg-alert-ok"><i class="bi bi-check-circle-fill"></i> <?= e($exito) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="reg-alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Estado actual -->
    <div class="reg-card">
        <div class="reg-card-title">
            <i class="bi bi-file-earmark-text-fill"></i>
            Reglamento Interno activo
        </div>

        <?php if ($reglamentoActivo): ?>
            <div class="reg-status-ok">
                <span class="reg-status-icon">✅</span>
                <div>
                    <strong><?= e((string)$reglamentoActivo['nombre_original']) ?></strong><br>
                    <span class="reg-chars">
                        <?= number_format((int)$reglamentoActivo['caracteres']) ?> caracteres ·
                        Subido el <?= date('d-m-Y H:i', strtotime((string)$reglamentoActivo['created_at'])) ?>
                        <?php if (!empty($reglamentoActivo['subido_por_nombre'])): ?>
                            por <?= e((string)$reglamentoActivo['subido_por_nombre']) ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <details>
                <summary style="cursor:pointer;font-size:.82rem;color:#1a3a5c;font-weight:600;">
                    Ver primeros 500 caracteres del texto cargado
                </summary>
                <pre style="font-size:.72rem;background:#f8f9fa;padding:.75rem;border-radius:6px;
                            white-space:pre-wrap;max-height:160px;overflow-y:auto;margin-top:.5rem;"><?=
                    e(mb_strimwidth((string)($reglamentoActivo['texto_contenido'] ?? ''), 0, 500, '…', 'UTF-8'))
                ?></pre>
            </details>
        <?php else: ?>
            <div class="reg-status-none">
                <span class="reg-status-icon">⚠️</span>
                <div>
                    <strong>No hay reglamento cargado.</strong><br>
                    Sin reglamento, la IA sólo usará la Ley 21.809 y la REX 782 como referencia normativa.
                    Para obtener recomendaciones contextualizadas a tu establecimiento, sube el Reglamento Interno.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Formulario subir nuevo reglamento -->
    <div class="reg-card">
        <div class="reg-card-title">
            <i class="bi bi-cloud-arrow-up-fill"></i>
            <?= $reglamentoActivo ? 'Actualizar Reglamento Interno' : 'Cargar Reglamento Interno' ?>
        </div>

        <div class="reg-tabs" id="regTabs">
            <div class="reg-tab active" data-panel="panel-archivo">
                <i class="bi bi-file-earmark-pdf"></i> Subir archivo (PDF/TXT)
            </div>
            <div class="reg-tab" data-panel="panel-texto">
                <i class="bi bi-textarea-t"></i> Pegar texto directamente
            </div>
        </div>

        <form method="post" enctype="multipart/form-data">
            <?= CSRF::field() ?>
            <input type="hidden" name="_accion" value="subir_reglamento">

            <!-- Panel archivo -->
            <div class="reg-tab-panel active" id="panel-archivo">
                <div class="reg-form-grid">
                    <div>
                        <label class="reg-label" for="regArchivo">Archivo del Reglamento Interno</label>
                        <input class="reg-control" type="file" id="regArchivo" name="archivo"
                               accept=".pdf,.txt,.doc,.docx">
                        <p class="reg-help">
                            Formatos permitidos: PDF, TXT · Máximo 5 MB.<br>
                            Si el PDF contiene texto seleccionable (no imagen escaneada), el sistema lo extrae automáticamente.
                            Si no, completa también la pestaña "Pegar texto".
                        </p>
                    </div>
                </div>
            </div>

            <!-- Panel texto -->
            <div class="reg-tab-panel" id="panel-texto">
                <div class="reg-form-grid">
                    <div>
                        <label class="reg-label" for="regTextoManual">
                            Texto del Reglamento Interno
                            <span style="color:#888;font-weight:400;">(pega el contenido completo)</span>
                        </label>
                        <textarea class="reg-control" id="regTextoManual" name="texto_manual"
                                  rows="14"
                                  placeholder="Pega aquí el texto completo del Reglamento Interno...&#10;&#10;Puedes copiar desde el PDF y pegar directamente.&#10;Mientras más completo sea el texto, mejores serán las recomendaciones de la IA."
                        ></textarea>
                        <p class="reg-help">
                            Puedes combinar archivo + texto. Si el PDF no se extrae bien, pega el texto aquí como respaldo.
                        </p>
                    </div>
                </div>
            </div>

            <hr class="reg-divider">

            <div style="display:flex;justify-content:flex-end;">
                <button type="submit" class="reg-submit">
                    <i class="bi bi-cloud-check-fill"></i>
                    Guardar reglamento
                </button>
            </div>
        </form>
    </div>

    <!-- Historial de versiones -->
    <?php if ($historialReglamentos): ?>
    <div class="reg-card">
        <div class="reg-card-title">
            <i class="bi bi-clock-history"></i>
            Versiones anteriores
        </div>
        <div style="overflow-x:auto;">
            <table class="reg-hist-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Caracteres</th>
                        <th>Subido</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historialReglamentos as $reg): ?>
                    <tr>
                        <td><?= e((string)($reg['nombre_original'] ?? '')) ?></td>
                        <td><?= number_format((int)($reg['caracteres'] ?? 0)) ?></td>
                        <td><?= date('d-m-Y', strtotime((string)$reg['created_at'])) ?></td>
                        <td>
                            <?php if ((int)$reg['activo'] === 1): ?>
                                <span class="reg-badge-activo">Activo</span>
                            <?php else: ?>
                                <span class="reg-badge-inactivo">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$reg['activo'] !== 1): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('¿Eliminar esta versión?');">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="_accion"       value="eliminar_reglamento">
                                    <input type="hidden" name="reglamento_id" value="<?= (int)$reg['id'] ?>">
                                    <button type="submit" class="reg-btn-del">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('.reg-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.reg-tab-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            var panelId = tab.dataset.panel;
            var panel = document.getElementById(panelId);
            if (panel) panel.classList.add('active');
        });
    });
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
