<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

require_once __DIR__ . '/includes/snapshot_report_helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$rolCodigo = (string)($user['rol_codigo'] ?? '');
$casoId = (int)($_GET['id'] ?? 0);

if ($casoId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

$puedeVerReporte = Auth::canOperate();

if (!$puedeVerReporte) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function ras_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ras_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ras_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function ras_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ras_fecha(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    if (!$ts) {
        return $value;
    }

    return $withTime ? date('d-m-Y H:i', $ts) : date('d-m-Y', $ts);
}

function ras_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    return mb_strtoupper(str_replace('_', ' ', $value), 'UTF-8');
}

function ras_estado_texto(string $estado): string
{
    return match ($estado) {
        'posible' => 'Posible Aula Segura',
        'en_evaluacion' => 'En evaluación directiva',
        'descartado' => 'Descartado por Dirección',
        'procedimiento_iniciado' => 'Procedimiento iniciado',
        'suspension_cautelar' => 'Suspensión cautelar registrada',
        'resuelto' => 'Procedimiento resuelto',
        'reconsideracion' => 'En reconsideración',
        'cerrado' => 'Cerrado',
        'no_aplica' => 'No aplica',
        default => ras_label($estado),
    };
}

function ras_estado_class(string $estado): string
{
    return match ($estado) {
        'posible', 'reconsideracion' => 'warn',
        'en_evaluacion' => 'blue',
        'procedimiento_iniciado', 'suspension_cautelar' => 'danger',
        'resuelto', 'cerrado' => 'ok',
        'descartado', 'no_aplica' => 'soft',
        default => 'soft',
    };
}

function ras_plazo_estado(?string $fechaLimite, ?string $estado): array
{
    if (!$fechaLimite) {
        return ['Sin plazo registrado', 'soft'];
    }

    if (in_array((string)$estado, ['resuelto', 'cerrado', 'descartado'], true)) {
        return ['Plazo referencial cerrado', 'ok'];
    }

    $hoy = new DateTimeImmutable('today');
    $limite = DateTimeImmutable::createFromFormat('Y-m-d', substr($fechaLimite, 0, 10));

    if (!$limite) {
        return ['Fecha inválida', 'warn'];
    }

    $dias = (int)$hoy->diff($limite)->format('%r%a');

    if ($dias < 0) {
        return ['Vencido hace ' . abs($dias) . ' día(s)', 'danger'];
    }

    if ($dias <= 2) {
        return ['Vence en ' . $dias . ' día(s)', 'warn'];
    }

    return ['Vigente: faltan ' . $dias . ' día(s)', 'ok'];
}

function ras_check(bool $ok, string $textoOk, string $textoPendiente): array
{
    return $ok ? [$textoOk, 'ok'] : [$textoPendiente, 'warn'];
}

if (!ras_table_exists($pdo, 'casos')) {
    http_response_code(500);
    exit('No existe la tabla casos.');
}

$stmtCaso = $pdo->prepare("\n    SELECT\n        c.*,\n        ec.nombre AS estado_formal,\n        ec.codigo AS estado_codigo\n    FROM casos c\n    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n    WHERE c.id = ?\n      AND c.colegio_id = ?\n    LIMIT 1\n");
$stmtCaso->execute([$casoId, $colegioId]);
$caso = $stmtCaso->fetch();

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado o no pertenece al establecimiento.');
}

$posibleAulaSegura = (int)($caso['posible_aula_segura'] ?? 0) === 1;

