<?php
declare(strict_types=1);

/**
 * Navegación contextual del expediente.
 * Mantiene compatibilidad con ?id={caso}&tab={tab} y respeta la configuración
 * existente en sistema_config.tabs_ver_denuncia.
 */
function ver_tab_visible(PDO $pdo, string $tab): bool
{
    static $config = null;

    if ($config === null) {
        try {
            $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = 'tabs_ver_denuncia' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            $config = $val ? (json_decode((string)$val, true) ?: []) : [];
        } catch (Throwable $e) {
            $config = [];
        }
    }

    if (!isset($config[$tab])) {
        return true;
    }

    return (bool)($config[$tab]['visible'] ?? true);
}

function ver_tab_url(int $casoId, string $tab): string
{
    return '?id=' . $casoId . '&tab=' . rawurlencode($tab);
}

function ver_tab_active(string $actual, string $tab): string
{
    return $actual === $tab ? ' active' : '';
}

function ver_badge_html(string $text, string $type = 'soft', string $title = ''): string
{
    $titleAttr = $title !== '' ? ' title="' . e($title) . '"' : '';
    return '<span class="exp-tab-badge ' . e($type) . '"' . $titleAttr . '>' . e($text) . '</span>';
}

function ver_render_nav_item(array $item, int $casoId, string $tab): void
{
    $badge = (string)($item['badge'] ?? '');
    ?>
    <a class="exp-case-nav-link<?= ver_tab_active($tab, (string)$item['tab']) ?>"
       href="<?= e(ver_tab_url($casoId, (string)$item['tab'])) ?>"
       <?= !empty($item['title']) ? 'title="' . e((string)$item['title']) . '"' : '' ?>>
        <span class="exp-case-nav-left">
            <i class="bi <?= e((string)$item['icon']) ?>"></i>
            <span><?= e((string)$item['label']) ?></span>
        </span>
        <?php if ($badge !== ''): ?>
            <?= $badge ?>
        <?php endif; ?>
    </a>
    <?php
}

$_noPautasAlto = 0;
$_sinPauta = 0;

try {
    $stmtBl = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_pauta_riesgo pr
        INNER JOIN casos c ON c.id = pr.caso_id
        WHERE pr.caso_id = ?
          AND c.colegio_id = ?
          AND pr.nivel_final IN ('alto','critico')
          AND (pr.derivado = 0 OR pr.derivado IS NULL)
    ");
    $stmtBl->execute([$casoId, $colegioId]);
    $_noPautasAlto = (int)$stmtBl->fetchColumn();
} catch (Throwable $e) {
    $_noPautasAlto = 0;
}

