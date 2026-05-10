<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

// Solo superadmin
$user = Auth::user() ?? [];
if (($user['rol_codigo'] ?? '') !== 'superadmin' && !Auth::can('admin_sistema')) {
    http_response_code(403);
    exit('Acceso restringido al superadministrador.');
}

$pdo     = DB::conn();
$msgOk   = '';
$msgErr  = '';

// ── Helper: leer config ───────────────────────────────────
function sc_get(PDO $pdo, string $clave, array $default = []): array
{
    try {
        $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = ? LIMIT 1");
        $stmt->execute([$clave]);
        $val = $stmt->fetchColumn();
        return $val ? (json_decode((string)$val, true) ?: $default) : $default;
    } catch (Throwable $e) { return $default; }
}

function sc_set(PDO $pdo, string $clave, array $valor, int $userId): void
{
    $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $pdo->prepare("
        INSERT INTO sistema_config (clave, valor, tipo, actualizado_por, updated_at)
        VALUES (?, ?, 'json', ?, NOW())
        ON DUPLICATE KEY UPDATE valor = VALUES(valor),
            actualizado_por = VALUES(actualizado_por),
            updated_at = NOW()
    ")->execute([$clave, $json, $userId ?: null]);
}

// Definición de todos los tabs disponibles (orden y labels)
$tabsDenuncia = [
    'resumen'             => ['label' => 'Resumen',              'core' => true,  'desc' => 'Vista general del caso. Siempre recomendado.'],
    'seguimiento'         => ['label' => 'Seguimiento / IA',     'core' => true,  'desc' => 'Análisis IA, medidas y seguimiento. Core del sistema.'],
    'plan_accion'         => ['label' => 'Plan de Acción',        'core' => false, 'desc' => 'Plan de acción versionado por participante con trazabilidad completa.'],
    'clasificacion'       => ['label' => 'Clasificación',        'core' => false, 'desc' => 'Registro normativo MINEDUC, indicadores y marcadores Ley 21.809 / REX 782.'],
    'participantes'       => ['label' => 'Participantes',        'core' => false, 'desc' => 'Listado de intervinientes del caso.'],
    // medidas_preventivas forma parte del tab Seguimiento — no tiene tab propio
    'declaraciones'       => ['label' => 'Declaraciones y evidencias', 'core' => false, 'desc' => 'Declaraciones de participantes y evidencias adjuntas (integradas en un solo tab).'],
    // 'evidencias' integrada en tab Declaraciones
    'gestion'             => ['label' => 'Gestión ejecutiva',    'core' => false, 'desc' => 'Acciones y compromisos directivos. En desarrollo.'],
    'aula_segura'         => ['label' => 'Aula Segura',          'core' => false, 'desc' => 'Procedimiento Aula Segura. Se muestra solo si aplica.'],
    'historial'           => ['label' => 'Historial',            'core' => false, 'desc' => 'Log de cambios del caso.'],
    'cierre'              => ['label' => 'Cierre',               'core' => false, 'desc' => 'Registro de cierre formal del caso.'],
];

// ── POST: guardar cambios ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::requireValid($_POST['_token'] ?? null);

    $userId    = (int)($user['id'] ?? 0);
    $configNew = [];

    foreach ($tabsDenuncia as $key => $meta) {
        $configNew[$key] = [
            'visible' => isset($_POST['tab_' . $key]) ? 1 : 0,
            'label'   => $meta['label'],
        ];
        // Tabs core siempre visibles
        if ($meta['core']) {
            $configNew[$key]['visible'] = 1;
        }
    }

    // Guardar módulos sidebar
    $sidebarNew = [
        'seguimiento' => [
            'visible' => isset($_POST['sidebar_seguimiento']) ? 1 : 0,
            'label'   => 'Seguimiento',
        ],
        'alertas' => [
            'visible' => isset($_POST['sidebar_alertas']) ? 1 : 0,
            'label'   => 'Alertas',
        ],
        'evidencias' => [
            'visible' => isset($_POST['sidebar_evidencias']) ? 1 : 0,
            'label'   => 'Evidencias',
        ],
    ];

    try {
        sc_set($pdo, 'tabs_ver_denuncia', $configNew, $userId);
        sc_set($pdo, 'modulos_sidebar', $sidebarNew, $userId);
        $msgOk = 'Configuración guardada. Los cambios aplican de inmediato.';
    } catch (Throwable $e) {
        $msgErr = 'Error al guardar: ' . $e->getMessage();
    }
}