$aula = [];
if (ras_table_exists($pdo, 'caso_aula_segura')) {
    $stmtAula = $pdo->prepare("\n        SELECT *\n        FROM caso_aula_segura\n        WHERE caso_id = ?\n          AND colegio_id = ?\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $stmtAula->execute([$casoId, $colegioId]);
    $aula = $stmtAula->fetch() ?: [];
}

$estadoAula = (string)($aula['estado'] ?? $caso['aula_segura_estado'] ?? ($posibleAulaSegura ? 'posible' : 'no_aplica'));
$estadoTexto = ras_estado_texto($estadoAula);
$estadoClass = ras_estado_class($estadoAula);
[$plazoTexto, $plazoClass] = ras_plazo_estado((string)($aula['fecha_limite_resolucion'] ?? ''), $estadoAula);

$catalogo = [
    ['codigo' => 'agresion_sexual', 'campo' => 'causal_agresion_sexual', 'nombre' => 'Agresión de carácter sexual'],
    ['codigo' => 'agresion_fisica_lesiones', 'campo' => 'causal_agresion_fisica_lesiones', 'nombre' => 'Agresión física que produce lesiones'],
    ['codigo' => 'armas', 'campo' => 'causal_armas', 'nombre' => 'Uso, porte, posesión o tenencia de armas'],
    ['codigo' => 'artefactos_incendiarios', 'campo' => 'causal_artefactos_incendiarios', 'nombre' => 'Uso, porte, posesión o tenencia de artefactos incendiarios'],
    ['codigo' => 'infraestructura_esencial', 'campo' => 'causal_infraestructura_esencial', 'nombre' => 'Actos contra infraestructura esencial para la prestación del servicio educativo'],
    ['codigo' => 'grave_reglamento', 'campo' => 'causal_grave_reglamento', 'nombre' => 'Conducta grave o gravísima del Reglamento Interno'],
];

if (ras_table_exists($pdo, 'aula_segura_causales')) {
    try {
        $stmtCat = $pdo->query("\n            SELECT codigo, nombre\n            FROM aula_segura_causales\n            WHERE activo = 1\n            ORDER BY orden ASC, id ASC\n        ");
        $rows = $stmtCat->fetchAll();
        if ($rows) {
            $mapCampos = [
                'agresion_sexual' => 'causal_agresion_sexual',
                'agresion_fisica_lesiones' => 'causal_agresion_fisica_lesiones',
                'armas' => 'causal_armas',
                'artefactos_incendiarios' => 'causal_artefactos_incendiarios',
                'infraestructura_esencial' => 'causal_infraestructura_esencial',
                'grave_reglamento' => 'causal_grave_reglamento',
            ];
            $catalogo = [];
            foreach ($rows as $row) {
                $codigo = (string)($row['codigo'] ?? '');
                if (isset($mapCampos[$codigo])) {
                    $catalogo[] = [
                        'codigo' => $codigo,
                        'campo' => $mapCampos[$codigo],
                        'nombre' => (string)($row['nombre'] ?? $codigo),
                    ];
                }
            }
        }
    } catch (Throwable $ignored) {
    }
}

$causalesMarcadas = [];
foreach ($catalogo as $causal) {
    $campo = (string)$causal['campo'];
    if ((int)($aula[$campo] ?? 0) === 1) {
        $causalesMarcadas[] = $causal;
    }
}

if (!$causalesMarcadas && !empty($caso['aula_segura_causales_preliminares'])) {
    $pre = json_decode((string)$caso['aula_segura_causales_preliminares'], true);
    if (is_array($pre)) {
        foreach ($catalogo as $causal) {
            if (in_array((string)$causal['codigo'], $pre, true)) {
                $causalesMarcadas[] = $causal;
            }
        }
    }
}

$historial = [];
if (ras_table_exists($pdo, 'caso_aula_segura_historial')) {
    $stmtHist = $pdo->prepare("\n        SELECT h.*, u.nombre AS usuario_nombre\n        FROM caso_aula_segura_historial h\n        LEFT JOIN usuarios u ON u.id = h.usuario_id\n        WHERE h.caso_id = ?\n          AND (h.colegio_id = ? OR h.colegio_id IS NULL)\n        ORDER BY h.created_at DESC, h.id DESC\n        LIMIT 80\n    ");
    $stmtHist->execute([$casoId, $colegioId]);
    $historial = $stmtHist->fetchAll();
}

$controles = [];
$controles[] = ras_check($posibleAulaSegura, 'Alerta preliminar marcada en denuncia.', 'No existe alerta preliminar Aula Segura.');
$controles[] = ras_check(count($causalesMarcadas) > 0, 'Existe al menos una causal registrada.', 'Falta marcar causal legal o reglamentaria.');
$controles[] = ras_check(trim((string)($aula['descripcion_hecho'] ?? '')) !== '', 'Existe descripción objetiva del hecho.', 'Falta descripción objetiva del hecho.');
$controles[] = ras_check(!in_array($estadoAula, ['posible', 'no_aplica'], true), 'Existe evaluación o avance directivo.', 'Pendiente evaluación directiva.');
$controles[] = ras_check(empty($aula['suspension_cautelar']) || !empty($aula['fecha_notificacion_suspension']), 'Suspensión cautelar con notificación registrada o no aplica.', 'Suspensión cautelar sin fecha de notificación.');
$controles[] = ras_check(empty($aula['suspension_cautelar']) || !empty($aula['fecha_limite_resolucion']), 'Plazo de resolución registrado o calculado.', 'Falta plazo de resolución con suspensión cautelar.');
$controles[] = ras_check(!in_array($estadoAula, ['resuelto', 'cerrado'], true) || !empty($aula['fecha_resolucion']), 'Resolución con fecha registrada o no aplica.', 'Procedimiento resuelto/cerrado sin fecha de resolución.');
$controles[] = ras_check(empty($aula['comunicacion_supereduc']) || !empty($aula['fecha_comunicacion_supereduc']), 'Comunicación Supereduc respaldada o no aplica.', 'Comunicación Supereduc marcada sin fecha.');

$numeroCaso = (string)($caso['numero_caso'] ?? ('Caso #' . $casoId));
$fechaReporte = date('d-m-Y H:i');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte Aula Segura · <?= ras_e($numeroCaso) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --navy: #0f172a;
            --blue: #1d4ed8;
            --green: #047857;
            --amber: #92400e;
            --red: #b91c1c;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; color: var(--navy); font-family: Arial, Helvetica, sans-serif; }
        .page { width: min(1100px, calc(100% - 2rem)); margin: 1rem auto; background: #fff; border-radius: 18px; box-shadow: 0 18px 50px rgba(15,23,42,.14); overflow: hidden; }
        .toolbar { display: flex; justify-content: space-between; gap: .8rem; align-items: center; padding: 1rem 1.2rem; background: #fff; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 5; }
        .toolbar a, .toolbar button { border: 0; border-radius: 999px; padding: .65rem 1rem; font-weight: 900; font-size: .86rem; cursor: pointer; text-decoration: none; background: #eff6ff; color: var(--blue); border: 1px solid #bfdbfe; }
        .toolbar button { background: var(--navy); color: #fff; border-color: var(--navy); }
        .report { padding: 2rem; }
        .cover { background: linear-gradient(135deg, #0f172a, #1e3a8a 55%, #0f766e); color: #fff; padding: 2rem; border-radius: 18px; margin-bottom: 1.2rem; }
        .cover h1 { margin: 0 0 .35rem; font-size: 2rem; line-height: 1; }
        .cover p { margin: 0; color: #bfdbfe; line-height: 1.45; }
        .meta-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin-top: 1rem; }
        .meta { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.16); border-radius: 14px; padding: .85rem; }
        .meta span { display: block; color: #cbd5e1; font-size: .68rem; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
        .meta strong { display: block; font-size: 1rem; margin-top: .25rem; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .card { border: 1px solid var(--border); border-radius: 16px; padding: 1rem; background: #fff; margin-bottom: 1rem; }
        .card h2 { margin: 0 0 .8rem; font-size: 1.05rem; }
        .card h3 { margin: 1rem 0 .4rem; font-size: .92rem; }
        .badge { display: inline-flex; align-items: center; border-radius: 999px; padding: .28rem .68rem; font-size: .72rem; font-weight: 900; border: 1px solid var(--border); background: var(--bg); color: var(--muted); margin: .12rem .12rem .12rem 0; }
        .badge.ok { background: #ecfdf5; border-color: #bbf7d0; color: var(--green); }
        .badge.warn { background: #fffbeb; border-color: #fde68a; color: var(--amber); }
        .badge.danger { background: #fef2f2; border-color: #fecaca; color: var(--red); }
        .badge.blue { background: #eff6ff; border-color: #bfdbfe; color: var(--blue); }
        .badge.soft { background: #f8fafc; border-color: #e2e8f0; color: #475569; }
        .kv { display: grid; grid-template-columns: 220px 1fr; gap: .7rem; padding: .58rem 0; border-bottom: 1px solid #f1f5f9; }
        .kv:last-child { border-bottom: 0; }
        .kv strong { color: #334155; font-size: .78rem; }
        .kv span, .text-block { color: #0f172a; line-height: 1.45; white-space: pre-wrap; }
        .check { display: grid; grid-template-columns: auto 1fr; gap: .6rem; padding: .55rem 0; border-bottom: 1px solid #f1f5f9; align-items: start; }
        .check:last-child { border-bottom: 0; }
        .dot { width: 12px; height: 12px; border-radius: 999px; margin-top: .22rem; background: #f59e0b; }
        .dot.ok { background: #10b981; }
        .dot.warn { background: #f59e0b; }
        .dot.danger { background: #ef4444; }
        table { width: 100%; border-collapse: collapse; font-size: .86rem; }
        th, td { text-align: left; padding: .65rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { background: #f8fafc; color: #334155; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; }
        .signatures { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-top: 2rem; }
        .signature { border-top: 1px solid #0f172a; padding-top: .5rem; text-align: center; color: #475569; font-size: .82rem; min-height: 70px; }
        @media (max-width: 820px) {
            .grid, .meta-grid, .signatures { grid-template-columns: 1fr; }
            .kv { grid-template-columns: 1fr; gap: .2rem; }
            .toolbar { position: static; flex-direction: column; align-items: stretch; }
        }
        @media print {
            body { background: #fff; }
            .page { width: 100%; margin: 0; border-radius: 0; box-shadow: none; }
            .toolbar { display: none; }
            .report { padding: 1.2cm; }
            .card, .cover { break-inside: avoid; }
            a { color: inherit; text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="toolbar">
        <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$casoId ?>&tab=aula_segura">Volver al expediente</a>
        <button type="button" onclick="window.print()">Imprimir / guardar PDF</button>
    </div>

    <main class="report">
        <section class="cover">
            <h1>Reporte Aula Segura</h1>
            <p>
                Informe normativo imprimible del expediente. La marca “Posible Aula Segura” corresponde
                a una alerta preliminar; el inicio formal requiere decisión fundada de Dirección.
            </p>

            <div class="meta-grid">
                <div class="meta">
                    <span>Caso</span>
                    <strong><?= ras_e($numeroCaso) ?></strong>
                </div>
                <div class="meta">
                    <span>Estado Aula Segura</span>
                    <strong><?= ras_e($estadoTexto) ?></strong>
                </div>
                <div class="meta">
                    <span>Plazo resolución</span>
                    <strong><?= ras_e($plazoTexto) ?></strong>
                </div>
                <div class="meta">
                    <span>Emisión</span>
                    <strong><?= ras_e($fechaReporte) ?></strong>
                </div>
            </div>
        </section>

        <?php if (!$posibleAulaSegura): ?>
            <section class="card">
                <h2>Aula Segura no marcada</h2>
                <p class="text-block">
                    Este caso no fue registrado con alerta preliminar de Aula Segura. Por diseño,
                    no corresponde completar ni emitir control procedimental específico de Aula Segura.
                </p>
            </section>
        <?php else: ?>
            <section class="grid">
                <article class="card">
                    <h2>Identificación del caso</h2>
                    <div class="kv"><strong>Número de caso</strong><span><?= ras_e($numeroCaso) ?></span></div>
                    <div class="kv"><strong>Fecha ingreso</strong><span><?= ras_e(ras_fecha((string)($caso['created_at'] ?? $caso['fecha_ingreso'] ?? ''), true)) ?></span></div>
                    <div class="kv"><strong>Estado formal</strong><span><?= ras_e((string)($caso['estado_formal'] ?? $caso['estado_codigo'] ?? '-')) ?></span></div>
                    <div class="kv"><strong>Prioridad / riesgo</strong><span><?= ras_e(ras_label((string)($caso['prioridad'] ?? $caso['riesgo'] ?? '-'))) ?></span></div>
                    <div class="kv"><strong>Marcado preliminar</strong><span><?= ras_e(ras_fecha((string)($caso['aula_segura_marcado_at'] ?? ''), true)) ?></span></div>
                </article>

                <article class="card">
                    <h2>Estado normativo</h2>
                    <span class="badge <?= ras_e($estadoClass) ?>"><?= ras_e($estadoTexto) ?></span>
                    <span class="badge <?= ras_e($plazoClass) ?>"><?= ras_e($plazoTexto) ?></span>
                    <div class="kv"><strong>Decisión directiva</strong><span><?= ras_e(ras_label((string)($aula['decision_director'] ?? '-'))) ?></span></div>
                    <div class="kv"><strong>Evaluación directiva</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_evaluacion_directiva'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Inicio procedimiento</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_inicio_procedimiento'] ?? ''))) ?></span></div>
                </article>
            </section>

            <section class="card">
                <h2>Causales registradas</h2>
                <?php if (!$causalesMarcadas): ?>
                    <span class="badge warn">Sin causal marcada</span>
                <?php else: ?>
                    <?php foreach ($causalesMarcadas as $causal): ?>
                        <span class="badge danger"><?= ras_e((string)$causal['nombre']) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3>Descripción objetiva del hecho</h3>
                <div class="text-block"><?= ras_e((string)($aula['descripcion_hecho'] ?? 'Sin descripción registrada.')) ?></div>

                <h3>Fundamento de proporcionalidad / Reglamento Interno</h3>
                <div class="text-block"><?= ras_e((string)($aula['fundamento_proporcionalidad'] ?? 'Sin fundamento registrado.')) ?></div>
            </section>

            <section class="grid">
                <article class="card">
                    <h2>Suspensión cautelar y plazos</h2>
                    <div class="kv"><strong>Suspensión cautelar</strong><span><?= (int)($aula['suspension_cautelar'] ?? 0) === 1 ? 'Sí' : 'No' ?></span></div>
                    <div class="kv"><strong>Notificación suspensión</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_notificacion_suspension'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Fecha límite resolución</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_limite_resolucion'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Control plazo</strong><span><span class="badge <?= ras_e($plazoClass) ?>"><?= ras_e($plazoTexto) ?></span></span></div>
                    <h3>Fundamento suspensión</h3>
                    <div class="text-block"><?= ras_e((string)($aula['fundamento_suspension'] ?? 'Sin fundamento registrado.')) ?></div>
                </article>

                <article class="card">
                    <h2>Comunicación y defensa</h2>
                    <div class="kv"><strong>Comunicación apoderado</strong><span><?= ras_e(ras_fecha((string)($aula['comunicacion_apoderado_at'] ?? ''), true)) ?></span></div>
                    <div class="kv"><strong>Medio comunicación</strong><span><?= ras_e((string)($aula['medio_comunicacion_apoderado'] ?? '-')) ?></span></div>
                    <div class="kv"><strong>Descargos recibidos</strong><span><?= (int)($aula['descargos_recibidos'] ?? 0) === 1 ? 'Sí' : 'No / Pendiente' ?></span></div>
                    <div class="kv"><strong>Fecha descargos</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_descargos'] ?? ''))) ?></span></div>
                    <h3>Observación descargos</h3>
                    <div class="text-block"><?= ras_e((string)($aula['observacion_descargos'] ?? 'Sin observación registrada.')) ?></div>
                </article>
            </section>

            <section class="grid">
                <article class="card">
                    <h2>Resolución y reconsideración</h2>
                    <div class="kv"><strong>Resolución</strong><span><?= ras_e(ras_label((string)($aula['resolucion'] ?? '-'))) ?></span></div>
                    <div class="kv"><strong>Fecha resolución</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_resolucion'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Notificación resolución</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_notificacion_resolucion'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Reconsideración</strong><span><?= (int)($aula['reconsideracion_presentada'] ?? 0) === 1 ? 'Presentada' : 'No / Pendiente' ?></span></div>
                    <div class="kv"><strong>Fecha reconsideración</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_reconsideracion'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Resultado reconsideración</strong><span><?= ras_e(ras_label((string)($aula['resultado_reconsideracion'] ?? '-'))) ?></span></div>
                    <h3>Fundamento resolución</h3>
                    <div class="text-block"><?= ras_e((string)($aula['fundamento_resolucion'] ?? 'Sin fundamento registrado.')) ?></div>
                </article>

                <article class="card">
                    <h2>Comunicación Supereduc</h2>
                    <div class="kv"><strong>Comunicación</strong><span><?= (int)($aula['comunicacion_supereduc'] ?? 0) === 1 ? 'Registrada' : 'No registrada / No aplica' ?></span></div>
                    <div class="kv"><strong>Fecha</strong><span><?= ras_e(ras_fecha((string)($aula['fecha_comunicacion_supereduc'] ?? ''))) ?></span></div>
                    <div class="kv"><strong>Medio</strong><span><?= ras_e((string)($aula['medio_comunicacion_supereduc'] ?? '-')) ?></span></div>
                    <h3>Observación</h3>
                    <div class="text-block"><?= ras_e((string)($aula['observacion_supereduc'] ?? 'Sin observación registrada.')) ?></div>
                </article>
            </section>

            <section class="card">
                <h2>Checklist de completitud</h2>
                <?php foreach ($controles as [$texto, $class]): ?>
                    <div class="check">
                        <span class="dot <?= ras_e($class) ?>"></span>
                        <span><?= ras_e($texto) ?></span>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="card">
                <h2>Historial Aula Segura</h2>
                <?php if (!$historial): ?>
                    <p class="text-block">Sin historial específico registrado.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Acción</th>
                                <th>Estado</th>
                                <th>Usuario</th>
                                <th>Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historial as $h): ?>
                                <tr>
                                    <td><?= ras_e(ras_fecha((string)($h['created_at'] ?? ''), true)) ?></td>
                                    <td><?= ras_e(ras_label((string)($h['accion'] ?? '-'))) ?></td>
                                    <td><?= ras_e(ras_label((string)($h['estado_anterior'] ?? '-'))) ?> → <?= ras_e(ras_label((string)($h['estado_nuevo'] ?? '-'))) ?></td>
                                    <td><?= ras_e((string)($h['usuario_nombre'] ?? '-')) ?></td>
                                    <td><?= ras_e((string)($h['detalle'] ?? '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="signatures">
                <div class="signature">Dirección</div>
                <div class="signature">Encargado/a de convivencia</div>
                <div class="signature">Responsable registro</div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
