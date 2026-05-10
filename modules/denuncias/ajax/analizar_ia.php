<?php
declare(strict_types=1);

/**
 * Metis · AJAX: Análisis IA de caso
 *
 * Recibe: POST { caso_id: int }
 * Devuelve: JSON con análisis y medidas propuestas
 *
 * Flujo:
 * 1. Valida sesión, CSRF y parámetros
 * 2. Carga datos del caso (hechos, participantes, clasificación)
 * 3. Carga reglamento interno activo del colegio
 * 4. Construye prompt con contexto legal (Ley 21.809, REX 782) + caso + reglamento
 * 5. Llama a la API de Anthropic
 * 6. Guarda resultado en caso_analisis_ia
 * 7. Devuelve JSON
 */

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';
require_once dirname(__DIR__, 3) . '/core/CSRF.php';
require_once dirname(__DIR__, 3) . '/core/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// ── Helpers de respuesta ────────────────────────────────────
function ia_ok(array $payload): never
{
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ia_error(string $mensaje, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Validaciones ────────────────────────────────────────────
if (!Auth::check()) {
    ia_error('Sesión no iniciada.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ia_error('Método no permitido.', 405);
}

try {
    CSRF::requireValid($_POST['_token'] ?? null);
} catch (Throwable $e) {
    ia_error('Token de seguridad inválido. Recarga la página.', 403);
}

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);
$casoId    = (int)($_POST['caso_id'] ?? 0);

if ($casoId <= 0) {
    ia_error('ID de caso inválido.');
}

// ── Verificar módulo IA contratado ──────────────────────────
try {
    $stmtMod = $pdo->prepare("
        SELECT COUNT(*) FROM colegio_modulos
        WHERE colegio_id = ? AND modulo_codigo = 'ia'
          AND activo = 1
          AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
    ");
    $stmtMod->execute([$colegioId]);
    $tieneIA = (bool)$stmtMod->fetchColumn();
} catch (Throwable $e) {
    $tieneIA = false;
}

// Superadmin siempre puede usar IA
$rolActualIA = (string)($user['rol_codigo'] ?? '');
if ($rolActualIA !== 'superadmin' && !$tieneIA) {
    ia_error('El establecimiento no tiene el módulo de Inteligencia Artificial contratado. Contacta al administrador del sistema para activarlo.', 403);
}

// ── Config IA ───────────────────────────────────────────────
$cfgFile = dirname(__DIR__, 3) . '/config/ia.php';
if (!is_file($cfgFile)) {
    ia_error('Archivo config/ia.php no encontrado. Crea el archivo con tu clave API de Anthropic.', 500);
}
$iaCfg = require $cfgFile;
$apiKey = trim((string)($iaCfg['anthropic_key'] ?? ''));

if ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI') {
    ia_error('La clave API de Anthropic no está configurada. Ve a config/ia.php y agrega tu clave.', 500);
}

// ── Cargar datos del caso ───────────────────────────────────
$stmtCaso = $pdo->prepare("
    SELECT c.*, ec.nombre AS estado_nombre
    FROM casos c
    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    WHERE c.id = ? AND c.colegio_id = ?
    LIMIT 1
");
$stmtCaso->execute([$casoId, $colegioId]);
$caso = $stmtCaso->fetch();

if (!$caso) {
    ia_error('Caso no encontrado o no pertenece al establecimiento.', 404);
}

// Participantes
$stmtPart = $pdo->prepare("SELECT * FROM caso_participantes WHERE caso_id = ? ORDER BY id ASC");
$stmtPart->execute([$casoId]);
$participantes = $stmtPart->fetchAll();

// Declaraciones (resumen)
$stmtDecl = $pdo->prepare("
    SELECT p.nombre_referencial, p.rol_en_caso, d.texto_declaracion
    FROM caso_declaraciones d
    LEFT JOIN caso_participantes p ON p.id = d.participante_id
    WHERE d.caso_id = ?
    ORDER BY d.fecha_declaracion ASC LIMIT 5
");
$stmtDecl->execute([$casoId]);
$declaraciones = $stmtDecl->fetchAll();

// Clasificación normativa
$clasificacion = null;
try {
    $stmtClasif = $pdo->prepare("
        SELECT * FROM caso_clasificacion_normativa
        WHERE caso_id = ? LIMIT 1
    ");
    $stmtClasif->execute([$casoId]);
    $clasificacion = $stmtClasif->fetch() ?: null;
} catch (Throwable $e) { /* tabla opcional */ }

// ── Cargar reglamento interno ───────────────────────────────
$reglamentoTexto = '';
$reglamentoId    = null;
try {
    $stmtReg = $pdo->prepare("
        SELECT id, texto_contenido
        FROM colegio_reglamentos
        WHERE colegio_id = ? AND activo = 1
        ORDER BY id DESC LIMIT 1
    ");
    $stmtReg->execute([$colegioId]);
    $reg = $stmtReg->fetch();
    if ($reg) {
        $reglamentoId    = (int)$reg['id'];
        $reglamentoTexto = trim((string)($reg['texto_contenido'] ?? ''));
    }
} catch (Throwable $e) { /* tabla no existe todavía */ }

// ── Detectar contexto TEA e interés superior ────────────────
$involucraTeaCaso   = 0;
$participantesConTea = [];
$interesSuperior    = 0;
$marcoLegalCaso     = ['Ley 21.809', 'REX 782', 'Ley 20.536', 'Ley 21.430'];

try {
    $stmtTea = $pdo->prepare("
        SELECT
            cp.nombre_referencial AS nombre,
            ace.estado_diagnostico,
            ace.nivel_apoyo,
            ace.derivado_salud,
            ace.destino_derivacion
        FROM caso_participantes cp
        INNER JOIN alumno_condicion_especial ace ON ace.alumno_id = cp.alumno_id
        INNER JOIN catalogo_condicion_especial cc ON cc.id = ace.condicion_id
        WHERE cp.caso_id = ?
          AND cc.codigo = 'TEA'
          AND ace.activo = 1
    ");
    $stmtTea->execute([$casoId]);
    $participantesConTea = $stmtTea->fetchAll();
    $involucraTeaCaso    = count($participantesConTea) > 0 ? 1 : 0;
} catch (Throwable $e) { /* tabla opcional */ }

if ($involucraTeaCaso) {
    $marcoLegalCaso[] = 'Ley 21.545';
}

// Interés superior: cualquier participante menor de 18
foreach ($participantes as $p) {
    if (!empty($p['edad']) && (int)$p['edad'] < 18) {
        $interesSuperior = 1;
        break;
    }
}

// ── Construir contexto del caso ─────────────────────────────
$participantesTexto = '';
foreach ($participantes as $p) {
    $nombre    = trim((string)($p['nombre_referencial'] ?? 'N/N'));
    $tipo      = (string)($p['tipo_persona'] ?? '');
    $condicion = (string)($p['rol_en_caso'] ?? '');
    $edad      = !empty($p['edad']) ? ', ' . $p['edad'] . ' años' : '';
    $participantesTexto .= "- {$nombre} ({$tipo}{$edad}): condición = {$condicion}\n";
}

$declaracionesTexto = '';
foreach ($declaraciones as $d) {
    $nombre = trim((string)($d['nombre_referencial'] ?? 'N/N'));
    $rol    = (string)($d['rol_en_caso'] ?? '');
    $texto  = mb_strimwidth(trim((string)($d['texto_declaracion'] ?? '')), 0, 400, '…', 'UTF-8');
    if ($texto !== '') {
        $declaracionesTexto .= "- {$nombre} ({$rol}): \"{$texto}\"\n";
    }
}

$gravedad = (string)($clasificacion['gravedad'] ?? (string)($caso['gravedad'] ?? 'no especificada'));
$area     = (string)($clasificacion['area_mineduc'] ?? '');
$tipo     = (string)($clasificacion['tipo_conducta'] ?? '');
$descrip  = mb_strimwidth(trim((string)($caso['descripcion'] ?? '')), 0, 800, '…', 'UTF-8');

// ── Construcción del prompt ─────────────────────────────────
// Bloque legal extra según contexto del caso
$marcoBloqueExtra = '';
if ($involucraTeaCaso) {
    $marcoBloqueExtra .= "- Ley 21.545 (2023): Promoción de la inclusion y proteccion de personas con TEA. ";
    $marcoBloqueExtra .= "Art. 12: protocolo de derivacion obligatoria a salud ante sospecha TEA. ";
    $marcoBloqueExtra .= "Art. 18: deber del establecimiento de generar espacios inclusivos y ajustar reglamentos. ";
    $marcoBloqueExtra .= "Art. 19: formacion y acompanamiento de profesionales y asistentes de la educacion. ";
    $marcoBloqueExtra .= "Art. 20: deber de proveer espacios sin violencia y sin discriminacion para NNA con TEA.\n";
}
if ($participantesConTea) {
    $marcoBloqueExtra .= "\nESTUDIANTES CON TEA REGISTRADO EN EL CASO:\n";
    foreach ($participantesConTea as $pt) {
        $marcoBloqueExtra .= "- " . ($pt['nombre'] ?? 'N/N') . ": " . ($pt['estado_diagnostico'] ?? '') .
            ($pt['nivel_apoyo'] ? ", Nivel " . $pt['nivel_apoyo'] : "") .
            ($pt['derivado_salud'] ? ", ya derivado a: " . ($pt['destino_derivacion'] ?? 'salud') : ", SIN derivacion a salud registrada") . "\n";
    }
}
if ($interesSuperior) {
    $marcoBloqueExtra .= "PRINCIPIO DE INTERES SUPERIOR: Aplicado. Todas las medidas deben priorizarlo (Ley 21.430 Art. 9).\n";
}

// Instrucciones específicas TEA
$instruccionesTea = '';
if ($involucraTeaCaso) {
    $instruccionesTea = 'INSTRUCCIONES ESPECIALES LEY 21.545 (TEA):
- Incluye obligatoriamente una medida de tipo "apoyo_psicosocial" orientada al NNA con TEA.
- Si no hay derivacion a salud registrada, incluye una medida "comunicacion" para activar el protocolo de derivacion a salud (Art. 12 Ley 21.545).
- Propone ajustes razonables especificos para el NNA con TEA segun su nivel de apoyo (Art. 18 Ley 21.545).
- Indica en cada medida el articulo de la Ley 21.545 que la fundamenta.
- Considera el principio de neurodiversidad: las medidas no deben estigmatizar al NNA.';
}

// ── Construcción de mensajes con prompt caching ─────────────
//
// Estrategia de cache por capa:
//   Bloque 1 (system) — Marco legal fijo → se cachea entre TODOS los colegios
//   Bloque 2 (user)   — Reglamento del colegio → se cachea por colegio (mismo reglamento_id)
//   Bloque 3 (user)   — Datos del caso → cambia por cada caso, no se cachea
//
// El ahorro real aparece a partir del 2º análisis del mismo colegio (~90% menos tokens de entrada).

$systemPrompt = "Eres un asistente legal-educativo especializado en convivencia escolar chilena.\n"
    . "Analiza casos de convivencia y propone medidas preventivas concretas.\n\n"
    . "MARCO LEGAL APLICABLE (referencia permanente):\n"
    . "- Ley 21.809 (2024): Modifica Ley 20.536 sobre violencia escolar. Obligaciones reforzadas, plazos de actuación, derecho a información de familias, medidas de resguardo ante denuncias graves.\n"
    . "- REX 782 (MINEDUC, 2023): Protocolo ante violencia escolar, agresión sexual, maltrato y discriminación. Roles del encargado de convivencia, director y red de protección.\n"
    . "- Ley 20.536: Marco general de convivencia escolar y responsabilidad del establecimiento.\n"
    . "- Ley 21.430 (2022): Garantías y protección integral de NNA. Art. 9: interés superior. Art. 11: autonomía progresiva. Art. 19: inclusión sin discriminación.\n"
    . "- Ley 21.545 (2023): Inclusión y protección de personas con TEA. Art. 12: derivación obligatoria a salud. Art. 18: espacios inclusivos. Art. 19: formación de profesionales. Art. 20: ambientes sin violencia.\n\n"
    . "FORMATO DE RESPUESTA OBLIGATORIO:\n"
    . "Responde ÚNICAMENTE con un objeto JSON válido, sin texto adicional, sin bloques de código, sin prefijos.\n"
    . "Estructura exacta:\n"
    . "{\n"
    . "  \"resumen\": \"Párrafo de 2-3 oraciones con la evaluación del caso.\",\n"
    . "  \"gravedad_ia\": \"baja|media|alta|critica\",\n"
    . "  \"fundamento_legal\": \"Normas aplicables y por qué.\",\n"
    . "  \"alerta_normativa\": \"Alertas o plazos legales urgentes si los hay, o null.\",\n"
    . "  \"medidas\": [\n"
    . "    {\n"
    . "      \"tipo\": \"preventiva|resguardo|apoyo_psicosocial|comunicacion|sancion|derivacion\",\n"
    . "      \"descripcion\": \"Descripción clara y accionable.\",\n"
    . "      \"responsable\": \"Rol que ejecuta (ej: Encargado de Convivencia).\",\n"
    . "      \"plazo_dias\": 5,\n"
    . "      \"prioridad\": \"alta|media|baja\",\n"
    . "      \"para_condicion\": \"victima|denunciado|testigo|general\"\n"
    . "    }\n"
    . "  ]\n"
    . "}\n"
    . "Propón entre 3 y 7 medidas concretas y proporcionales a la gravedad.";

// Bloque del reglamento (se cachea por colegio, cambia solo cuando sube uno nuevo)
$bloqueReglamento = $reglamentoTexto !== ''
    ? "REGLAMENTO INTERNO DEL ESTABLECIMIENTO (extracto relevante):\n"
      . mb_strimwidth($reglamentoTexto, 0, 6000, '…', 'UTF-8')
    : "REGLAMENTO INTERNO: No cargado. Aplica solo marco legal nacional.";

// Bloque del caso específico (nunca se cachea)
$bloqueCaso = "CASO N° {$caso['numero_caso']} — {$caso['estado_nombre']}\n"
    . "Fecha registro: {$caso['created_at']}\n"
    . "Gravedad: {$gravedad} | Área MINEDUC: {$area} | Tipo conducta: {$tipo}\n\n"
    . "DESCRIPCIÓN:\n{$descrip}\n\n"
    . "PARTICIPANTES:\n{$participantesTexto}\n"
    . "DECLARACIONES RELEVANTES:\n{$declaracionesTexto}\n"
    . $marcoBloqueExtra;

if ($instruccionesTea !== '') {
    $bloqueCaso .= "\n" . $instruccionesTea;
}

$requestBody = json_encode([
    'model'      => $iaCfg['model'] ?? 'claude-sonnet-4-6',
    'max_tokens' => (int)($iaCfg['max_tokens'] ?? 2000),
    'system'     => [
        [
            'type'          => 'text',
            'text'          => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ],
    ],
    'messages' => [
        [
            'role'    => 'user',
            'content' => [
                [
                    'type'          => 'text',
                    'text'          => $bloqueReglamento,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
                [
                    'type' => 'text',
                    'text' => $bloqueCaso,
                ],
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE);

// ── Llamada a la API de Anthropic ────────────────────────────
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_TIMEOUT        => (int)($iaCfg['timeout'] ?? 60),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: prompt-caching-2024-07-31',
    ],
]);

$responseRaw = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    ia_error('Error de conexión con la API de Anthropic: ' . $curlError, 502);
}

if ($httpCode !== 200) {
    $decoded = json_decode((string)$responseRaw, true);
    $msg = (string)($decoded['error']['message'] ?? $responseRaw);
    ia_error("La API respondió con código {$httpCode}: " . mb_strimwidth($msg, 0, 300, '…'), 502);
}

$apiResponse   = json_decode((string)$responseRaw, true);
$textoIA       = trim((string)($apiResponse['content'][0]['text'] ?? ''));
$tokensUsados  = (int)($apiResponse['usage']['output_tokens'] ?? 0)
               + (int)($apiResponse['usage']['input_tokens'] ?? 0);
$tokensCacheHit = (int)($apiResponse['usage']['cache_read_input_tokens'] ?? 0);

if ($textoIA === '') {
    ia_error('La API no devolvió respuesta. Intente nuevamente.', 502);
}

// ── Parsear JSON de la respuesta ────────────────────────────
// Limpiar posibles bloques markdown que el modelo añada
$textoLimpio = preg_replace('/^```(?:json)?\s*/i', '', $textoIA);
$textoLimpio = preg_replace('/\s*```$/', '', trim($textoLimpio));

$analisisData = json_decode($textoLimpio, true);

if (!is_array($analisisData)) {
    // Fallback: entregar como texto si JSON falla
    $analisisData = [
        'resumen'          => $textoIA,
        'gravedad_ia'      => 'media',
        'fundamento_legal' => '',
        'alerta_normativa' => null,
        'medidas'          => [],
    ];
}

$medidas    = is_array($analisisData['medidas'] ?? null) ? $analisisData['medidas'] : [];
$medidasJson = json_encode($medidas, JSON_UNESCAPED_UNICODE);

// ── Guardar análisis en BD ──────────────────────────────────
$analisisId = null;
try {
    $stmtGuardar = $pdo->prepare("
        INSERT INTO caso_analisis_ia
            (caso_id, colegio_id, usuario_id, reglamento_id, modelo_usado, tokens_usados,
             analisis_texto, medidas_json, gravedad_ia, alerta_normativa, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtGuardar->execute([
        $casoId,
        $colegioId,
        $userId,
        $reglamentoId,
        $iaCfg['model'] ?? 'claude-sonnet-4-6',
        $tokensUsados,
        $analisisData['resumen'] ?? $textoIA,
        $medidasJson,
        $analisisData['gravedad_ia'] ?? null,
        $analisisData['alerta_normativa'] ?? null,
    ]);
    $analisisId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    // No bloquear si la tabla aún no existe
}

// Registrar en historial del caso
try {
    $pdo->prepare("
        INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)
        VALUES (?, 'analisis_ia', 'Análisis IA realizado', ?, ?)
    ")->execute([$casoId, 'Se generó análisis IA con ' . count($medidas) . ' medidas propuestas.', $userId]);
} catch (Throwable $e) { /* silencioso */ }

// ── Responder ───────────────────────────────────────────────
ia_ok([
    'analisis_id'      => $analisisId,
    'resumen'          => $analisisData['resumen']          ?? '',
    'gravedad_ia'      => $analisisData['gravedad_ia']      ?? 'media',
    'fundamento_legal' => $analisisData['fundamento_legal'] ?? '',
    'alerta_normativa' => $analisisData['alerta_normativa'] ?? null,
    'medidas'          => $medidas,
    'tokens_usados'     => $tokensUsados,
    'tokens_cache_hit'  => $tokensCacheHit,
    'con_reglamento'    => $reglamentoTexto !== '',
    'involucra_tea'     => $involucraTeaCaso === 1,
    'marco_legal'       => $marcoLegalCaso,
    'interes_superior'  => $interesSuperior === 1,
]);
