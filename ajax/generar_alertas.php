<?php
declare(strict_types=1);
/**
 * Motor de alertas automáticas — Metis
 * Detecta condiciones de riesgo y genera alertas en caso_alertas
 */
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/DB.php';
require_once dirname(__DIR__) . '/core/Auth.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$cid       = (int)($user['colegio_id'] ?? 0);
$generadas = 0;
$errores   = [];

if ($cid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Sin colegio']); exit; }

function alerta_unica(PDO $pdo, int $cid, int $casoId, string $tipo,
    string $titulo, string $desc, string $prio = 'media'): bool {
    try {
        $s = $pdo->prepare("SELECT id FROM caso_alertas
            WHERE colegio_id=? AND caso_id=? AND tipo=? AND estado='pendiente' LIMIT 1");
        $s->execute([$cid, $casoId, $tipo]);
        if ($s->fetchColumn()) return false;
        $pdo->prepare("INSERT INTO caso_alertas
            (caso_id,colegio_id,tipo,mensaje,prioridad,estado,fecha_alerta,created_at)
            VALUES (?,?,?,?,?,'pendiente',NOW(),NOW())")
            ->execute([$casoId,$cid,$tipo,"$titulo — $desc",$prio]);
        return true;
    } catch (Throwable $e) { return false; }
}

try {
    // 1. Casos sin movimiento 7+ días
    $s = $pdo->prepare("SELECT id,numero_caso,semaforo,
        DATEDIFF(NOW(),updated_at) AS dias FROM casos
        WHERE colegio_id=? AND estado NOT IN('cerrado','archivado','borrador')
        AND DATEDIFF(NOW(),updated_at)>=7 LIMIT 50");
    $s->execute([$cid]);
    foreach ($s->fetchAll() as $c) {
        $dias = (int)$c['dias'];
        if (alerta_unica($pdo,$cid,(int)$c['id'],
            'sin_movimiento_'.($dias>=15?'15':'7'),
            "Caso sin movimiento: {$c['numero_caso']}",
            "El expediente lleva {$dias} días sin actualizaciones.",
            $dias>=15?'alta':'media')) $generadas++;
    }

    // 2. Próxima revisión vencida
    try {
        $s = $pdo->prepare("SELECT DISTINCT c.id,c.numero_caso,
            MIN(css.proxima_revision) AS proxima
            FROM casos c INNER JOIN caso_seguimiento_sesion css ON css.caso_id=c.id
            WHERE c.colegio_id=? AND c.estado NOT IN('cerrado','archivado','borrador')
            AND css.proxima_revision<CURDATE() AND css.proxima_revision IS NOT NULL
            GROUP BY c.id,c.numero_caso LIMIT 30");
        $s->execute([$cid]);
        foreach ($s->fetchAll() as $c) {
            if (alerta_unica($pdo,$cid,(int)$c['id'],'revision_vencida',
                "Revisión vencida: {$c['numero_caso']}",
                "Fecha límite: ".date('d-m-Y',strtotime($c['proxima'])),'alta')) $generadas++;
        }
    } catch (Throwable $e) { $errores[]='revisiones: '.$e->getMessage(); }

    // 3. Semáforo rojo sin plan de acción
    try {
        $s = $pdo->prepare("SELECT c.id,c.numero_caso FROM casos c
            LEFT JOIN caso_plan_accion pa ON pa.caso_id=c.id AND pa.vigente=1
            WHERE c.colegio_id=? AND c.semaforo IN('rojo','negro')
            AND c.estado NOT IN('cerrado','archivado','borrador') AND pa.id IS NULL LIMIT 20");
        $s->execute([$cid]);
        foreach ($s->fetchAll() as $c) {
            if (alerta_unica($pdo,$cid,(int)$c['id'],'rojo_sin_plan',
                "Caso crítico sin plan: {$c['numero_caso']}",
                "Semáforo rojo/negro sin plan de acción definido.",'alta')) $generadas++;
        }
    } catch (Throwable $e) { $errores[]='rojo_sin_plan: '.$e->getMessage(); }

    // 4. TEA sin derivar 3+ días
    try {
        $s = $pdo->prepare("SELECT ace.id,
            CONCAT_WS(' ',a.apellido_paterno,a.nombres) AS nombre,
            DATEDIFF(NOW(),ace.created_at) AS dias
            FROM alumno_condicion_especial ace
            INNER JOIN alumnos a ON a.id=ace.alumno_id
            WHERE ace.colegio_id=? AND ace.tipo_condicion LIKE 'tea%'
            AND ace.derivado_salud=0 AND ace.estado_diagnostico IN('sospecha','en_proceso')
            AND DATEDIFF(NOW(),ace.created_at)>=3 AND ace.activo=1 LIMIT 20");
        $s->execute([$cid]);
        foreach ($s->fetchAll() as $al) {
            $chk = $pdo->prepare("SELECT id FROM caso_alertas
                WHERE colegio_id=? AND tipo='tea_sin_derivar'
                AND estado='pendiente' AND mensaje LIKE ? LIMIT 1");
            $chk->execute([$cid,'%id:'.(int)$al['id'].'%']);
            if ($chk->fetchColumn()) continue;
            $pdo->prepare("INSERT INTO caso_alertas
                (caso_id,colegio_id,tipo,mensaje,prioridad,estado,fecha_alerta,created_at)
                VALUES (0,?,'tea_sin_derivar',?,'alta','pendiente',NOW(),NOW())")
                ->execute([$cid,"TEA sin derivar: {$al['nombre']} (id:{$al['id']}) — {$al['dias']} días. Art.12 Ley 21.545."]);
            $generadas++;
        }
    } catch (Throwable $e) { $errores[]='tea: '.$e->getMessage(); }

    // 5. Pauta de riesgo crítica sin actuación reciente
    try {
        $s = $pdo->prepare("SELECT c.id,c.numero_caso,pr.nivel_riesgo
            FROM caso_pauta_riesgo pr INNER JOIN casos c ON c.id=pr.caso_id
            WHERE pr.colegio_id=? AND pr.vigente=1
            AND pr.nivel_riesgo IN('alto','critico')
            AND c.estado NOT IN('cerrado','archivado')
            AND DATEDIFF(NOW(),pr.created_at)>=1 LIMIT 20");
        $s->execute([$cid]);
        foreach ($s->fetchAll() as $c) {
            if (alerta_unica($pdo,$cid,(int)$c['id'],'pauta_critica',
                "Pauta crítica: {$c['numero_caso']}",
                "Nivel ".strtoupper($c['nivel_riesgo'])." — Intervención inmediata requerida.",'alta')) $generadas++;
        }
    } catch (Throwable $e) { $errores[]='pauta: '.$e->getMessage(); }

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
}

// Contar pendientes para badge sidebar
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM caso_alertas WHERE colegio_id=? AND estado='pendiente'");
    $cnt->execute([$cid]);
    $pendientes = (int)$cnt->fetchColumn();
} catch (Throwable $e) { $pendientes = 0; }

echo json_encode(['ok'=>true,'generadas'=>$generadas,'pendientes'=>$pendientes,
    'errores'=>$errores,'ts'=>date('H:i')]);
