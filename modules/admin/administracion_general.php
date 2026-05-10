<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$user = Auth::user() ?? [];
if (($user['rol_codigo'] ?? '') !== 'superadmin' && !Auth::can('admin_sistema')) {
    http_response_code(403);
    exit('Acceso restringido al superadministrador.');
}

$pdo = DB::conn();

// ── KPIs rápidos ─────────────────────────────────────────
function ag_count(PDO $pdo, string $sql): int {
    try { $s = $pdo->query($sql); return $s ? (int)$s->fetchColumn() : 0; }
    catch (Throwable $e) { return 0; }
}

$stats = [
    'colegios'  => ag_count($pdo, "SELECT COUNT(*) FROM colegios WHERE activo = 1"),
    'usuarios'  => ag_count($pdo, "SELECT COUNT(*) FROM usuarios WHERE activo = 1"),
    'casos'     => ag_count($pdo, "SELECT COUNT(*) FROM casos WHERE estado != 'cerrado'"),
    'alertas'   => ag_count($pdo, "SELECT COUNT(*) FROM caso_alertas WHERE estado = 'pendiente'"),
];

$pageTitle = 'Administración General';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
/* ══ ADMINISTRACIÓN GENERAL ══════════════════════════════ */
.ag-wrap {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1.25rem 2.5rem;
}

