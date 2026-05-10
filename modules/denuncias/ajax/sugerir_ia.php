<?php
declare(strict_types=1);

/**
 * Metis · AJAX: Sugerencias IA por sección
 *
 * POST { caso_id, tipo: 'clasificacion'|'plan_accion'|'medidas' }
 * Devuelve JSON estructurado con propuestas para cada pestaña.
 *
 * Diferencia con analizar_ia.php:
 *   - Prompts más cortos y focalizados (menor costo)
 *   - Respuesta estructurada para rellenar formularios
 *   - No guarda en caso_analisis_ia (son sugerencias, no análisis oficial)
 */

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';
require_once dirname(__DIR__, 3) . '/core/CSRF.php';
require_once dirname(__DIR__, 3) . '/core/helpers.php';

header('Content-Type: application/json; charset=utf-8');

function sug_ok(array $data): never {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function sug_error(string $msg, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Validaciones ─────────────────────────────────────────────────────────────
if (!Auth::check())                         sug_error('Sesión no iniciada.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  sug_error('Método no permitido.', 405);

try {
    CSRF::requireValid($_POST['_token'] ?? null);
} catch (Throwable $e) {
    sug_error('Token inválido. Recarga la página.', 403);
}

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);
$casoId    = (int)($_POST['caso_id'] ?? 0);
$tipo      = trim((string)($_POST['tipo'] ?? ''));

if ($casoId <= 0)         sug_error('ID de caso inválido.');
if (!in_array($tipo, ['clasificacion', 'plan_accion', 'medidas'], true))
    sug_error('Tipo de sugerencia inválido.');

// ── Verificar módulo IA ───────────────────────────────────────────────────────
$tieneIA = false;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM colegio_modulos
        WHERE colegio_id = ? AND modulo_codigo = 'ia' AND activo = 1
          AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())");
    $s->execute([$colegioId]);
    $tieneIA = (bool)$s->fetchColumn();
} catch (Throwable $e) {}

$esSuperAdmin = ($user['rol_codigo'] ?? '') === 'superadmin';
if (!$tieneIA && !$esSuperAdmin)
    sug_error('El establecimiento no tiene el módulo IA contratado.', 403);

// ── Config IA ─────────────────────────────────────────────────────────────────
$iaCfg  = require dirname(__DIR__, 3) . '/config/ia.php';
$apiKey = trim((string)($iaCfg['anthropic_key'] ?? ''));
if ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI')
    sug_error('API key de Anthropic no configurada.', 500);

// ── Cargar datos del caso ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT c.*, ec.nombre AS estado_nombre
    FROM casos c LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    WHERE c.id = ? AND c.colegio_id = ? LIMIT 1");
$stmt->execute([$casoId, $colegioId]);
$caso = $stmt->fetch();
if (!$caso) sug_error('Caso no encontrado.', 404);

// Participantes
$stmt = $pdo->prepare("SELECT * FROM caso_participantes WHERE caso_id = ? ORDER BY id ASC");
$stmt->execute([$casoId]);
$participantes = $stmt->fetchAll();

// Declaraciones (resumen breve)
$stmt = $pdo->prepare("SELECT p.nombre_referencial, p.rol_en_caso, d.texto_declaracion
    FROM caso_declaraciones d
    LEFT JOIN caso_participantes p ON p.id = d.participante_id
    WHERE d.caso_id = ? ORDER BY d.fecha_declaracion ASC LIMIT 4");
$stmt->execute([$casoId]);
$declaraciones = $stmt->fetchAll();

// Clasificación existente (para contexto)
$clasificacion = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM caso_clasificacion_normativa WHERE caso_id = ? LIMIT 1");
    $stmt->execute([$casoId]);
    $clasificacion = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

// Reglamento del colegio
$reglamentoTexto = '';
try {
    $stmt = $pdo->prepare("SELECT texto_contenido FROM colegio_reglamentos
        WHERE colegio_id = ? AND activo = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$colegioId]);
    $reg = $stmt->fetch();
    if ($reg) $reglamentoTexto = trim((string)($reg['texto_contenido'] ?? ''));
} catch (Throwable $e) {}