try {
    $stmtVT2 = $pdo->prepare("
        SELECT COUNT(*)
        FROM caso_participantes cp
        INNER JOIN casos c ON c.id = cp.caso_id
        WHERE cp.caso_id = ?
          AND c.colegio_id = ?
          AND cp.rol_en_caso IN ('victima','testigo')
    ");
    $stmtVT2->execute([$casoId, $colegioId]);
    $totalVT = (int)$stmtVT2->fetchColumn();

    $stmtCP2 = $pdo->prepare("
        SELECT COUNT(DISTINCT CONCAT(COALESCE(pr.alumno_id,0),'_',pr.rol_en_caso))
        FROM caso_pauta_riesgo pr
        INNER JOIN casos c ON c.id = pr.caso_id
        WHERE pr.caso_id = ?
          AND c.colegio_id = ?
    ");
    $stmtCP2->execute([$casoId, $colegioId]);
    $conPauta = (int)$stmtCP2->fetchColumn();

    $_sinPauta = max(0, $totalVT - $conPauta);
} catch (Throwable $e) {
    $_sinPauta = 0;
}

$tieneModuloIAVer = false;
try {
    $stmtModIA = $pdo->prepare("
        SELECT COUNT(*)
        FROM colegio_modulos
        WHERE colegio_id = ?
          AND modulo_codigo = 'ia'
          AND activo = 1
          AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
    ");
    $stmtModIA->execute([$colegioId]);
    $tieneModuloIAVer = (bool)$stmtModIA->fetchColumn();
} catch (Throwable $e) {
    $tieneModuloIAVer = false;
}

$esSuperAdminVer = (($user['rol_codigo'] ?? '') === 'superadmin');
$totalDeclaraciones = count($declaraciones ?? []);
$totalEvidencias = count($evidencias ?? []);
$totalParticipantes = count($participantes ?? []);
$totalGestion = count($gestionEjecutiva ?? []);
$navGroups = [];

$navGroups[] = [
    'title' => 'Expediente',
    'items' => array_values(array_filter([
        [
            'tab' => 'resumen',
            'label' => 'Resumen ejecutivo',
            'icon' => 'bi-file-text-fill',
        ],
        ver_tab_visible($pdo, 'participantes') ? [
            'tab' => 'participantes',
            'label' => 'Intervinientes',
            'icon' => 'bi-people-fill',
            'badge' => $totalParticipantes > 0 ? ver_badge_html((string)$totalParticipantes, 'soft') : '',
        ] : null,
        ver_tab_visible($pdo, 'declaraciones') ? [
            'tab' => 'declaraciones',
            'label' => 'Declaraciones',
            'icon' => 'bi-chat-left-text-fill',
            'badge' => $totalDeclaraciones > 0 ? ver_badge_html((string)$totalDeclaraciones, 'soft') : '',
        ] : null,
        ver_tab_visible($pdo, 'evidencias') ? [
            'tab' => 'evidencias',
            'label' => 'Evidencias',
            'icon' => 'bi-folder2-open',
            'badge' => $totalEvidencias > 0 ? ver_badge_html((string)$totalEvidencias, 'soft') : '',
        ] : null,
    ])),
];

$navGroups[] = [
    'title' => 'Análisis',
    'items' => array_values(array_filter([
        ver_tab_visible($pdo, 'clasificacion') ? [
            'tab' => 'clasificacion',
            'label' => 'Clasificación',
            'icon' => 'bi-tag-fill',
        ] : null,
        ver_tab_visible($pdo, 'pauta_riesgo') ? [
            'tab' => 'pauta_riesgo',
            'label' => 'Pauta de riesgo',
            'icon' => 'bi-clipboard2-pulse-fill',
            'badge' => $_noPautasAlto > 0
                ? ver_badge_html('⚑ ' . $_noPautasAlto, 'danger', 'Hay riesgo alto/crítico sin derivar')
                : ($_sinPauta > 0 ? ver_badge_html((string)$_sinPauta, 'warn', 'Pautas pendientes') : ''),
        ] : null,
        ver_tab_visible($pdo, 'aula_segura') ? [
            'tab' => 'aula_segura',
            'label' => 'Aula Segura',
            'icon' => 'bi-shield-fill-check',
        ] : null,
        ($tieneModuloIAVer || $esSuperAdminVer) ? [
            'tab' => 'analisis_ia',
            'label' => 'Análisis IA',
            'icon' => 'bi-stars',
            'badge' => ($esSuperAdminVer && !$tieneModuloIAVer) ? ver_badge_html('DEMO', 'blue') : '',
        ] : null,
    ])),
];

$navGroups[] = [
    'title' => 'Gestión',
    'items' => array_values(array_filter([
        [
            'tab' => 'seguimiento',
            'label' => 'Seguimiento',
            'icon' => 'bi-journal-check',
        ],
        ver_tab_visible($pdo, 'plan_accion') ? [
            'tab' => 'plan_accion',
            'label' => 'Plan de acción',
            'icon' => 'bi-list-check',
        ] : null,
        ver_tab_visible($pdo, 'gestion') ? [
            'tab' => 'gestion',
            'label' => 'Gestión ejecutiva',
            'icon' => 'bi-briefcase-fill',
            'badge' => $totalGestion > 0 ? ver_badge_html((string)$totalGestion, 'soft') : '',
        ] : null,
    ])),
];

$navGroups[] = [
    'title' => 'Cierre y trazabilidad',
    'items' => array_values(array_filter([
        ver_tab_visible($pdo, 'cierre') ? [
            'tab' => 'cierre',
            'label' => 'Cierre del caso',
            'icon' => 'bi-check2-circle',
            'badge' => !empty($cierreCaso)
                ? ver_badge_html('1', 'ok')
                : ($_noPautasAlto > 0 ? ver_badge_html('🔒', 'danger', 'Hay riesgo alto/crítico sin derivar') : ''),
        ] : null,
        ver_tab_visible($pdo, 'historial') ? [
            'tab' => 'historial',
            'label' => 'Historial',
            'icon' => 'bi-clock-history',
        ] : null,
    ])),
];

$activeLabel = 'Resumen ejecutivo';
foreach ($navGroups as $group) {
    foreach ($group['items'] as $item) {
        if (($item['tab'] ?? '') === $tab) {
            $activeLabel = (string)$item['label'];
            break 2;
        }
    }
}
?>

<div class="exp-mobile-nav">
    <label for="expMobileTabSelect"><i class="bi bi-list"></i> Navegación del expediente</label>
    <select id="expMobileTabSelect" onchange="if(this.value){window.location.href=this.value;}">
        <?php foreach ($navGroups as $group): ?>
            <?php if (empty($group['items'])) continue; ?>
            <optgroup label="<?= e((string)$group['title']) ?>">
                <?php foreach ($group['items'] as $item): ?>
                    <option value="<?= e(ver_tab_url($casoId, (string)$item['tab'])) ?>" <?= $tab === $item['tab'] ? 'selected' : '' ?>>
                        <?= e((string)$item['label']) ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>
</div>

<div class="exp-workspace">
    <aside class="exp-case-nav" aria-label="Navegación contextual del expediente">
        <div class="exp-case-nav-current">
            <span>Vista actual</span>
            <strong><?= e($activeLabel) ?></strong>
        </div>

        <?php foreach ($navGroups as $group): ?>
            <?php if (empty($group['items'])) continue; ?>
            <div class="exp-case-nav-group">
                <div class="exp-case-nav-title"><?= e((string)$group['title']) ?></div>
                <?php foreach ($group['items'] as $item): ?>
                    <?php ver_render_nav_item($item, $casoId, $tab); ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </aside>

    <main class="exp-case-content" id="expCaseContent">