// Leer config actual
$configActual = sc_get($pdo, 'tabs_ver_denuncia', []);
foreach ($tabsDenuncia as $key => $meta) {
    if (!isset($configActual[$key])) {
        $configActual[$key] = ['visible' => $meta['core'] ? 1 : 0, 'label' => $meta['label']];
    }
}

// Leer config sidebar
$sidebarActual = sc_get($pdo, 'modulos_sidebar', []);
if (!isset($sidebarActual['seguimiento'])) {
    $sidebarActual['seguimiento'] = ['visible' => 1, 'label' => 'Seguimiento'];
}
if (!isset($sidebarActual['alertas'])) {
    $sidebarActual['alertas'] = ['visible' => 1, 'label' => 'Alertas'];
}
if (!isset($sidebarActual['evidencias'])) {
    $sidebarActual['evidencias'] = ['visible' => 1, 'label' => 'Evidencias'];
}

$pageTitle = 'Configuración de desarrollo · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.dev-hero   { background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:14px;
              color:#fff;padding:2rem 2.5rem;margin-bottom:1.5rem; }
.dev-card   { background:#fff;border:1px solid #e2e8f0;border-radius:12px;
              padding:1.5rem;margin-bottom:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.06); }
.dev-section-title { font-size:.72rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
                     color:#1e3a8a;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem; }
.dev-tab-list   { display:flex;flex-direction:column;gap:.5rem; }
.dev-tab-row    { display:flex;align-items:center;justify-content:space-between;
                  padding:.75rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;
                  border-radius:9px;gap:1rem; }
.dev-tab-info   { flex:1; }
.dev-tab-label  { font-size:.87rem;font-weight:700;color:#0f172a; }
.dev-tab-desc   { font-size:.74rem;color:#94a3b8;margin-top:.15rem; }
.dev-tab-core   { font-size:.67rem;font-weight:700;color:#059669;background:#d1fae5;
                  border-radius:12px;padding:.1rem .5rem;margin-left:.4rem; }
/* Toggle switch */
.dev-toggle     { position:relative;display:inline-block;width:46px;height:26px;flex-shrink:0; }
.dev-toggle input{ opacity:0;width:0;height:0; }
.dev-slider     { position:absolute;cursor:pointer;inset:0;background:#cdd5e0;
                  border-radius:26px;transition:.25s; }
.dev-slider::before { content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;
                      background:#fff;border-radius:50%;transition:.25s;
                      box-shadow:0 1px 3px rgba(0,0,0,.2); }
.dev-toggle input:checked + .dev-slider { background:#1e3a8a; }
.dev-toggle input:checked + .dev-slider::before { transform:translateX(20px); }
.dev-toggle input:disabled + .dev-slider { background:#6ee7b7;cursor:not-allowed; }
.dev-toggle-wrap { display:flex;align-items:center;gap:.5rem;font-size:.78rem;font-weight:600;
                   color:#64748b; }
.dev-save-btn   { background:#1e3a8a;color:#fff;border:none;border-radius:8px;
                  padding:.65rem 1.5rem;font-size:.87rem;font-weight:700;cursor:pointer; }
.dev-save-btn:hover { background:#1e3358; }
.alert-ok  { background:#d1fae5;color:#065f46;border-radius:8px;padding:.65rem 1rem;
             margin-bottom:1rem;font-size:.85rem; }
.alert-err { background:#fee2e2;color:#991b1b;border-radius:8px;padding:.65rem 1rem;
             margin-bottom:1rem;font-size:.85rem; }
</style>

<div class="dev-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <span style="font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
                         color:#93c5fd;display:block;margin-bottom:.35rem;">
                <i class="bi bi-gear-fill"></i> Superadmin · Entorno de desarrollo
            </span>
            <h1 style="font-size:1.7rem;font-weight:800;color:#fff;margin-bottom:.3rem;">
                Configuración de desarrollo
            </h1>
            <p style="font-size:.87rem;color:#93c5fd;margin:0;">
                Controla la visibilidad de pestañas y módulos. Los cambios aplican de inmediato para todos los usuarios.
            </p>
        </div>
        <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);
                    border-radius:10px;padding:.75rem 1.1rem;font-size:.78rem;color:#93c5fd;
                    align-self:center;">
            <i class="bi bi-shield-lock-fill"></i> Solo visible para superadmin
        </div>
    </div>
</div>

<?php if ($msgOk !== ''): ?>
    <div class="alert-ok"><i class="bi bi-check-circle-fill"></i> <?= e($msgOk) ?></div>
<?php endif; ?>
<?php if ($msgErr !== ''): ?>
    <div class="alert-err"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($msgErr) ?></div>
<?php endif; ?>

<form method="post">
    <?= CSRF::field() ?>

    <div class="dev-card">
        <div class="dev-section-title">
            <i class="bi bi-folder2-open"></i>
            Pestañas — Ver denuncia
            <span style="font-weight:400;color:#94a3b8;font-size:.7rem;margin-left:.25rem;">
                modules/denuncias/ver.php
            </span>
        </div>

        <div class="dev-tab-list">
            <?php foreach ($tabsDenuncia as $key => $meta):
                $isCore    = $meta['core'];
                $isVisible = (int)($configActual[$key]['visible'] ?? ($isCore ? 1 : 0));
            ?>
            <div class="dev-tab-row">
                <div class="dev-tab-info">
                    <div class="dev-tab-label">
                        <?= e($meta['label']) ?>
                        <?php if ($isCore): ?>
                            <span class="dev-tab-core">CORE — siempre visible</span>
                        <?php endif; ?>
                    </div>
                    <div class="dev-tab-desc"><?= e($meta['desc']) ?></div>
                </div>

                <div class="dev-toggle-wrap">
                    <span style="color:<?= $isVisible ? '#059669' : '#94a3b8' ?>;">
                        <?= $isVisible ? 'Visible' : 'Oculta' ?>
                    </span>
                    <label class="dev-toggle">
                        <input type="checkbox"
                               name="tab_<?= e($key) ?>"
                               value="1"
                               <?= $isVisible ? 'checked' : '' ?>
                               <?= $isCore ? 'disabled checked' : '' ?>>
                        <span class="dev-slider"></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:1.25rem;gap:.75rem;align-items:center;">
            <span style="font-size:.78rem;color:#94a3b8;">
                <i class="bi bi-info-circle"></i>
                Las pestañas CORE no se pueden ocultar.
            </span>
        </div>
    </div>

    <!-- MÓDULOS DEL SIDEBAR -->
    <div class="dev-card" style="margin-top:1rem;">
        <div class="dev-section-title">
            <i class="bi bi-layout-sidebar"></i>
            Módulos — Sidebar de navegación
            <span style="font-weight:400;color:#94a3b8;font-size:.7rem;margin-left:.25rem;">
                core/layout_header.php
            </span>
        </div>

        <div class="dev-tab-list">
            <?php
            $sidebarModulos = [
                'seguimiento' => [
                    'label' => 'Seguimiento',
                    'icon'  => 'bi-clipboard2-check',
                    'desc'  => 'Módulo de seguimiento de casos en la barra lateral.',
                ],
                'alertas' => [
                    'label' => 'Alertas',
                    'icon'  => 'bi-bell',
                    'desc'  => 'Módulo de alertas en la barra lateral.',
                ],
                'evidencias' => [
                    'label' => 'Evidencias',
                    'icon'  => 'bi-paperclip',
                    'desc'  => 'Módulo de evidencias en la barra lateral.',
                ],
            ];
            foreach ($sidebarModulos as $key => $meta):
                $isVisible = (int)($sidebarActual[$key]['visible'] ?? 1);
            ?>
            <div class="dev-tab-row">
                <div class="dev-tab-info">
                    <div class="dev-tab-label">
                        <i class="bi <?= e($meta['icon']) ?>" style="color:#2563eb;"></i>
                        <?= e($meta['label']) ?>
                    </div>
                    <div class="dev-tab-desc"><?= e($meta['desc']) ?></div>
                </div>
                <div class="dev-toggle-wrap">
                    <span style="color:<?= $isVisible ? '#059669' : '#94a3b8' ?>;">
                        <?= $isVisible ? 'Visible' : 'Oculto' ?>
                    </span>
                    <label class="dev-toggle">
                        <input type="checkbox"
                               name="sidebar_<?= e($key) ?>"
                               value="1"
                               <?= $isVisible ? 'checked' : '' ?>>
                        <span class="dev-slider"></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:1.25rem;">
            <button type="submit" class="dev-save-btn">
                <i class="bi bi-check-circle-fill"></i> Guardar configuración
            </button>
        </div>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
