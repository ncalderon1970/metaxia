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
$tipo=com_safe_tipo((string)($_POST['tipo']??'alumnos'));
try{
 if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Método no permitido.');}
 CSRF::requireValid($_POST['_token']??null);
 $run=com_normalize_run((string)($_POST['run']??''));
 $nombres=com_upper((string)($_POST['nombres']??'')); if(!$nombres) throw new RuntimeException('Debe ingresar nombres.');
 $apellidoP=com_upper((string)($_POST['apellido_paterno']??'')); if($tipo==='alumnos'&&!$apellidoP) throw new RuntimeException('Debe ingresar apellido paterno.');
 $apellidoM=com_upper((string)($_POST['apellido_materno']??''));
 $nombre=trim(implode(' ', array_filter([$nombres,$apellidoP,$apellidoM])));
 $activo=((int)($_POST['activo']??1)===1)?1:0;
 $stmt=$pdo->prepare("SELECT id FROM {$tipo} WHERE colegio_id=? AND run=? LIMIT 1"); $stmt->execute([$colegioId,$run]);
 if($stmt->fetchColumn()) throw new RuntimeException('Ya existe un registro con ese RUN en este establecimiento.');
 if($tipo==='alumnos'){
  $sql="INSERT INTO alumnos (colegio_id,run,nombres,apellido_paterno,apellido_materno,fecha_nacimiento,curso,genero,direccion,telefono,email,observacion,activo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
  $params=[$colegioId,$run,$nombres,$apellidoP,$apellidoM,com_clean($_POST['fecha_nacimiento']??null),com_upper($_POST['curso']??null),com_upper($_POST['genero']??null),com_upper($_POST['direccion']??null),com_upper($_POST['telefono']??null),com_email($_POST['email']??null),com_upper($_POST['observacion']??null),$activo];
 } elseif($tipo==='apoderados'){
  $sql="INSERT INTO apoderados (colegio_id,run,nombres,apellido_paterno,apellido_materno,nombre,telefono,email,direccion,observacion,activo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
  $params=[$colegioId,$run,$nombres,$apellidoP,$apellidoM,$nombre,com_upper($_POST['telefono']??null),com_email($_POST['email']??null),com_upper($_POST['direccion']??null),com_upper($_POST['observacion']??null),$activo];
 } elseif($tipo==='docentes'){
  $sql="INSERT INTO docentes (colegio_id,run,nombres,apellido_paterno,apellido_materno,nombre,email,telefono,cargo,activo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
  $params=[$colegioId,$run,$nombres,$apellidoP,$apellidoM,$nombre,com_email($_POST['email']??null),com_upper($_POST['telefono']??null),com_upper($_POST['cargo']??null),$activo];
 } else {
  $sql="INSERT INTO asistentes (colegio_id,run,nombres,apellido_paterno,apellido_materno,nombre,cargo,email,telefono,activo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
  $params=[$colegioId,$run,$nombres,$apellidoP,$apellidoM,$nombre,com_upper($_POST['cargo']??null),com_email($_POST['email']??null),com_upper($_POST['telefono']??null),$activo];
 }
 $ins=$pdo->prepare($sql); $ins->execute($params); $id=(int)$pdo->lastInsertId();
 com_register_log($pdo,$colegioId,$userId,'crear',$tipo,$id,'Creación de registro en comunidad educativa.');
 com_redirect(com_back_index($tipo,'ok','Registro creado correctamente.'));
}catch(Throwable $e){ if(session_status()===PHP_SESSION_NONE)session_start(); $_SESSION['comunidad_form_old']=$_POST; unset($_SESSION['comunidad_form_old']['_token']); $_SESSION['comunidad_form_error']=$e->getMessage(); com_redirect(APP_URL.'/modules/comunidad/crear.php?tipo='.urlencode($tipo)); }
