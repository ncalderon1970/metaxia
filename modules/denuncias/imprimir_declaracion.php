<?php
declare(strict_types=1);

/**
 * Metis · Imprimir declaración
 *
 * Genera un documento imprimible para que el declarante
 * y el encargado de convivencia lo firmen físicamente.
 *
 * GET ?id=<declaracion_id>
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$declId    = (int)($_GET['id'] ?? 0);

if ($declId <= 0) {
    http_response_code(400);
    exit('ID de declaración inválido.');
}

// ── Cargar declaración ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        d.*,
        p.nombre_referencial AS participante_nombre,
        p.rol_en_caso        AS participante_rol,
        c.numero_caso,
        c.descripcion        AS caso_descripcion,
        c.fecha_ingreso      AS caso_fecha_ingreso,
        c.colegio_id
    FROM caso_declaraciones d
    LEFT JOIN caso_participantes p ON p.id = d.participante_id
    LEFT JOIN casos c              ON c.id  = d.caso_id
    WHERE d.id = ?
    LIMIT 1
");
$stmt->execute([$declId]);
$decl = $stmt->fetch();

if (!$decl || (int)$decl['colegio_id'] !== $colegioId) {
    http_response_code(404);
    exit('Declaración no encontrada o sin permisos.');
}

// ── Datos del colegio ─────────────────────────────────────────────────────────
$colegio = null;
try {
    $sc = $pdo->prepare("SELECT nombre, rbd, ciudad, comuna FROM colegios WHERE id = ? LIMIT 1");
    $sc->execute([$colegioId]);
    $colegio = $sc->fetch() ?: null;
} catch (Throwable $e) {}

$nombreColegio = $colegio ? e((string)($colegio['nombre'] ?? '')) : 'Establecimiento Educacional';
$rbdColegio    = $colegio ? e((string)($colegio['rbd'] ?? ''))    : '';
$ciudadColegio = $colegio ? e((string)($colegio['ciudad'] ?? $colegio['comuna'] ?? '')) : '';

// ── Helpers de formato ────────────────────────────────────────────────────────
$rolLabels = [
    'victima'     => 'Víctima',
    'denunciante' => 'Denunciante',
    'denunciado'  => 'Denunciado/a',
    'testigo'     => 'Testigo',
    'involucrado' => 'Otro interviniente',
    'declarante'  => 'Declarante',
];

$calidadLabel = $rolLabels[strtolower(trim((string)($decl['calidad_procesal'] ?? '')))]
              ?? ucfirst((string)($decl['calidad_procesal'] ?? 'Declarante'));

$fechaDecl = '';
if (!empty($decl['fecha_declaracion'])) {
    try {
        $dt = new DateTimeImmutable((string)$decl['fecha_declaracion']);
        $meses = ['enero','febrero','marzo','abril','mayo','junio',
                  'julio','agosto','septiembre','octubre','noviembre','diciembre'];
        $fechaDecl = (int)$dt->format('d') . ' de ' . $meses[(int)$dt->format('m') - 1]
                   . ' de ' . $dt->format('Y') . ', ' . $dt->format('H:i') . ' hrs.';
    } catch (Throwable $e) {
        $fechaDecl = (string)$decl['fecha_declaracion'];
    }
}

$numeroCaso = e((string)($decl['numero_caso'] ?? 'S/N'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Declaración · <?= $numeroCaso ?></title>
<style>
/* ── Base ────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    color: #111;
    background: #fff;
    padding: 0;
}

/* ── Pantalla ────────────────────────────────────────────────── */
@media screen {
    body { background: #e5e7eb; padding: 2rem 0; }
    .pagina {
        background: #fff;
        width: 21cm;
        min-height: 29.7cm;
        margin: 0 auto 2rem;
        padding: 2.5cm 2.5cm 3cm;
        box-shadow: 0 4px 24px rgba(0,0,0,.18);
        border-radius: 4px;
    }
    .no-imprimir {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    .btn-print {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .6rem 1.5rem;
        background: #1d4ed8;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 1rem;
        font-family: sans-serif;
        cursor: pointer;
        text-decoration: none;
    }
    .btn-print:hover { background: #1e40af; }
    .btn-close {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .6rem 1.2rem;
        background: #6b7280;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 1rem;
        font-family: sans-serif;
        cursor: pointer;
        margin-left: .75rem;
        text-decoration: none;
    }
}

/* ── Impresión ───────────────────────────────────────────────── */
@media print {
    body { background: #fff; font-size: 11pt; }
    .pagina { padding: 1.8cm 2cm 2.5cm; }
    .no-imprimir { display: none !important; }
    .ficha, .ratificacion { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: A4 portrait; margin: 0; }
}

/* ── Cabecera institucional ──────────────────────────────────── */
.cabecera {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    border-bottom: 2.5pt solid #111;
    padding-bottom: .8rem;
    margin-bottom: 1.2rem;
}
.cabecera-logo {
    width: 60px;
    height: 60px;
    border: 1px solid #ccc;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.6rem;
    color: #6b7280;
}
.cabecera-info { flex: 1; }
.cabecera-nombre {
    font-size: 13pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.cabecera-sub {
    font-size: 9.5pt;
    color: #444;
    margin-top: .15rem;
}
.cabecera-sello {
    text-align: right;
    font-size: 9pt;
    color: #444;
    line-height: 1.5;
}

/* ── Título del documento ────────────────────────────────────── */
.doc-titulo {
    text-align: center;
    margin: 1.4rem 0 .3rem;
    font-size: 14pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.doc-subtitulo {
    text-align: center;
    font-size: 10pt;
    color: #555;
    margin-bottom: 1.4rem;
}
.separador {
    border: none;
    border-top: 1pt solid #aaa;
    margin: 1rem 0;
}

/* ── Datos del caso ──────────────────────────────────────────── */
.ficha {
    border: 1pt solid #bbb;
    border-radius: 4px;
    padding: .7rem 1rem;
    margin-bottom: 1.2rem;
    background: #fafafa;
}
.ficha-title {
    font-size: 9.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #444;
    margin-bottom: .5rem;
}
.ficha-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .25rem .5rem;
}
.ficha-row { font-size: 10.5pt; line-height: 1.6; }
.ficha-label { color: #555; }

/* ── Cuerpo de la declaración ────────────────────────────────── */
.decl-titulo {
    font-size: 11pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: .6rem;
    color: #222;
}
.decl-cuerpo {
    border: 1pt solid #bbb;
    border-radius: 4px;
    padding: .9rem 1.1rem;
    font-size: 11pt;
    line-height: 1.8;
    text-align: justify;
    min-height: 6cm;
    white-space: pre-wrap;
    word-break: break-word;
    background: #fff;
}

/* ── Observaciones ───────────────────────────────────────────── */
.obs-bloque {
    margin-top: .9rem;
    border-left: 3pt solid #d1d5db;
    padding-left: .75rem;
    font-size: 10pt;
    color: #444;
    font-style: italic;
}
.obs-label { font-weight: bold; font-style: normal; color: #222; }

/* ── Ratificación ────────────────────────────────────────────── */
.ratificacion {
    margin-top: 1.2rem;
    padding: .7rem 1rem;
    border: 1pt dashed #999;
    border-radius: 4px;
    font-size: 10.5pt;
    line-height: 1.7;
    background: #fffbf0;
}

/* ── Firmas ──────────────────────────────────────────────────── */
.firmas {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-top: 2.5rem;
}
.firma-bloque { text-align: center; }
.firma-linea {
    border-bottom: 1pt solid #333;
    margin: 3.5rem auto 0;
    width: 85%;
}
.firma-nombre {
    margin-top: .45rem;
    font-size: 10.5pt;
    font-weight: bold;
    word-break: break-word;
}
.firma-cargo {
    font-size: 9.5pt;
    color: #555;
    margin-top: .15rem;
}
.firma-run {
    font-size: 9pt;
    color: #777;
    margin-top: .1rem;
}

/* ── Pie ─────────────────────────────────────────────────────── */
.pie {
    margin-top: 2.5rem;
    border-top: 1pt solid #ccc;
    padding-top: .6rem;
    font-size: 8.5pt;
    color: #888;
    text-align: center;
}
</style>
</head>
<body>

<!-- ══ Botones de pantalla ══════════════════════════════════════════════════ -->
<div class="no-imprimir">
    <button class="btn-print" onclick="window.print()">
        &#128438; Imprimir documento
    </button>
    <button class="btn-close" onclick="window.close()">
        ✕ Cerrar
    </button>
</div>

<!-- ══ Página imprimible ════════════════════════════════════════════════════ -->
<div class="pagina">

    <!-- Cabecera -->
    <div class="cabecera">
        <div class="cabecera-logo">&#127979;</div>
        <div class="cabecera-info">
            <div class="cabecera-nombre"><?= $nombreColegio ?></div>
            <div class="cabecera-sub">
                Sistema de Gestión de Convivencia Escolar
                <?php if ($rbdColegio !== ''): ?>
                    &nbsp;·&nbsp; RBD <?= $rbdColegio ?>
                <?php endif; ?>
                <?php if ($ciudadColegio !== ''): ?>
                    &nbsp;·&nbsp; <?= $ciudadColegio ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="cabecera-sello">
            <div style="font-weight:bold;text-transform:uppercase;font-size:8.5pt;">
                Encargado(a) de Convivencia
            </div>
            <div>Firma y Timbre</div>
            <div style="margin-top:.3rem;border:1pt solid #bbb;width:4.5cm;height:2.5cm;
                        display:flex;align-items:center;justify-content:center;
                        font-size:8pt;color:#ccc;">
                (Sello)
            </div>
        </div>
    </div>

    <!-- Título -->
    <div class="doc-titulo">Acta de Declaración</div>
    <div class="doc-subtitulo">
        Caso N° <?= $numeroCaso ?>
        &nbsp;·&nbsp;
        Convivencia Escolar — Ley 21.809
    </div>

    <!-- Ficha del caso y declarante -->
    <div class="ficha">
        <div class="ficha-title">Datos del caso y declarante</div>
        <div class="ficha-grid">
            <div class="ficha-row">
                <span class="ficha-label">N° de caso:</span>&nbsp;
                <strong><?= $numeroCaso ?></strong>
            </div>
            <div class="ficha-row">
                <span class="ficha-label">Fecha y hora declaración:</span>&nbsp;
                <?= $fechaDecl !== '' ? $fechaDecl : '—' ?>
            </div>
            <div class="ficha-row">
                <span class="ficha-label">Nombre declarante:</span>&nbsp;
                <?= e((string)($decl['nombre_declarante'] ?? '—')) ?>
            </div>
            <div class="ficha-row">
                <span class="ficha-label">RUN declarante:</span>&nbsp;
                <?php
                $runD = (string)($decl['run_declarante'] ?? '');
                echo ($runD !== '' && $runD !== '0-0') ? e($runD) : 'Sin RUN registrado';
                ?>
            </div>
            <div class="ficha-row">
                <span class="ficha-label">Calidad procesal:</span>&nbsp;
                <?= e($calidadLabel) ?>
            </div>
            <?php if (!empty($decl['participante_nombre'])): ?>
            <div class="ficha-row">
                <span class="ficha-label">Interviniente vinculado:</span>&nbsp;
                <?= e((string)$decl['participante_nombre']) ?>
                <?php if (!empty($decl['participante_rol'])): ?>
                    (<?= e($rolLabels[strtolower(trim((string)$decl['participante_rol']))] ?? (string)$decl['participante_rol']) ?>)
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Declaración -->
    <div class="decl-titulo">Texto de la declaración</div>
    <div class="decl-cuerpo"><?= e((string)($decl['texto_declaracion'] ?? '')) ?></div>

    <?php if (!empty($decl['observaciones'])): ?>
    <div class="obs-bloque">
        <span class="obs-label">Observaciones del encargado:</span>&nbsp;
        <?= nl2br(e((string)$decl['observaciones'])) ?>
    </div>
    <?php endif; ?>

    <!-- Ratificación -->
    <div class="ratificacion">
        El/la declarante presta la presente declaración en forma voluntaria, en el marco del
        proceso de investigación de convivencia escolar del establecimiento, de conformidad con
        la <strong>Ley N° 21.809</strong> y el Reglamento Interno. Declara que el relato anterior
        es fiel y verdadero a su leal saber y entender, y autoriza al establecimiento a utilizarla
        en el proceso correspondiente. Se le informa del carácter confidencial del procedimiento
        y de sus derechos conforme a la normativa vigente.
    </div>

    <!-- Firmas -->
    <?php $runD = (string)($decl['run_declarante'] ?? ''); ?>
    <div class="firmas">
        <!-- Firma del declarante -->
        <div class="firma-bloque">
            <div class="firma-linea"></div>
            <div class="firma-nombre"><?= e((string)($decl['nombre_declarante'] ?? '')) ?></div>
            <div class="firma-cargo"><?= e($calidadLabel) ?></div>
            <?php if ($runD !== '' && $runD !== '0-0'): ?>
            <div class="firma-run">RUN: <?= e($runD) ?></div>
            <?php endif; ?>
        </div>

        <!-- Firma del encargado -->
        <div class="firma-bloque">
            <div class="firma-linea"></div>
            <div class="firma-nombre">Encargado(a) de Convivencia Escolar</div>
            <div class="firma-cargo"><?= $nombreColegio ?></div>
        </div>
    </div>

    <!-- Pie de página -->
    <div class="pie">
        Documento generado por <strong>Metis SGCE</strong> —
        Impreso el <?= date('d/m/Y \a \l\a\s H:i') ?> hrs. —
        N° Declaración: <?= $declId ?>
        <br>
        Este documento tiene valor probatorio dentro del procedimiento de convivencia escolar
        según Ley 21.809. No distribuir sin autorización del encargado de convivencia.
    </div>

</div><!-- /.pagina -->
</body>
</html>
