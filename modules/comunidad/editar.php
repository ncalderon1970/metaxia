<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';
require_once __DIR__ . '/_comunidad_helpers.php';
Auth::requireLogin(); com_require_operate();
$pdo=DB::conn(); $user=Auth::user()??[]; $colegioId=(int)($user['colegio_id']??0);
$tipo=com_safe_tipo((string)($_GET['tipo']??'alumnos')); $id=(int)($_GET['id']??0); $meta=com_tipo_meta($tipo);
$row=com_fetch_person($pdo,$tipo,$id,$colegioId); if(!$row){http_response_code(404);exit('Registro no encontrado.');}
$pageTitle='Editar registro · Comunidad'; $pageSubtitle='Actualizar '. $meta['singular'] .' de comunidad educativa';
$pageHeaderActions=metis_context_actions([metis_context_action('Volver',APP_URL.'/modules/comunidad/index.php?tipo='.urlencode($tipo),'bi-arrow-left','secondary')]);
$error=(string)($_GET['error']??''); require_once dirname(__DIR__,2).'/core/layout_header.php';
?>
<style>.com-form-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1.3rem;box-shadow:0 12px 28px rgba(15,23,42,.06)}.com-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}.com-field label{display:block;font-size:.76rem;font-weight:900;color:#334155;margin-bottom:.35rem}.com-field input,.com-field select,.com-field textarea{width:100%;border:1px solid #cbd5e1;border-radius:13px;padding:.68rem .8rem;font-size:.9rem}.com-actions{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.2rem}.com-btn{border:1px solid #cbd5e1;border-radius:7px;padding:.65rem 1rem;font-weight:900;text-decoration:none;background:#fff;color:#334155}.com-btn.primary{background:#1e3a8a;color:#fff;border-color:#1e3a8a}.com-alert{border-radius:14px;padding:.85rem 1rem;margin-bottom:1rem;font-weight:800;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:900px){.com-grid{grid-template-columns:1fr}}</style>
<?php if($error): ?><div class="com-alert"><?= com_e($error) ?></div><?php endif; ?>
<section class="com-form-card"><h2 style="margin-top:0;color:#0f172a;">Editar <?= com_e($meta['singular']) ?></h2><form method="post" action="<?= APP_URL ?>/modules/comunidad/actualizar.php"><?= CSRF::field() ?><input type="hidden" name="tipo" value="<?= com_e($tipo) ?>"><input type="hidden" name="id" value="<?= $id ?>"><div class="com-grid">
<div class="com-field"><label>RUN *</label><input name="run" value="<?= com_e((string)$row['run']) ?>" required></div><div class="com-field"><label>Nombres *</label><input name="nombres" value="<?= com_e((string)($row['nombres']??$row['nombre']??'')) ?>" required></div><div class="com-field"><label>Apellido paterno <?= $tipo==='alumnos'?'*':'' ?></label><input name="apellido_paterno" value="<?= com_e((string)($row['apellido_paterno']??'')) ?>" <?= $tipo==='alumnos'?'required':'' ?>></div><div class="com-field"><label>Apellido materno</label><input name="apellido_materno" value="<?= com_e((string)($row['apellido_materno']??'')) ?>"></div>
<?php if($tipo==='alumnos'): ?><div class="com-field"><label>Curso</label><input name="curso" value="<?= com_e((string)($row['curso']??'')) ?>"></div><div class="com-field"><label>Género</label><input name="genero" value="<?= com_e((string)($row['genero']??'')) ?>"></div><div class="com-field"><label>Fecha nacimiento</label><input type="date" name="fecha_nacimiento" value="<?= com_e((string)($row['fecha_nacimiento']??'')) ?>"></div><?php endif; ?>
<?php if(in_array($tipo,['docentes','asistentes'],true)): ?><div class="com-field"><label>Cargo</label><input name="cargo" value="<?= com_e((string)($row['cargo']??'')) ?>"></div><?php endif; ?>
<div class="com-field"><label>Teléfono</label><input name="telefono" value="<?= com_e((string)($row['telefono']??'')) ?>"></div><div class="com-field"><label>Email</label><input type="email" name="email" value="<?= com_e((string)($row['email']??'')) ?>"></div><div class="com-field"><label>Estado</label><select name="activo"><option value="1" <?= (int)($row['activo']??1)===1?'selected':'' ?>>Activo</option><option value="0" <?= (int)($row['activo']??1)===0?'selected':'' ?>>Inactivo</option></select></div><div class="com-field" style="grid-column:1/-1"><label>Dirección</label><input name="direccion" value="<?= com_e((string)($row['direccion']??'')) ?>"></div><div class="com-field" style="grid-column:1/-1"><label>Observación</label><textarea name="observacion" rows="3"><?= com_e((string)($row['observacion']??'')) ?></textarea></div>
</div><div class="com-actions"><a class="com-btn" href="<?= APP_URL ?>/modules/comunidad/index.php?tipo=<?= urlencode($tipo) ?>">Cancelar</a><button class="com-btn primary" type="submit">Guardar cambios</button></div></form></section>
<?php require_once dirname(__DIR__,2).'/core/layout_footer.php'; ?>
