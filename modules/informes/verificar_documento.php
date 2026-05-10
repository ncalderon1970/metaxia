<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

$resultado  = null; // null=sin verificar | 'ok' | 'error'
$msgDetalle = '';
$casoData   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $folioInput = strtoupper(trim((string)($_POST['folio'] ?? '')));
    $hashInput  = strtoupper(trim((string)($_POST['hash']  ?? '')));

    if ($folioInput === '' || $hashInput === '') {
        $resultado  = 'error';
        $msgDetalle = 'Debes ingresar el folio y el código de autenticidad del documento.';
    } else {
        // Extraer casoId del folio: METIS-YYYYMMDD-NNNNNN
        // Formato: METIS-20260502-000011
        if (preg_match('/^METIS-\d{8}-(\d{6})$/', $folioInput, $m)) {
            $casoIdDoc = (int)ltrim($m[1], '0') ?: 1;

            // Buscar el caso en la BD
            try {
                $sc = $pdo->prepare("
                    SELECT id, numero_caso, colegio_id, fecha_ingreso, estado,
                           denunciante_nombre, lugar_hechos, fecha_hora_incidente
                    FROM casos
                    WHERE id = ?
                    LIMIT 1
                ");
                $sc->execute([$casoIdDoc]);
                $caso = $sc->fetch();

                if (!$caso) {
                    $resultado  = 'error';
                    $msgDetalle = 'El folio no corresponde a ningún caso registrado en el sistema.';
                } else {
                    // Recalcular hash con todos los posibles colegio_id
                    // (el PDF puede haber sido generado con cualquier colegio)
                    $hashEsperado = strtoupper(substr(
                        hash('sha256', $folioInput . ($caso['numero_caso'] ?? '') . $caso['colegio_id']),
                        0, 16
                    ));

                    if ($hashInput === $hashEsperado) {
                        $resultado  = 'ok';
                        $casoData   = $caso;
                        $msgDetalle = 'El documento es auténtico y fue generado por Metis SGCE.';
                    } else {
                        $resultado  = 'error';
                        $msgDetalle = 'El código de autenticidad no coincide. El documento puede haber sido alterado o los datos ingresados son incorrectos.';
                    }
                }
            } catch (Throwable $e) {
                $resultado  = 'error';
                $msgDetalle = 'Error al verificar: ' . $e->getMessage();
            }
        } else {
            $resultado  = 'error';
            $msgDetalle = 'Formato de folio inválido. El folio debe tener el formato METIS-YYYYMMDD-NNNNNN (ej: METIS-20260502-000011).';
        }
    }
}

$pageTitle = 'Verificar documento · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.vd-wrap { max-width: 680px; margin: 0 auto; }

.vd-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #2563eb 100%);
    color: #fff; border-radius: 16px; padding: 1.75rem 2rem; margin-bottom: 1.5rem;
    box-shadow: 0 12px 32px rgba(15,23,42,.14);
}
.vd-hero h2 { margin: 0 0 .3rem; font-size: 1.35rem; font-weight: 700; }
.vd-hero p  { margin: 0; font-size: .88rem; color: rgba(255,255,255,.72); line-height: 1.5; }

