<?php
declare(strict_types=1);

/**
 * Metis · AJAX: Aprobar medida IA
 *
 * Guarda una medida propuesta por la IA directamente en caso_plan_intervencion.
 * Recibe: POST { caso_id, tipo, descripcion, responsable, plazo_dias, analisis_id }
 * Devuelve: JSON { ok: true, medida_id }
 */

require_once dirname(__DIR__, 3) . '/config/app.php';
require_once dirname(__DIR__, 3) . '/core/DB.php';
require_once dirname(__DIR__, 3) . '/core/Auth.php';
require_once dirname(__DIR__, 3) . '/core/CSRF.php';
require_once dirname(__DIR__, 3) . '/core/helpers.php';

header('Content-Type: application/json; charset=utf-8');

function apmed_ok(array $p): never
{
    echo json_encode(['ok' => true] + $p, JSON_UNESCAPED_UNICODE); exit;
}
function apmed_err(string $m, int $s = 400): never
{
    http_response_code($s);
    echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit;
}

if (!Auth::check())                          { apmed_err('Sesión no iniciada.', 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')   { apmed_err('Método no permitido.', 405); }
try { CSRF::requireValid($_POST['_token'] ?? null); } catch (Throwable $e) { apmed_err('Token CSRF inválido.', 403); }

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);
$userId    = (int)($user['id'] ?? 0);

$casoId     = (int)($_POST['caso_id']    ?? 0);
$tipo       = trim((string)($_POST['tipo']        ?? 'preventiva'));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));
$responsable = trim((string)($_POST['responsable'] ?? ''));
$plazoDias  = (int)($_POST['plazo_dias'] ?? 0);
$analisisId = (int)($_POST['analisis_id'] ?? 0);

if ($casoId <= 0)        { apmed_err('ID de caso inválido.'); }
if ($descripcion === '') { apmed_err('La descripción de la medida es obligatoria.'); }

// Verificar que el caso pertenezca al colegio
$stmt = $pdo->prepare('SELECT id FROM casos WHERE id = ? AND colegio_id = ? LIMIT 1');
$stmt->execute([$casoId, $colegioId]);
if (!$stmt->fetchColumn()) { apmed_err('Caso no encontrado o acceso denegado.', 404); }

// Verificar tabla
try {
    $check = $pdo->query("SHOW TABLES LIKE 'caso_plan_intervencion'")->fetchColumn();
    if (!$check) { apmed_err('Tabla caso_plan_intervencion no existe. Ejecuta el SQL de migración 38K2I.', 500); }
} catch (Throwable $e) { apmed_err('Error verificando tabla: ' . $e->getMessage(), 500); }

// Calcular fecha compromiso si hay plazo
$fechaCompromiso = null;
if ($plazoDias > 0) {
    $fechaCompromiso = date('Y-m-d', strtotime("+{$plazoDias} days"));
}

// Tipos válidos
$tiposValidos = ['preventiva','resguardo','apoyo_psicosocial','comunicacion','sancion','derivacion','otra'];
if (!in_array($tipo, $tiposValidos, true)) { $tipo = 'preventiva'; }

// ── Helper: verificar columna ──────────────────────────────
function apmed_col(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $s->execute([$table, $col]);
        return $cache[$key] = (bool)$s->fetchColumn();
    } catch (Throwable $e) { return $cache[$key] = false; }
}

// ── Insertar defensivo: solo columnas que existen ──────────
try {
    $cols   = ['descripcion', 'estado', 'created_at', 'updated_at'];
    $vals   = ['?',           "'pendiente'", 'NOW()',  'NOW()'];
    $params = [$descripcion];

    if (apmed_col($pdo,'caso_plan_intervencion','caso_id'))     { $cols[]='caso_id';       $vals[]='?'; $params[]=$casoId; }
    if (apmed_col($pdo,'caso_plan_intervencion','colegio_id'))  { $cols[]='colegio_id';    $vals[]='?'; $params[]=$colegioId; }
    if (apmed_col($pdo,'caso_plan_intervencion','tipo_medida')) { $cols[]='tipo_medida';   $vals[]='?'; $params[]=$tipo; }
    if (apmed_col($pdo,'caso_plan_intervencion','responsable')) { $cols[]='responsable';   $vals[]='?'; $params[]=$responsable ?: null; }
    if (apmed_col($pdo,'caso_plan_intervencion','fecha_compromiso')) { $cols[]='fecha_compromiso'; $vals[]='?'; $params[]=$fechaCompromiso; }
    if (apmed_col($pdo,'caso_plan_intervencion','observacion_cumplimiento')) {
        $cols[]='observacion_cumplimiento';
        $vals[]='?';
        $params[]=$analisisId > 0 ? 'Medida aprobada desde análisis IA #'.$analisisId : null;
    }
    // titulo (esquema original, campo obligatorio en versiones antiguas)
    if (apmed_col($pdo,'caso_plan_intervencion','titulo')) {
        $cols[]='titulo'; $vals[]='?';
        $params[]=mb_strimwidth($descripcion,0,148,'…','UTF-8');
    }
    // seguimiento_id: ya es NULL permitido tras fix_fk_plan_intervencion.sql
    // No se rellena — las medidas IA son independientes del seguimiento

    $stmt = $pdo->prepare(
        'INSERT INTO caso_plan_intervencion (' . implode(',',$cols) . ') VALUES (' . implode(',',$vals) . ')'
    );
    $stmt->execute($params);
    $medidaId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    apmed_err('Error al guardar la medida: ' . $e->getMessage(), 500);
}

// Registrar en historial
try {
    $pdo->prepare("
        INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)
        VALUES (?, 'medida_aprobada_ia', 'Medida IA aprobada', ?, ?)
    ")->execute([
        $casoId,
        mb_strimwidth("Medida aprobada: {$descripcion}", 0, 200, '…', 'UTF-8'),
        $userId ?: null,
    ]);
} catch (Throwable $e) { /* silencioso */ }

apmed_ok(['medida_id' => $medidaId]);
