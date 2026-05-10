<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';
require_once 'ia_helpers.php';

Auth::requireLogin();

// --- NUEVA VALIDACIÓN DE PERMISOS ---
if (!tiene_permiso('gestionar_casos')) {
    http_response_code(403);
    exit('No autorizado.');
}
// ------------------------------------

$pdo = DB::conn();
$user = Auth::user();

$casoId = (int)($_GET['id'] ?? 0);

if ($casoId <= 0) {
    die('Caso inválido.');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM casos
    WHERE id = ? AND colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $user['colegio_id']]);
$caso = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caso) {
    die('Caso no encontrado.');
}

if (!moduloActivoColegio((int)$user['colegio_id'], 'IA_ANALISIS_REGLAMENTARIO')) {
    http_response_code(403);
    die('Este establecimiento no tiene activo el módulo premium de análisis reglamentario.');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM caso_declaraciones
    WHERE caso_id = ?
    ORDER BY id ASC
");
$stmt->execute([$casoId]);
$declaraciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$textoBase = trim((string)$caso['relato']);
$agrava = false;
$atenua = false;

foreach ($declaraciones as $d) {
    $txt = mb_strtolower((string)$d['texto_declaracion']);
    if (
        str_contains($txt, 'amenaza') ||
        str_contains($txt, 'golpe') ||
        str_contains($txt, 'difund') ||
        str_contains($txt, 'reiterad') ||
        str_contains($txt, 'acoso')
    ) {
        $agrava = true;
    }

    if (
        str_contains($txt, 'accidental') ||
        str_contains($txt, 'sin intención') ||
        str_contains($txt, 'sin intencion') ||
        str_contains($txt, 'malentendido')
    ) {
        $atenua = true;
    }
}

$clasificacion = 'conflicto';
$gravedad = 'leve';
$medidas = 'Conversación formativa y registro de seguimiento.';

if ((int)$caso['requiere_reanalisis_ia'] === 1 || count($declaraciones) > 0) {
    if ($agrava && !$atenua) {
        $clasificacion = 'infraccion';
        $gravedad = 'grave';
        $medidas = 'Evaluar medidas formativas intensivas, medidas de resguardo y revisión del reglamento aplicable.';
    } elseif ($atenua && !$agrava) {
        $clasificacion = 'conflicto';
        $gravedad = 'leve';
        $medidas = 'Favorecer gestión colaborativa, mediación y seguimiento breve.';
    } else {
        $clasificacion = 'infraccion';
        $gravedad = 'media';
        $medidas = 'Realizar revisión del expediente, contrastar versiones y definir intervención gradual.';
    }
}

$resumen = 'Análisis preliminar IA sobre el caso ' . $caso['numero_caso'] . '. ';
$resumen .= 'Clasificación sugerida: ' . $clasificacion . '. ';
$resumen .= 'Gravedad sugerida: ' . $gravedad . '. ';
$resumen .= 'Se consideran denuncia inicial y declaraciones posteriores.';

if ($pdo->query("SHOW TABLES LIKE 'caso_analisis_ia'")->fetchColumn()) {
    $stmt = $pdo->prepare("
        INSERT INTO caso_analisis_ia (
            caso_id,
            clasificacion,
            gravedad_sugerida,
            medidas_sugeridas,
            resumen,
            created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $casoId,
        $clasificacion,
        $gravedad,
        $medidas,
        $resumen
    ]);
}

$stmt = $pdo->prepare("
    UPDATE casos
    SET requiere_reanalisis_ia = 0
    WHERE id = ?
");
$stmt->execute([$casoId]);

$stmtHist = $pdo->prepare("
    INSERT INTO caso_historial (
        caso_id,
        tipo_evento,
        titulo,
        detalle,
        user_id
    ) VALUES (?, ?, ?, ?, ?)
");
$stmtHist->execute([
    $casoId,
    'ia',
    'Análisis IA ejecutado',
    'Se ejecuta análisis reglamentario premium sobre el caso.',
    $user['id']
]);

registrarConsumoIA((int)$user['colegio_id'], (int)$user['id'], $casoId, 'reanalisis_reglamentario', 0, 0.00);

header('Location: ver.php?id=' . $casoId);
exit;