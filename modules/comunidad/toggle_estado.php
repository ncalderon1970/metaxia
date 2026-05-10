<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/_comunidad_helpers.php';
Auth::requireLogin(); com_require_operate();
$pdo=DB::conn(); $user=Auth::user()??[]; $colegioId=(int)($user['colegio_id']??0); $userId=(int)($user['id']??0);
$tipo=com_safe_tipo((string)($_POST['tipo']??'alumnos')); $id=(int)($_POST['id']??0);
try{
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Método no permitido.');}
 CSRF::requireValid($_POST['_token']??null);
 $row=com_fetch_person($pdo,$tipo,$id,$colegioId); if(!$row) throw new RuntimeException('Registro no encontrado.');
 $nuevo=(int)($row['activo']??1)===1?0:1; $fechaBaja=$nuevo===0?date('Y-m-d H:i:s'):null;
 $sql="UPDATE {$tipo} SET activo=?, fecha_baja=?, motivo_baja=?, updated_at=NOW() WHERE id=? AND colegio_id=? LIMIT 1";
 $pdo->prepare($sql)->execute([$nuevo,$fechaBaja,$nuevo===0?'Desactivado desde comunidad educativa':null,$id,$colegioId]);
 com_register_log($pdo,$colegioId,$userId,$nuevo?'activar':'desactivar',$tipo,$id,($nuevo?'Activación':'Desactivación').' de registro de comunidad educativa.');
 com_redirect(com_back_index($tipo,'ok',$nuevo?'Registro activado.':'Registro desactivado.'));
}catch(Throwable $e){ com_redirect(com_back_index($tipo,'error',$e->getMessage())); }