.vd-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 1.5rem; box-shadow: 0 1px 3px rgba(15,23,42,.05); margin-bottom: 1rem;
}
.vd-card-title {
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .09em; color: #2563eb; margin: 0 0 1.1rem;
    display: flex; align-items: center; gap: .4rem;
}
.vd-field { margin-bottom: .9rem; }
.vd-label { display: block; font-size: .78rem; font-weight: 600; color: #334155; margin-bottom: .3rem; }
.vd-control {
    width: 100%; border: 1px solid #cbd5e1; border-radius: 8px;
    padding: .58rem .85rem; font-size: .95rem; font-family: var(--font-mono, monospace);
    color: #0f172a; outline: none; background: #fff; box-sizing: border-box;
    letter-spacing: .04em; text-transform: uppercase;
    transition: border-color .15s, box-shadow .15s;
}
.vd-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.vd-help { font-size: .76rem; color: #94a3b8; margin-top: .28rem; }
.vd-btn {
    width: 100%; border: none; background: #1e3a8a; color: #fff; border-radius: 8px;
    padding: .72rem; font-size: .9rem; font-weight: 600; cursor: pointer;
    font-family: inherit; transition: background .15s; margin-top: .4rem;
    display: flex; align-items: center; justify-content: center; gap: .45rem;
}
.vd-btn:hover { background: #1e40af; }

/* Resultado */
.vd-result {
    border-radius: 14px; padding: 1.35rem 1.5rem; margin-bottom: 1rem;
    border: 2px solid; display: flex; align-items: flex-start; gap: 1rem;
}
.vd-result.ok    { background: #f0fdf4; border-color: #86efac; }
.vd-result.error { background: #fef2f2; border-color: #fca5a5; }
.vd-result-icon  { font-size: 2.25rem; flex-shrink: 0; line-height: 1; }
.vd-result.ok    .vd-result-icon { color: #16a34a; }
.vd-result.error .vd-result-icon { color: #dc2626; }
.vd-result-body h3 { margin: 0 0 .3rem; font-size: 1.05rem; font-weight: 700; }
.vd-result.ok    .vd-result-body h3 { color: #15803d; }
.vd-result.error .vd-result-body h3 { color: #b91c1c; }
.vd-result-body p { margin: 0; font-size: .88rem; color: #334155; line-height: 1.5; }

/* Datos del caso verificado */
.vd-caso-data {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: .9rem 1rem; margin-top: .9rem;
}
.vd-caso-row {
    display: grid; grid-template-columns: 140px 1fr; gap: .35rem .85rem;
    font-size: .86rem; padding: .28rem 0; border-bottom: 1px solid #f1f5f9;
    align-items: baseline;
}
.vd-caso-row:last-child { border-bottom: none; }
.vd-caso-lbl { font-size: .74rem; font-weight: 600; color: #64748b; }
.vd-caso-val { color: #0f172a; }

.vd-instrucciones {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 1rem; font-size: .84rem; color: #475569; line-height: 1.6;
}
.vd-instrucciones ol { margin: .5rem 0 0 1.2rem; padding: 0; }
.vd-instrucciones li { margin-bottom: .3rem; }
</style>

<div class="vd-wrap">

    <div class="vd-hero">
        <h2><i class="bi bi-shield-check"></i> Verificador de autenticidad</h2>
        <p>Confirma que un documento PDF generado por Metis SGCE es auténtico y no ha sido alterado.</p>
    </div>

    <?php if ($resultado === 'ok'): ?>
    <div class="vd-result ok">
        <div class="vd-result-icon"><i class="bi bi-patch-check-fill"></i></div>
        <div class="vd-result-body">
            <h3>Documento auténtico</h3>
            <p><?= e($msgDetalle) ?></p>
            <?php if ($casoData): ?>
            <div class="vd-caso-data">
                <div class="vd-caso-row">
                    <span class="vd-caso-lbl">N° de caso</span>
                    <span class="vd-caso-val"><?= e((string)($casoData['numero_caso'] ?? '-')) ?></span>
                </div>
                <div class="vd-caso-row">
                    <span class="vd-caso-lbl">Fecha de ingreso</span>
                    <span class="vd-caso-val"><?= $casoData['fecha_ingreso'] ? date('d/m/Y H:i', strtotime((string)$casoData['fecha_ingreso'])) : '-' ?></span>
                </div>
                <div class="vd-caso-row">
                    <span class="vd-caso-lbl">Estado</span>
                    <span class="vd-caso-val"><?= e(ucfirst((string)($casoData['estado'] ?? '-'))) ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($resultado === 'error'): ?>
    <div class="vd-result error">
        <div class="vd-result-icon"><i class="bi bi-x-circle-fill"></i></div>
        <div class="vd-result-body">
            <h3>No se pudo verificar</h3>
            <p><?= e($msgDetalle) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="vd-card">
        <p class="vd-card-title"><i class="bi bi-search"></i> Ingresar datos del documento</p>
        <form method="post">
            <div class="vd-field">
                <label class="vd-label">Folio del documento</label>
                <input class="vd-control" type="text" name="folio"
                       placeholder="METIS-20260502-000011"
                       value="<?= e((string)($_POST['folio'] ?? '')) ?>">
                <span class="vd-help">Encuéntralo en el pie de página o en el encabezado del PDF.</span>
            </div>
            <div class="vd-field">
                <label class="vd-label">Código de autenticidad</label>
                <input class="vd-control" type="text" name="hash"
                       placeholder="AB12CD34EF56GH78"
                       value="<?= e((string)($_POST['hash'] ?? '')) ?>">
                <span class="vd-help">16 caracteres alfanuméricos que aparecen junto al folio.</span>
            </div>
            <button type="submit" class="vd-btn">
                <i class="bi bi-shield-lock-fill"></i> Verificar autenticidad
            </button>
        </form>
    </div>

    <div class="vd-instrucciones">
        <strong>¿Dónde encuentro el folio y el código?</strong>
        <ol>
            <li>Abre el PDF generado por Metis SGCE.</li>
            <li>En el <strong>pie de página</strong> encontrarás: <em>Folio METIS-YYYYMMDD-NNNNNN · Código de autenticidad: XXXXXXXXXXXXXXXX</em></li>
            <li>Copia ambos datos e ingrésalos en el formulario de arriba.</li>
        </ol>
    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
ob_end_flush();
?>
