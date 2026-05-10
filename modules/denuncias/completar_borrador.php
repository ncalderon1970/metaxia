<?php
declare(strict_types=1);
ob_start();

/*
 * completar_borrador.php
 * Edición de borradores usando los mismos partials de crear/.
 */

require_once __DIR__ . '/crear/crear_bootstrap.php';

$borradorId   = (int)($_GET['id'] ?? 0);
$borradorData = [];
$borradorPart = [];
$colegioId    = $colegioIdActual;

if ($borradorId <= 0) {
    header('Location: ' . APP_URL . '/modules/denuncias/index.php');
    exit;
}

// Cargar borrador
$sc = $pdo->prepare("
    SELECT c.* FROM casos c
    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    WHERE c.id = ? AND c.colegio_id = ?
      AND (c.estado = 'borrador' OR ec.codigo = 'borrador')
    LIMIT 1
");
$sc->execute([$borradorId, $colegioId]);
$borradorData = $sc->fetch() ?: [];

if (!$borradorData) {
    header('Location: ' . APP_URL . '/modules/denuncias/index.php');
    exit;
}

// Cargar participantes
$sbp = $pdo->prepare("SELECT * FROM caso_participantes WHERE caso_id = ? ORDER BY id ASC");
$sbp->execute([$borradorId]);
$borradorPart = $sbp->fetchAll() ?: [];

$pageTitle = 'Completar borrador · ' . e((string)($borradorData['numero_caso'] ?? ''));
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
require_once __DIR__ . '/crear/crear_styles.php';

// Capturar el header y ajustar action, hero y campos
ob_start();
require_once __DIR__ . '/crear/crear_header.php';
$headerHtml = ob_get_clean();

$headerHtml = str_replace(
    'action="' . APP_URL . '/modules/denuncias/guardar.php"',
    'action="' . APP_URL . '/modules/denuncias/actualizar_borrador.php"',
    $headerHtml
);
$headerHtml = str_replace(
    '<h2>Registrar Incidente / Denuncia</h2>',
    '<h2><i class="bi bi-pencil-fill" style="opacity:.8;margin-right:.3rem;"></i>Completar borrador</h2>',
    $headerHtml
);
$headerHtml = str_replace(
    'Ingreso inicial del caso. Primero identifica intervinientes; luego registra fecha, hora, lugar, relato y marcadores normativos preliminares vinculados a convivencia educativa y medidas formativas/disciplinarias.',
    'N° ' . htmlspecialchars((string)($borradorData['numero_caso'] ?? ''), ENT_QUOTES) . ' · Completa los campos pendientes y haz clic en "Registrar denuncia".',
    $headerHtml
);
$headerHtml = str_replace(
    '<input type="hidden" name="denunciante" id="denuncianteOculto" value="">',
    '<input type="hidden" name="denunciante" id="denuncianteOculto" value="">
    <input type="hidden" name="_caso_id" value="' . $borradorId . '">
    <input type="hidden" name="_submit_mode" value="registrar">',
    $headerHtml
);
// Guardar borrador: sobrescribir _submit_mode antes de enviar
$headerHtml = str_replace(
    'name="_submit_mode" value="borrador"',
    'name="_submit_mode_borrador" value="borrador" onclick="document.querySelector(\'[name=_submit_mode]\').value=\'borrador\'"',
    $headerHtml
);
echo $headerHtml;

require_once __DIR__ . '/crear/tab_intervinientes.php';
require_once __DIR__ . '/crear/tab_datos_denuncia.php';
require_once __DIR__ . '/crear/tab_comunicacion_apoderado.php';
require_once __DIR__ . '/crear/crear_sidebar.php';
?>

</form>

<?php
// Inyectar participantes del borrador ANTES de que crear_scripts los lea
$_borradorPartJson = json_encode(array_map(fn($p) => [
    'nombre'    => (string)($p['nombre_referencial'] ?? ''),
    'run'       => (string)($p['run'] ?? '0-0'),
    'tipo'      => (string)($p['tipo_participante'] ?? 'alumno'),
    'condicion' => (string)($p['rol_en_caso'] ?? ''),
    'esAnonimo' => (int)($p['es_anonimo'] ?? 0) === 1,
], $borradorPart), JSON_UNESCAPED_UNICODE);
?>
<script>window._borradorPart = <?= $_borradorPartJson ?>;</script>
<?php require_once __DIR__ . '/crear/crear_scripts.php'; ?>

<script>
(function () {
    var d = <?= json_encode([
        'relato'              => (string)($borradorData['relato'] ?? ''),
        'contexto'            => (string)($borradorData['contexto'] ?? ''),
        'lugar_hechos'        => (string)($borradorData['lugar_hechos'] ?? ''),
        'canal_ingreso'       => (string)($borradorData['canal_ingreso'] ?? ''),
        'marco_legal'         => (string)($borradorData['marco_legal'] ?? 'ley21809'),
        'fecha_hora_incidente'=> $borradorData['fecha_hora_incidente']
            ? date('Y-m-d\TH:i', strtotime((string)$borradorData['fecha_hora_incidente']))
            : '',
        'comunicacion_apoderado_estado'    => (string)($borradorData['comunicacion_apoderado_estado'] ?? 'pendiente'),
        'comunicacion_apoderado_modalidad' => (string)($borradorData['comunicacion_apoderado_modalidad'] ?? ''),
        'comunicacion_apoderado_notas'     => (string)($borradorData['comunicacion_apoderado_notas'] ?? ''),
    ], JSON_UNESCAPED_UNICODE) ?>;

    var bPart = <?= json_encode(array_map(fn($p) => [
        'nombre'    => (string)($p['nombre_referencial'] ?? ''),
        'run'       => (string)($p['run'] ?? '0-0'),
        'tipo'      => (string)($p['tipo_participante'] ?? 'alumno'),
        'condicion' => (string)($p['rol_en_caso'] ?? ''),
        'esAnonimo' => (int)($p['es_anonimo'] ?? 0) === 1,
    ], $borradorPart), JSON_UNESCAPED_UNICODE) ?>;

    var condTextos = { 'denunciante':'Denunciante','victima':'Víctima','testigo':'Testigo','denunciado':'Denunciado' };

    function setVal(name, val) {
        if (!val && val !== 0) return;
        var el = document.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = val;
        el.dispatchEvent(new Event('input',  { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    window.addEventListener('DOMContentLoaded', function () {
        setVal('relato',               d.relato);
        setVal('contexto',             d.contexto);
        setVal('lugar_hechos',         d.lugar_hechos);
        setVal('canal_ingreso',        d.canal_ingreso);
        setVal('marco_legal',          d.marco_legal);
        setVal('fecha_hora_incidente', d.fecha_hora_incidente);
        setVal('comunicacion_apoderado_estado',    d.comunicacion_apoderado_estado);
        setVal('comunicacion_apoderado_modalidad', d.comunicacion_apoderado_modalidad);
        setVal('comunicacion_apoderado_notas',     d.comunicacion_apoderado_notas);

        // Flags Ley 21.809
        <?php foreach (json_decode((string)($borradorData['ley21809_flags'] ?? '[]'), true) ?: [] as $flag): ?>
        var cbFlag = document.querySelector('input[name="ley21809_flags[]"][value="<?= e($flag) ?>"]');
        if (cbFlag) { cbFlag.checked = true; cbFlag.dispatchEvent(new Event('change',{bubbles:true})); }
        <?php endforeach; ?>

        // Intervinientes — usar setTimeout para esperar que crear_scripts inicialice el array
        if (bPart.length > 0) {
            setTimeout(function () {
                if (typeof intervinientes === 'undefined') return;
                intervinientes.length = 0; // limpiar antes de restaurar
                bPart.forEach(function (p) {
                    intervinientes.push({
                        nombre: p.nombre, run: p.run,
                        tipoBusqueda: p.tipo, tipoPersona: p.tipo,
                        tipoTexto: p.tipo.charAt(0).toUpperCase() + p.tipo.slice(1),
                        condicion: p.condicion, condicionTexto: condTextos[p.condicion] || p.condicion,
                        personaId: '', busquedaTexto: p.nombre,
                        esAnonimo: p.esAnonimo, esNN: false,
                    });
                });
                if (typeof renderResumenIntervinientes === 'function') renderResumenIntervinientes();
                if (typeof actualizarComunicacionApoderado === 'function') actualizarComunicacionApoderado();
            }, 150);
        }
    });
})();
</script>

<?php
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
ob_end_flush();
?>
