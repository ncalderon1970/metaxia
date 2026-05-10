<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$user = Auth::user() ?? [];
$rolNombre = (string)($user['rol_nombre'] ?? $user['rol_codigo'] ?? 'Usuario');

$pageTitle = 'Manual operativo · Metis';
$pageSubtitle = 'Guía rápida de uso para convivencia escolar, administración y dirección';

$secciones = [
    ['inicio', 'Ingreso y navegación', 'bi-compass', 'Usar el menú lateral para ir a Dashboard, Denuncias, Seguimiento, Comunidad, Importar datos, Reportes y Administración.', ['Ingresar con usuario autorizado.', 'Revisar Dashboard al iniciar la jornada.', 'Usar Diagnóstico después de cada instalación o cambio importante.'], 'No compartir credenciales. Cada acción debe quedar asociada al usuario real.'],
    ['denuncias', 'Denuncias y expediente', 'bi-megaphone', 'Registrar casos y mantener trazabilidad del expediente.', ['Crear la denuncia con relato, contexto, fecha y lugar.', 'Abrir el expediente y revisar resumen, participantes, evidencias, historial, gestión ejecutiva y cierre.', 'No eliminar información relevante; usar estados, observaciones e historial.'], 'Antes de cerrar o reportar, verificar que existan antecedentes suficientes.'],
    ['comunidad', 'Comunidad educativa', 'bi-people', 'Gestionar alumnos, apoderados, docentes y asistentes con datos consistentes.', ['Crear o editar registros desde Comunidad Educativa.', 'El RUN se valida y los datos se guardan en mayúsculas, salvo correo.', 'Usar Activar/Inactivar en vez de borrar registros.'], 'Antes de registrar casos, revisar si el alumno y apoderado ya existen.'],
    ['csv', 'Importación CSV', 'bi-file-earmark-arrow-up', 'Cargar datos masivos con plantillas oficiales.', ['Descargar la plantilla oficial.', 'No modificar encabezados.', 'Subir CSV y revisar cargados/pendientes.', 'Corregir pendientes por RUN inválido, vacío o duplicado.'], 'Los pendientes no contaminan las tablas finales; deben corregirse o descartarse.'],
    ['familia', 'Relación alumno/apoderado', 'bi-person-hearts', 'Mantener contexto familiar operativo.', ['Desde alumnos, presionar Apoderados.', 'Buscar apoderado existente o crear y vincular.', 'Marcar principal, emergencia, retiro autorizado y vive con estudiante.'], 'En casos sensibles, confirmar teléfono y correo antes de contactar.'],
    ['gestion', 'Gestión ejecutiva', 'bi-kanban', 'Controlar acciones, responsables, compromisos y vencimientos.', ['Crear acciones ejecutivas desde el expediente.', 'Asignar responsable, prioridad y fecha compromiso.', 'Actualizar estado: pendiente, en proceso, cumplida o descartada.'], 'Toda acción relevante debe quedar registrada.'],
    ['evidencias', 'Evidencias, declaraciones y alertas', 'bi-paperclip', 'Respaldar el expediente con antecedentes verificables.', ['Subir evidencias pertinentes.', 'Registrar declaraciones y entrevistas.', 'Atender alertas pendientes.', 'Usar historial para revisar movimientos.'], 'No subir archivos innecesarios o no relacionados con el caso.'],
    ['cierre', 'Cierre formal', 'bi-lock', 'Cerrar expedientes con fundamento y trazabilidad.', ['Registrar fecha, tipo y fundamento del cierre.', 'Agregar medidas finales, acuerdos, derivaciones y observaciones.', 'Reabrir solo con motivo fundado.'], 'El cierre debe basarse en antecedentes del expediente.'],
    ['reportes', 'Reportes ejecutivos', 'bi-bar-chart-line', 'Obtener información para dirección y convivencia.', ['Usar Reportes para indicadores generales.', 'Exportar CSV cuando corresponda.', 'Desde expediente, emitir Reporte ejecutivo imprimible.'], 'Antes de reportar, revisar completitud y actualización del expediente.'],
    ['admin', 'Administración y control', 'bi-gear', 'Controlar salud técnica, pruebas, preproducción, respaldos y auditoría.', ['Usar Administración como hub de control.', 'Revisar pruebas integrales y checklist preproducción.', 'Generar respaldo SQL antes de cambios relevantes.', 'Revisar auditoría y roles.'], 'No pasar a producción con diagnóstico observado o sin respaldo inicial.'],
];

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.mu-hero{background:radial-gradient(circle at 90% 16%,rgba(16,185,129,.22),transparent 28%),linear-gradient(135deg,#0f172a 0%,#0f766e 58%,#14b8a6 100%);color:#fff;border-radius:22px;padding:2rem;margin-bottom:1.2rem;box-shadow:0 18px 45px rgba(15,23,42,.18)}
.mu-hero h2{margin:0 0 .45rem;font-size:1.9rem;font-weight:900}.mu-hero p{margin:0;color:#ccfbf1;max-width:960px;line-height:1.55}.mu-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1rem}.mu-btn{display:inline-flex;align-items:center;gap:.42rem;border-radius:999px;padding:.62rem 1rem;font-size:.84rem;font-weight:900;text-decoration:none;border:1px solid rgba(255,255,255,.28);color:#fff;background:rgba(255,255,255,.12);cursor:pointer}.mu-layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:1.2rem;align-items:start}.mu-panel{background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 12px 28px rgba(15,23,42,.06);overflow:hidden;margin-bottom:1.2rem}.mu-panel-head{padding:1rem 1.2rem;border-bottom:1px solid #e2e8f0}.mu-panel-title{margin:0;color:#0f172a;font-size:1rem;font-weight:900}.mu-panel-body{padding:1.2rem}.mu-index{position:sticky;top:92px}.mu-index a{display:flex;align-items:center;gap:.45rem;padding:.62rem .7rem;border-radius:13px;text-decoration:none;color:#334155;font-weight:850;font-size:.84rem;border:1px solid transparent}.mu-index a:hover{background:#f8fafc;border-color:#e2e8f0;color:#0f766e}.mu-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:1rem;margin-bottom:1rem}.mu-card h3{margin:.1rem 0 .45rem;color:#0f172a;font-size:1.05rem;font-weight:900}.mu-text{color:#334155;line-height:1.5;margin-bottom:.75rem}.mu-list{margin:.4rem 0 0;padding-left:1.1rem;color:#334155;line-height:1.55}.mu-alert{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:14px;padding:.8rem .9rem;margin-top:.9rem;line-height:1.45;font-size:.88rem}.mu-badge{display:inline-flex;align-items:center;gap:.3rem;border-radius:999px;padding:.25rem .62rem;font-size:.72rem;font-weight:900;background:#ecfdf5;border:1px solid #bbf7d0;color:#047857}.mu-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem}.mu-mini{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:.9rem}.mu-mini strong{display:block;color:#0f172a;margin-bottom:.25rem}.mu-mini span{color:#64748b;font-size:.8rem;line-height:1.35}@media print{.metis-sidebar,.metis-topbar,.mu-index,.mu-actions{display:none!important}.metis-shell{display:block!important}.metis-content{padding:0!important;max-width:100%!important}.mu-hero{box-shadow:none;color:#000;background:#fff;border:1px solid #ccc}.mu-card,.mu-panel{break-inside:avoid;box-shadow:none}}@media(max-width:1100px){.mu-layout{grid-template-columns:1fr}.mu-index{position:static}.mu-grid{grid-template-columns:1fr}}
</style>
<section class="mu-hero">
    <h2>Manual operativo Metis</h2>
    <p>Guía rápida para operar el sistema. Resume módulos, buenas prácticas y controles mínimos antes de producción. Perfil actual: <?= e($rolNombre) ?>.</p>
    <div class="mu-actions">
        <a class="mu-btn" href="<?= APP_URL ?>/modules/admin/index.php"><i class="bi bi-arrow-left"></i> Administración</a>
        <a class="mu-btn" href="<?= APP_URL ?>/modules/dashboard/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <button class="mu-btn" type="button" onclick="window.print();"><i class="bi bi-printer"></i> Imprimir / guardar PDF</button>
    </div>
</section>
<div class="mu-layout">
    <aside class="mu-panel mu-index">
        <div class="mu-panel-head"><h3 class="mu-panel-title">Índice</h3></div>
        <div class="mu-panel-body">
            <?php foreach ($secciones as $s): ?>
                <a href="#<?= e($s[0]) ?>"><i class="bi <?= e($s[2]) ?>"></i><?= e($s[1]) ?></a>
            <?php endforeach; ?>
        </div>
    </aside>
    <main>
        <section class="mu-panel">
            <div class="mu-panel-head"><h3 class="mu-panel-title">Reglas de operación segura</h3></div>
            <div class="mu-panel-body"><div class="mu-grid">
                <div class="mu-mini"><strong>No borrar trazabilidad</strong><span>Preferir activar/inactivar, cerrar, descartar u observar.</span></div>
                <div class="mu-mini"><strong>Respaldar antes de cambios</strong><span>Generar respaldo SQL antes de instalar fases o modificar datos críticos.</span></div>
                <div class="mu-mini"><strong>Diagnóstico en cero</strong><span>Después de cada instalación, revisar diagnóstico técnico.</span></div>
            </div></div>
        </section>
        <?php foreach ($secciones as $s): ?>
            <article class="mu-card" id="<?= e($s[0]) ?>">
                <span class="mu-badge"><i class="bi <?= e($s[2]) ?>"></i> Módulo operativo</span>
                <h3><?= e($s[1]) ?></h3>
                <div class="mu-text"><strong>Objetivo:</strong> <?= e($s[3]) ?></div>
                <strong>Pasos recomendados:</strong>
                <ol class="mu-list">
                    <?php foreach ($s[4] as $paso): ?><li><?= e($paso) ?></li><?php endforeach; ?>
                </ol>
                <div class="mu-alert"><strong>Precaución:</strong> <?= e($s[5]) ?></div>
            </article>
        <?php endforeach; ?>
    </main>
</div>
<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
