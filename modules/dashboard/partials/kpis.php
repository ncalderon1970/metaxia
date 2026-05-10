<?php
declare(strict_types=1);

// Dashboard partial: KPIs con acceso directo.
$u = APP_URL;

if (!function_exists('dash_kpi_card')) {
function dash_kpi_card(string $label, string $valor, string $url, string $extraClass = '', string $valorStyle = ''): void
{
    $cls = trim('dash-kpi dash-kpi-link ' . $extraClass);
    $vs  = $valorStyle ? ' style="' . $valorStyle . '"' : '';

    echo '<div class="' . e($cls) . '" onclick="location.href=\'' . htmlspecialchars($url, ENT_QUOTES) . '\'"'
       . ' role="link" tabindex="0" title="Ir a ' . htmlspecialchars($label, ENT_QUOTES) . '">'
       . '<span>' . e($label) . '</span>'
       . '<strong' . $vs . '>' . e($valor) . '</strong>'
       . '<i class="bi bi-arrow-right-short dash-kpi-arrow"></i>'
       . '</div>' . "\n";
}
}
?>

<section class="dash-kpis">
<?php
dash_kpi_card('Total casos', number_format($totalCasos, 0, ',', '.'), $u . '/modules/denuncias/index.php');
dash_kpi_card('Recepción', number_format($totalRecepcion, 0, ',', '.'), $u . '/modules/denuncias/index.php?estado=recepcion', $totalRecepcion > 0 ? 'dash-kpi-warn' : '');
dash_kpi_card('En investigación', number_format($totalInvestigacion, 0, ',', '.'), $u . '/modules/denuncias/index.php?estado=investigacion');
dash_kpi_card('En seguimiento', number_format($totalSeguimiento, 0, ',', '.'), $u . '/modules/seguimiento/index.php');
dash_kpi_card('Cerrados', number_format($totalCerrados, 0, ',', '.'), $u . '/modules/denuncias/index.php?estado=cerrado');
dash_kpi_card('Prioridad alta', number_format($totalAlta, 0, ',', '.'), $u . '/modules/denuncias/index.php?prioridad=alta', $totalAlta > 0 ? 'dash-kpi-warn' : '');
?>
</section>

<section class="dash-kpis">
<?php
dash_kpi_card('Alertas pendientes', number_format($totalAlertasPendientes, 0, ',', '.'), $u . '/modules/alertas/index.php', $totalAlertasPendientes > 0 ? 'dash-kpi-warn' : '');
dash_kpi_card('Sin plan de acción', number_format($totalSinPlan, 0, ',', '.'), $u . '/modules/seguimiento/index.php', $totalSinPlan > 0 ? 'dash-kpi-warn' : '');
dash_kpi_card('Revisión vencida', number_format($totalSeguimientoVencido, 0, ',', '.'), $u . '/modules/seguimiento/index.php?filtro=vencidos', $totalSeguimientoVencido > 0 ? 'dash-kpi-danger' : '');
dash_kpi_card('Riesgo alto/crítico', number_format($totalRiesgoAltoCritico, 0, ',', '.'), $u . '/modules/seguimiento/index.php?filtro=riesgo', $totalRiesgoAltoCritico > 0 ? 'dash-kpi-danger' : '');
dash_kpi_card('Aula Segura pendiente', number_format($totalAulaSeguraPendiente, 0, ',', '.'), $u . '/modules/alertas/index.php?tipo=aula_segura', $totalAulaSeguraPendiente > 0 ? 'dash-kpi-warn' : '');
dash_kpi_card('Evidencias', number_format($totalEvidencias, 0, ',', '.'), $u . '/modules/evidencias/index.php');
?>
</section>

<section class="dash-kpis">
<?php
$saludColor = $saludOk ? '#047857' : '#b91c1c';
$saludTexto = $saludOk ? 'OK' : 'Revisar';
$csvColor = $totalPendientesImportacion > 0 ? '#92400e' : '#047857';

dash_kpi_card('Declaraciones', number_format($totalDeclaraciones, 0, ',', '.'), $u . '/modules/denuncias/index.php');
dash_kpi_card('Participantes', number_format($totalParticipantes, 0, ',', '.'), $u . '/modules/denuncias/index.php');
dash_kpi_card('Comunidad', number_format($totalComunidad, 0, ',', '.'), $u . '/modules/comunidad/index.php');
dash_kpi_card('Eventos hoy', number_format($totalLogsHoy, 0, ',', '.'), $u . '/modules/admin/diagnostico.php');
dash_kpi_card('Pendientes CSV', number_format($totalPendientesImportacion, 0, ',', '.'), $u . '/modules/importar/pendientes.php', $totalPendientesImportacion > 0 ? 'dash-kpi-warn' : '', "color:{$csvColor};");
dash_kpi_card('Salud sistema', $saludTexto, $u . '/modules/admin/diagnostico.php', '', "font-size:1.3rem;color:{$saludColor};");
?>
</section>

<section class="dash-kpis">
<?php
dash_kpi_card('Alumnos', number_format($totalAlumnos, 0, ',', '.'), $u . '/modules/comunidad/index.php?tipo=alumnos');
dash_kpi_card('Apoderados', number_format($totalApoderados, 0, ',', '.'), $u . '/modules/comunidad/index.php?tipo=apoderados');
dash_kpi_card('Docentes', number_format($totalDocentes, 0, ',', '.'), $u . '/modules/comunidad/index.php?tipo=docentes');
dash_kpi_card('Asistentes', number_format($totalAsistentes, 0, ',', '.'), $u . '/modules/comunidad/index.php?tipo=asistentes');
dash_kpi_card('Usuarios', number_format($totalUsuarios, 0, ',', '.'), $u . '/modules/admin/usuarios_colegio.php');
dash_kpi_card('Resolución', number_format($totalResolucion, 0, ',', '.'), $u . '/modules/denuncias/index.php?estado=resolucion');
?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dash-kpi-link[onclick]').forEach(function (card) {
        card.addEventListener('click', function () {
            var match = card.getAttribute('onclick').match(/location\.href='([^']+)'/);
            if (match && match[1]) {
                window.location.href = match[1];
            }
        });
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                card.click();
            }
        });
    });
});
</script>