/* Hero */
.ag-hero {
    background:
        radial-gradient(ellipse at 80% 0%, rgba(99,102,241,.35), transparent 55%),
        radial-gradient(ellipse at 10% 90%, rgba(16,185,129,.18), transparent 45%),
        linear-gradient(135deg, #020617 0%, #0f172a 60%, #1e1b4b 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem 1.75rem;
    margin-bottom: 1.5rem;
    color: #fff;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.ag-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.02'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
}
.ag-hero-left { flex: 1; min-width: 0; position: relative; }
.ag-hero-eyebrow {
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: rgba(255,255,255,.5);
    margin-bottom: .5rem;
}
.ag-hero-title {
    font-size: 1.75rem;
    font-weight: 700;
    letter-spacing: -.02em;
    margin: 0 0 .4rem;
    line-height: 1.15;
}
.ag-hero-desc {
    font-size: .88rem;
    color: rgba(255,255,255,.6);
    margin: 0;
    line-height: 1.5;
}

/* KPIs del hero */
.ag-hero-kpis {
    display: flex;
    gap: .75rem;
    flex-shrink: 0;
    flex-wrap: wrap;
    align-items: flex-start;
    position: relative;
}
.ag-hero-kpi {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: .85rem 1.1rem;
    min-width: 90px;
    text-align: center;
    backdrop-filter: blur(8px);
}
.ag-hero-kpi-val {
    font-size: 1.65rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: .22rem;
    letter-spacing: -.02em;
}
.ag-hero-kpi-val.ok     { color: #4ade80; }
.ag-hero-kpi-val.warn   { color: #fbbf24; }
.ag-hero-kpi-val.blue   { color: #93c5fd; }
.ag-hero-kpi-val.purple { color: #c4b5fd; }
.ag-hero-kpi-lbl {
    font-size: .68rem;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: rgba(255,255,255,.55);
}

/* Grid de módulos */
.ag-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 1.1rem;
    margin-bottom: 1.5rem;
}
.ag-grid-2 {
    grid-template-columns: repeat(2, minmax(0,1fr));
}

/* Título de grupo */
.ag-group-title {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: #64748b;
    margin: 1.5rem 0 .75rem;
    display: flex;
    align-items: center;
    gap: .45rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid #e2e8f0;
}
.ag-group-title:first-child { margin-top: 0; }

/* Tarjeta de módulo */
.ag-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem 1.35rem;
    text-decoration: none;
    color: inherit;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    box-shadow: 0 1px 3px rgba(15,23,42,.05);
    transition: all .18s ease;
    position: relative;
    overflow: hidden;
}
.ag-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, transparent 70%, rgba(37,99,235,.04));
    opacity: 0;
    transition: opacity .18s;
}
.ag-card:hover {
    border-color: #bfdbfe;
    box-shadow: 0 4px 16px rgba(37,99,235,.1);
    transform: translateY(-2px);
    color: inherit;
}
.ag-card:hover::after { opacity: 1; }

.ag-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
/* Colores de ícono por tipo */
.ag-card-icon.blue   { background: #dbeafe; color: #2563eb; }
.ag-card-icon.green  { background: #d1fae5; color: #059669; }
.ag-card-icon.amber  { background: #fef3c7; color: #d97706; }
.ag-card-icon.red    { background: #fee2e2; color: #dc2626; }
.ag-card-icon.purple { background: #ede9fe; color: #7c3aed; }
.ag-card-icon.slate  { background: #f1f5f9; color: #475569; }
.ag-card-icon.indigo { background: #e0e7ff; color: #4338ca; }
.ag-card-icon.teal   { background: #ccfbf1; color: #0d9488; }
.ag-card-icon.rose   { background: #ffe4e6; color: #e11d48; }
.ag-card-icon.gold   { background: #fef9c3; color: #ca8a04; }

.ag-card-body { flex: 1; min-width: 0; }
.ag-card-title {
    font-size: .9rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 .2rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.ag-card-desc {
    font-size: .8rem;
    color: #64748b;
    line-height: 1.45;
    margin: 0;
}
.ag-card-badge {
    font-size: .65rem;
    font-weight: 700;
    padding: .12rem .5rem;
    border-radius: 20px;
    background: #fef3c7;
    color: #d97706;
    border: 1px solid #fde68a;
}
.ag-card-badge.purple {
    background: #ede9fe;
    color: #7c3aed;
    border-color: #ddd6fe;
}
.ag-card-arrow {
    font-size: .9rem;
    color: #cbd5e1;
    margin-left: auto;
    align-self: center;
    flex-shrink: 0;
    transition: color .15s, transform .15s;
}
.ag-card:hover .ag-card-arrow {
    color: #2563eb;
    transform: translateX(3px);
}

/* Responsive */
@media (max-width: 980px) {
    .ag-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 620px) {
    .ag-hero { flex-direction: column; }
    .ag-hero-kpis { width: 100%; justify-content: space-between; }
    .ag-grid, .ag-grid-2 { grid-template-columns: 1fr; }
    .ag-hero-title { font-size: 1.35rem; }
}
</style>

<div class="ag-wrap">

    <!-- HERO -->
    <div class="ag-hero">
        <div class="ag-hero-left">
            <div class="ag-hero-eyebrow"><i class="bi bi-shield-fill-check"></i> &nbsp;Superadministrador</div>
            <h1 class="ag-hero-title">Administración General</h1>
            <p class="ag-hero-desc">Centro de control del sistema Metis. Gestión de colegios, usuarios, roles, seguridad y configuración global.</p>
        </div>
        <div class="ag-hero-kpis">
            <div class="ag-hero-kpi">
                <div class="ag-hero-kpi-val ok"><?= $stats['colegios'] ?></div>
                <div class="ag-hero-kpi-lbl">Colegios</div>
            </div>
            <div class="ag-hero-kpi">
                <div class="ag-hero-kpi-val blue"><?= $stats['usuarios'] ?></div>
                <div class="ag-hero-kpi-lbl">Usuarios</div>
            </div>
            <div class="ag-hero-kpi">
                <div class="ag-hero-kpi-val purple"><?= $stats['casos'] ?></div>
                <div class="ag-hero-kpi-lbl">Casos activos</div>
            </div>
            <?php if ($stats['alertas'] > 0): ?>
            <div class="ag-hero-kpi">
                <div class="ag-hero-kpi-val warn"><?= $stats['alertas'] ?></div>
                <div class="ag-hero-kpi-lbl">Alertas</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- GRUPO: SISTEMA -->
    <div class="ag-group-title">
        <i class="bi bi-gear-fill"></i> Sistema
    </div>
    <div class="ag-grid">
        <a class="ag-card" href="<?= APP_URL ?>/modules/colegios/index.php">
            <div class="ag-card-icon blue"><i class="bi bi-building"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Colegios</div>
                <p class="ag-card-desc">Gestión de establecimientos, datos comerciales y estado de suscripción.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/admin/usuarios.php">
            <div class="ag-card-icon indigo"><i class="bi bi-person-gear"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Usuarios globales</div>
                <p class="ag-card-desc">Todos los usuarios del sistema. Crear, editar, asignar roles y colegios.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/roles/index.php">
            <div class="ag-card-icon purple"><i class="bi bi-person-badge"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Roles</div>
                <p class="ag-card-desc">Definición de roles del sistema y sus alcances de acceso.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/admin/permisos.php">
            <div class="ag-card-icon slate"><i class="bi bi-sliders"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Permisos</div>
                <p class="ag-card-desc">Asignación de permisos por rol. Control granular de accesos.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/admin/financiero.php">
            <div class="ag-card-icon green"><i class="bi bi-cash-coin"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Financiero</div>
                <p class="ag-card-desc">Gestión de planes, cobros y estado comercial por establecimiento.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/auditoria/index.php">
            <div class="ag-card-icon teal"><i class="bi bi-shield-check"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Auditoría</div>
                <p class="ag-card-desc">Registro de acciones del sistema. Trazabilidad completa por usuario.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>
    </div>

    <!-- GRUPO: CONFIGURACIÓN -->
    <div class="ag-group-title">
        <i class="bi bi-toggles"></i> Configuración
    </div>
    <div class="ag-grid ag-grid-2">
        <a class="ag-card" href="<?= APP_URL ?>/modules/admin/configuracion_desarrollo.php">
            <div class="ag-card-icon amber"><i class="bi bi-gear-wide-connected"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Panel Desarrollo</div>
                <p class="ag-card-desc">Habilitar o deshabilitar pestañas de ver.php y módulos del sidebar. Configuración visual del sistema.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/admin/reglamento.php">
            <div class="ag-card-icon gold"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">
                    Reglamento IA
                    <span class="ag-card-badge purple">IA</span>
                </div>
                <p class="ag-card-desc">Subir y actualizar el Reglamento Interno del establecimiento para el análisis con IA.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>
    </div>

    <!-- GRUPO: OPERACIÓN -->
    <div class="ag-group-title">
        <i class="bi bi-layers"></i> Operación
    </div>
    <div class="ag-grid">
        <a class="ag-card" href="<?= APP_URL ?>/modules/denuncias/index.php">
            <div class="ag-card-icon red"><i class="bi bi-megaphone-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Denuncias</div>
                <p class="ag-card-desc">Listado y gestión de casos de convivencia escolar activos.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/reportes/index.php">
            <div class="ag-card-icon blue"><i class="bi bi-bar-chart-line-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Reportes</div>
                <p class="ag-card-desc">Estadísticas, exportación CSV y generación de informes PDF.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/importar/index.php">
            <div class="ag-card-icon teal"><i class="bi bi-file-earmark-arrow-up-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Importar datos</div>
                <p class="ag-card-desc">Carga masiva de alumnos, apoderados, docentes y vinculaciones desde CSV.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/comunidad/index.php">
            <div class="ag-card-icon green"><i class="bi bi-people-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Comunidad Educativa</div>
                <p class="ag-card-desc">Alumnos, apoderados, docentes y asistentes del establecimiento.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/inclusion/index.php">
            <div class="ag-card-icon rose"><i class="bi bi-heart-pulse-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Inclusión / NEE</div>
                <p class="ag-card-desc">Registro de condiciones especiales, TEA y protocolos Ley 21.545.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/admin/diagnostico.php">
            <div class="ag-card-icon slate"><i class="bi bi-activity"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Diagnóstico del sistema</div>
                <p class="ag-card-desc">Estado de tablas, columnas críticas y salud general del sistema.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/informes/verificar_documento.php">
            <div class="ag-card-icon teal"><i class="bi bi-shield-check"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Verificar documento PDF</div>
                <p class="ag-card-desc">Confirma la autenticidad de documentos PDF generados por Metis mediante folio y código hash.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>

        <a class="ag-card" href="<?= APP_URL ?>/modules/informes/pautas_riesgo.php">
            <div class="ag-card-icon red"><i class="bi bi-clipboard2-pulse-fill"></i></div>
            <div class="ag-card-body">
                <div class="ag-card-title">Panel de pautas de riesgo</div>
                <p class="ag-card-desc">Vista centralizada de todas las evaluaciones de riesgo aplicadas a víctimas y testigos.</p>
            </div>
            <i class="bi bi-chevron-right ag-card-arrow"></i>
        </a>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
