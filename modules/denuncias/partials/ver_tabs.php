<?php
// Leer configuración de tabs desde sistema_config
function ver_tab_visible(PDO $pdo, string $tab): bool
{
    static $config = null;
    if ($config === null) {
        try {
            $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = 'tabs_ver_denuncia' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            $config = $val ? (json_decode((string)$val, true) ?: []) : [];
        } catch (Throwable $e) { $config = []; }
    }
    // Si no hay config para este tab, mostrar por defecto
    if (!isset($config[$tab])) return true;
    return (bool)($config[$tab]['visible'] ?? true);
}

// Tabs core siempre visibles (inmune a config)
$tabsCore = ['resumen', 'seguimiento'];
?>
<nav class="exp-tabs">

    <a class="exp-tab <?= $tab === 'resumen' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=resumen">
        <i class="bi bi-file-text-fill"></i> Resumen
    </a>

    <a class="exp-tab <?= $tab === 'seguimiento' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=seguimiento">
        <i class="bi bi-journal-check"></i> Seguimiento
    </a>

    <?php if (ver_tab_visible($pdo, 'cierre')): ?>
    <?php
    // Contar pautas con riesgo alto sin derivar (bloqueo)
    $_noPautasAlto = 0;
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
    } catch (Throwable $e) {}
    // Contar víctimas/testigos sin pauta
    $_sinPauta = 0;
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
    } catch (Throwable $e) {}
    ?>
    <a class="exp-tab <?= $tab === 'pauta_riesgo' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=pauta_riesgo">
        <i class="bi bi-clipboard2-pulse-fill"></i> Pauta de riesgo
        <?php if ($_noPautasAlto > 0): ?>
            <span class="exp-tab-badge danger">⚑ <?= $_noPautasAlto ?></span>
        <?php elseif ($_sinPauta > 0): ?>
            <span class="exp-tab-badge warn"><?= $_sinPauta ?> pendiente<?= $_sinPauta > 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'plan_accion')): ?>
    <a class="exp-tab <?= $tab === 'plan_accion' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=plan_accion">
        <i class="bi bi-list-check"></i> Plan de Acción
    </a>
    <?php endif; ?>

    <?php
    // Tab Análisis IA — solo si colegio tiene módulo IA contratado
    $tieneModuloIAVer = false;
    try {
        $stmtModIA = $pdo->prepare("SELECT COUNT(*) FROM colegio_modulos WHERE colegio_id=? AND modulo_codigo='ia' AND activo=1 AND (fecha_expiracion IS NULL OR fecha_expiracion>NOW())");
        $stmtModIA->execute([$colegioId]);
        $tieneModuloIAVer = (bool)$stmtModIA->fetchColumn();
    } catch (Throwable $e) {}
    $esSuperAdminVer = ($currentUser['rol_codigo'] ?? '') === 'superadmin';
    if ($tieneModuloIAVer || $esSuperAdminVer):
    ?>
    <a class="exp-tab <?= $tab === 'analisis_ia' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=analisis_ia"
       style="color:#f5c518;font-weight:700;">
        <i class="bi bi-stars"></i> Análisis IA
        <?php if ($esSuperAdminVer && !$tieneModuloIAVer): ?>
            <span style="font-size:.65rem;background:#374151;color:#f5c518;border-radius:8px;padding:.1rem .4rem;">DEMO</span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'participantes')): ?>
    <a class="exp-tab <?= $tab === 'participantes' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=participantes">
        <i class="bi bi-people-fill"></i> Participantes (<?= count($participantes ?? []) ?>)
    </a>
    <?php endif; ?>



    <?php if (ver_tab_visible($pdo, 'declaraciones')): ?>
    <a class="exp-tab <?= $tab === 'declaraciones' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=declaraciones">
        <i class="bi bi-chat-left-text-fill"></i> Declaraciones
        <?php $totalDecEv = count($declaraciones ?? []) + count($evidencias ?? []); ?>
        <?php if ($totalDecEv > 0): ?>
            <span class="exp-tab-badge soft"><?= $totalDecEv ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'gestion')): ?>
    <a class="exp-tab <?= $tab === 'gestion' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=gestion">
        <i class="bi bi-briefcase-fill"></i> Gestión ejecutiva (<?= count($gestionEjecutiva ?? []) ?>)
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'aula_segura')): ?>
    <a class="exp-tab <?= $tab === 'aula_segura' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=aula_segura">
        <i class="bi bi-shield-fill-check"></i> Aula Segura
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'clasificacion')): ?>
    <a class="exp-tab <?= $tab === 'clasificacion' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=clasificacion">
        <i class="bi bi-tag-fill"></i> Clasificación
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'historial')): ?>
    <a class="exp-tab <?= $tab === 'historial' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=historial">
        <i class="bi bi-clock-history"></i> Historial
    </a>
    <?php endif; ?>

    <?php if (ver_tab_visible($pdo, 'cierre')): ?>
    <a class="exp-tab <?= $tab === 'cierre' ? 'active' : '' ?>"
       href="?id=<?= $casoId ?>&tab=cierre">
        <i class="bi bi-check2-circle"></i> Cierre <?= !empty($cierreCaso) ? '(1)' : '' ?>
        <?php if ($_noPautasAlto > 0): ?>
            <span class="exp-tab-badge danger" title="Hay riesgo alto sin derivar">🔒</span>
        <?php endif; ?>
    </a>
    <?php endif; ?>

</nav>