// ── Construir resumen del caso ────────────────────────────────────────────────
$partTexto = '';
foreach ($participantes as $p) {
    $partTexto .= '- ' . trim((string)($p['nombre_referencial'] ?? 'N/N'))
                . ' (' . ($p['tipo_persona'] ?? '') . '): ' . ($p['rol_en_caso'] ?? '') . "\n";
}

$declTexto = '';
foreach ($declaraciones as $d) {
    $txt = mb_strimwidth(trim((string)($d['texto_declaracion'] ?? '')), 0, 300, '…', 'UTF-8');
    if ($txt) $declTexto .= '- ' . ($d['nombre_referencial'] ?? 'N/N') . ' [' . ($d['rol_en_caso'] ?? '') . "]: \"$txt\"\n";
}

$descrip  = mb_strimwidth(trim((string)($caso['descripcion'] ?? '')), 0, 600, '…', 'UTF-8');
$gravedad = (string)($clasificacion['gravedad'] ?? $caso['gravedad'] ?? 'no especificada');

$contextoCaso = "CASO N° {$caso['numero_caso']}\n"
    . "Descripción: $descrip\n"
    . "Gravedad actual: $gravedad\n\n"
    . "PARTICIPANTES:\n$partTexto\n"
    . "DECLARACIONES:\n" . ($declTexto ?: "(Sin declaraciones registradas)\n");

$bloqueReglamento = $reglamentoTexto !== ''
    ? "REGLAMENTO INTERNO (extracto):\n" . mb_strimwidth($reglamentoTexto, 0, 4000, '…', 'UTF-8')
    : "REGLAMENTO INTERNO: No cargado. Aplica solo marco legal nacional.";

// ── Prompts por tipo ──────────────────────────────────────────────────────────

if ($tipo === 'clasificacion') {

    $systemPrompt = "Eres un asesor de convivencia escolar chileno experto en Ley 21.809 y REX 782.\n"
        . "Analiza el caso y devuelve ÚNICAMENTE un objeto JSON con la clasificación normativa sugerida.\n"
        . "Estructura exacta (sin texto adicional, sin bloques de código):\n"
        . "{\n"
        . "  \"area_mineduc\": \"convivencia_escolar|maltrato_violencia_acoso|discriminacion_inclusion|vulneracion_derechos|seguridad_integridad|aula_segura|otro\",\n"
        . "  \"ambito_mineduc\": \"entre_estudiantes|adulto_estudiante|estudiante_adulto|funcionarios|familia_escuela|redes_sociales|otro\",\n"
        . "  \"tipo_conducta\": \"conflicto_convivencia|maltrato_escolar|acoso_escolar|ciberacoso|violencia_fisica|violencia_psicologica|violencia_sexual|discriminacion|amenaza|agresion_grave|vulneracion_derechos|otro\",\n"
        . "  \"gravedad\": \"baja|media|alta|critica\",\n"
        . "  \"categoria_convivencia\": \"preventivo|disciplinario|proteccion_resguardo|derivacion|aula_segura|otro\",\n"
        . "  \"flags_21809\": [\"codigo_flag1\", \"codigo_flag2\"],\n"
        . "  \"flags_rex782\": [\"codigo_flag1\"],\n"
        . "  \"justificacion\": \"Explicación breve de por qué esta clasificación (máx 2 oraciones).\"\n"
        . "}";

    $userMsg = $bloqueReglamento . "\n\n" . $contextoCaso;

} elseif ($tipo === 'plan_accion') {

    $rolesPresentes = array_unique(array_map(fn($p) => (string)($p['rol_en_caso'] ?? ''), $participantes));
    $rolesStr = implode(', ', $rolesPresentes);

    $systemPrompt = "Eres un especialista en convivencia escolar chilena (Ley 21.809, REX 782).\n"
        . "Genera un plan de acción para cada participante del caso.\n"
        . "Devuelve ÚNICAMENTE un objeto JSON (sin texto adicional, sin bloques de código):\n"
        . "{\n"
        . "  \"planes\": [\n"
        . "    {\n"
        . "      \"nombre\": \"Nombre del participante\",\n"
        . "      \"rol\": \"victima|denunciante|denunciado|testigo|involucrado\",\n"
        . "      \"texto_plan\": \"Plan de acción concreto para este participante (3-5 acciones).\",\n"
        . "      \"responsable\": \"Rol institucional responsable.\",\n"
        . "      \"plazo_dias\": 5\n"
        . "    }\n"
        . "  ]\n"
        . "}\n"
        . "Genera un plan por cada participante. Roles presentes: $rolesStr.";

    $userMsg = $bloqueReglamento . "\n\n" . $contextoCaso;

} else { // medidas

    $systemPrompt = "Eres un especialista en convivencia escolar chilena (Ley 21.809, REX 782).\n"
        . "Propón medidas preventivas y de resguardo para este caso.\n"
        . "Devuelve ÚNICAMENTE un objeto JSON (sin texto adicional, sin bloques de código):\n"
        . "{\n"
        . "  \"medidas\": [\n"
        . "    {\n"
        . "      \"tipo\": \"preventiva|resguardo|apoyo_psicosocial|comunicacion|sancion|derivacion\",\n"
        . "      \"descripcion\": \"Descripción concreta y accionable.\",\n"
        . "      \"responsable\": \"Rol que ejecuta (ej: Encargado de Convivencia).\",\n"
        . "      \"plazo_dias\": 5,\n"
        . "      \"prioridad\": \"alta|media|baja\",\n"
        . "      \"para_quien\": \"victima|denunciado|testigo|apoderado|general\"\n"
        . "    }\n"
        . "  ]\n"
        . "}\n"
        . "Propón entre 3 y 6 medidas proporcionales a la gravedad del caso.";

    $userMsg = $bloqueReglamento . "\n\n" . $contextoCaso;
}

