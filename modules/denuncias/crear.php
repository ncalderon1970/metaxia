<?php
declare(strict_types=1);

/*
 * Fase 0.5.38G — Crear denuncia modularizado.
 * Este archivo queda como orquestador de la pantalla.
 * La lógica, estilos, pestañas y scripts se cargan desde modules/denuncias/crear/.
 */

require_once __DIR__ . '/crear/crear_bootstrap.php';

// ── Pre-cargar borrador si viene con borrador_id ──────────
$borradorId   = (int)($_GET['borrador_id'] ?? 0);
$borradorData = [];
$borradorPart = [];
$colegioId    = $colegioIdActual; // definido en crear_bootstrap.php
if ($borradorId > 0) {
    try {
        $sbr = $pdo->prepare("
            SELECT c.* FROM casos c
            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
            WHERE c.id = ? AND c.colegio_id = ?
              AND (c.estado = 'borrador' OR ec.codigo = 'borrador')
            LIMIT 1
        ");
        $sbr->execute([$borradorId, $colegioId]);
        $borradorData = $sbr->fetch() ?: [];

        // Cargar participantes guardados
        if ($borradorData) {
            $sbp = $pdo->prepare("
                SELECT nombre_referencial, run, tipo_participante, rol_en_caso
                FROM caso_participantes
                WHERE caso_id = ?
                ORDER BY id ASC
            ");
            $sbp->execute([$borradorId]);
            $borradorPart = $sbp->fetchAll() ?: [];
        }
    } catch (Throwable $e) { $borradorData = []; $borradorPart = []; }
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';

require_once __DIR__ . '/crear/crear_styles.php';
require_once __DIR__ . '/crear/crear_header.php';

require_once __DIR__ . '/crear/tab_intervinientes.php';
require_once __DIR__ . '/crear/tab_datos_denuncia.php';
require_once __DIR__ . '/crear/tab_comunicacion_apoderado.php';
require_once __DIR__ . '/crear/crear_sidebar.php';
?>

<form>
    <?php if ($borradorId > 0): ?>
    <input type="hidden" name="_borrador_id" value="<?= $borradorId ?>">
    <?php endif; ?>
</form>

<?php
require_once __DIR__ . '/crear/crear_scripts.php';

// ── JS: pre-rellenar campos del borrador ──────────────────
if ($borradorId > 0 && $borradorData): ?>
<script>
(function () {
    var b = <?= json_encode([
        'relato'              => (string)($borradorData['relato'] ?? ''),
        'contexto'            => (string)($borradorData['contexto'] ?? ''),
        'lugar_hechos'        => (string)($borradorData['lugar_hechos'] ?? ''),
        'fecha_hora_incidente'=> $borradorData['fecha_hora_incidente']
                                    ? date('Y-m-d\TH:i', strtotime((string)$borradorData['fecha_hora_incidente']))
                                    : '',
        'canal_ingreso'       => (string)($borradorData['canal_ingreso'] ?? ''),
        'marco_legal'         => (string)($borradorData['marco_legal'] ?? ''),
    ], JSON_UNESCAPED_UNICODE) ?>;

    var bPart = <?= json_encode(array_map(function($p) {
        return [
            'nombre'    => (string)($p['nombre_referencial'] ?? ''),
            'run'       => (string)($p['run'] ?? '0-0'),
            'tipo'      => (string)($p['tipo_participante'] ?? 'alumno'),
            'condicion' => (string)($p['rol_en_caso'] ?? ''),
        ];
    }, $borradorPart), JSON_UNESCAPED_UNICODE) ?>;

    var condTextos = {
        'denunciante': 'Denunciante', 'victima': 'Víctima',
        'testigo': 'Testigo', 'denunciado': 'Denunciado'
    };

    function setVal(name, val) {
        if (!val) return;
        var el = document.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = val;
        el.dispatchEvent(new Event('input',  { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    window.addEventListener('DOMContentLoaded', function () {
        // Pre-rellenar campos de texto
        setVal('relato',               b.relato);
        setVal('contexto',             b.contexto);
        setVal('lugar_hechos',         b.lugar_hechos);
        setVal('fecha_hora_incidente', b.fecha_hora_incidente);
        setVal('canal_ingreso',        b.canal_ingreso);
        setVal('marco_legal',          b.marco_legal);

        // Restaurar intervinientes en el resumen
        if (bPart.length > 0 && typeof intervinientes !== 'undefined') {
            bPart.forEach(function (p) {
                intervinientes.push({
                    nombre:         p.nombre,
                    run:            p.run,
                    tipoBusqueda:   p.tipo,
                    tipoPersona:    p.tipo,
                    tipoTexto:      p.tipo.charAt(0).toUpperCase() + p.tipo.slice(1),
                    condicion:      p.condicion,
                    condicionTexto: condTextos[p.condicion] || p.condicion,
                    personaId:      '',
                    busquedaTexto:  p.nombre,
                    esAnonimo:      false,
                    esNN:           false,
                });
            });
            if (typeof renderResumenIntervinientes === 'function') renderResumenIntervinientes();
            if (typeof actualizarComunicacionApoderado === 'function') actualizarComunicacionApoderado();
        }

        // Aviso borrador
        var hero = document.querySelector('.nd-hero');
        if (hero) {
            var aviso = document.createElement('div');
            aviso.style.cssText = 'background:#fef3c7;border:1px solid #fde68a;border-radius:10px;' +
                'padding:.65rem 1rem;margin-top:.75rem;font-size:.84rem;color:#92400e;' +
                'display:flex;align-items:center;gap:.5rem;';
            aviso.innerHTML = '<i class="bi bi-floppy-fill"></i> <strong>Completando borrador</strong> — ' +
                'Los campos se pre-rellenaron con los datos guardados. Completa y haz clic en "Registrar denuncia".';
            hero.appendChild(aviso);
        }
    });
})();
</script>
<?php endif; ?>

<?php
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
?>
