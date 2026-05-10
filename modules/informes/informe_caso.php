<?php
require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$casoId = (int)($_GET['id'] ?? 0);
$modo = in_array(($_GET['modo'] ?? 'interno'), ['interno', 'autoridad', 'externo'], true) ? $_GET['modo'] : 'interno';
$colegioId = (int)($user['colegio_id'] ?? 0);

if ($casoId <= 0) {
    http_response_code(400);
    exit('Caso no válido.');
}

if ($colegioId === 0) {
    $s = $pdo->prepare('SELECT colegio_id FROM casos WHERE id = ? LIMIT 1');
    $s->execute([$casoId]);
    $colegioId = (int)($s->fetchColumn() ?: 0);
}

$stmt = $pdo->prepare("
    SELECT c.*, ec.nombre AS estado_formal, ec.codigo AS estado_codigo
    FROM casos c
    LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
    WHERE c.id = ? AND c.colegio_id = ?
    LIMIT 1
");
$stmt->execute([$casoId, $colegioId]);
$caso = $stmt->fetch();

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado.');
}

$stmt = $pdo->prepare("
    SELECT p.*
    FROM caso_participantes p
    INNER JOIN casos c ON c.id = p.caso_id
    WHERE p.caso_id = ? AND c.colegio_id = ?
    ORDER BY FIELD(p.rol_en_caso, 'victima', 'denunciante', 'denunciado', 'testigo', 'involucrado'), p.id ASC
");
$stmt->execute([$casoId, $colegioId]);
$participantes = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT d.*, p.nombre_referencial AS participante_nombre, p.rol_en_caso AS participante_rol
    FROM caso_declaraciones d
    LEFT JOIN caso_participantes p ON p.id = d.participante_id
    INNER JOIN casos c ON c.id = d.caso_id
    WHERE d.caso_id = ? AND c.colegio_id = ?
    ORDER BY d.fecha_declaracion ASC, d.id ASC
");
$stmt->execute([$casoId, $colegioId]);
$declaraciones = $stmt->fetchAll();

function informe_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function informe_fecha($value, bool $hora = false): string
{
    if (!$value) return '-';
    $ts = strtotime((string)$value);
    return $ts ? date($hora ? 'd-m-Y H:i' : 'd-m-Y', $ts) : (string)$value;
}

function informe_rol($rol): string
{
    return match ((string)$rol) {
        'victima' => 'Víctima',
        'denunciante' => 'Denunciante',
        'denunciado' => 'Denunciado/a',
        'testigo' => 'Testigo',
        'involucrado' => 'Otro interviniente',
        default => 'Otro interviniente',
    };
}

function informe_label($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    return mb_convert_case(str_replace(['_', '-'], ' ', $value), MB_CASE_TITLE, 'UTF-8');
}

function nombreSeguro(array $p, string $modo): string
{
    if (($p['rol_en_caso'] ?? '') === 'denunciante' && !empty($p['solicita_reserva_identidad']) && $modo !== 'autoridad') {
        return 'Denunciante con identidad reservada';
    }
    return (string)($p['nombre_referencial'] ?? 'NN');
}

function runSeguro(array $p, string $modo): string
{
    if (($p['rol_en_caso'] ?? '') === 'denunciante' && !empty($p['solicita_reserva_identidad']) && $modo !== 'autoridad') {
        return 'Reservado';
    }
    return (string)($p['run'] ?? '0-0');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe Caso <?= informe_e($caso['numero_caso'] ?? '') ?></title>

<style>
body{font-family:Arial,sans-serif;color:#0f172a;margin:40px}
h1{font-size:22px;margin-bottom:10px}
h2{margin-top:30px;border-bottom:1px solid #ccc;padding-bottom:6px}
p{line-height:1.5}.box{margin-bottom:20px}.table{width:100%;border-collapse:collapse}.table td,.table th{border:1px solid #ddd;padding:8px}.badge{padding:4px 8px;border-radius:6px;background:#e2e8f0}.footer{margin-top:40px;font-size:12px;color:#64748b}
</style>
</head>

<body>

<h1>INFORME DE CONVIVENCIA ESCOLAR</h1>

<div class="box">
<strong>N° Caso:</strong> <?= informe_e($caso['numero_caso'] ?? '') ?><br>
<strong>Fecha:</strong> <?= informe_e(informe_fecha($caso['fecha_ingreso'] ?? '', true)) ?><br>
<strong>Estado:</strong> <?= informe_e($caso['estado_formal'] ?? $caso['estado_codigo'] ?? $caso['estado'] ?? '-') ?><br>
<strong>Prioridad:</strong> <?= informe_e(informe_label($caso['prioridad'] ?? '')) ?><br>
</div>

<h2>1. Relato de la denuncia</h2>
<p><?= nl2br(informe_e($caso['relato'] ?? '')) ?></p>

<h2>2. Intervinientes</h2>
<table class="table">
<tr><th>Rol</th><th>Nombre</th><th>RUN</th></tr>
<?php foreach ($participantes as $p): ?>
<tr>
<td><?= informe_e(informe_rol($p['rol_en_caso'] ?? '')) ?></td>
<td><?= informe_e(nombreSeguro($p, $modo)) ?></td>
<td><?= informe_e(runSeguro($p, $modo)) ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>3. Declaraciones</h2>
<?php if (!$declaraciones): ?><p>No se registran declaraciones.</p><?php endif; ?>
<?php foreach ($declaraciones as $d): ?>
<div class="box">
<strong><?= informe_e($d['nombre_declarante'] ?? '') ?></strong>
<span class="badge"><?= informe_e(informe_rol($d['calidad_procesal'] ?? $d['participante_rol'] ?? '')) ?></span><br>
<small><?= informe_e(informe_fecha($d['fecha_declaracion'] ?? '', true)) ?></small>
<p><?= nl2br(informe_e($d['texto_declaracion'] ?? '')) ?></p>
</div>
<?php endforeach; ?>

<?php if ($modo === 'autoridad'): ?>
<div class="box"><strong>Observación:</strong><br>Este documento puede contener antecedentes reservados para revisión de autoridad competente.</div>
<?php endif; ?>

<div class="footer">Documento generado por sistema Metis · Gestión de Convivencia Escolar</div>
<script>window.print();</script>
</body>
</html>