// ── Llamada a la API ──────────────────────────────────────────────────────────
$requestBody = json_encode([
    'model'      => $iaCfg['model'] ?? 'claude-sonnet-4-6',
    'max_tokens' => 1200,
    'system'     => [[
        'type'          => 'text',
        'text'          => $systemPrompt,
        'cache_control' => ['type' => 'ephemeral'],
    ]],
    'messages'   => [[
        'role'    => 'user',
        'content' => [[
            'type'          => 'text',
            'text'          => $userMsg,
            'cache_control' => ['type' => 'ephemeral'],
        ]],
    ]],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $requestBody,
    CURLOPT_TIMEOUT        => (int)($iaCfg['timeout'] ?? 45),
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

if ($curlError !== '')    sug_error('Error de conexión con Anthropic: ' . $curlError, 502);
if ($httpCode !== 200) {
    $decoded = json_decode((string)$responseRaw, true);
    sug_error('API respondió ' . $httpCode . ': ' . mb_strimwidth((string)($decoded['error']['message'] ?? ''), 0, 200, '…'), 502);
}

$respuesta   = json_decode((string)$responseRaw, true);
$textoIA     = trim((string)($respuesta['content'][0]['text'] ?? ''));

// Limpiar posibles bloques markdown
$textoLimpio = preg_replace('/^```(?:json)?\s*/i', '', $textoIA);
$textoLimpio = preg_replace('/\s*```$/',            '', trim($textoLimpio));

$datos = json_decode($textoLimpio, true);
if (!is_array($datos)) sug_error('La IA devolvió una respuesta inesperada. Intenta nuevamente.');

// Registrar en historial (no bloquear si falla)
try {
    $pdo->prepare("INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)
        VALUES (?, 'analisis_ia', 'Sugerencia IA generada', ?, ?)")
        ->execute([$casoId, "Sugerencia IA para pestaña: $tipo.", $userId]);
} catch (Throwable $e) {}

sug_ok([
    'tipo'            => $tipo,
    'datos'           => $datos,
    'con_reglamento'  => $reglamentoTexto !== '',
    'tokens_usados'   => (int)($respuesta['usage']['input_tokens']  ?? 0)
                       + (int)($respuesta['usage']['output_tokens'] ?? 0),
]);
