<?php
declare(strict_types=1);
/**
 * Módulo Seguimiento — Bandeja ejecutiva de casos activos
 * Corrección Fase 22A.1:
 * - Evita error 500 por dependencias opcionales.
 * - Mantiene barra contextual global si existe core/context_actions.php.
 * - Evita filtrar por columnas no garantizadas en tablas relacionadas.
 * - Toda consulta queda protegida por try/catch para no bloquear la vista.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

$contextActionsFile = dirname(__DIR__, 2) . '/core/context_actions.php';
if (is_file($contextActionsFile)) {
    require_once $contextActionsFile;
}

if (!function_exists('metis_context_action')) {
    function metis_context_action(string $label, string $url, string $icon = 'bi-chevron-right', string $variant = 'secondary', bool $visible = true): array
    {
        return compact('label', 'url', 'icon', 'variant', 'visible');
    }
}

if (!function_exists('metis_context_actions')) {
    function metis_context_actions(array $actions): array
    {
        return array_values(array_filter($actions, static fn($a) => is_array($a) && ($a['visible'] ?? true)));
    }
}

if (!function_exists('metis_topbar_action_visible')) {
    function metis_topbar_action_visible(PDO $pdo, string $key, bool $default = false): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = 'acciones_expediente_topbar' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if (!$raw) {
                return $default;
            }
            $cfg = json_decode((string)$raw, true);
            if (!is_array($cfg) || !array_key_exists($key, $cfg)) {
                return $default;
            }
            return (int)($cfg[$key]['visible'] ?? 0) === 1;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$cid = method_exists('Auth', 'colegioId') ? (int)Auth::colegioId() : (int)($user['colegio_id'] ?? 0);

$pageTitle = 'Seguimiento · Metis';
$pageSubtitle = 'Bandeja ejecutiva de casos activos, planes, revisiones y alertas.';

function seg_norm_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

function seg_e(string $value): string
{
    return function_exists('e') ? e($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$filtDesde = seg_norm_date((string)($_GET['desde'] ?? ''));
$filtHasta = seg_norm_date((string)($_GET['hasta'] ?? ''));

$kpi = [
    'total'              => 0,
    'riesgo_alto'        => 0,
    'revision_vencida'   => 0,
    'sin_plan'           => 0,
    'con_sesion_hoy'     => 0,
    'alta'               => 0,
    'alertas_pendientes' => 0,
    'sin_pauta_riesgo'   => 0,
];

$casos = [];
$erroresSeguimiento = [];

$activeWhere = "c.colegio_id = ? AND COALESCE(ec.codigo, 'abierto') NOT IN ('cerrado','archivado','borrador')";
$activeParams = [$cid];

$tablaWhere = $activeWhere;
$tablaParams = $activeParams;

if ($filtDesde !== '') {
    $tablaWhere .= " AND DATE(COALESCE(c.fecha_ingreso, c.created_at)) >= ?";
    $tablaParams[] = $filtDesde;
}
if ($filtHasta !== '') {
    $tablaWhere .= " AND DATE(COALESCE(c.fecha_ingreso, c.created_at)) <= ?";
    $tablaParams[] = $filtHasta;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM casos c LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id WHERE {$activeWhere}");
    $stmt->execute($activeParams);
    $kpi['total'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $erroresSeguimiento[] = 'No fue posible calcular total de casos activos.';
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM casos c LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id WHERE {$activeWhere} AND c.prioridad = 'alta'");
    $stmt->execute($activeParams);
    $kpi['alta'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM casos c\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$activeWhere}\n          AND NOT EXISTS (\n              SELECT 1\n              FROM caso_plan_accion pa\n              WHERE pa.caso_id = c.id\n                AND pa.colegio_id = c.colegio_id\n                AND pa.vigente = 1\n          )\n    ");
    $stmt->execute($activeParams);
    $kpi['sin_plan'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM casos c\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$activeWhere}\n          AND NOT EXISTS (\n              SELECT 1\n              FROM caso_pauta_riesgo pr\n              WHERE pr.caso_id = c.id\n                AND pr.completada_por IS NOT NULL\n          )\n    ");
    $stmt->execute($activeParams);
    $kpi['sin_pauta_riesgo'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM casos c\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$activeWhere}\n          AND EXISTS (\n              SELECT 1\n              FROM caso_pauta_riesgo pr\n              WHERE pr.caso_id = c.id\n                AND pr.completada_por IS NOT NULL\n                AND pr.nivel_final IN ('alto','critico')\n          )\n    ");
    $stmt->execute($activeParams);
    $kpi['riesgo_alto'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM caso_alertas a\n        INNER JOIN casos c ON c.id = a.caso_id\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$activeWhere}\n          AND a.estado = 'pendiente'\n    ");
    $stmt->execute($activeParams);
    $kpi['alertas_pendientes'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM casos c\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$activeWhere}\n          AND EXISTS (\n              SELECT 1\n              FROM caso_seguimiento_sesion css\n              WHERE css.caso_id = c.id\n                AND css.colegio_id = c.colegio_id\n                AND DATE(css.created_at) = CURDATE()\n          )\n    ");
    $stmt->execute($activeParams);
    $kpi['con_sesion_hoy'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM casos c\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$activeWhere}\n          AND EXISTS (\n              SELECT 1\n              FROM caso_seguimiento_sesion css\n              WHERE css.caso_id = c.id\n                AND css.colegio_id = c.colegio_id\n                AND css.proxima_revision < CURDATE()\n                AND css.proxima_revision IS NOT NULL\n          )\n          AND NOT EXISTS (\n              SELECT 1\n              FROM caso_seguimiento_sesion css2\n              WHERE css2.caso_id = c.id\n                AND css2.colegio_id = c.colegio_id\n                AND css2.proxima_revision >= CURDATE()\n          )\n    ");
    $stmt->execute($activeParams);
    $kpi['revision_vencida'] = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("\n        SELECT\n            c.id,\n            c.numero_caso,\n            c.prioridad,\n            c.updated_at,\n            COALESCE(c.fecha_ingreso, c.created_at) AS fecha_ingreso,\n            ec.nombre AS estado_formal,\n            ec.codigo AS estado_codigo,\n            DATEDIFF(NOW(), COALESCE(c.updated_at, c.fecha_ingreso, c.created_at)) AS dias_sin_mov,\n            (SELECT MAX(css.created_at)\n             FROM caso_seguimiento_sesion css\n             WHERE css.caso_id = c.id\n               AND css.colegio_id = c.colegio_id) AS ultima_sesion,\n            (SELECT MIN(css.proxima_revision)\n             FROM caso_seguimiento_sesion css\n             WHERE css.caso_id = c.id\n               AND css.colegio_id = c.colegio_id\n               AND css.proxima_revision >= CURDATE()) AS proxima_revision,\n            (SELECT MAX(css.proxima_revision)\n             FROM caso_seguimiento_sesion css\n             WHERE css.caso_id = c.id\n               AND css.colegio_id = c.colegio_id\n               AND css.proxima_revision < CURDATE()\n               AND css.proxima_revision IS NOT NULL) AS revision_vencida_fecha,\n            (SELECT COUNT(*)\n             FROM caso_plan_accion pa\n             WHERE pa.caso_id = c.id\n               AND pa.colegio_id = c.colegio_id\n               AND pa.vigente = 1) AS tiene_plan,\n            (SELECT COUNT(*)\n             FROM caso_alertas a\n             WHERE a.caso_id = c.id\n               AND a.estado = 'pendiente') AS alertas,\n            (SELECT pr.nivel_final\n             FROM caso_pauta_riesgo pr\n             WHERE pr.caso_id = c.id\n               AND pr.completada_por IS NOT NULL\n             ORDER BY pr.created_at DESC, pr.id DESC\n             LIMIT 1) AS nivel_riesgo,\n            (SELECT COUNT(*)\n             FROM caso_participantes cp\n             WHERE cp.caso_id = c.id) AS participantes\n        FROM casos c\n        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id\n        WHERE {$tablaWhere}\n        ORDER BY\n            FIELD(c.prioridad,'alta','media','baja') ASC,\n            COALESCE(c.updated_at, c.fecha_ingreso, c.created_at) ASC\n        LIMIT 100\n    ");
    $stmt->execute($tablaParams);
    $casos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $erroresSeguimiento[] = 'No fue posible cargar la bandeja de seguimiento. Revise tablas de seguimiento si el problema persiste.';
    $casos = [];
}

$pageHeaderActions = metis_context_actions([
    metis_context_action('Denuncias', APP_URL . '/modules/denuncias/index.php', 'bi-folder2-open', 'primary'),
    metis_context_action('Alertas', APP_URL . '/modules/alertas/index.php', 'bi-bell-fill', 'warning', metis_topbar_action_visible($pdo, 'alertas', false)),
    metis_context_action('Dashboard', APP_URL . '/modules/dashboard/index.php', 'bi-speedometer2', 'secondary'),
    metis_context_action('Reportes', APP_URL . '/modules/reportes/index.php', 'bi-bar-chart', 'secondary'),
]);

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
$styleFile = __DIR__ . '/partials/_style.php';
if (is_file($styleFile)) {
    require_once $styleFile;
}
?>

<section class="seg-page" style="padding: 1.25rem;">
    <div class="seg-hero" style="margin-bottom:1rem;">
        <div>
            <div class="seg-eyebrow"><i class="bi bi-graph-up"></i> METIS · CONTROL DIRECTIVO</div>
            <h1>Seguimiento de casos</h1>
            <p>Vista panorámica de casos activos · <?= date('d-m-Y H:i') ?></p>
        </div>
    </div>

    <div class="seg-kpis">
        <div class="seg-kpi"><strong><?= (int)$kpi['total'] ?></strong><span>Casos activos</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['riesgo_alto'] ?></strong><span>Riesgo alto/crítico</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['revision_vencida'] ?></strong><span>Revisiones vencidas</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['sin_plan'] ?></strong><span>Sin plan de acción</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['con_sesion_hoy'] ?></strong><span>Sesiones hoy</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['alta'] ?></strong><span>Prioridad alta</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['alertas_pendientes'] ?></strong><span>Alertas pendientes</span></div>
        <div class="seg-kpi"><strong><?= (int)$kpi['sin_pauta_riesgo'] ?></strong><span>Sin pauta de riesgo</span></div>
    </div>

    <?php foreach ($erroresSeguimiento as $msg): ?>
        <div style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:10px;padding:.8rem 1rem;margin:.75rem 0;font-size:.88rem;">
            <i class="bi bi-exclamation-triangle"></i> <?= seg_e($msg) ?>
        </div>
    <?php endforeach; ?>

    <div class="seg-card">
        <div style="padding:.85rem 1.2rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <span style="font-size:.73rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">
                <i class="bi bi-funnel"></i> Filtrar por fecha de ingreso
            </span>
            <form method="get" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                <input type="date" name="desde" value="<?= seg_e($filtDesde) ?>" style="border:1px solid #cbd5e1;border-radius:8px;padding:.32rem .65rem;font-size:.83rem;outline:none;background:#fff;">
                <span style="color:#94a3b8;font-size:.8rem;">—</span>
                <input type="date" name="hasta" value="<?= seg_e($filtHasta) ?>" style="border:1px solid #cbd5e1;border-radius:8px;padding:.32rem .65rem;font-size:.83rem;outline:none;background:#fff;">
                <button type="submit" style="background:#0f172a;color:#fff;border:0;border-radius:7px;padding:.35rem .8rem;font-size:.8rem;font-weight:600;cursor:pointer;">Aplicar</button>
                <?php if ($filtDesde !== '' || $filtHasta !== ''): ?>
                    <a href="<?= APP_URL ?>/modules/seguimiento/index.php" style="font-size:.8rem;color:#64748b;text-decoration:none;white-space:nowrap;">
                        <i class="bi bi-x-circle"></i> Limpiar
                    </a>
                <?php endif; ?>
            </form>
            <span style="margin-left:auto;font-size:.78rem;color:#94a3b8;white-space:nowrap;">
                <?= count($casos) ?> caso(s) visibles
            </span>
        </div>

        <?php if (!$casos): ?>
            <div class="seg-empty">
                <i class="bi bi-check-circle" style="font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3;"></i>
                No hay casos activos en seguimiento.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="seg-table">
                    <thead>
                        <tr>
                            <th>N° Caso</th>
                            <th>Riesgo</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th>Partic.</th>
                            <th>Plan</th>
                            <th>Alertas</th>
                            <th>Última sesión</th>
                            <th>Próx. revisión</th>
                            <th>Sin mov.</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($casos as $c):
                        $nivelR = (string)($c['nivel_riesgo'] ?? '');
                        $proxima = (string)($c['proxima_revision'] ?? '');
                        $vencida = (string)($c['revision_vencida_fecha'] ?? '');
                        $revVencida = $proxima === '' && $vencida !== '';
                        $riesgoLabel = $nivelR !== '' ? ucfirst($nivelR) : 'Sin pauta';
                        $prioridad = (string)($c['prioridad'] ?? 'media');
                    ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$c['id'] ?>" style="color:#2563eb;font-weight:600;text-decoration:none;">
                                    <?= seg_e((string)$c['numero_caso']) ?>
                                </a>
                            </td>
                            <td><span class="badge <?= $nivelR === 'critico' ? 'badge-negro' : ($nivelR === 'alto' ? 'badge-rojo' : 'badge-gris') ?>"><?= seg_e($riesgoLabel) ?></span></td>
                            <td><span class="badge <?= $prioridad === 'alta' ? 'badge-rojo' : ($prioridad === 'media' ? 'badge-amarillo' : 'badge-gris') ?>"><?= seg_e(ucfirst($prioridad)) ?></span></td>
                            <td style="font-size:.78rem;color:#475569;"><?= seg_e((string)($c['estado_formal'] ?? 'Sin estado')) ?></td>
                            <td style="text-align:center;font-weight:600;"><?= (int)($c['participantes'] ?? 0) ?></td>
                            <td style="text-align:center;"><?= (int)($c['tiene_plan'] ?? 0) > 0 ? '<i class="bi bi-check-circle-fill" style="color:#059669;"></i>' : '<i class="bi bi-dash-circle" style="color:#dc2626;"></i>' ?></td>
                            <td style="text-align:center;"><?= (int)($c['alertas'] ?? 0) > 0 ? '<span class="badge badge-rojo">'.(int)$c['alertas'].'</span>' : '<span style="color:#94a3b8;">—</span>' ?></td>
                            <td style="font-size:.77rem;color:#64748b;white-space:nowrap;"><?= !empty($c['ultima_sesion']) ? date('d-m-Y', strtotime((string)$c['ultima_sesion'])) : '<span style="color:#dc2626;">Sin sesión</span>' ?></td>
                            <td style="font-size:.77rem;white-space:nowrap;color:<?= $revVencida ? '#dc2626' : '#374151' ?>;font-weight:<?= $revVencida ? '700' : '400' ?>;">
                                <?php if ($proxima !== ''): ?>
                                    <?= date('d-m-Y', strtotime($proxima)) ?>
                                <?php elseif ($revVencida): ?>
                                    <i class="bi bi-exclamation-circle-fill"></i> Vencida: <?= date('d-m-Y', strtotime($vencida)) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.77rem;color:#64748b;"><?= (int)($c['dias_sin_mov'] ?? 0) ?>d</td>
                            <td>
                                <a href="<?= APP_URL ?>/modules/denuncias/ver.php?id=<?= (int)$c['id'] ?>&tab=seguimiento" class="btn-ir">
                                    <i class="bi bi-journal-check"></i> Seguimiento
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
