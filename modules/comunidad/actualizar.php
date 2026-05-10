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
 $run=com_normalize_run((string)($_POST['run']??'')); $nombres=com_upper($_POST['nombres']??''); if(!$nombres) throw new RuntimeException('Debe ingresar nombres.');
 $apellidoP=com_upper($_POST['apellido_paterno']??''); if($tipo==='alumnos'&&!$apellidoP) throw new RuntimeException('Debe ingresar apellido paterno.');
 $apellidoM=com_upper($_POST['apellido_materno']??''); $nombre=trim(implode(' ', array_filter([$nombres,$apellidoP,$apellidoM]))); $activo=((int)($_POST['activo']??1)===1)?1:0;
 $dup=$pdo->prepare("SELECT id FROM {$tipo} WHERE colegio_id=? AND run=? AND id<>? LIMIT 1"); $dup->execute([$colegioId,$run,$id]); if($dup->fetchColumn()) throw new RuntimeException('Ya existe otro registro con ese RUN en este establecimiento.');
 if($tipo==='alumnos'){$sql="UPDATE alumnos SET run=?,nombres=?,apellido_paterno=?,apellido_materno=?,fecha_nacimiento=?,curso=?,genero=?,direccion=?,telefono=?,email=?,observacion=?,activo=?,updated_at=NOW() WHERE id=? AND colegio_id=? LIMIT 1"; $params=[$run,$nombres,$apellidoP,$apellidoM,com_clean($_POST['fecha_nacimiento']??null),com_upper($_POST['curso']??null),com_upper($_POST['genero']??null),com_upper($_POST['direccion']??null),com_upper($_POST['telefono']??null),com_email($_POST['email']??null),com_upper($_POST['observacion']??null),$activo,$id,$colegioId];}
 elseif($tipo==='apoderados'){$sql="UPDATE apoderados SET run=?,nombres=?,apellido_paterno=?,apellido_materno=?,nombre=?,telefono=?,email=?,direccion=?,observacion=?,activo=?,updated_at=NOW() WHERE id=? AND colegio_id=? LIMIT 1"; $params=[$run,$nombres,$apellidoP,$apellidoM,$nombre,com_upper($_POST['telefono']??null),com_email($_POST['email']??null),com_upper($_POST['direccion']??null),com_upper($_POST['observacion']??null),$activo,$id,$colegioId];}
 elseif($tipo==='docentes'){$sql="UPDATE docentes SET run=?,nombres=?,apellido_paterno=?,apellido_materno=?,nombre=?,email=?,telefono=?,cargo=?,activo=?,updated_at=NOW() WHERE id=? AND colegio_id=? LIMIT 1"; $params=[$run,$nombres,$apellidoP,$apellidoM,$nombre,com_email($_POST['email']??null),com_upper($_POST['telefono']??null),com_upper($_POST['cargo']??null),$activo,$id,$colegioId];}
 else{$sql="UPDATE asistentes SET run=?,nombres=?,apellido_paterno=?,apellido_materno=?,nombre=?,cargo=?,email=?,telefono=?,activo=?,updated_at=NOW() WHERE id=? AND colegio_id=? LIMIT 1"; $params=[$run,$nombres,$apellidoP,$apellidoM,$nombre,com_upper($_POST['cargo']??null),com_email($_POST['email']??null),com_upper($_POST['telefono']??null),$activo,$id,$colegioId];}
 $pdo->prepare($sql)->execute($params); com_register_log($pdo,$colegioId,$userId,'actualizar',$tipo,$id,'Actualización de registro de comunidad educativa.'); com_redirect(com_back_index($tipo,'ok','Registro actualizado correctamente.'));
}catch(Throwable $e){ com_redirect(APP_URL.'/modules/comunidad/editar.php?tipo='.urlencode($tipo).'&id='.$id.'&error='.urlencode($e->getMessage())); }
