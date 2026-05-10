<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeGestionar = in_array($rolCodigo, ['superadmin', 'director'], true)
    || Auth::can('admin_sistema');

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Control del proyecto · Metis';
$pageSubtitle = 'Semáforo ejecutivo de pruebas integrales y preparación para producción';

function cp_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cp_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cp_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!cp_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`';
        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function cp_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function cp_pct(int $done, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int)round(($done / $total) * 100);
}

function cp_badge_estado(string $estado): string
{
    return match ($estado) {
        'ok' => 'ok',
        'observado' => 'danger',
        'pendiente' => 'warn',
        'no_aplica' => 'soft',
        default => 'soft',
    };
}

function cp_badge_prioridad(string $prioridad): string
{
    return match ($prioridad) {
        'alta' => 'danger',
        'media' => 'warn',
        'baja' => 'ok',
        default => 'soft',
    };
}

function cp_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Sin dato';
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function cp_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

$error = '';

$existePruebas = cp_table_exists($pdo, 'pruebas_integrales');
$existePreprod = cp_table_exists($pdo, 'checklist_preproduccion');

$pruebas = [
    'total' => 0,
    'ok' => 0,
    'pendiente' => 0,
    'observado' => 0,
    'no_aplica' => 0,
    'alta_pendiente' => 0,
    'alta_observada' => 0,
    'avance' => 0,
];

$preprod = [
    'total' => 0,
    'ok' => 0,
    'pendiente' => 0,
    'observado' => 0,
    'no_aplica' => 0,
    'alta_pendiente' => 0,
    'alta_observada' => 0,
    'avance' => 0,
];

$bloqueadores = [];
$resumenAreas = [];
$resumenCategorias = [];
$ultimosMovimientos = [];

try {
    if ($existePruebas) {
        $pruebas['total'] = cp_count($pdo, 'pruebas_integrales', 'activo = 1');
        foreach (['ok', 'pendiente', 'observado', 'no_aplica'] as $estado) {
            $pruebas[$estado] = cp_count($pdo, 'pruebas_integrales', 'activo = 1 AND resultado = ?', [$estado]);
        }
        $pruebas['alta_pendiente'] = cp_count($pdo, 'pruebas_integrales', "activo = 1 AND prioridad = 'alta' AND resultado = 'pendiente'");
        $pruebas['alta_observada'] = cp_count($pdo, 'pruebas_integrales', "activo = 1 AND prioridad = 'alta' AND resultado = 'observado'");
        $pruebas['avance'] = cp_pct($pruebas['ok'] + $pruebas['no_aplica'], $pruebas['total']);

        $resumenAreas = cp_fetch_all($pdo, "\n            SELECT\n                area,\n                COUNT(*) AS total,\n                SUM(CASE WHEN resultado = 'ok' THEN 1 ELSE 0 END) AS ok_total,\n                SUM(CASE WHEN resultado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,\n                SUM(CASE WHEN resultado = 'observado' THEN 1 ELSE 0 END) AS observados\n            FROM pruebas_integrales\n            WHERE activo = 1\n            GROUP BY area\n            ORDER BY\n                SUM(CASE WHEN prioridad = 'alta' AND resultado IN ('pendiente','observado') THEN 1 ELSE 0 END) DESC,\n                area ASC\n        ");

        $bloqueadores = array_merge($bloqueadores, cp_fetch_all($pdo, "\n            SELECT\n                'Prueba integral' AS origen,\n                id,\n                area AS grupo,\n                prueba AS item,\n                descripcion AS detalle,\n                prioridad,\n                resultado AS estado,\n                responsable,\n                observacion,\n                updated_at\n            FROM pruebas_integrales\n            WHERE activo = 1\n              AND prioridad = 'alta'\n              AND resultado IN ('pendiente','observado')\n            ORDER BY\n                CASE resultado WHEN 'observado' THEN 1 WHEN 'pendiente' THEN 2 ELSE 3 END,\n                id ASC\n            LIMIT 20\n        "));

        $ultimosMovimientos = array_merge($ultimosMovimientos, cp_fetch_all($pdo, "\n            SELECT\n                'Prueba integral' AS origen,\n                area AS grupo,\n                prueba AS item,\n                resultado AS estado,\n                responsable,\n                observacion,\n                updated_at\n            FROM pruebas_integrales\n            WHERE activo = 1\n              AND updated_at IS NOT NULL\n            ORDER BY updated_at DESC\n            LIMIT 8\n        "));
    }

    if ($existePreprod) {
        $preprod['total'] = cp_count($pdo, 'checklist_preproduccion');
        foreach (['ok', 'pendiente', 'observado', 'no_aplica'] as $estado) {
            $preprod[$estado] = cp_count($pdo, 'checklist_preproduccion', 'estado = ?', [$estado]);
        }
        $preprod['alta_pendiente'] = cp_count($pdo, 'checklist_preproduccion', "prioridad = 'alta' AND estado = 'pendiente'");
        $preprod['alta_observada'] = cp_count($pdo, 'checklist_preproduccion', "prioridad = 'alta' AND estado = 'observado'");
        $preprod['avance'] = cp_pct($preprod['ok'] + $preprod['no_aplica'], $preprod['total']);

        $resumenCategorias = cp_fetch_all($pdo, "\n            SELECT\n                categoria,\n                COUNT(*) AS total,\n                SUM(CASE WHEN estado = 'ok' THEN 1 ELSE 0 END) AS ok_total,\n                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,\n                SUM(CASE WHEN estado = 'observado' THEN 1 ELSE 0 END) AS observados\n            FROM checklist_preproduccion\n            GROUP BY categoria\n            ORDER BY\n                SUM(CASE WHEN prioridad = 'alta' AND estado IN ('pendiente','observado') THEN 1 ELSE 0 END) DESC,\n                orden ASC\n        ");

        $bloqueadores = array_merge($bloqueadores, cp_fetch_all($pdo, "\n            SELECT\n                'Preproducción' AS origen,\n                id,\n                categoria AS grupo,\n                item,\n                detalle,\n                prioridad,\n                estado,\n                responsable,\n                observacion,\n                updated_at\n            FROM checklist_preproduccion\n            WHERE prioridad = 'alta'\n              AND estado IN ('pendiente','observado')\n            ORDER BY\n                CASE estado WHEN 'observado' THEN 1 WHEN 'pendiente' THEN 2 ELSE 3 END,\n                orden ASC\n            LIMIT 20\n        "));

        $ultimosMovimientos = array_merge($ultimosMovimientos, cp_fetch_all($pdo, "\n            SELECT\n                'Preproducción' AS origen,\n                categoria AS grupo,\n                item,\n                estado,\n                responsable,\n                observacion,\n                updated_at\n            FROM checklist_preproduccion\n            WHERE updated_at IS NOT NULL\n            ORDER BY updated_at DESC\n            LIMIT 8\n        "));
    }

    usort($ultimosMovimientos, static function (array $a, array $b): int {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    $ultimosMovimientos = array_slice($ultimosMovimientos, 0, 10);
} catch (Throwable $e) {
    $error = 'Error al cargar control del proyecto: ' . $e->getMessage();
}

$totalItems = $pruebas['total'] + $preprod['total'];
$totalOk = $pruebas['ok'] + $preprod['ok'] + $pruebas['no_aplica'] + $preprod['no_aplica'];
$totalPendientes = $pruebas['pendiente'] + $preprod['pendiente'];
$totalObservados = $pruebas['observado'] + $preprod['observado'];
$totalAltasCriticas = $pruebas['alta_pendiente'] + $pruebas['alta_observada'] + $preprod['alta_pendiente'] + $preprod['alta_observada'];
$avanceGlobal = cp_pct($totalOk, $totalItems);
$estadoGlobal = $totalAltasCriticas > 0 ? 'No listo' : ($avanceGlobal >= 90 ? 'Candidato' : 'En preparación');
$estadoColor = $totalAltasCriticas > 0 ? '#b91c1c' : ($avanceGlobal >= 90 ? '#047857' : '#92400e');

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.cp-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.25), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}
.cp-hero h2 { margin: 0 0 .45rem; font-size: 1.9rem; font-weight: 900; }
.cp-hero p { margin: 0; color: #bfdbfe; max-width: 920px; line-height: 1.55; }
.cp-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }
.cp-btn {
    display: inline-flex; align-items: center; gap: .42rem; border-radius: 999px; padding: .62rem 1rem;
    font-size: .84rem; font-weight: 900; text-decoration: none; border: 1px solid rgba(255,255,255,.28);
    color: #fff; background: rgba(255,255,255,.12);
}
.cp-btn:hover { color: #fff; }
.cp-kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .9rem; margin-bottom: 1.2rem; }
.cp-kpi { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 1rem; box-shadow: 0 12px 28px rgba(15,23,42,.06); }
.cp-kpi span { color: #64748b; display: block; font-size: .68rem; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
.cp-kpi strong { display: block; color: #0f172a; font-size: 1.85rem; line-height: 1; margin-top: .35rem; }
.cp-layout { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(360px, .9fr); gap: 1.2rem; align-items: start; }
.cp-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 12px 28px rgba(15,23,42,.06); overflow: hidden; margin-bottom: 1.2rem; }
.cp-panel-head { padding: 1rem 1.2rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }
.cp-panel-title { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 900; }
.cp-panel-body { padding: 1.2rem; }
.cp-progress { height: 14px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
.cp-progress > div { height: 100%; background: linear-gradient(90deg, #2563eb, #10b981); border-radius: 999px; }
.cp-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem; }
.cp-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 18px; padding: 1rem; margin-bottom: .85rem; }
.cp-card-title { color: #0f172a; font-weight: 900; margin-bottom: .2rem; }
.cp-meta { color: #64748b; font-size: .78rem; line-height: 1.4; margin-top: .25rem; }
.cp-text { color: #334155; line-height: 1.45; font-size: .86rem; margin-top: .45rem; }
.cp-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: .24rem .62rem; font-size: .72rem; font-weight: 900; border: 1px solid #e2e8f0; background: #fff; color: #475569; white-space: nowrap; margin: .12rem; }
.cp-badge.ok { background: #ecfdf5; border-color: #bbf7d0; color: #047857; }
.cp-badge.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.cp-badge.danger { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
.cp-badge.soft { background: #f8fafc; color: #475569; }
.cp-link { display: inline-flex; align-items: center; gap: .35rem; border-radius: 999px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: .48rem .85rem; font-size: .78rem; font-weight: 900; text-decoration: none; white-space: nowrap; }
.cp-link.warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.cp-link.green { background: #ecfdf5; color: #047857; border-color: #bbf7d0; }
.cp-table-scroll { width: 100%; overflow-x: auto; }
.cp-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .84rem; }
.cp-table th { background: #f8fafc; color: #64748b; font-size: .66rem; text-transform: uppercase; letter-spacing: .08em; padding: .75rem; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
.cp-table td { padding: .8rem .75rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.cp-error { border-radius: 14px; padding: .9rem 1rem; margin-bottom: 1rem; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-weight: 800; }
.cp-empty { text-align: center; padding: 2rem 1rem; color: #94a3b8; }
@media (max-width: 1300px) { .cp-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } .cp-layout { grid-template-columns: 1fr; } }
@media (max-width: 760px) { .cp-kpis, .cp-grid2 { grid-template-columns: 1fr; } .cp-hero { padding: 1.35rem; } }
</style>

<section class="cp-hero">
    <h2>Centro de control del proyecto</h2>
    <p>
        Vista consolidada para bajar ansiedad y decidir el próximo trabajo: combina pruebas integrales,
        checklist preproducción, bloqueadores críticos y avance global antes de pasar a producción.
    </p>

    <div class="cp-actions">
        <a class="cp-btn" href="<?= APP_URL ?>/modules/admin/pruebas_integrales.php">
            <i class="bi bi-clipboard2-check"></i>
            Pruebas integrales
        </a>

        <a class="cp-btn" href="<?= APP_URL ?>/modules/admin/preproduccion.php">
            <i class="bi bi-rocket-takeoff"></i>
            Preproducción
        </a>

        <a class="cp-btn" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <i class="bi bi-shield-check"></i>
            Diagnóstico
        </a>

        <a class="cp-btn" href="<?= APP_URL ?>/modules/dashboard/index.php">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="cp-error"><?= e($error) ?></div>
<?php endif; ?>

<section class="cp-kpis">
    <div class="cp-kpi">
        <span>Avance global</span>
        <strong><?= number_format($avanceGlobal, 0, ',', '.') ?>%</strong>
    </div>

    <div class="cp-kpi">
        <span>Estado salida</span>
        <strong style="font-size:1.25rem;color:<?= e($estadoColor) ?>;"><?= e($estadoGlobal) ?></strong>
    </div>

    <div class="cp-kpi">
        <span>Bloqueadores alta</span>
        <strong style="color:<?= $totalAltasCriticas > 0 ? '#b91c1c' : '#047857' ?>;">
            <?= number_format($totalAltasCriticas, 0, ',', '.') ?>
        </strong>
    </div>

    <div class="cp-kpi">
        <span>Pendientes</span>
        <strong style="color:<?= $totalPendientes > 0 ? '#92400e' : '#047857' ?>;">
            <?= number_format($totalPendientes, 0, ',', '.') ?>
        </strong>
    </div>

    <div class="cp-kpi">
        <span>Observados</span>
        <strong style="color:<?= $totalObservados > 0 ? '#b91c1c' : '#047857' ?>;">
            <?= number_format($totalObservados, 0, ',', '.') ?>
        </strong>
    </div>

    <div class="cp-kpi">
        <span>Total controlado</span>
        <strong><?= number_format($totalItems, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="cp-panel">
    <div class="cp-panel-head">
        <h3 class="cp-panel-title">
            <i class="bi bi-bar-chart-line"></i>
            Avance consolidado
        </h3>
    </div>

    <div class="cp-panel-body">
        <div class="cp-progress" aria-label="Avance global">
            <div style="width:<?= max(0, min(100, $avanceGlobal)) ?>%;"></div>
        </div>
        <div class="cp-meta" style="margin-top:.65rem;">
            Se considera avance cumplido lo marcado como <strong>OK</strong> o <strong>No aplica</strong>.
            Para pasar a producción, no deberían existir observados ni pendientes de prioridad alta.
        </div>
    </div>
</section>

<div class="cp-layout">
    <section>
        <div class="cp-panel">
            <div class="cp-panel-head">
                <h3 class="cp-panel-title">
                    <i class="bi bi-exclamation-triangle"></i>
                    Bloqueadores críticos
                </h3>

                <span class="cp-badge <?= $totalAltasCriticas > 0 ? 'danger' : 'ok' ?>">
                    <?= number_format($totalAltasCriticas, 0, ',', '.') ?> prioridad alta
                </span>
            </div>

            <div class="cp-panel-body">
                <?php if (!$existePruebas || !$existePreprod): ?>
                    <div class="cp-error">
                        Faltan tablas de control. Ejecuta primero las fases 0.5.28 y 0.5.29 si aún no están instaladas.
                    </div>
                <?php endif; ?>

                <?php if (!$bloqueadores): ?>
                    <div class="cp-empty">
                        No hay bloqueadores críticos de prioridad alta. Buen indicador para avanzar.
                    </div>
                <?php else: ?>
                    <?php foreach ($bloqueadores as $item): ?>
                        <article class="cp-card">
                            <div class="cp-card-title"><?= e((string)$item['item']) ?></div>
                            <div>
                                <span class="cp-badge soft"><?= e((string)$item['origen']) ?></span>
                                <span class="cp-badge soft"><?= e((string)$item['grupo']) ?></span>
                                <span class="cp-badge <?= e(cp_badge_prioridad((string)$item['prioridad'])) ?>">
                                    <?= e(cp_label((string)$item['prioridad'])) ?>
                                </span>
                                <span class="cp-badge <?= e(cp_badge_estado((string)$item['estado'])) ?>">
                                    <?= e(cp_label((string)$item['estado'])) ?>
                                </span>
                            </div>
                            <?php if (!empty($item['detalle'])): ?>
                                <div class="cp-text"><?= e((string)$item['detalle']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($item['observacion'])): ?>
                                <div class="cp-meta"><strong>Observación:</strong> <?= e((string)$item['observacion']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($item['responsable'])): ?>
                                <div class="cp-meta"><strong>Responsable:</strong> <?= e((string)$item['responsable']) ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="cp-panel">
            <div class="cp-panel-head">
                <h3 class="cp-panel-title">
                    <i class="bi bi-list-check"></i>
                    Avance por área de pruebas
                </h3>

                <a class="cp-link" href="<?= APP_URL ?>/modules/admin/pruebas_integrales.php">Abrir pruebas</a>
            </div>

            <div class="cp-table-scroll">
                <table class="cp-table">
                    <thead>
                        <tr>
                            <th>Área</th>
                            <th>Total</th>
                            <th>OK</th>
                            <th>Pend.</th>
                            <th>Obs.</th>
                            <th>Avance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$resumenAreas): ?>
                            <tr><td colspan="6" class="cp-empty">Sin información de pruebas integrales.</td></tr>
                        <?php else: ?>
                            <?php foreach ($resumenAreas as $row): ?>
                                <?php $pct = cp_pct((int)$row['ok_total'], (int)$row['total']); ?>
                                <tr>
                                    <td><strong><?= e((string)$row['area']) ?></strong></td>
                                    <td><?= (int)$row['total'] ?></td>
                                    <td><span class="cp-badge ok"><?= (int)$row['ok_total'] ?></span></td>
                                    <td><span class="cp-badge warn"><?= (int)$row['pendientes'] ?></span></td>
                                    <td><span class="cp-badge danger"><?= (int)$row['observados'] ?></span></td>
                                    <td><?= $pct ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <aside>
        <div class="cp-panel">
            <div class="cp-panel-head">
                <h3 class="cp-panel-title">
                    <i class="bi bi-rocket-takeoff"></i>
                    Preparación producción
                </h3>

                <a class="cp-link green" href="<?= APP_URL ?>/modules/admin/preproduccion.php">Abrir checklist</a>
            </div>

            <div class="cp-panel-body">
                <div class="cp-grid2">
                    <div class="cp-card">
                        <div class="cp-card-title">Pruebas integrales</div>
                        <div class="cp-meta">Avance</div>
                        <div style="font-size:2rem;font-weight:900;color:#0f172a;margin-top:.25rem;">
                            <?= number_format($pruebas['avance'], 0, ',', '.') ?>%
                        </div>
                        <div class="cp-meta">
                            OK: <?= (int)$pruebas['ok'] ?> · Pendientes: <?= (int)$pruebas['pendiente'] ?> · Observados: <?= (int)$pruebas['observado'] ?>
                        </div>
                    </div>

                    <div class="cp-card">
                        <div class="cp-card-title">Checklist producción</div>
                        <div class="cp-meta">Avance</div>
                        <div style="font-size:2rem;font-weight:900;color:#0f172a;margin-top:.25rem;">
                            <?= number_format($preprod['avance'], 0, ',', '.') ?>%
                        </div>
                        <div class="cp-meta">
                            OK: <?= (int)$preprod['ok'] ?> · Pendientes: <?= (int)$preprod['pendiente'] ?> · Observados: <?= (int)$preprod['observado'] ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="cp-panel">
            <div class="cp-panel-head">
                <h3 class="cp-panel-title">
                    <i class="bi bi-boxes"></i>
                    Checklist por categoría
                </h3>
            </div>

            <div class="cp-panel-body">
                <?php if (!$resumenCategorias): ?>
                    <div class="cp-empty">Sin información de preproducción.</div>
                <?php else: ?>
                    <?php foreach ($resumenCategorias as $row): ?>
                        <?php $pct = cp_pct((int)$row['ok_total'], (int)$row['total']); ?>
                        <article class="cp-card">
                            <div class="cp-card-title"><?= e(cp_label((string)$row['categoria'])) ?></div>
                            <div>
                                <span class="cp-badge ok">OK <?= (int)$row['ok_total'] ?></span>
                                <span class="cp-badge warn">Pend. <?= (int)$row['pendientes'] ?></span>
                                <span class="cp-badge danger">Obs. <?= (int)$row['observados'] ?></span>
                                <span class="cp-badge soft">Avance <?= $pct ?>%</span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="cp-panel">
            <div class="cp-panel-head">
                <h3 class="cp-panel-title">
                    <i class="bi bi-clock-history"></i>
                    Últimos movimientos
                </h3>
            </div>

            <div class="cp-panel-body">
                <?php if (!$ultimosMovimientos): ?>
                    <div class="cp-empty">Aún no hay movimientos registrados.</div>
                <?php else: ?>
                    <?php foreach ($ultimosMovimientos as $mov): ?>
                        <article class="cp-card">
                            <div class="cp-card-title"><?= e((string)$mov['item']) ?></div>
                            <div>
                                <span class="cp-badge soft"><?= e((string)$mov['origen']) ?></span>
                                <span class="cp-badge <?= e(cp_badge_estado((string)$mov['estado'])) ?>">
                                    <?= e(cp_label((string)$mov['estado'])) ?>
                                </span>
                            </div>
                            <div class="cp-meta">
                                <?= e((string)$mov['grupo']) ?> · <?= e(cp_fecha((string)($mov['updated_at'] ?? ''))) ?>
                            </div>
                            <?php if (!empty($mov['responsable'])): ?>
                                <div class="cp-meta">Responsable: <?= e((string)$mov['responsable']) ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
