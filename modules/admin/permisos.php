<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigoActual = (string)($user['rol_codigo'] ?? '');
$puedeAdministrar = in_array($rolCodigoActual, ['superadmin'], true)
    || (method_exists('Auth', 'can') && Auth::can('admin_sistema'))
    || (method_exists('Auth', 'can') && Auth::can('administrar_permisos'));

if (!$puedeAdministrar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Permisos · Metis';
$pageSubtitle = 'Matriz de permisos por rol para control fino de acceso';

function perm_table_exists(PDO $pdo, string $table): bool
{
    static $schema = [
        'alumno_apoderado' => ['id', 'alumno_id', 'apoderado_id', 'tipo_relacion', 'parentesco', 'es_titular', 'puede_retirar', 'recibe_notificaciones', 'vive_con_estudiante', 'observacion', 'activo', 'created_at', 'updated_at'],
        'alumno_condicion_especial' => ['id', 'colegio_id', 'alumno_id', 'tipo_condicion', 'nombre_condicion', 'estado_diagnostico', 'nivel_apoyo', 'tiene_pie', 'tiene_certificado', 'nro_certificado', 'fecha_deteccion', 'fecha_diagnostico', 'fecha_inicio_pie', 'derivado_salud', 'fecha_derivacion', 'destino_derivacion', 'estado_derivacion', 'fecha_respuesta_salud', 'requiere_ajustes', 'descripcion_ajustes', 'ajustes_aplicados', 'observaciones', 'fuente_informacion', 'registrado_por', 'activo', 'created_at', 'updated_at'],
        'alumnos' => ['id', 'colegio_id', 'run', 'nombres', 'apellido_paterno', 'apellido_materno', 'fecha_nacimiento', 'curso', 'genero', 'direccion', 'telefono', 'email', 'observacion', 'condicion_especial', 'tiene_pie', 'diagnostico_tea', 'nivel_apoyo_tea', 'derivado_salud_tea', 'fecha_derivacion_tea', 'destino_derivacion_tea', 'estado_derivacion_tea', 'tiene_certificado_discapacidad', 'nro_certificado_discapacidad', 'requiere_ajustes_razonables', 'descripcion_ajustes', 'activo', 'fecha_baja', 'motivo_baja', 'created_at', 'updated_at'],
        'apoderados' => ['id', 'colegio_id', 'run', 'nombres', 'apellido_paterno', 'apellido_materno', 'nombre_cache', 'nombre', 'telefono', 'telefono_secundario', 'email', 'direccion', 'observacion', 'activo', 'fecha_baja', 'motivo_baja', 'created_at', 'updated_at'],
        'asistentes' => ['id', 'colegio_id', 'run', 'nombres', 'apellido_paterno', 'apellido_materno', 'nombre_cache', 'nombre', 'cargo', 'email', 'telefono', 'activo', 'fecha_baja', 'motivo_baja', 'created_at', 'updated_at'],
        'aula_segura_causales' => ['id', 'codigo', 'nombre', 'tipo', 'descripcion', 'activo', 'orden', 'created_at', 'updated_at'],
        'aula_segura_procedimientos' => ['id', 'caso_id', 'aplica', 'causal', 'medida_cautelar_suspension', 'fecha_notificacion_suspension', 'fecha_limite_resolucion', 'fecha_notificacion_resolucion', 'fecha_limite_reconsideracion', 'reconsideracion_presentada', 'fecha_reconsideracion', 'estado', 'observaciones', 'created_at', 'updated_at'],
        'caso_alertas' => ['id', 'caso_id', 'seguimiento_id', 'plan_id', 'tipo', 'mensaje', 'prioridad', 'estado', 'fecha_alerta', 'atendida_por', 'fecha_atendida', 'resuelta_por', 'resuelta_at', 'created_at', 'updated_at'],
        'caso_analisis_ia' => ['id', 'caso_id', 'colegio_id', 'usuario_id', 'reglamento_id', 'modelo_usado', 'tokens_usados', 'analisis_texto', 'medidas_json', 'gravedad_ia', 'alerta_normativa', 'created_at'],
        'caso_aula_segura' => ['id', 'caso_id', 'colegio_id', 'posible_aula_segura', 'causal_agresion_sexual', 'causal_agresion_fisica_lesiones', 'causal_armas', 'causal_artefactos_incendiarios', 'causal_infraestructura_esencial', 'causal_grave_reglamento', 'descripcion_hecho', 'fuente_informacion', 'evidencia_inicial', 'falta_reglamento', 'fundamento_proporcionalidad', 'estado', 'decision_director', 'fecha_evaluacion_directiva', 'evaluado_por', 'fecha_inicio_procedimiento', 'iniciado_por', 'comunicacion_apoderado_at', 'medio_comunicacion_apoderado', 'observacion_comunicacion_apoderado', 'suspension_cautelar', 'fecha_notificacion_suspension', 'fecha_limite_resolucion', 'fundamento_suspension', 'descargos_recibidos', 'fecha_descargos', 'observacion_descargos', 'resolucion', 'fecha_resolucion', 'fecha_notificacion_resolucion', 'fundamento_resolucion', 'reconsideracion_presentada', 'fecha_reconsideracion', 'fecha_limite_reconsideracion', 'fecha_resolucion_reconsideracion', 'resultado_reconsideracion', 'fundamento_reconsideracion', 'comunicacion_supereduc', 'fecha_comunicacion_supereduc', 'medio_comunicacion_supereduc', 'observacion_supereduc', 'observaciones', 'creado_por', 'created_at', 'updated_at'],
        'caso_aula_segura_historial' => ['id', 'caso_id', 'caso_aula_segura_id', 'colegio_id', 'accion', 'estado_anterior', 'estado_nuevo', 'detalle', 'usuario_id', 'created_at'],
        'caso_cierre' => ['id', 'colegio_id', 'caso_id', 'fecha_cierre', 'tipo_cierre', 'fundamento', 'medidas_finales', 'acuerdos', 'derivaciones', 'observaciones', 'estado_cierre', 'cerrado_por', 'anulado_por', 'anulado_at', 'motivo_anulacion', 'created_at', 'updated_at'],
        'caso_clasificacion_normativa' => ['id', 'colegio_id', 'caso_id', 'area_mineduc', 'ambito_mineduc', 'tipo_conducta', 'categoria_convivencia', 'conducta_principal', 'gravedad', 'reiteracion', 'involucra_adulto', 'discriminacion', 'ciberacoso', 'acoso_escolar', 'violencia_fisica', 'violencia_psicologica', 'violencia_sexual', 'maltrato_adulto_estudiante', 'posible_aula_segura', 'causal_aula_segura', 'fundamento_aula_segura', 'requiere_denuncia', 'entidad_derivacion', 'plazo_revision', 'observaciones_normativas', 'creado_por', 'created_at', 'updated_at', 'ley21809_flags', 'rex782_flags', 'contexto_normativo_flags'],
        'caso_correlativos' => ['anio', 'ultimo_correlativo', 'created_at', 'updated_at'],
        'caso_declaraciones' => ['id', 'caso_id', 'participante_id', 'tipo_declarante', 'nombre_declarante', 'run_declarante', 'calidad_procesal', 'fecha_declaracion', 'texto_declaracion', 'requiere_reanalisis_ia', 'observaciones', 'tomada_por', 'created_at'],
        'caso_evidencias' => ['id', 'caso_id', 'tipo', 'nombre_archivo', 'ruta', 'descripcion', 'mime_type', 'tamano_bytes', 'subido_por', 'created_at'],
        'caso_gestion_ejecutiva' => ['id', 'colegio_id', 'caso_id', 'titulo', 'descripcion', 'responsable_nombre', 'responsable_rol', 'prioridad', 'estado', 'fecha_compromiso', 'fecha_cumplimiento', 'creado_por', 'cerrado_por', 'created_at', 'updated_at'],
        'caso_historial' => ['id', 'caso_id', 'tipo_evento', 'titulo', 'detalle', 'user_id', 'created_at'],
        'caso_hitos' => ['id', 'caso_id', 'colegio_id', 'codigo', 'nombre', 'user_id', 'created_at'],
        'caso_marcadores_normativos' => ['id', 'caso_id', 'colegio_id', 'marcador_codigo', 'user_id', 'created_at'],
        'caso_participantes' => ['id', 'caso_id', 'tipo_persona', 'persona_id', 'nombre_referencial', 'run', 'identidad_confirmada', 'fecha_identificacion', 'identificado_por', 'rol_en_caso', 'solicita_reserva_identidad', 'observacion_reserva', 'observacion', 'observacion_identificacion', 'created_at'],
        'caso_pauta_riesgo' => ['id', 'caso_id', 'alumno_id', 'nombre_alumno', 'rol_en_caso', 'numero_aplicacion', 'd1_frecuencia', 'd1_tipo_violencia', 'd1_lugar', 'd1_medios', 'puntaje_d1', 'd2_edad', 'd2_condicion', 'd2_red_familiar', 'd2_victimizacion', 'puntaje_d2', 'd3_quien_agresor', 'd3_actitud', 'd3_antecedentes', 'puntaje_d3', 'd4_visibilidad', 'd4_riesgo_repeticion', 'd4_familia_agresor', 'd4_derivacion', 'puntaje_d4', 'esc_menor_8', 'esc_agresor_funcionario', 'esc_violencia_sexual', 'esc_amenazas_armas', 'esc_tea_sin_red', 'esc_reincidencia', 'puntaje_total', 'nivel_calculado', 'nivel_final', 'ajuste_profesional', 'justificacion_ajuste', 'derivado', 'fecha_derivacion', 'entidad_derivacion', 'motivo_reaplicacion', 'observacion', 'firmado', 'firma_hash', 'firma_timestamp', 'firmado_por_id', 'firmado_por_nombre', 'firma_ip', 'completada_por', 'created_at'],
        'caso_plan_accion' => ['id', 'caso_id', 'colegio_id', 'participante_id', 'plan_accion', 'medidas_preventivas', 'version', 'vigente', 'motivo_version', 'estado_plan', 'creado_por', 'created_at', 'updated_at'],
        'caso_plan_intervencion' => ['id', 'caso_id', 'colegio_id', 'participante_id', 'tipo_medida', 'seguimiento_id', 'titulo', 'descripcion', 'responsable', 'fecha_compromiso', 'responsable_id', 'fecha_inicio', 'fecha_vencimiento', 'estado', 'observacion_cumplimiento', 'prioridad', 'created_at', 'updated_at'],
        'caso_protocolo_tea' => ['id', 'caso_id', 'colegio_id', 'alumno_condicion_id', 'deteccion_registrada', 'fecha_deteccion', 'comunicacion_familia', 'fecha_comunicacion_familia', 'derivacion_salud', 'fecha_derivacion', 'establecimiento_salud', 'profesional_receptor', 'coordinacion_pie', 'ajustes_metodologicos', 'seguimiento_establecido', 'fecha_proximo_seguimiento', 'respuesta_salud_recibida', 'fecha_respuesta_salud', 'diagnostico_confirmado', 'estado_protocolo', 'observaciones', 'completado_por', 'created_at', 'updated_at'],
        'caso_seguimiento' => ['id', 'colegio_id', 'caso_id', 'fecha_apertura', 'objetivo_general', 'estado', 'responsable_general_id', 'fecha_cierre', 'observacion_avance', 'proxima_revision', 'estado_seguimiento', 'medidas_preventivas', 'cumplimiento', 'comunicacion_apoderado_modalidad', 'comunicacion_apoderado_fecha', 'notas_comunicacion', 'actualizado_por', 'created_at', 'updated_at'],
        'caso_seguimiento_avances' => ['id', 'plan_id', 'descripcion', 'porcentaje_avance', 'registrado_por', 'created_at'],
        'caso_seguimiento_participantes' => ['id', 'colegio_id', 'caso_id', 'seguimiento_id', 'participante_id', 'tipo_participante', 'nombre_participante', 'run_participante', 'condicion', 'plan_accion', 'estado', 'created_at', 'updated_at'],
        'caso_seguimiento_sesion' => ['id', 'caso_id', 'colegio_id', 'participante_id', 'plan_accion_id', 'observacion_avance', 'medidas_sesion', 'estado_caso', 'cumplimiento_plan', 'proxima_revision', 'comunicacion_apoderado', 'fecha_comunicacion_apoderado', 'notas_comunicacion', 'registrado_por', 'created_at', 'updated_at'],
        'casos' => ['id', 'colegio_id', 'numero_caso', 'codigo', 'anio_caso', 'correlativo_anual', 'numero_caso_base', 'numero_caso_dv', 'fecha_ingreso', 'denunciante_nombre', 'es_anonimo', 'relato', 'contexto', 'involucra_moviles', 'estado', 'estado_caso_id', 'falta_id', 'denuncia_aspecto_id', 'requiere_reanalisis_ia', 'semaforo', 'prioridad', 'marco_legal', 'involucra_nna_tea', 'interes_superior_aplicado', 'interés_superior_aplicado', 'autonomia_progresiva_considerada', 'requiere_coordinacion_senape', 'requiere_coordinacion_salud', 'fecha_coordinacion_senape', 'observacion_coordinacion', 'creado_por', 'created_at', 'updated_at', 'denunciante_run', 'lugar_hechos', 'fecha_hechos', 'clasificacion_ia', 'denunciante', 'denunciante_persona_id', 'fecha_hora_incidente', 'canal_ingreso', 'descripcion', 'resumen_ia', 'recomendacion_ia', 'posible_aula_segura', 'aula_segura_estado', 'aula_segura_marcado_por', 'aula_segura_marcado_at', 'aula_segura_causales_preliminares', 'aula_segura_observacion_preliminar', 'ley21809_flags', 'rex782_flags', 'denuncia_normativa_observacion', 'comunicacion_apoderado_estado', 'comunicacion_apoderado_realizada', 'comunicacion_apoderado_modalidad', 'comunicacion_apoderado_fecha', 'comunicacion_apoderado_notas', 'comunicacion_apoderado_registrado_por', 'comunicacion_apoderado_registrado_at'],
        'catalogo_condicion_especial' => ['id', 'codigo', 'nombre', 'categoria', 'ley_base', 'requiere_pie', 'activo'],
        'checklist_preproduccion' => ['id', 'codigo', 'categoria', 'item', 'detalle', 'prioridad', 'estado', 'responsable', 'observacion', 'revisado_por', 'revisado_at', 'orden', 'created_at', 'updated_at'],
        'colegio_modulos' => ['id', 'colegio_id', 'modulo_codigo', 'activo', 'fecha_activacion', 'fecha_expiracion', 'plan', 'created_at', 'updated_at'],
        'colegio_reglamentos' => ['id', 'colegio_id', 'nombre_original', 'ruta_archivo', 'texto_contenido', 'caracteres', 'activo', 'subido_por', 'created_at', 'updated_at'],
        'colegio_suscripciones' => ['id', 'colegio_id', 'modulo_codigo', 'plan', 'precio', 'estado', 'fecha_inicio', 'fecha_fin', 'created_at', 'updated_at'],
        'colegios' => ['id', 'rbd', 'rut_entidad', 'nombre', 'logo_url', 'director_nombre', 'firma_url', 'dependencia', 'comuna', 'region', 'direccion', 'telefono', 'email', 'activo', 'fecha_vencimiento', 'estado_comercial', 'precio_uf_mensual', 'plan', 'contacto_comercial', 'email_comercial', 'telefono_comercial', 'created_at', 'updated_at'],
        'comunidad_importacion_pendientes' => ['id', 'colegio_id', 'tipo', 'fila_csv', 'run_original', 'motivo', 'datos_json', 'estado', 'corregido_run', 'corregido_por', 'corregido_at', 'observacion', 'created_at', 'updated_at'],
        'denuncia_areas' => ['id', 'codigo', 'nombre', 'descripcion', 'activo', 'created_at'],
        'denuncia_aspectos' => ['id', 'area_id', 'codigo', 'nombre', 'descripcion', 'activo', 'created_at'],
        'docentes' => ['id', 'colegio_id', 'run', 'nombres', 'apellido_paterno', 'apellido_materno', 'nombre_cache', 'nombre', 'email', 'telefono', 'cargo', 'activo', 'fecha_baja', 'motivo_baja', 'created_at', 'updated_at'],
        'estado_caso' => ['id', 'codigo', 'nombre', 'orden_visual', 'activo'],
        'faltas' => ['id', 'colegio_id', 'codigo', 'nombre', 'gravedad', 'descripcion', 'activo', 'created_at', 'updated_at'],
        'ia_consumo' => ['id', 'colegio_id', 'usuario_id', 'caso_id', 'tipo_analisis', 'tokens_usados', 'costo_estimado', 'created_at'],
        'importacion_pendientes' => ['id', 'colegio_id', 'tipo', 'fila', 'run', 'motivo', 'datos_json', 'estado', 'creado_por', 'created_at', 'updated_at'],
        'logs_sistema' => ['id', 'colegio_id', 'usuario_id', 'modulo', 'accion', 'entidad', 'entidad_id', 'descripcion', 'ip', 'user_agent', 'created_at'],
        'marcadores_normativos' => ['id', 'codigo', 'nombre', 'grupo', 'descripcion', 'orden', 'activo'],
        'modulos_catalogo' => ['id', 'codigo', 'nombre', 'descripcion', 'es_premium', 'activo', 'created_at'],
        'password_resets' => ['id', 'email', 'token', 'expires_at', 'used', 'created_at'],
        'permisos' => ['id', 'codigo', 'nombre', 'modulo', 'grupo', 'descripcion', 'activo'],
        'pruebas_integrales' => ['id', 'codigo', 'area', 'prueba', 'descripcion', 'prioridad', 'resultado', 'observacion', 'responsable', 'fecha_revision', 'revisado_por', 'activo', 'created_at', 'updated_at'],
        'rol_permiso' => ['id', 'rol_id', 'permiso_id'],
        'rol_permisos' => ['id', 'rol_id', 'permiso_id', 'permitido', 'created_at', 'updated_at'],
        'roles' => ['id', 'codigo', 'nombre', 'descripcion', 'activo'],
        'sistema_config' => ['id', 'clave', 'valor', 'tipo', 'descripcion', 'scope', 'actualizado_por', 'updated_at'],
        'usuarios' => ['id', 'colegio_id', 'rol_id', 'run', 'nombre', 'email', 'password_hash', 'activo', 'ultimo_acceso', 'created_at', 'updated_at'],
];

    return array_key_exists($table, $schema);
}

function perm_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function perm_redirect(string $status, string $msg, ?int $rolId = null): void
{
    $url = APP_URL . '/modules/admin/permisos.php?status=' . urlencode($status) . '&msg=' . urlencode($msg);
    if ($rolId !== null) {
        $url .= '&rol_id=' . $rolId;
    }
    header('Location: ' . $url);
    exit;
}

function perm_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!perm_table_exists($pdo, $table)) {
        return 0;
    }
    try {
        $sql = 'SELECT COUNT(*) FROM ' . perm_quote($table);
        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function perm_roles(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, codigo, nombre, descripcion, activo FROM roles ORDER BY activo DESC, nombre ASC");
    return $stmt->fetchAll();
}

function perm_permisos(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, codigo, nombre, grupo, descripcion, activo FROM permisos ORDER BY grupo ASC, nombre ASC");
    return $stmt->fetchAll();
}

function perm_rol(PDO $pdo, int $rolId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
    $stmt->execute([$rolId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function perm_mapa_rol(PDO $pdo, int $rolId): array
{
    $stmt = $pdo->prepare("SELECT permiso_id, permitido FROM rol_permisos WHERE rol_id = ?");
    $stmt->execute([$rolId]);
    $mapa = [];
    foreach ($stmt->fetchAll() as $row) {
        $mapa[(int)$row['permiso_id']] = (int)$row['permitido'] === 1;
    }
    return $mapa;
}

function perm_agrupados(array $permisos): array
{
    $out = [];
    foreach ($permisos as $permiso) {
        $grupo = trim((string)($permiso['grupo'] ?? 'General')) ?: 'General';
        $out[$grupo][] = $permiso;
    }
    return $out;
}

foreach (['roles', 'permisos', 'rol_permisos'] as $tabla) {
    if (!perm_table_exists($pdo, $tabla)) {
        http_response_code(500);
        exit('Falta la tabla ' . $tabla . '. Ejecuta primero sql/34_matriz_permisos.sql.');
    }
}

$roles = perm_roles($pdo);
$permisos = perm_permisos($pdo);
$primerRolId = isset($roles[0]['id']) ? (int)$roles[0]['id'] : 0;
$rolId = (int)($_GET['rol_id'] ?? $_POST['rol_id'] ?? $primerRolId);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);
        $accion = clean((string)($_POST['_accion'] ?? ''));
        if ($accion !== 'guardar_permisos') {
            throw new RuntimeException('Acción no válida.');
        }
        $rolIdPost = (int)($_POST['rol_id'] ?? 0);
        $rol = perm_rol($pdo, $rolIdPost);
        if (!$rol) {
            throw new RuntimeException('Rol no encontrado.');
        }
        $rolCodigo = (string)($rol['codigo'] ?? '');
        $seleccionados = array_map('intval', $_POST['permisos'] ?? []);
        $pdo->beginTransaction();
        foreach ($permisos as $permiso) {
            $permisoId = (int)$permiso['id'];
            $permitido = in_array($rolCodigo, ['superadmin'], true) ? 1 : (in_array($permisoId, $seleccionados, true) ? 1 : 0);
            $stmt = $pdo->prepare("INSERT INTO rol_permisos (rol_id, permiso_id, permitido, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE permitido = VALUES(permitido), updated_at = NOW()");
            $stmt->execute([$rolIdPost, $permisoId, $permitido]);
        }
        registrar_bitacora('admin', 'actualizar_permisos_rol', 'roles', $rolIdPost, 'Se actualizó matriz de permisos del rol: ' . (string)($rol['nombre'] ?? $rolCodigo));
        $pdo->commit();
        perm_redirect('ok', 'Permisos actualizados correctamente.', $rolIdPost);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    perm_redirect('error', $e->getMessage(), $rolId > 0 ? $rolId : null);
}

$rolSeleccionado = $rolId > 0 ? perm_rol($pdo, $rolId) : null;
$mapa = $rolSeleccionado ? perm_mapa_rol($pdo, (int)$rolSeleccionado['id']) : [];
$grupos = perm_agrupados($permisos);
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));
$totalRoles = count($roles);
$totalPermisos = count($permisos);
$totalAsignaciones = perm_count($pdo, 'rol_permisos', 'permitido = 1');
$totalSinPermisos = 0;
foreach ($roles as $rolCheck) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rol_permisos WHERE rol_id = ? AND permitido = 1");
    $stmt->execute([(int)$rolCheck['id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        $totalSinPermisos++;
    }
}

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>
<style>
.perm-hero{background:radial-gradient(circle at 90% 16%,rgba(16,185,129,.22),transparent 28%),linear-gradient(135deg,#0f172a 0%,#1e3a8a 58%,#2563eb 100%);color:#fff;border-radius:22px;padding:2rem;margin-bottom:1.2rem;box-shadow:0 18px 45px rgba(15,23,42,.18)}.perm-hero h2{margin:0 0 .45rem;font-size:1.85rem;font-weight:900}.perm-hero p{margin:0;color:#bfdbfe;max-width:920px;line-height:1.55}.perm-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1rem}.perm-btn{display:inline-flex;align-items:center;gap:.42rem;border-radius:999px;padding:.62rem 1rem;font-size:.84rem;font-weight:900;text-decoration:none;border:1px solid rgba(255,255,255,.28);color:#fff;background:rgba(255,255,255,.12)}.perm-btn:hover{color:#fff}.perm-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.9rem;margin-bottom:1.2rem}.perm-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1rem;box-shadow:0 12px 28px rgba(15,23,42,.06)}.perm-kpi span{color:#64748b;display:block;font-size:.68rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.perm-kpi strong{display:block;color:#0f172a;font-size:1.9rem;line-height:1;margin-top:.35rem}.perm-layout{display:grid;grid-template-columns:minmax(280px,.35fr) minmax(0,.65fr);gap:1.2rem;align-items:start}.perm-panel{background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 12px 28px rgba(15,23,42,.06);overflow:hidden;margin-bottom:1.2rem}.perm-panel-head{padding:1rem 1.2rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap}.perm-panel-title{margin:0;color:#0f172a;font-size:1rem;font-weight:900}.perm-panel-body{padding:1.2rem}.perm-role-list{display:grid;gap:.55rem}.perm-role{display:block;text-decoration:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:.85rem;color:inherit}.perm-role.active{background:#eff6ff;border-color:#93c5fd}.perm-role-title{color:#0f172a;font-weight:900}.perm-role-text{color:#64748b;font-size:.78rem;line-height:1.35;margin-top:.2rem}.perm-group{border:1px solid #e2e8f0;background:#f8fafc;border-radius:18px;padding:1rem;margin-bottom:.9rem}.perm-group-title{color:#0f172a;font-weight:900;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}.perm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}.perm-check{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:.8rem;display:grid;grid-template-columns:auto 1fr;gap:.65rem;align-items:start}.perm-check input{margin-top:.15rem}.perm-check-title{color:#0f172a;font-weight:900;font-size:.86rem}.perm-check-text{color:#64748b;font-size:.76rem;line-height:1.35;margin-top:.15rem}.perm-code{color:#64748b;font-size:.68rem;font-weight:900;margin-top:.25rem}.perm-submit,.perm-link{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;border:0;background:#059669;color:#fff;border-radius:999px;padding:.72rem 1.1rem;font-weight:900;font-size:.86rem;text-decoration:none;white-space:nowrap;cursor:pointer}.perm-link{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}.perm-badge{display:inline-flex;align-items:center;border-radius:999px;padding:.24rem .62rem;font-size:.72rem;font-weight:900;border:1px solid #e2e8f0;background:#fff;color:#475569;white-space:nowrap}.perm-badge.ok{background:#ecfdf5;border-color:#bbf7d0;color:#047857}.perm-badge.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}.perm-badge.blue{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}.perm-msg{border-radius:14px;padding:.9rem 1rem;margin-bottom:1rem;font-weight:800}.perm-msg.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534}.perm-msg.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.perm-note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:16px;padding:.9rem 1rem;line-height:1.45;font-size:.86rem;margin-bottom:.85rem}@media(max-width:1100px){.perm-layout{grid-template-columns:1fr}.perm-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.perm-grid{grid-template-columns:1fr}}@media(max-width:720px){.perm-kpis{grid-template-columns:1fr}.perm-hero{padding:1.35rem}}
</style>
<section class="perm-hero">
    <h2>Matriz de permisos por rol</h2>
    <p>Administra qué puede realizar cada perfil dentro de Metis. Esta matriz prepara el control fino de acceso para colegios, usuarios, expedientes, reportes, auditoría y operación administrativa.</p>
    <div class="perm-actions">
        <a class="perm-btn" href="<?= APP_URL ?>/modules/admin/index.php"><i class="bi bi-gear"></i>Administración</a>
        <a class="perm-btn" href="<?= APP_URL ?>/modules/admin/usuarios.php"><i class="bi bi-person-gear"></i>Usuarios</a>
        <a class="perm-btn" href="<?= APP_URL ?>/modules/roles/index.php"><i class="bi bi-person-badge"></i>Roles</a>
    </div>
</section>
<?php if ($status === 'ok' && $msg !== ''): ?><div class="perm-msg ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($status === 'error' && $msg !== ''): ?><div class="perm-msg error"><?= e($msg) ?></div><?php endif; ?>
<section class="perm-kpis">
    <div class="perm-kpi"><span>Roles</span><strong><?= number_format($totalRoles, 0, ',', '.') ?></strong></div>
    <div class="perm-kpi"><span>Permisos</span><strong><?= number_format($totalPermisos, 0, ',', '.') ?></strong></div>
    <div class="perm-kpi"><span>Asignaciones activas</span><strong><?= number_format($totalAsignaciones, 0, ',', '.') ?></strong></div>
    <div class="perm-kpi"><span>Roles sin permisos</span><strong style="color:<?= $totalSinPermisos > 0 ? '#b91c1c' : '#047857' ?>;"><?= number_format($totalSinPermisos, 0, ',', '.') ?></strong></div>
</section>
<div class="perm-layout">
    <aside>
        <section class="perm-panel">
            <div class="perm-panel-head"><h3 class="perm-panel-title"><i class="bi bi-person-badge"></i> Roles</h3></div>
            <div class="perm-panel-body">
                <div class="perm-role-list">
                    <?php foreach ($roles as $rol): ?>
                        <?php $cantidadPermisosRol = perm_count($pdo, 'rol_permisos', 'rol_id = ? AND permitido = 1', [(int)$rol['id']]); ?>
                        <a class="perm-role <?= $rolSeleccionado && (int)$rolSeleccionado['id'] === (int)$rol['id'] ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/admin/permisos.php?rol_id=<?= (int)$rol['id'] ?>">
                            <div class="perm-role-title"><?= e((string)($rol['nombre'] ?? $rol['codigo'])) ?></div>
                            <div class="perm-role-text">Código: <?= e((string)($rol['codigo'] ?? '-')) ?><br><?= number_format($cantidadPermisosRol, 0, ',', '.') ?> permiso(s) activo(s)</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </aside>
    <main>
        <section class="perm-panel">
            <div class="perm-panel-head">
                <h3 class="perm-panel-title"><i class="bi bi-sliders"></i> Permisos del rol <?= $rolSeleccionado ? e((string)($rolSeleccionado['nombre'] ?? '')) : '' ?></h3>
                <?php if ($rolSeleccionado): ?><span class="perm-badge blue"><?= e((string)($rolSeleccionado['codigo'] ?? '')) ?></span><?php endif; ?>
            </div>
            <div class="perm-panel-body">
                <?php if (!$rolSeleccionado): ?>
                    <div class="perm-note">Selecciona un rol para administrar permisos.</div>
                <?php else: ?>
                    <?php $rolCodigoSeleccionado = (string)($rolSeleccionado['codigo'] ?? ''); ?>
                    <?php if ($rolCodigoSeleccionado === 'superadmin'): ?>
                        <div class="perm-note">El rol Superadmin mantiene todos los permisos habilitados por seguridad administrativa.</div>
                    <?php else: ?>
                        <div class="perm-note">Marca solo los permisos que este rol debe usar. Los cambios quedarán registrados en bitácora.</div>
                    <?php endif; ?>
                    <form method="post">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="_accion" value="guardar_permisos">
                        <input type="hidden" name="rol_id" value="<?= (int)$rolSeleccionado['id'] ?>">
                        <?php foreach ($grupos as $grupo => $items): ?>
                            <section class="perm-group">
                                <div class="perm-group-title"><span><?= e((string)$grupo) ?></span><span class="perm-badge"><?= count($items) ?> permiso(s)</span></div>
                                <div class="perm-grid">
                                    <?php foreach ($items as $permiso): ?>
                                        <?php $permisoId = (int)$permiso['id']; $checked = $rolCodigoSeleccionado === 'superadmin' || (($mapa[$permisoId] ?? false) === true); ?>
                                        <label class="perm-check">
                                            <input type="checkbox" name="permisos[]" value="<?= $permisoId ?>" <?= $checked ? 'checked' : '' ?> <?= $rolCodigoSeleccionado === 'superadmin' ? 'disabled' : '' ?>>
                                            <span><span class="perm-check-title"><?= e((string)$permiso['nombre']) ?></span><span class="perm-check-text"><?= e((string)($permiso['descripcion'] ?? '')) ?></span><span class="perm-code"><?= e((string)$permiso['codigo']) ?></span></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                        <button class="perm-submit" type="submit"><i class="bi bi-save"></i> Guardar matriz de permisos</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
