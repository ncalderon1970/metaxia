<?php
declare(strict_types=1);
/**
 * Metis · Informe Ejecutivo del Expediente
 * Destinatarios: organismos del Estado (MINEDUC, Fiscalía, Tribunales, SENAME/Mejor Niñez, etc.)
 * Incluye: identificación, hechos, participantes, clasificación, marcadores normativos,
 *          pauta de riesgo, plan de acción, seguimiento por participante,
 *          comunicaciones al apoderado, gestión ejecutiva, declaraciones, evidencias,
 *          aula segura, cierre formal y firma.
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/includes/ver_helpers.php';
require_once __DIR__ . '/includes/ver_queries.php';

require_once __DIR__ . '/includes/snapshot_report_helpers.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$casoId    = (int)($_GET['id'] ?? 0);
$colegioId = (int)($user['colegio_id'] ?? 0);

if ($casoId <= 0) { http_response_code(400); exit('ID de caso inválido.'); }

// Fix superadmin (colegio_id = 0)
if ($colegioId === 0) {
    $s = $pdo->prepare("SELECT colegio_id FROM casos WHERE id = ? LIMIT 1");
    $s->execute([$casoId]);
    $colegioId = (int)($s->fetchColumn() ?: 0);
}

// ══════════════════════════════════════════════════════════════
// 1. CASO BASE
// ══════════════════════════════════════════════════════════════
$stmt = $pdo->prepare("
    SELECT c.*,
           ec.nombre  AS estado_nombre,
           ec.codigo  AS estado_codigo,
           u.nombre   AS creado_por_nombre
    FROM casos c
    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    LEFT JOIN usuarios u     ON u.id  = c.creado_por
    WHERE c.id = ? AND c.colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $colegioId]);
$caso = $stmt->fetch();
if (!$caso) { http_response_code(404); exit('Caso no encontrado.'); }

// ══════════════════════════════════════════════════════════════
// 2. COLEGIO
// ══════════════════════════════════════════════════════════════
$stmtCol = $pdo->prepare("SELECT * FROM colegios WHERE id = ? LIMIT 1");
$stmtCol->execute([$colegioId]);
$colegio = $stmtCol->fetch() ?: [];

// ══════════════════════════════════════════════════════════════
// 3. PARTICIPANTES
// ══════════════════════════════════════════════════════════════
$stmtP = $pdo->prepare("
    SELECT cp.*,
           COALESCE(cp.snapshot_fecha_nacimiento, a.fecha_nacimiento) AS fecha_nacimiento,
           COALESCE(NULLIF(cp.snapshot_curso,''), a.curso) AS curso,
           cp.snapshot_edad, cp.snapshot_anio_escolar, cp.snapshot_nombre_social,
           cp.snapshot_sexo, cp.snapshot_genero,
           a.condicion_especial, a.diagnostico_tea, a.tiene_pie
    FROM caso_participantes cp
    LEFT JOIN alumnos a ON a.id = cp.persona_id AND cp.tipo_persona = 'alumno'
    WHERE cp.caso_id = ?
    ORDER BY FIELD(cp.rol_en_caso,'victima','denunciante','testigo','denunciado') ASC, cp.id ASC
");
$stmtP->execute([$casoId]);
$participantes = $stmtP->fetchAll();

// Agrupar participantes: si la misma persona tiene rol víctima Y denunciante,
// aparece una sola vez con todos sus roles. Clave = RUN normalizado ó nombre en mayúsculas.
$participantesAgrupados = [];
foreach ($participantes as $p) {
    $run    = metis_snapshot_run($p);
    $nombre = mb_strtoupper(metis_snapshot_nombre($p), 'UTF-8');
    $clave  = ($run !== '' && $run !== '0-0')
            ? 'run:' . preg_replace('/[.\-\s]/', '', $run)
            : 'nom:' . preg_replace('/\s+/', ' ', $nombre);

    if (!isset($participantesAgrupados[$clave])) {
        $participantesAgrupados[$clave] = $p;          // fila base (primer rol)
        $participantesAgrupados[$clave]['_roles'] = [];
    }
    $participantesAgrupados[$clave]['_roles'][] = (string)($p['rol_en_caso'] ?? '');
}

$porRol = [];
foreach ($participantes as $p) {
    $porRol[$p['rol_en_caso']][] = $p;
}

// ══════════════════════════════════════════════════════════════
// 4. CONTEXTO FAMILIAR (apoderados via función oficial)
// ══════════════════════════════════════════════════════════════
// Usa ver_cargar_contexto_familiar() que detecta columnas dinámicamente
// y resuelve el alumno por persona_id O por RUN como fallback.
$contextoFamiliar = ver_cargar_contexto_familiar($pdo, $participantes, $colegioId);

// Índice rápido alumnoId → apoderados para la sección de participantes
$apoderadosPorAlumno = [];
foreach ($contextoFamiliar as $bloque) {
    $alumnoId = (int)($bloque['alumno']['id'] ?? 0);
    if ($alumnoId > 0) {
        $apoderadosPorAlumno[$alumnoId] = $bloque['apoderados'] ?? [];
    }
}

// ══════════════════════════════════════════════════════════════
// 5. CLASIFICACIÓN NORMATIVA + MARCADORES
// ══════════════════════════════════════════════════════════════
$clasif = null;
try {
    $stmtCl = $pdo->prepare("SELECT * FROM caso_clasificacion_normativa WHERE caso_id = ? LIMIT 1");
    $stmtCl->execute([$casoId]);
    $clasif = $stmtCl->fetch() ?: null;
} catch (Throwable $e) {}

// Resolver nombre legible de área y ámbito desde DB
$_areaNombre   = '';
$_ambitoNombre = '';
if ($clasif) {
    if (!empty($clasif['area_mineduc'])) {
        try {
            $stmtAnomb = $pdo->prepare("SELECT nombre FROM denuncia_areas WHERE codigo = ? LIMIT 1");
            $stmtAnomb->execute([$clasif['area_mineduc']]);
            $_areaNombre = (string)($stmtAnomb->fetchColumn() ?: $clasif['area_mineduc']);
        } catch (Throwable $e) { $_areaNombre = (string)$clasif['area_mineduc']; }
    }
    if (!empty($clasif['ambito_mineduc'])) {
        try {
            $stmtAspNomb = $pdo->prepare("SELECT nombre FROM denuncia_aspectos WHERE codigo = ? LIMIT 1");
            $stmtAspNomb->execute([$clasif['ambito_mineduc']]);
            $_ambitoNombre = (string)($stmtAspNomb->fetchColumn() ?: $clasif['ambito_mineduc']);
        } catch (Throwable $e) { $_ambitoNombre = (string)$clasif['ambito_mineduc']; }
    }
}

// ══════════════════════════════════════════════════════════════
// 6. PAUTA DE RIESGO
// ══════════════════════════════════════════════════════════════
$pauta = null;
try {
    $stmtPr = $pdo->prepare("
        SELECT pr.*, u.nombre AS evaluador
        FROM caso_pauta_riesgo pr
        LEFT JOIN usuarios u ON u.id = pr.firmado_por_id
        WHERE pr.caso_id = ? AND pr.puntaje_total > 0
        ORDER BY pr.created_at DESC LIMIT 1
    ");
    $stmtPr->execute([$casoId]);
    $pauta = $stmtPr->fetch() ?: null;
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// 7. PLAN DE ACCIÓN
// ══════════════════════════════════════════════════════════════
$planes = [];
try {
    $stmtPa = $pdo->prepare("
        SELECT pa.*, cp.nombre_referencial, cp.rol_en_caso,
               u.nombre AS autor
        FROM caso_plan_accion pa
        INNER JOIN caso_participantes cp ON cp.id = pa.participante_id
        LEFT  JOIN usuarios u ON u.id = pa.creado_por
        WHERE pa.caso_id = ? AND pa.vigente = 1
        ORDER BY cp.id ASC
    ");
    $stmtPa->execute([$casoId]);
    $planes = $stmtPa->fetchAll();
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// 8. SESIONES DE SEGUIMIENTO (agrupadas por participante)
// ══════════════════════════════════════════════════════════════
$sesionesPorParticipante = [];
try {
    $stmtSes = $pdo->prepare("
        SELECT css.*,
               cp.nombre_referencial,
               cp.rol_en_caso,
               u.nombre AS registrador
        FROM caso_seguimiento_sesion css
        INNER JOIN caso_participantes cp ON cp.id = css.participante_id
        LEFT  JOIN usuarios u ON u.id = css.registrado_por
        WHERE css.caso_id = ?
        ORDER BY cp.id ASC, css.created_at ASC
    ");
    $stmtSes->execute([$casoId]);
    foreach ($stmtSes->fetchAll() as $ses) {
        $pid = (int)$ses['participante_id'];
        if (!isset($sesionesPorParticipante[$pid])) {
            $sesionesPorParticipante[$pid] = [
                'nombre' => (string)($ses['nombre_referencial'] ?? ''),
                'rol'    => (string)($ses['rol_en_caso'] ?? ''),
                'items'  => [],
            ];
        }
        $sesionesPorParticipante[$pid]['items'][] = $ses;
    }
} catch (Throwable $e) {}

$totalSesiones = array_sum(array_map(fn($g) => count($g['items']), $sesionesPorParticipante));

// ══════════════════════════════════════════════════════════════
// 9. COMUNICACIONES AL APODERADO (desde sesiones)
// ══════════════════════════════════════════════════════════════
$comunicaciones = [];
try {
    $stmtCom = $pdo->prepare("
        SELECT css.comunicacion_apoderado,
               css.fecha_comunicacion_apoderado,
               css.notas_comunicacion,
               css.created_at,
               cp.nombre_referencial,
               cp.rol_en_caso,
               u.nombre AS registrador
        FROM caso_seguimiento_sesion css
        INNER JOIN caso_participantes cp ON cp.id = css.participante_id
        LEFT  JOIN usuarios u ON u.id = css.registrado_por
        WHERE css.caso_id = ?
          AND css.comunicacion_apoderado IS NOT NULL
          AND css.comunicacion_apoderado != ''
        ORDER BY COALESCE(css.fecha_comunicacion_apoderado, css.created_at) ASC
    ");
    $stmtCom->execute([$casoId]);
    $comunicaciones = $stmtCom->fetchAll();
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// 10. GESTIÓN EJECUTIVA (acciones directivas)
// ══════════════════════════════════════════════════════════════
$accionesEjecutivas = [];
try {
    $stmtGe = $pdo->prepare("
        SELECT ge.*, u.nombre AS creado_por_nombre
        FROM caso_gestion_ejecutiva ge
        LEFT JOIN usuarios u ON u.id = ge.creado_por
        WHERE ge.caso_id = ?
        ORDER BY FIELD(ge.prioridad,'critica','alta','media','baja') ASC,
                 ge.fecha_compromiso ASC,
                 ge.created_at ASC
    ");
    $stmtGe->execute([$casoId]);
    $accionesEjecutivas = $stmtGe->fetchAll();
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// 11. DECLARACIONES
// ══════════════════════════════════════════════════════════════
$declaraciones = [];
try {
    $stmtDec = $pdo->prepare("
        SELECT d.*,
               p.nombre_referencial AS participante_nombre,
               p.rol_en_caso        AS participante_rol,
               u.nombre             AS registrador
        FROM caso_declaraciones d
        LEFT JOIN caso_participantes p ON p.id = d.participante_id
        LEFT JOIN usuarios u           ON u.id = d.tomada_por
        WHERE d.caso_id = ?
        ORDER BY d.fecha_declaracion ASC, d.id ASC
    ");
    $stmtDec->execute([$casoId]);
    $declaraciones = $stmtDec->fetchAll();
} catch (Throwable $e) {}

// Fallback sin JOIN si la query anterior falló por diferencias de esquema
if (!$declaraciones) {
    try {
        $stmtDec2 = $pdo->prepare("SELECT * FROM caso_declaraciones WHERE caso_id = ? ORDER BY fecha_declaracion ASC, id ASC");
        $stmtDec2->execute([$casoId]);
        $declaraciones = $stmtDec2->fetchAll();
    } catch (Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 12. EVIDENCIAS
// ══════════════════════════════════════════════════════════════
$evidencias = [];
try {
    $stmtEv = $pdo->prepare("
        SELECT ce.*, u.nombre AS subido_por_nombre
        FROM caso_evidencias ce
        LEFT JOIN usuarios u ON u.id = ce.subido_por
        WHERE ce.caso_id = ?
        ORDER BY ce.created_at ASC
    ");
    $stmtEv->execute([$casoId]);
    $evidencias = $stmtEv->fetchAll();
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// 13. AULA SEGURA
// ══════════════════════════════════════════════════════════════
$aulaSegura = null;
try {
    $stmtAs = $pdo->prepare("
        SELECT cas.*, u.nombre AS responsable
        FROM caso_aula_segura cas
        LEFT JOIN usuarios u ON u.id = cas.responsable_id
        WHERE cas.caso_id = ? AND cas.colegio_id = ?
        ORDER BY cas.created_at DESC LIMIT 1
    ");
    $stmtAs->execute([$casoId, $colegioId]);
    $aulaSegura = $stmtAs->fetch() ?: null;
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// 14. CIERRE FORMAL
// ══════════════════════════════════════════════════════════════
$cierre = null;
try {
    $stmtCierre = $pdo->prepare("
        SELECT cc.*, u.nombre AS cerrado_por
        FROM caso_cierre cc
        LEFT JOIN usuarios u ON u.id = cc.usuario_id
        WHERE cc.caso_id = ? AND cc.colegio_id = ?
        ORDER BY cc.created_at DESC LIMIT 1
    ");
    $stmtCierre->execute([$casoId, $colegioId]);
    $cierre = $stmtCierre->fetch() ?: null;
} catch (Throwable $e) {}

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════
function inf_fecha(?string $d): string {
    if (!$d || $d === '0000-00-00') return '—';
    try { return (new DateTime($d))->format('d-m-Y'); } catch (Throwable $e) { return $d; }
}
function inf_fecha_hora(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    try { return (new DateTime($d))->format('d-m-Y H:i'); } catch (Throwable $e) { return $d; }
}
function inf_si_no(mixed $v): string {
    return ($v && $v !== '0') ? 'Sí' : 'No';
}
function inf_label(string $v): string {
    static $map = [
        'victima'=>'Víctima','denunciado'=>'Denunciado/a',
        'denunciante'=>'Denunciante','testigo'=>'Testigo',
        'alumno'=>'Alumno/a','funcionario'=>'Funcionario/a',
        'apoderado'=>'Apoderado/a','externo'=>'Actor externo',
        'bajo'=>'Bajo','medio'=>'Medio','alto'=>'Alto','critico'=>'Crítico',
        'abierto'=>'Abierto','cerrado'=>'Cerrado','borrador'=>'Borrador',
        'recepcion'=>'En recepción','investigacion'=>'En investigación',
        'resolucion'=>'En resolución','seguimiento'=>'En seguimiento',
        'verde'=>'Verde','amarillo'=>'Amarillo','rojo'=>'Rojo','negro'=>'Negro',
        'padre'=>'Padre','madre'=>'Madre','abuelo'=>'Abuelo/a',
        'tio'=>'Tío/a','hermano'=>'Hermano/a','tutor'=>'Tutor/a',
        'convivencia_escolar'=>'Convivencia Escolar',
        'entre_estudiantes'=>'Entre estudiantes',
        'adulto_estudiante'=>'Adulto a estudiante',
        'estudiante_adulto'=>'Estudiante a adulto',
        'estudiante_funcionario'=>'Estudiante y funcionario',
        'conducta_contraria'=>'Conducta contraria',
        'falta_grave'=>'Falta grave',
        'maltrato_escolar'=>'Maltrato escolar',
        'acoso_escolar'=>'Acoso escolar (bullying)',
        'violencia_fisica'=>'Violencia física',
        'violencia_psicologica'=>'Violencia psicológica',
        'ciberacoso'=>'Ciberacoso',
        'discriminacion'=>'Discriminación',
        'violencia_sexual'=>'Violencia sexual',
        'ley21809'=>'Ley 21.809 — Convivencia Escolar',
        'ley21545'=>'Ley 21.545 — TEA',
        'ley21430'=>'Ley 21.430 — Garantías NNA',
        'pendiente'=>'Pendiente','en_proceso'=>'En proceso',
        'cumplida'=>'Cumplida','descartada'=>'Descartada',
        'en_revision'=>'En revisión','resuelto'=>'Resuelto',
        'cumplido'=>'Cumplido','parcial'=>'Parcial',
        'no_cumplido'=>'No cumplido',
        'baja'=>'Baja','alta'=>'Alta','critica'=>'Crítica',
        'presencial'=>'Presencial','telefono'=>'Teléfono',
        'correo'=>'Correo electrónico','whatsapp'=>'WhatsApp',
        'libreta'=>'Libreta de comunicaciones',
        'documento'=>'Documento','imagen'=>'Imagen',
        'audio'=>'Audio','video'=>'Video','archivo'=>'Archivo',
    ];
    return $map[$v] ?? ucfirst(str_replace(['_','-'], ' ', $v));
}
function inf_nivel_css(string $n): string {
    return match($n) {
        'critico'=>'#0f172a','alto'=>'#dc2626','medio'=>'#d97706',default=>'#059669'
    };
}
function inf_prioridad_css(string $p): string {
    return match($p) {
        'critica'=>'#0f172a','alta'=>'#dc2626','media'=>'#d97706',default=>'#059669'
    };
}

$emitidoEn  = (new DateTime())->format('d-m-Y H:i');
$numeroCaso = e((string)($caso['numero_caso'] ?? ''));
$pageTitle  = "Informe Expediente {$numeroCaso}";
$secNum     = 1; // contador de secciones
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <style>
    /* ── Reset y base ─────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        font-size: 13px;
        color: #1a202c;
        background: #f0f4f8;
        line-height: 1.55;
    }
    a { color: inherit; text-decoration: none; }

    /* ── Contenedor ───────────────────────────────────────── */
    .inf-wrap {
        max-width: 940px;
        margin: 1.5rem auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 12px rgba(0,0,0,.08);
        overflow: hidden;
    }

    /* ── Barra de acciones (solo pantalla) ────────────────── */
    .inf-toolbar {
        background: #1a3a5c;
        padding: .75rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
    }
    .inf-toolbar span { color: #93c5fd; font-size: .82rem; font-weight: 600; }
    .inf-btn {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        border-radius: 7px;
        padding: .4rem .9rem;
        font-size: .8rem;
        font-weight: 700;
        cursor: pointer;
        border: none;
        text-decoration: none;
    }
    .inf-btn-print { background: #fff; color: #1a3a5c; }
    .inf-btn:hover { opacity: .85; }

    /* ── Cabecera del informe ─────────────────────────────── */
    .inf-header {
        background: linear-gradient(135deg, #0f172a, #1a3a5c);
        color: #fff;
        padding: 1.75rem 2rem;
        display: flex;
        align-items: flex-start;
        gap: 1.5rem;
    }
    .inf-logo {
        width: 72px; height: 72px; object-fit: contain;
        border-radius: 8px; background: #fff; padding: 4px; flex-shrink: 0;
    }
    .inf-logo-placeholder {
        width: 72px; height: 72px; border-radius: 8px;
        background: rgba(255,255,255,.15);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem; flex-shrink: 0;
    }
    .inf-header-meta { flex: 1; }
    .inf-header-meta h1 { font-size: 1.1rem; font-weight: 800; margin-bottom: .2rem; }
    .inf-header-meta p  { font-size: .78rem; color: #93c5fd; margin-bottom: .1rem; }
    .inf-header-right   { text-align: right; font-size: .75rem; color: #93c5fd; flex-shrink: 0; }
    .inf-header-right strong { display: block; font-size: 1rem; color: #fff; margin-bottom: .1rem; }
    /* ── Índice de secciones ──────────────────────────────── */
    .inf-indice {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: .75rem 2rem;
        font-size: .76rem;
        color: #475569;
        display: flex;
        flex-wrap: wrap;
        gap: .4rem .75rem;
    }
    .inf-indice a { color: #1a3a5c; font-weight: 600; text-decoration: none; }
    .inf-indice a:hover { text-decoration: underline; }

    /* ── Secciones ────────────────────────────────────────── */
    .inf-body { padding: 1.5rem 2rem; }
    .inf-section {
        margin-bottom: 1.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }
    .inf-section-head {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: .6rem 1rem;
        display: flex;
        align-items: center;
        gap: .5rem;
    }
    .inf-section-num {
        background: #1a3a5c; color: #fff;
        border-radius: 50%; width: 22px; height: 22px;
        display: flex; align-items: center; justify-content: center;
        font-size: .7rem; font-weight: 800; flex-shrink: 0;
    }
    .inf-section-title { font-size: .82rem; font-weight: 800; color: #1a3a5c; text-transform: uppercase; letter-spacing: .06em; }
    .inf-section-body  { padding: 1rem; }

    /* ── Tablas de datos ──────────────────────────────────── */
    .inf-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
    .inf-table th {
        width: 32%; text-align: left; padding: .4rem .65rem;
        font-weight: 600; color: #475569;
        background: #f8fafc; border: 1px solid #e2e8f0; vertical-align: top;
    }
    .inf-table td { padding: .4rem .65rem; border: 1px solid #e2e8f0; vertical-align: top; }

    /* ── Participante card ────────────────────────────────── */
    .inf-part-card {
        border: 1px solid #e2e8f0; border-radius: 7px;
        margin-bottom: .75rem; overflow: hidden;
    }
    .inf-part-head {
        background: #f1f5f9; padding: .45rem .8rem;
        display: flex; align-items: center; gap: .5rem;
        font-weight: 700; font-size: .82rem;
    }
    .inf-part-body { padding: .6rem .8rem; }
    .inf-rol-badge {
        border-radius: 5px; padding: .1rem .45rem;
        font-size: .7rem; font-weight: 700; color: #fff;
    }
    .rol-victima     { background: #dc2626; }
    .rol-denunciado  { background: #0f172a; }
    .rol-denunciante { background: #d97706; }
    .rol-testigo     { background: #2563eb; }
    .rol-otro        { background: #64748b; }

    /* ── Bloque de seguimiento ────────────────────────────── */
    .inf-seg-part {
        border: 1px solid #dbeafe; border-radius: 8px;
        margin-bottom: 1rem; overflow: hidden;
    }
    .inf-seg-part-head {
        background: #eff6ff; border-bottom: 1px solid #dbeafe;
        padding: .5rem .85rem; display: flex; align-items: center; gap: .5rem;
        font-size: .81rem; font-weight: 700; color: #1e40af;
    }
    .inf-sesion {
        border-left: 3px solid #2563eb;
        padding: .6rem .85rem; margin: .5rem .85rem .5rem;
        background: #f8fafc; border-radius: 0 6px 6px 0; font-size: .81rem;
    }
    .inf-sesion:last-child { margin-bottom: .85rem; }
    .inf-sesion-head {
        display: flex; justify-content: space-between;
        margin-bottom: .3rem; flex-wrap: wrap; gap: .2rem;
    }
    .inf-sesion-head strong { color: #1a3a5c; }
    .inf-sesion-head span   { color: #64748b; font-size: .76rem; }

    /* ── Pauta de riesgo ──────────────────────────────────── */
    .inf-pauta-nivel {
        display: inline-block; border-radius: 6px;
        padding: .25rem .8rem; font-weight: 800; font-size: .9rem;
        color: #fff; margin-bottom: .5rem;
    }
    .inf-pauta-bar { height: 10px; background: #e2e8f0; border-radius: 20px; overflow: hidden; margin-bottom: .3rem; }
    .inf-pauta-bar-fill { height: 100%; border-radius: 20px; background: linear-gradient(90deg, #059669, #d97706, #dc2626); }

    /* ── Gestión ejecutiva ────────────────────────────────── */
    .inf-accion {
        border: 1px solid #e2e8f0; border-radius: 7px;
        margin-bottom: .65rem; overflow: hidden;
    }
    .inf-accion-head {
        padding: .45rem .8rem; display: flex; align-items: center;
        gap: .5rem; flex-wrap: wrap;
        background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: .81rem;
    }
    .inf-prioridad-badge {
        border-radius: 4px; padding: .1rem .45rem;
        font-size: .68rem; font-weight: 700; color: #fff;
    }
    .inf-estado-badge {
        border-radius: 4px; padding: .1rem .45rem;
        font-size: .68rem; font-weight: 700;
        background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;
    }
    .inf-estado-cumplida  { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
    .inf-estado-descartada{ background: #f1f5f9; color: #94a3b8; }
    .inf-vencida          { background: #fee2e2; border-color: #fca5a5; }
    .inf-accion-body { padding: .55rem .8rem; font-size: .8rem; }

    /* ── Comunicaciones apoderado ─────────────────────────── */
    .inf-com {
        display: flex; align-items: flex-start; gap: .65rem;
        padding: .55rem .8rem; border-bottom: 1px solid #f1f5f9; font-size: .8rem;
    }
    .inf-com:last-child { border-bottom: none; }
    .inf-com-icon {
        width: 28px; height: 28px; border-radius: 50%;
        background: #eff6ff; color: #2563eb;
        display: flex; align-items: center; justify-content: center;
        font-size: .85rem; flex-shrink: 0;
    }

    /* ── Evidencias ───────────────────────────────────────── */
    .inf-ev-row {
        display: flex; align-items: center; gap: .65rem;
        padding: .5rem .8rem; border-bottom: 1px solid #f1f5f9; font-size: .8rem;
    }
    .inf-ev-row:last-child { border-bottom: none; }
    .inf-ev-icon {
        width: 30px; height: 30px; border-radius: 6px;
        background: #f1f5f9; color: #475569;
        display: flex; align-items: center; justify-content: center;
        font-size: .9rem; flex-shrink: 0;
    }

    /* ── Texto libre ──────────────────────────────────────── */
    .inf-text { font-size: .82rem; line-height: 1.7; color: #1a202c; white-space: pre-wrap; }
    .inf-muted { color: #94a3b8; font-style: italic; font-size: .8rem; }

    /* ── Pie del informe ──────────────────────────────────── */
    .inf-footer {
        border-top: 1px solid #e2e8f0; padding: 1rem 2rem;
        font-size: .72rem; color: #94a3b8;
        display: flex; justify-content: space-between; flex-wrap: wrap; gap: .4rem;
        background: #f8fafc;
    }

    /* ── Impresión ────────────────────────────────────────── */
    @media print {
        body { background: #fff; font-size: 11px; }
        .inf-toolbar { display: none !important; }
        .inf-indice  { display: none !important; }
        .inf-wrap { max-width: 100%; margin: 0; box-shadow: none; border-radius: 0; }
        .inf-header { padding: 1rem 1.25rem; }
        .inf-body   { padding: 1rem 1.25rem; }
        .inf-section { page-break-inside: avoid; }
        .inf-part-card, .inf-sesion, .inf-accion { page-break-inside: avoid; }
        .inf-seg-part { page-break-inside: avoid; }
        @page { margin: 1.5cm; }
    }
    </style>
</head>
<body>

<div class="inf-wrap">

    <!-- Barra de acciones -->
    <div class="inf-toolbar" id="infToolbar">
        <span>Metis · Sistema de Gestión de Convivencia Escolar</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <button class="inf-btn inf-btn-print" onclick="window.print()">🖨️ Imprimir</button>
            <a class="inf-btn" style="background:rgba(255,255,255,.15);color:#fff;"
               href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= $casoId ?>">
                ← Volver al expediente
            </a>
        </div>
    </div>

    <!-- Cabecera -->
    <div class="inf-header">
        <?php if (!empty($colegio['logo_url'])): ?>
            <img src="<?= e((string)$colegio['logo_url']) ?>" alt="Logo" class="inf-logo">
        <?php else: ?>
            <div class="inf-logo-placeholder">🏫</div>
        <?php endif; ?>

        <div class="inf-header-meta">
            <h1><?= e((string)($colegio['nombre'] ?? 'Establecimiento')) ?></h1>
            <p>RBD: <?= e((string)($colegio['rbd'] ?? '—')) ?>
               <?= !empty($colegio['comuna']) ? ' · ' . e((string)$colegio['comuna']) : '' ?>
               <?= !empty($colegio['region']) ? ' · ' . e((string)$colegio['region']) : '' ?>
            </p>
            <?php if (!empty($colegio['director_nombre'])): ?>
            <p>Director/a: <?= e((string)$colegio['director_nombre']) ?></p>
            <?php endif; ?>
            <p style="margin-top:.4rem;font-size:.7rem;color:#bfdbfe;">
                INFORME DE EXPEDIENTE DE CONVIVENCIA ESCOLAR — DOCUMENTO OFICIAL
            </p>
        </div>

        <div class="inf-header-right">
            <strong><?= $numeroCaso ?></strong>
            <div>Ingreso: <?= inf_fecha((string)($caso['fecha_ingreso'] ?? $caso['created_at'] ?? '')) ?></div>
            <div>Emitido: <?= $emitidoEn ?></div>
        </div>
    </div>

    <!-- Índice rápido -->
    <div class="inf-indice">
        <strong style="color:#475569;">Contenido:</strong>
        <a href="#sec1">1. Identificación</a>
        <a href="#sec2">2. Hechos</a>
        <a href="#sec3">3. Participantes</a>
        <a href="#sec4">4. Clasificación</a>
        <a href="#sec5">5. Evaluación de riesgo</a>
        <a href="#sec6">6. Plan de acción</a>
        <a href="#sec7">7. Seguimiento por participante</a>
        <a href="#sec8">8. Comunicaciones al apoderado</a>
        <a href="#sec9">9. Gestión ejecutiva</a>
        <a href="#sec10">10. Declaraciones</a>
        <a href="#sec11">11. Evidencias</a>
        <?php if ($aulaSegura): ?><a href="#sec12">12. Aula Segura</a><?php endif; ?>
        <a href="#secCierre">Cierre</a>
        <a href="#secFirma">Firma</a>
    </div>

    <!-- Cuerpo -->
    <div class="inf-body">

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 1 · IDENTIFICACIÓN DEL CASO
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec1">
            <div class="inf-section-head">
                <div class="inf-section-num">1</div>
                <div class="inf-section-title">Identificación del caso</div>
            </div>
            <div class="inf-section-body">
                <table class="inf-table">
                    <tr><th>N° de expediente</th>
                        <td><strong><?= $numeroCaso ?></strong></td></tr>
                    <tr><th>Estado formal</th>
                        <td><?= e(inf_label((string)($caso['estado_nombre'] ?? $caso['estado'] ?? ''))) ?></td></tr>
                    <tr><th>Prioridad</th>
                        <td><?= e(inf_label((string)($caso['prioridad'] ?? 'media'))) ?></td></tr>
                    <tr><th>Fecha de ingreso</th>
                        <td><?= inf_fecha((string)($caso['fecha_ingreso'] ?? $caso['created_at'] ?? '')) ?></td></tr>
                    <tr><th>Fecha del incidente</th>
                        <td><?= inf_fecha_hora((string)($caso['fecha_hora_incidente'] ?? $caso['fecha_hechos'] ?? '')) ?></td></tr>
                    <tr><th>Lugar de los hechos</th>
                        <td><?= e((string)($caso['lugar_hechos'] ?? '—')) ?></td></tr>
                    <tr><th>Canal de ingreso</th>
                        <td><?= e((string)($caso['canal_ingreso'] ?? '—')) ?></td></tr>
                    <tr><th>Registrado por</th>
                        <td><?= e((string)($caso['creado_por_nombre'] ?? '—')) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 2 · HECHOS DENUNCIADOS
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec2">
            <div class="inf-section-head">
                <div class="inf-section-num">2</div>
                <div class="inf-section-title">Hechos denunciados</div>
            </div>
            <div class="inf-section-body">
                <?php $relato = trim((string)($caso['relato'] ?? '')); ?>
                <?php if ($relato && $relato !== '(Borrador — sin relato)'): ?>
                    <p class="inf-text"><?= nl2br(e($relato)) ?></p>
                <?php else: ?>
                    <p class="inf-muted">Sin relato registrado.</p>
                <?php endif; ?>
                <?php if (!empty($caso['contexto'])): ?>
                    <div style="margin-top:.75rem;">
                        <strong style="font-size:.77rem;color:#475569;">Contexto adicional</strong>
                        <p class="inf-text" style="margin-top:.25rem;"><?= nl2br(e((string)$caso['contexto'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 3 · PARTICIPANTES
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec3">
            <div class="inf-section-head">
                <div class="inf-section-num">3</div>
                <div class="inf-section-title">
                    Participantes (<?= count($participantesAgrupados) ?> persona(s)
                    <?php if (count($participantesAgrupados) < count($participantes)): ?>
                        <span style="font-weight:400;font-size:.73rem;color:#64748b;">
                            · <?= count($participantes) - count($participantesAgrupados) ?> rol(es) combinado(s)
                        </span>
                    <?php endif; ?>)
                </div>
            </div>
            <div class="inf-section-body">
                <?php if (!$participantesAgrupados): ?>
                    <p class="inf-muted">Sin participantes registrados.</p>
                <?php endif; ?>
                <?php foreach ($participantesAgrupados as $p):
                    $rolesUnicos = array_unique($p['_roles'] ?? [$p['rol_en_caso'] ?? '']);
                    // Badge del primer rol para el color de cabecera
                    $rol    = strtolower((string)($rolesUnicos[0] ?? ''));
                    $rolCss = match($rol) {
                        'victima'=>'rol-victima','denunciado'=>'rol-denunciado',
                        'denunciante'=>'rol-denunciante','testigo'=>'rol-testigo',
                        default=>'rol-otro'
                    };
                    // Buscar apoderados: primero por persona_id, luego buscando en contextoFamiliar por RUN
                    $alumnoId = (int)($p['persona_id'] ?? 0);
                    $apos = [];
                    if (strtolower((string)($p['tipo_persona'] ?? '')) === 'alumno') {
                        if ($alumnoId > 0 && isset($apoderadosPorAlumno[$alumnoId])) {
                            $apos = $apoderadosPorAlumno[$alumnoId];
                        } else {
                            // Fallback: buscar en contextoFamiliar por nombre_referencial o run
                            foreach ($contextoFamiliar as $bloque) {
                                $alu = $bloque['alumno'] ?? [];
                                $runP   = preg_replace('/[.\-\s]/', '', strtoupper((string)($p['run'] ?? '')));
                                $runA   = preg_replace('/[.\-\s]/', '', strtoupper(ver_pick($alu, ['run','rut'], '')));
                                $matchRun  = $runP !== '' && $runP === $runA;
                                $matchNom  = mb_strtoupper(trim((string)($p['nombre_referencial'] ?? '')), 'UTF-8')
                                          === mb_strtoupper(trim(ver_nombre_persona($alu)), 'UTF-8');
                                if ($matchRun || $matchNom) {
                                    $apos = $bloque['apoderados'] ?? [];
                                    break;
                                }
                            }
                        }
                    }
                ?>
                <div class="inf-part-card">
                    <div class="inf-part-head">
                        <?php foreach ($rolesUnicos as $rIdx => $rUnico):
                            $rU = strtolower((string)$rUnico);
                            $rCss = match($rU) {
                                'victima'=>'rol-victima','denunciado'=>'rol-denunciado',
                                'denunciante'=>'rol-denunciante','testigo'=>'rol-testigo',
                                default=>'rol-otro'
                            };
                        ?>
                            <span class="inf-rol-badge <?= $rCss ?>"><?= e(inf_label($rU)) ?></span>
                        <?php endforeach; ?>
                        <span><?= e(metis_snapshot_nombre($p)) ?></span>
                        <?php if (!empty($p['run']) && $p['run'] !== '0-0'): ?>
                            <span style="color:#64748b;font-weight:400;font-size:.76rem;">
                                RUN: <?= e((string)$p['run']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="inf-part-body">
                        <table class="inf-table" style="font-size:.79rem;">
                            <tr><th>Tipo</th><td><?= e(inf_label((string)($p['tipo_persona'] ?? ''))) ?></td></tr>
                            <?php if (metis_snapshot_curso($p) !== '—'): ?>
                            <tr><th>Curso</th><td><?= e(metis_snapshot_curso($p)) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($p['fecha_nacimiento'])): ?>
                            <tr><th>Fecha nacimiento</th><td><?= inf_fecha((string)$p['fecha_nacimiento']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($p['condicion_especial']) || !empty($p['tiene_pie'])): ?>
                            <tr><th>Condición especial</th>
                                <td><?= e(inf_label((string)($p['condicion_especial'] ?? ''))) ?>
                                    <?= $p['tiene_pie'] ? ' · PIE' : '' ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($p['observacion'])): ?>
                            <tr><th>Observación</th><td><?= e((string)$p['observacion']) ?></td></tr>
                            <?php endif; ?>
                        </table>

                        <?php if ($apos): ?>
                        <div style="margin-top:.6rem;">
                            <div style="font-size:.73rem;font-weight:700;color:#1a3a5c;
                                        text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem;">
                                Apoderado(s)
                            </div>
                            <?php foreach ($apos as $apo):
                                $esPrincipal  = (int)($apo['es_principal'] ?? $apo['es_titular'] ?? 0) === 1;
                                $puedeRetirar = (int)($apo['autorizado_retirar'] ?? $apo['puede_retirar'] ?? 0) === 1;
                                $viveConEst   = (int)($apo['vive_con_estudiante'] ?? 0) === 1;
                                $parentesco   = ver_pick($apo, ['relacion_parentesco','parentesco','tipo_relacion'], '—');
                                $telefono     = ver_pick($apo, ['telefono','fono','celular'], '');
                                $email        = ver_pick($apo, ['email','correo','correo_electronico'], '');
                                $nombre       = ver_nombre_persona($apo);
                                $run          = ver_pick($apo, ['run','rut'], '—');
                            ?>
                            <table class="inf-table" style="font-size:.78rem;margin-bottom:.4rem;">
                                <tr><th>Nombre</th>
                                    <td><?= e($nombre) ?>
                                        <?php if ($esPrincipal): ?>
                                            <span style="background:#1a3a5c;color:#fff;border-radius:4px;
                                                         font-size:.65rem;padding:.1rem .35rem;margin-left:.3rem;">Principal</span>
                                        <?php endif; ?>
                                    </td></tr>
                                <tr><th>RUN</th><td><?= e($run) ?></td></tr>
                                <tr><th>Parentesco</th><td><?= e(inf_label($parentesco)) ?></td></tr>
                                <?php if ($telefono !== ''): ?>
                                <tr><th>Teléfono</th><td><?= e($telefono) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($email !== ''): ?>
                                <tr><th>Correo</th><td><?= e($email) ?></td></tr>
                                <?php endif; ?>
                                <tr><th>Autorizado retiro</th><td><?= inf_si_no($puedeRetirar) ?></td></tr>
                                <tr><th>Vive con estudiante</th><td><?= inf_si_no($viveConEst) ?></td></tr>
                            </table>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 4 · CLASIFICACIÓN NORMATIVA
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec4">
            <div class="inf-section-head">
                <div class="inf-section-num">4</div>
                <div class="inf-section-title">Clasificación normativa</div>
            </div>
            <div class="inf-section-body">
                <?php if (!$clasif): ?>
                    <p class="inf-muted">Sin clasificación normativa registrada.</p>
                <?php else: ?>
                <table class="inf-table">
                    <?php if ($_areaNombre !== ''): ?>
                    <tr><th>Área MINEDUC</th><td><?= e($_areaNombre) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($_ambitoNombre !== ''): ?>
                    <tr><th>Ámbito</th><td><?= e($_ambitoNombre) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($clasif['tipo_conducta'])): ?>
                    <tr><th>Tipo de conducta</th><td><?= e(inf_label((string)$clasif['tipo_conducta'])) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Gravedad</th><td><?= e(inf_label((string)($clasif['gravedad'] ?? 'media'))) ?></td></tr>
                    <tr><th>Conducta reiterada</th><td><?= inf_si_no($clasif['reiteracion'] ?? 0) ?></td></tr>
                    <tr><th>Involucra adulto del establecimiento</th><td><?= inf_si_no($clasif['involucra_adulto'] ?? 0) ?></td></tr>
                    <tr><th>Posible Aula Segura</th><td><?= inf_si_no($clasif['posible_aula_segura'] ?? 0) ?></td></tr>
                    <tr><th>Requiere denuncia / derivación externa</th><td><?= inf_si_no($clasif['requiere_denuncia'] ?? 0) ?></td></tr>
                    <?php if (!empty($clasif['observaciones_normativas'])): ?>
                    <tr><th>Observaciones</th><td><?= nl2br(e((string)$clasif['observaciones_normativas'])) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 5 · PAUTA DE EVALUACIÓN DE RIESGO
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec5">
            <div class="inf-section-head">
                <div class="inf-section-num">5</div>
                <div class="inf-section-title">Evaluación de riesgo</div>
            </div>
            <div class="inf-section-body">
                <?php if (!$pauta): ?>
                    <p class="inf-muted">Sin pauta de riesgo registrada.</p>
                <?php else:
                    $nivelFinal = strtolower((string)($pauta['nivel_final'] ?? $pauta['nivel_calculado'] ?? 'bajo'));
                    $puntaje    = (int)($pauta['puntaje_total'] ?? 0);
                    $nivelColor = inf_nivel_css($nivelFinal);
                    $pct        = min(100, round(($puntaje / 70) * 100));
                    $nLabels    = ['bajo'=>'BAJO','medio'=>'MEDIO','alto'=>'ALTO','critico'=>'CRÍTICO'];
                ?>
                <div style="margin-bottom:1rem;">
                    <span class="inf-pauta-nivel" style="background:<?= $nivelColor ?>;">
                        <?= $nLabels[$nivelFinal] ?? strtoupper($nivelFinal) ?>
                    </span>
                    <span style="font-size:.85rem;font-weight:700;color:<?= $nivelColor ?>;margin-left:.5rem;">
                        <?= $puntaje ?> / 70 puntos
                    </span>
                </div>
                <div class="inf-pauta-bar">
                    <div class="inf-pauta-bar-fill" style="width:<?= $pct ?>%;"></div>
                </div>
                <table class="inf-table" style="margin-top:.75rem;">
                    <tr><th>Nivel calculado</th><td><?= e(ucfirst($nivelFinal)) ?></td></tr>
                    <?php if ($pauta['ajuste_profesional'] ?? 0): ?>
                    <tr><th>Ajuste profesional</th><td><?= e((string)($pauta['nivel_final'] ?? '')) ?></td></tr>
                    <tr><th>Justificación ajuste</th><td><?= nl2br(e((string)($pauta['justificacion_ajuste'] ?? ''))) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>D1 Hechos</th><td><?= (int)($pauta['puntaje_d1'] ?? 0) ?> pts.</td></tr>
                    <tr><th>D2 Vulnerabilidad víctima</th><td><?= (int)($pauta['puntaje_d2'] ?? 0) ?> pts.</td></tr>
                    <tr><th>D3 Agresor</th><td><?= (int)($pauta['puntaje_d3'] ?? 0) ?> pts.</td></tr>
                    <tr><th>D4 Contexto</th><td><?= (int)($pauta['puntaje_d4'] ?? 0) ?> pts.</td></tr>
                    <?php
                    $factores = [];
                    if ($pauta['esc_menor_8']            ?? 0) $factores[] = 'Víctima menor de 8 años';
                    if ($pauta['esc_agresor_funcionario'] ?? 0) $factores[] = 'Agresor es funcionario adulto';
                    if ($pauta['esc_violencia_sexual']    ?? 0) $factores[] = 'Violencia sexual';
                    if ($pauta['esc_amenazas_armas']      ?? 0) $factores[] = 'Amenazas con armas';
                    if ($pauta['esc_tea_sin_red']         ?? 0) $factores[] = 'TEA sin red de apoyo';
                    if ($pauta['esc_reincidencia']        ?? 0) $factores[] = 'Reincidencia con misma víctima';
                    if ($factores):
                    ?>
                    <tr><th>Factores de escala</th>
                        <td style="color:#dc2626;font-weight:600;"><?= implode(' · ', $factores) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($pauta['observacion'])): ?>
                    <tr><th>Observaciones</th><td><?= nl2br(e((string)$pauta['observacion'])) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Evaluado</th>
                        <td><?= inf_fecha_hora((string)($pauta['created_at'] ?? '')) ?>
                            <?= !empty($pauta['evaluador']) ? ' · ' . e((string)$pauta['evaluador']) : '' ?>
                        </td></tr>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 6 · PLAN DE ACCIÓN
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec6">
            <div class="inf-section-head">
                <div class="inf-section-num">6</div>
                <div class="inf-section-title">Plan de acción (<?= count($planes) ?> plan(es) vigente(s))</div>
            </div>
            <div class="inf-section-body">
                <?php if (!$planes): ?>
                    <p class="inf-muted">Sin plan de acción registrado.</p>
                <?php else: ?>
                <?php foreach ($planes as $plan):
                    $rolPlan = strtolower((string)($plan['rol_en_caso'] ?? ''));
                    $rolPlanCss = match($rolPlan) {
                        'victima'=>'rol-victima','denunciado'=>'rol-denunciado',
                        'denunciante'=>'rol-denunciante',default=>'rol-testigo'
                    };
                ?>
                <div class="inf-part-card" style="margin-bottom:.75rem;">
                    <div class="inf-part-head">
                        <span class="inf-rol-badge <?= $rolPlanCss ?>"><?= e(inf_label($rolPlan)) ?></span>
                        <span><?= e((string)($plan['nombre_referencial'] ?? '')) ?></span>
                        <span style="color:#64748b;font-weight:400;font-size:.74rem;">
                            Versión <?= (int)($plan['version'] ?? 1) ?>
                        </span>
                    </div>
                    <div class="inf-part-body">
                        <table class="inf-table" style="font-size:.8rem;">
                            <tr><th>Plan de acción</th>
                                <td><?= nl2br(e((string)($plan['plan_accion'] ?? ''))) ?></td></tr>
                            <?php if (!empty($plan['medidas_preventivas'])): ?>
                            <tr><th>Medidas preventivas</th>
                                <td><?= nl2br(e((string)$plan['medidas_preventivas'])) ?></td></tr>
                            <?php endif; ?>
                            <tr><th>Estado</th>
                                <td><?= e(inf_label((string)($plan['estado_plan'] ?? 'activo'))) ?></td></tr>
                            <tr><th>Registrado</th>
                                <td><?= inf_fecha_hora((string)($plan['created_at'] ?? '')) ?>
                                    <?= !empty($plan['autor']) ? ' · ' . e((string)$plan['autor']) : '' ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 7 · BITÁCORA DE SEGUIMIENTO POR PARTICIPANTE
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec7">
            <div class="inf-section-head">
                <div class="inf-section-num">7</div>
                <div class="inf-section-title">
                    Bitácora de seguimiento
                    <span style="font-weight:400;font-size:.75rem;color:#64748b;margin-left:.4rem;">
                        (<?= $totalSesiones ?> sesión(es) · <?= count($sesionesPorParticipante) ?> participante(s))
                    </span>
                </div>
            </div>
            <div class="inf-section-body">
                <?php if (!$sesionesPorParticipante): ?>
                    <p class="inf-muted">Sin sesiones de seguimiento registradas.</p>
                <?php else: ?>
                <?php foreach ($sesionesPorParticipante as $pid => $grupo):
                    $rolSeg    = strtolower((string)($grupo['rol'] ?? ''));
                    $rolSegCss = match($rolSeg) {
                        'victima'=>'rol-victima','denunciado'=>'rol-denunciado',
                        'denunciante'=>'rol-denunciante',default=>'rol-testigo'
                    };
                ?>
                <div class="inf-seg-part">
                    <div class="inf-seg-part-head">
                        <span class="inf-rol-badge <?= $rolSegCss ?>" style="font-size:.68rem;">
                            <?= e(inf_label($rolSeg)) ?>
                        </span>
                        <span><?= e($grupo['nombre']) ?></span>
                        <span style="font-weight:400;color:#64748b;margin-left:auto;font-size:.74rem;">
                            <?= count($grupo['items']) ?> sesión(es)
                        </span>
                    </div>

                    <?php foreach ($grupo['items'] as $i => $ses): ?>
                    <div class="inf-sesion">
                        <div class="inf-sesion-head">
                            <strong>Sesión <?= $i + 1 ?></strong>
                            <span>
                                <?= inf_fecha_hora((string)($ses['created_at'] ?? '')) ?>
                                <?= !empty($ses['registrador']) ? ' · ' . e((string)$ses['registrador']) : '' ?>
                            </span>
                        </div>

                        <?php if (!empty($ses['observacion_avance'])): ?>
                        <div style="margin-bottom:.3rem;font-size:.81rem;">
                            <strong style="color:#1a3a5c;">Observación / Acuerdos:</strong>
                            <?= nl2br(e((string)$ses['observacion_avance'])) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($ses['medidas_sesion'])): ?>
                        <div style="margin-bottom:.3rem;font-size:.81rem;">
                            <strong style="color:#059669;">Medidas aplicadas:</strong>
                            <?= nl2br(e((string)$ses['medidas_sesion'])) ?>
                        </div>
                        <?php endif; ?>

                        <div style="font-size:.74rem;color:#64748b;display:flex;gap:1rem;flex-wrap:wrap;margin-top:.3rem;">
                            <?php if (!empty($ses['estado_caso'])): ?>
                                <span>Estado: <strong><?= e(inf_label((string)$ses['estado_caso'])) ?></strong></span>
                            <?php endif; ?>
                            <?php if (!empty($ses['cumplimiento_plan'])): ?>
                                <span>Cumplimiento plan: <strong><?= e(inf_label((string)$ses['cumplimiento_plan'])) ?></strong></span>
                            <?php endif; ?>
                            <?php if (!empty($ses['proxima_revision'])): ?>
                                <span>Próxima revisión: <strong><?= inf_fecha((string)$ses['proxima_revision']) ?></strong></span>
                            <?php endif; ?>
                            <?php if (!empty($ses['comunicacion_apoderado'])): ?>
                                <span>Com. apoderado: <strong><?= e(inf_label((string)$ses['comunicacion_apoderado'])) ?></strong>
                                    <?= !empty($ses['fecha_comunicacion_apoderado'])
                                        ? ' ' . inf_fecha((string)$ses['fecha_comunicacion_apoderado']) : '' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 8 · COMUNICACIONES AL APODERADO
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec8">
            <div class="inf-section-head">
                <div class="inf-section-num">8</div>
                <div class="inf-section-title">
                    Comunicaciones al apoderado (<?= count($comunicaciones) ?>)
                </div>
            </div>
            <div class="inf-section-body" style="padding:0;">
                <?php if (!$comunicaciones): ?>
                    <p class="inf-muted" style="padding:1rem;">Sin comunicaciones a apoderado registradas en sesiones de seguimiento.</p>
                <?php else: ?>
                <?php
                $comIconos = [
                    'presencial'=>'🤝','telefono'=>'📞','correo'=>'✉️',
                    'whatsapp'=>'💬','libreta'=>'📓',
                ];
                foreach ($comunicaciones as $com):
                    $modalidad = strtolower((string)($com['comunicacion_apoderado'] ?? ''));
                    $icono = $comIconos[$modalidad] ?? '📢';
                ?>
                <div class="inf-com">
                    <div class="inf-com-icon"><?= $icono ?></div>
                    <div style="flex:1;">
                        <div style="font-weight:600;font-size:.82rem;">
                            <?= e(inf_label($modalidad)) ?>
                            <span style="color:#64748b;font-weight:400;margin-left:.4rem;">
                                — <?= e((string)($com['nombre_referencial'] ?? '')) ?>
                                (<?= e(inf_label(strtolower((string)($com['rol_en_caso'] ?? '')))) ?>)
                            </span>
                        </div>
                        <?php if (!empty($com['fecha_comunicacion_apoderado'])): ?>
                        <div style="font-size:.76rem;color:#64748b;">
                            Fecha: <?= inf_fecha_hora((string)$com['fecha_comunicacion_apoderado']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($com['notas_comunicacion'])): ?>
                        <div style="font-size:.79rem;color:#374151;margin-top:.2rem;">
                            <?= e((string)$com['notas_comunicacion']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:.72rem;color:#94a3b8;white-space:nowrap;text-align:right;">
                        <?= inf_fecha((string)($com['created_at'] ?? '')) ?>
                        <?= !empty($com['registrador']) ? '<br>' . e((string)$com['registrador']) : '' ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 9 · GESTIÓN EJECUTIVA
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec9">
            <div class="inf-section-head">
                <div class="inf-section-num">9</div>
                <div class="inf-section-title">
                    Gestión ejecutiva (<?= count($accionesEjecutivas) ?> acción(es))
                </div>
            </div>
            <div class="inf-section-body">
                <?php if (!$accionesEjecutivas): ?>
                    <p class="inf-muted">Sin acciones ejecutivas registradas.</p>
                <?php else: ?>

                <!-- Resumen por estado -->
                <?php
                $geResumen = ['pendiente'=>0,'en_proceso'=>0,'cumplida'=>0,'descartada'=>0,'vencidas'=>0];
                $hoy = date('Y-m-d');
                foreach ($accionesEjecutivas as $ac) {
                    $est = (string)($ac['estado'] ?? 'pendiente');
                    if (isset($geResumen[$est])) $geResumen[$est]++;
                    $fc = (string)($ac['fecha_compromiso'] ?? '');
                    if ($fc && $fc < $hoy && in_array($est, ['pendiente','en_proceso'], true)) $geResumen['vencidas']++;
                }
                ?>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <?php if ($geResumen['vencidas'] > 0): ?>
                    <span style="background:#fee2e2;color:#991b1b;border-radius:5px;
                                 padding:.2rem .65rem;font-size:.75rem;font-weight:700;">
                        ⚠ <?= $geResumen['vencidas'] ?> vencida(s)
                    </span>
                    <?php endif; ?>
                    <span style="background:#fef3c7;color:#92400e;border-radius:5px;
                                 padding:.2rem .65rem;font-size:.75rem;font-weight:600;">
                        Pendiente: <?= $geResumen['pendiente'] ?>
                    </span>
                    <span style="background:#dbeafe;color:#1e40af;border-radius:5px;
                                 padding:.2rem .65rem;font-size:.75rem;font-weight:600;">
                        En proceso: <?= $geResumen['en_proceso'] ?>
                    </span>
                    <span style="background:#d1fae5;color:#065f46;border-radius:5px;
                                 padding:.2rem .65rem;font-size:.75rem;font-weight:600;">
                        Cumplidas: <?= $geResumen['cumplida'] ?>
                    </span>
                </div>

                <?php foreach ($accionesEjecutivas as $ac):
                    $acEst  = (string)($ac['estado'] ?? 'pendiente');
                    $acPrio = (string)($ac['prioridad'] ?? 'media');
                    $acFc   = (string)($ac['fecha_compromiso'] ?? '');
                    $acVenc = $acFc && $acFc < $hoy && in_array($acEst, ['pendiente','en_proceso'], true);
                    $prioCss = 'background:' . inf_prioridad_css($acPrio) . ';';
                    $estCss  = match($acEst) {
                        'cumplida'   => 'inf-estado-badge inf-estado-cumplida',
                        'descartada' => 'inf-estado-badge inf-estado-descartada',
                        default      => 'inf-estado-badge',
                    };
                ?>
                <div class="inf-accion <?= $acVenc ? 'inf-vencida' : '' ?>">
                    <div class="inf-accion-head">
                        <span class="inf-prioridad-badge" style="<?= $prioCss ?>">
                            <?= e(inf_label($acPrio)) ?>
                        </span>
                        <strong style="flex:1;font-size:.82rem;"><?= e((string)($ac['titulo'] ?? '')) ?></strong>
                        <span class="<?= $estCss ?>"><?= e(inf_label($acEst)) ?></span>
                        <?php if ($acVenc): ?>
                            <span style="background:#dc2626;color:#fff;border-radius:4px;
                                         padding:.1rem .4rem;font-size:.68rem;font-weight:700;">Vencida</span>
                        <?php endif; ?>
                    </div>
                    <div class="inf-accion-body">
                        <?php if (!empty($ac['descripcion'])): ?>
                        <div style="color:#374151;margin-bottom:.4rem;">
                            <?= nl2br(e((string)$ac['descripcion'])) ?>
                        </div>
                        <?php endif; ?>
                        <table class="inf-table" style="font-size:.78rem;">
                            <tr>
                                <th style="width:22%;">Responsable</th>
                                <td><?= e((string)($ac['responsable_nombre'] ?? 'No asignado')) ?>
                                    <?= !empty($ac['responsable_rol']) ? ' · ' . e((string)$ac['responsable_rol']) : '' ?>
                                </td>
                                <th style="width:22%;">Fecha compromiso</th>
                                <td><?= $acFc ? inf_fecha($acFc) : 'Sin fecha' ?></td>
                            </tr>
                            <?php if (!empty($ac['fecha_cumplimiento'])): ?>
                            <tr>
                                <th>Fecha cumplimiento</th>
                                <td colspan="3"><?= inf_fecha((string)$ac['fecha_cumplimiento']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Creada</th>
                                <td><?= inf_fecha_hora((string)($ac['created_at'] ?? '')) ?>
                                    <?= !empty($ac['creado_por_nombre']) ? ' · ' . e((string)$ac['creado_por_nombre']) : '' ?>
                                </td>
                                <th>Prioridad</th>
                                <td><?= e(inf_label($acPrio)) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 10 · DECLARACIONES
        ═══════════════════════════════════════════════════ -->
        <?php if ($declaraciones): ?>
        <div class="inf-section" id="sec10">
            <div class="inf-section-head">
                <div class="inf-section-num">10</div>
                <div class="inf-section-title">Declaraciones (<?= count($declaraciones) ?>)</div>
            </div>
            <div class="inf-section-body">
                <?php foreach ($declaraciones as $i => $dec): ?>
                <div style="margin-bottom:1rem;padding-bottom:1rem;
                            border-bottom:<?= $i < count($declaraciones)-1 ? '1px solid #e2e8f0' : 'none' ?>;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:.35rem;flex-wrap:wrap;gap:.3rem;">
                        <strong style="font-size:.83rem;">
                            <?= e((string)($dec['nombre_declarante'] ?? '')) ?>
                            <?php if (!empty($dec['calidad_procesal'])): ?>
                                <span style="font-weight:400;color:#64748b;margin-left:.3rem;font-size:.76rem;">
                                    (<?= e(inf_label((string)$dec['calidad_procesal'])) ?>)
                                </span>
                            <?php endif; ?>
                        </strong>
                        <span style="font-size:.76rem;color:#64748b;">
                            <?= inf_fecha_hora((string)($dec['fecha_declaracion'] ?? $dec['created_at'] ?? '')) ?>
                            <?= !empty($dec['registrador']) ? ' · ' . e((string)$dec['registrador']) : '' ?>
                        </span>
                    </div>
                    <?php if (!empty($dec['run_declarante']) && $dec['run_declarante'] !== '0-0'): ?>
                    <div style="font-size:.74rem;color:#475569;margin-bottom:.3rem;">
                        RUN: <?= e((string)$dec['run_declarante']) ?>
                    </div>
                    <?php endif; ?>
                    <p class="inf-text"><?= e((string)($dec['texto_declaracion'] ?? '')) ?></p>
                    <?php if (!empty($dec['observaciones'])): ?>
                    <div style="margin-top:.4rem;font-size:.78rem;color:#64748b;
                                border-left:2px solid #e2e8f0;padding-left:.5rem;">
                        <strong>Obs.:</strong> <?= e((string)$dec['observaciones']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 11 · EVIDENCIAS
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="sec11">
            <div class="inf-section-head">
                <div class="inf-section-num">11</div>
                <div class="inf-section-title">Evidencias (<?= count($evidencias) ?>)</div>
            </div>
            <div class="inf-section-body" style="padding:0;">
                <?php if (!$evidencias): ?>
                    <p class="inf-muted" style="padding:1rem;">Sin evidencias adjuntas.</p>
                <?php else: ?>
                <?php
                $evIconos = [
                    'documento'=>'📄','imagen'=>'🖼','audio'=>'🎵','video'=>'🎬','archivo'=>'📎'
                ];
                foreach ($evidencias as $ev):
                    $tipo = strtolower((string)($ev['tipo'] ?? 'archivo'));
                    $icono = $evIconos[$tipo] ?? '📎';
                ?>
                <div class="inf-ev-row">
                    <div class="inf-ev-icon"><?= $icono ?></div>
                    <div style="flex:1;">
                        <div style="font-weight:600;font-size:.82rem;">
                            <?= e((string)($ev['nombre_archivo'] ?? 'Archivo')) ?>
                        </div>
                        <div style="font-size:.75rem;color:#64748b;">
                            <?= e(inf_label($tipo)) ?>
                            <?= !empty($ev['mime_type']) ? ' · ' . e((string)$ev['mime_type']) : '' ?>
                            <?= !empty($ev['descripcion']) ? ' — ' . e((string)$ev['descripcion']) : '' ?>
                        </div>
                    </div>
                    <div style="font-size:.72rem;color:#94a3b8;text-align:right;">
                        <?= inf_fecha((string)($ev['created_at'] ?? '')) ?>
                        <?= !empty($ev['subido_por_nombre']) ? '<br>' . e((string)$ev['subido_por_nombre']) : '' ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             SECCIÓN 12 · AULA SEGURA (condicional)
        ═══════════════════════════════════════════════════ -->
        <?php if ($aulaSegura): ?>
        <div class="inf-section" id="sec12">
            <div class="inf-section-head">
                <div class="inf-section-num">12</div>
                <div class="inf-section-title">Aula Segura</div>
            </div>
            <div class="inf-section-body">
                <table class="inf-table">
                    <tr><th>Estado</th><td><?= e(inf_label((string)($aulaSegura['estado'] ?? ''))) ?></td></tr>
                    <?php if (!empty($aulaSegura['causal'])): ?>
                    <tr><th>Causal</th><td><?= e((string)$aulaSegura['causal']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($aulaSegura['fundamento'])): ?>
                    <tr><th>Fundamento</th><td><?= nl2br(e((string)$aulaSegura['fundamento'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($aulaSegura['responsable'])): ?>
                    <tr><th>Responsable</th><td><?= e((string)$aulaSegura['responsable']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Fecha</th><td><?= inf_fecha_hora((string)($aulaSegura['created_at'] ?? '')) ?></td></tr>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════
             CIERRE FORMAL
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="secCierre">
            <div class="inf-section-head">
                <div class="inf-section-num" style="background:<?= $cierre ? '#059669' : '#94a3b8' ?>;">
                    ✓
                </div>
                <div class="inf-section-title">Cierre formal del expediente</div>
            </div>
            <div class="inf-section-body">
                <?php if (!$cierre): ?>
                    <p class="inf-muted">El expediente no registra cierre formal. Estado actual:
                        <strong><?= e(inf_label((string)($caso['estado_nombre'] ?? 'activo'))) ?></strong>
                    </p>
                <?php else: ?>
                <table class="inf-table">
                    <tr><th>Tipo de cierre</th>
                        <td><?= e(inf_label((string)($cierre['tipo_cierre'] ?? ''))) ?></td></tr>
                    <tr><th>Fecha de cierre</th>
                        <td><?= inf_fecha((string)($cierre['fecha_cierre'] ?? $cierre['created_at'] ?? '')) ?></td></tr>
                    <tr><th>Responsable</th>
                        <td><?= e((string)($cierre['cerrado_por'] ?? '—')) ?></td></tr>
                    <?php if (!empty($cierre['fundamento'])): ?>
                    <tr><th>Fundamento</th>
                        <td><?= nl2br(e((string)$cierre['fundamento'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($cierre['medidas_finales'])): ?>
                    <tr><th>Medidas finales</th>
                        <td><?= nl2br(e((string)$cierre['medidas_finales'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($cierre['acuerdos'])): ?>
                    <tr><th>Acuerdos</th>
                        <td><?= nl2br(e((string)$cierre['acuerdos'])) ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($cierre['derivaciones'])): ?>
                    <tr><th>Derivaciones</th>
                        <td><?= nl2br(e((string)$cierre['derivaciones'])) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             FIRMA Y RESPONSABLE
        ═══════════════════════════════════════════════════ -->
        <div class="inf-section" id="secFirma">
            <div class="inf-section-head">
                <div class="inf-section-num" style="background:#1a3a5c;">✍</div>
                <div class="inf-section-title">Responsable y firma</div>
            </div>
            <div class="inf-section-body">
                <table class="inf-table">
                    <tr><th>Establecimiento</th>
                        <td><?= e((string)($colegio['nombre'] ?? '—')) ?></td></tr>
                    <tr><th>RBD</th>
                        <td><?= e((string)($colegio['rbd'] ?? '—')) ?></td></tr>
                    <?php if (!empty($colegio['comuna'])): ?>
                    <tr><th>Comuna / Ciudad</th>
                        <td><?= e((string)$colegio['comuna']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Director/a</th>
                        <td><?= e((string)($colegio['director_nombre'] ?? '—')) ?></td></tr>
                    <tr><th>Encargado/a de convivencia</th>
                        <td><?= e((string)($caso['creado_por_nombre'] ?? '—')) ?></td></tr>
                    <tr><th>Informe emitido</th>
                        <td><?= $emitidoEn ?></td></tr>
                    <tr><th>Sistema</th>
                        <td>Metis · Sistema de Gestión de Convivencia Escolar</td></tr>
                </table>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:2rem;margin-top:2.5rem;">
                    <div style="text-align:center;">
                        <div style="border-top:1.5px solid #1a3a5c;padding-top:.4rem;margin-top:3.5rem;">
                            <div style="font-size:.77rem;font-weight:700;">Director/a del Establecimiento</div>
                            <div style="font-size:.72rem;color:#64748b;">
                                <?= e((string)($colegio['director_nombre'] ?? '')) ?>
                            </div>
                        </div>
                    </div>
                    <div style="text-align:center;">
                        <div style="border-top:1.5px solid #1a3a5c;padding-top:.4rem;margin-top:3.5rem;">
                            <div style="font-size:.77rem;font-weight:700;">Encargado/a de Convivencia Escolar</div>
                            <div style="font-size:.72rem;color:#64748b;">&nbsp;</div>
                        </div>
                    </div>
                    <div style="text-align:center;">
                        <div style="border-top:1.5px solid #1a3a5c;padding-top:.4rem;margin-top:3.5rem;">
                            <div style="font-size:.77rem;font-weight:700;">Timbre del Establecimiento</div>
                            <div style="font-size:.72rem;color:#64748b;">&nbsp;</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:1.5rem;padding:.75rem;background:#fffbf0;
                            border:1px dashed #fbbf24;border-radius:6px;
                            font-size:.75rem;color:#78350f;text-align:center;">
                    Este informe ha sido generado automáticamente por el Sistema Metis SGCE y tiene carácter confidencial.
                    Su uso está restringido a organismos del Estado y personas debidamente autorizadas conforme a la
                    <strong>Ley 21.809</strong> y la normativa de protección de datos personales.
                    N° Expediente: <strong><?= $numeroCaso ?></strong> · Emitido: <?= $emitidoEn ?>
                </div>
            </div>
        </div>

    </div><!-- /inf-body -->

    <!-- Pie -->
    <div class="inf-footer">
        <span>Metis · Sistema de Gestión de Convivencia Escolar</span>
        <span>Expediente: <?= $numeroCaso ?> · Emitido: <?= $emitidoEn ?></span>
        <span><?= e((string)($colegio['nombre'] ?? '')) ?>
              <?= !empty($colegio['rbd']) ? ' · RBD ' . e((string)$colegio['rbd']) : '' ?>
        </span>
    </div>

</div><!-- /inf-wrap -->
</body>
</html>
