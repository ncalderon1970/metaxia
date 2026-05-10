<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');
$userId = (int)($user['id'] ?? 0);

$puedeAdministrar = in_array($rolCodigo, ['superadmin'], true) || Auth::can('admin_sistema');

if (!$puedeAdministrar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Colegios · Metis';
$pageSubtitle = 'Administración de establecimientos, planes, vencimientos y límites operativos';

function col_table_exists(PDO $pdo, string $table): bool
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

function col_column_exists(PDO $pdo, string $table, string $column): bool
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

    return isset($schema[$table]) && in_array($column, $schema[$table], true);
}

function col_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function col_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function col_upper(?string $value): ?string
{
    $value = col_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function col_email(?string $value): ?string
{
    $value = col_clean($value);

    if ($value === null) {
        return null;
    }

    return mb_strtolower($value, 'UTF-8');
}

function col_date(?string $value): ?string
{
    $value = col_clean($value);

    if ($value === null) {
        return null;
    }

    $ts = strtotime($value);

    return $ts ? date('Y-m-d', $ts) : null;
}

function col_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y', $ts) : $value;
}

function col_estado_vencimiento(?string $fecha): array
{
    if (!$fecha) {
        return ['Sin vencimiento', 'soft'];
    }

    $hoy = new DateTimeImmutable('today');
    $vencimiento = DateTimeImmutable::createFromFormat('Y-m-d', substr($fecha, 0, 10));

    if (!$vencimiento) {
        return ['Fecha inválida', 'warn'];
    }

    $dias = (int)$hoy->diff($vencimiento)->format('%r%a');

    if ($dias < 0) {
        return ['Vencido hace ' . abs($dias) . ' día(s)', 'danger'];
    }

    if ($dias <= 30) {
        return ['Vence en ' . $dias . ' día(s)', 'warn'];
    }

    return ['Vigente', 'ok'];
}

function col_pick(array $row, string $key, string $default = '-'): string
{
    return isset($row[$key]) && trim((string)$row[$key]) !== ''
        ? (string)$row[$key]
        : $default;
}

function col_redirect(string $status, string $msg, ?int $editId = null): void
{
    $url = APP_URL . '/modules/colegios/index.php?status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    if ($editId !== null) {
        $url .= '&edit=' . $editId;
    }

    header('Location: ' . $url);
    exit;
}

function col_count_by_colegio(PDO $pdo, string $table, int $colegioId): int
{
    if (!col_table_exists($pdo, $table) || !col_column_exists($pdo, $table, 'colegio_id')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . col_quote($table) . " WHERE colegio_id = ?");
        $stmt->execute([$colegioId]);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function col_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!col_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = col_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para crear el colegio.');
    }

    $sql = "
        INSERT INTO " . col_quote($table) . "
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function col_update_dynamic(PDO $pdo, string $table, int $id, array $data): void
{
    $sets = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!col_column_exists($pdo, $table, $column)) {
            continue;
        }

        $sets[] = col_quote($column) . ' = ?';
        $params[] = $value;
    }

    if (!$sets) {
        throw new RuntimeException('No hay columnas compatibles para actualizar el colegio.');
    }

    $params[] = $id;

    $sql = "
        UPDATE " . col_quote($table) . "
        SET " . implode(', ', $sets) . "
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function col_payload_desde_post(int $userId): array
{
    $planCodigo = col_clean((string)($_POST['plan_codigo'] ?? 'base')) ?? 'base';

    $estadoComercial = col_clean((string)($_POST['estado_comercial'] ?? 'activo')) ?? 'activo';
    $permitidosEstado = ['activo', 'demo', 'suspendido', 'vencido', 'cerrado'];

    if (!in_array($estadoComercial, $permitidosEstado, true)) {
        $estadoComercial = 'activo';
    }

    $precio = (float)str_replace(',', '.', (string)($_POST['precio_uf_mensual'] ?? '0'));
    $nombre = col_upper((string)($_POST['nombre'] ?? ''));

    if ($nombre === null) {
        throw new RuntimeException('Debe indicar el nombre del colegio.');
    }

    $rbd = col_upper((string)($_POST['rbd'] ?? ''));
    if ($rbd === null) {
        throw new RuntimeException('Debe indicar el RBD del colegio.');
    }

    return [
        'nombre' => $nombre,
        'rbd' => $rbd,
        'rut_entidad' => col_upper((string)($_POST['rut_sostenedor'] ?? $_POST['rut_entidad'] ?? '')),
        'director_nombre' => col_upper((string)($_POST['director_nombre'] ?? '')),
        'contacto_comercial' => col_upper((string)($_POST['contacto_nombre'] ?? $_POST['contacto_comercial'] ?? '')),
        'email_comercial' => col_email((string)($_POST['contacto_email'] ?? $_POST['email_comercial'] ?? '')),
        'telefono_comercial' => col_upper((string)($_POST['contacto_telefono'] ?? $_POST['telefono_comercial'] ?? '')),
        'direccion' => col_upper((string)($_POST['direccion'] ?? '')),
        'comuna' => col_upper((string)($_POST['comuna'] ?? '')),
        'region' => col_upper((string)($_POST['region'] ?? '')),
        'plan' => $planCodigo,
        'precio_uf_mensual' => $precio,
        'fecha_vencimiento' => col_date((string)($_POST['fecha_vencimiento'] ?? '')),
        'estado_comercial' => $estadoComercial,
        'activo' => isset($_POST['activo']) ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

if (!col_table_exists($pdo, 'colegios')) {
    http_response_code(500);
    exit('La tabla colegios no existe. Ejecuta primero la Fase 0.5.33A.');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'crear') {
            $data = col_payload_desde_post($userId);
            $data['created_at'] = date('Y-m-d H:i:s');

            $pdo->beginTransaction();

            $nuevoId = col_insert_dynamic($pdo, 'colegios', $data);

            registrar_bitacora(
                'colegios',
                'crear_colegio',
                'colegios',
                $nuevoId,
                'Colegio creado: ' . (string)$data['nombre']
            );

            $pdo->commit();

            col_redirect('ok', 'Colegio creado correctamente.');
        }

        if ($accion === 'actualizar') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Colegio no válido.');
            }

            $data = col_payload_desde_post($userId);
            unset($data['creado_por']);

            $pdo->beginTransaction();

            col_update_dynamic($pdo, 'colegios', $id, $data);

            registrar_bitacora(
                'colegios',
                'actualizar_colegio',
                'colegios',
                $id,
                'Colegio actualizado: ' . (string)$data['nombre']
            );

            $pdo->commit();

            col_redirect('ok', 'Colegio actualizado correctamente.', $id);
        }

        if ($accion === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $nuevoActivo = (int)($_POST['nuevo_activo'] ?? -1);

            if ($id <= 0 || !in_array($nuevoActivo, [0, 1], true)) {
                throw new RuntimeException('Estado no válido.');
            }

            $stmt = $pdo->prepare("SELECT nombre FROM colegios WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $nombre = (string)($stmt->fetchColumn() ?: 'Colegio');

            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("
                UPDATE colegios
                SET activo = ?,
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->execute([$nuevoActivo, $id]);

            registrar_bitacora(
                'colegios',
                $nuevoActivo === 1 ? 'activar_colegio' : 'inactivar_colegio',
                'colegios',
                $id,
                ($nuevoActivo === 1 ? 'Colegio activado: ' : 'Colegio inactivado: ') . $nombre
            );

            $pdo->commit();

            col_redirect(
                'ok',
                $nuevoActivo === 1 ? 'Colegio activado correctamente.' : 'Colegio inactivado correctamente.'
            );
        }

        throw new RuntimeException('Acción no válida.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    col_redirect('error', $e->getMessage());
}

$q = clean((string)($_GET['q'] ?? ''));
$filtroEstado = clean((string)($_GET['estado'] ?? 'todos'));
$filtroPlan = clean((string)($_GET['plan'] ?? 'todos'));
$editId = (int)($_GET['edit'] ?? 0);
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        nombre LIKE ?
        OR rbd LIKE ?
        OR comuna LIKE ?
        OR email_comercial LIKE ?
        OR rut_entidad LIKE ?
    )";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($filtroEstado !== 'todos') {
    if ($filtroEstado === 'activos') {
        $where[] = 'activo = 1';
    } elseif ($filtroEstado === 'inactivos') {
        $where[] = 'activo = 0';
    } elseif ($filtroEstado === 'vencidos') {
        $where[] = 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()';
    } elseif ($filtroEstado === 'por_vencer') {
        $where[] = 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    }
}

if ($filtroPlan !== 'todos') {
    $where[] = 'plan = ?';
    $params[] = $filtroPlan;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT *
    FROM colegios
    {$whereSql}
    ORDER BY activo DESC, nombre ASC
    LIMIT 300
");
$stmt->execute($params);
$colegios = $stmt->fetchAll();

$stmtEdit = null;
$editColegio = null;

if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM colegios WHERE id = ? LIMIT 1");
    $stmtEdit->execute([$editId]);
    $editColegio = $stmtEdit->fetch() ?: null;
}

$totalColegios = (int)$pdo->query("SELECT COUNT(*) FROM colegios")->fetchColumn();
$totalActivos = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE activo = 1")->fetchColumn();
$totalInactivos = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE activo = 0")->fetchColumn();
$totalVencidos = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()")->fetchColumn();
$totalPorVencer = (int)$pdo->query("SELECT COUNT(*) FROM colegios WHERE fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

$stmtMrr = $pdo->query("
    SELECT COALESCE(SUM(precio_uf_mensual), 0)
    FROM colegios
    WHERE activo = 1
      AND estado_comercial IN ('activo', 'demo')
");
$mrrUf = (float)$stmtMrr->fetchColumn();

$pageHeaderActions = metis_context_actions([
    metis_context_action('Administración', APP_URL . '/modules/admin/index.php', 'bi-gear', 'secondary'),
    metis_context_action('Usuarios', APP_URL . '/modules/admin/usuarios.php', 'bi-person-gear', 'primary'),
    metis_context_action('Dashboard', APP_URL . '/modules/dashboard/index.php', 'bi-speedometer2', 'secondary'),
    metis_context_action('Centro de control', APP_URL . '/modules/admin/control_proyecto.php', 'bi-kanban', 'secondary'),
]);

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.col-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #0f766e 58%, #14b8a6 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.col-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.col-hero p {
    margin: 0;
    color: #ccfbf1;
    max-width: 900px;
    line-height: 1.55;
}

.col-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.col-btn {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    border-radius: 999px;
    padding: .62rem 1rem;
    font-size: .84rem;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,.28);
    color: #fff;
    background: rgba(255,255,255,.12);
}

.col-btn:hover {
    color: #fff;
}

.col-kpis {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.col-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.col-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.col-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.8rem;
    line-height: 1;
    margin-top: .35rem;
}

.col-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(380px, .95fr);
    gap: 1.2rem;
    align-items: start;
}

.col-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.col-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.col-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.col-panel-body {
    padding: 1.2rem;
}

.col-filter {
    display: grid;
    grid-template-columns: 1.2fr .75fr .75fr auto auto;
    gap: .8rem;
    align-items: end;
}

.col-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .85rem;
}

.col-field.full {
    grid-column: 1 / -1;
}

.col-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.col-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.col-submit,
.col-link,
.col-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    border: 0;
    background: #0f172a;
    color: #fff;
    border-radius: 999px;
    padding: .66rem 1rem;
    font-weight: 900;
    font-size: .84rem;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
}

.col-submit.green,
.col-link.green,
.col-action.green {
    background: #059669;
    color: #fff;
    border: 1px solid #10b981;
}

.col-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.col-action.red {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.col-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: .85rem;
}

.col-card-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
}

.col-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .25rem;
}

.col-meta {
    color: #64748b;
    font-size: .78rem;
    line-height: 1.4;
    margin-top: .25rem;
}

.col-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .24rem .62rem;
    font-size: .72rem;
    font-weight: 900;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    white-space: nowrap;
    margin: .12rem;
}

.col-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.col-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.col-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.col-badge.soft {
    background: #f8fafc;
    color: #475569;
}

.col-row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-top: .75rem;
}

.col-row-actions form {
    margin: 0;
}

.col-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.col-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.col-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.col-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}

@media (max-width: 1250px) {
    .col-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .col-layout {
        grid-template-columns: 1fr;
    }

    .col-filter {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 720px) {
    .col-kpis,
    .col-filter,
    .col-form {
        grid-template-columns: 1fr;
    }

    .col-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="col-hero">
    <h2>Administración de colegios</h2>
    <p>
        Gestión central de establecimientos, planes comerciales, límites operativos,
        fechas de vigencia, contactos institucionales y estado de activación.
    </p>


</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="col-msg ok"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="col-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<section class="col-kpis">
    <div class="col-kpi">
        <span>Total colegios</span>
        <strong><?= number_format($totalColegios, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Activos</span>
        <strong style="color:#047857;"><?= number_format($totalActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Inactivos</span>
        <strong><?= number_format($totalInactivos, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Vencidos</span>
        <strong style="color:#b91c1c;"><?= number_format($totalVencidos, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>Por vencer</span>
        <strong style="color:#92400e;"><?= number_format($totalPorVencer, 0, ',', '.') ?></strong>
    </div>

    <div class="col-kpi">
        <span>MRR UF</span>
        <strong><?= number_format($mrrUf, 2, ',', '.') ?></strong>
    </div>
</section>

<div class="col-layout">
    <section>
        <div class="col-panel">
            <div class="col-panel-head">
                <h3 class="col-panel-title">
                    <i class="bi bi-funnel"></i>
                    Filtros
                </h3>
            </div>

            <div class="col-panel-body">
                <form method="get" class="col-filter">
                    <div>
                        <label class="col-label">Buscar</label>
                        <input
                            class="col-control"
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Nombre, RBD, comuna, sostenedor o correo"
                        >
                    </div>

                    <div>
                        <label class="col-label">Estado</label>
                        <select class="col-control" name="estado">
                            <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="activos" <?= $filtroEstado === 'activos' ? 'selected' : '' ?>>Activos</option>
                            <option value="inactivos" <?= $filtroEstado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                            <option value="vencidos" <?= $filtroEstado === 'vencidos' ? 'selected' : '' ?>>Vencidos</option>
                            <option value="por_vencer" <?= $filtroEstado === 'por_vencer' ? 'selected' : '' ?>>Por vencer</option>
                        </select>
                    </div>

                    <div>
                        <label class="col-label">Plan</label>
                        <select class="col-control" name="plan">
                            <option value="todos" <?= $filtroPlan === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="demo" <?= $filtroPlan === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="base" <?= $filtroPlan === 'base' ? 'selected' : '' ?>>Base</option>
                            <option value="profesional" <?= $filtroPlan === 'profesional' ? 'selected' : '' ?>>Profesional</option>
                            <option value="enterprise" <?= $filtroPlan === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                        </select>
                    </div>

                    <div>
                        <button class="col-submit" type="submit">
                            <i class="bi bi-search"></i>
                            Filtrar
                        </button>
                    </div>

                    <div>
                        <a class="col-link" href="<?= APP_URL ?>/modules/colegios/index.php">
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-panel">
            <div class="col-panel-head">
                <h3 class="col-panel-title">
                    <i class="bi bi-building"></i>
                    Colegios registrados
                </h3>

                <span class="col-badge"><?= number_format(count($colegios), 0, ',', '.') ?> mostrado(s)</span>
            </div>

            <div class="col-panel-body">
                <?php if (!$colegios): ?>
                    <div class="col-empty">
                        No hay colegios registrados con los filtros actuales.
                    </div>
                <?php else: ?>
                    <?php foreach ($colegios as $colegio): ?>
                        <?php
                        [$vencimientoTexto, $vencimientoClass] = col_estado_vencimiento($colegio['fecha_vencimiento'] ?? null);
                        $estaActivo = (int)($colegio['activo'] ?? 1) === 1;
                        $nuevoActivo = $estaActivo ? 0 : 1;
                        $colegioId = (int)$colegio['id'];
                        $usuariosColegio = col_count_by_colegio($pdo, 'usuarios', $colegioId);
                        $alumnosColegio = col_count_by_colegio($pdo, 'alumnos', $colegioId);
                        $casosColegio = col_count_by_colegio($pdo, 'casos', $colegioId);
                        ?>

                        <article class="col-card">
                            <div class="col-card-head">
                                <div>
                                    <div class="col-card-title">
                                        <?= e((string)$colegio['nombre']) ?>
                                    </div>

                                    <div class="col-meta">
                                        RBD: <?= e(col_pick($colegio, 'rbd')) ?> ·
                                        Comuna: <?= e(col_pick($colegio, 'comuna')) ?> ·
                                        Región: <?= e(col_pick($colegio, 'region')) ?>
                                    </div>
                                </div>

                                <div>
                                    <span class="col-badge <?= $estaActivo ? 'ok' : 'danger' ?>">
                                        <?= $estaActivo ? 'Activo' : 'Inactivo' ?>
                                    </span>

                                    <span class="col-badge <?= e($vencimientoClass) ?>">
                                        <?= e($vencimientoTexto) ?>
                                    </span>
                                </div>
                            </div>

                            <div style="margin-top:.6rem;">
                                <span class="col-badge soft">
                                    Plan: <?= e(col_pick($colegio, 'plan', 'Plan Base')) ?>
                                </span>

                                <span class="col-badge soft">
                                    UF mensual: <?= number_format((float)($colegio['precio_uf_mensual'] ?? 0), 2, ',', '.') ?>
                                </span>

                                <span class="col-badge soft">
                                    Usuarios: <?= number_format($usuariosColegio, 0, ',', '.') ?> / <?= number_format((int)($colegio['max_usuarios'] ?? 0), 0, ',', '.') ?>
                                </span>

                                <span class="col-badge soft">
                                    Alumnos: <?= number_format($alumnosColegio, 0, ',', '.') ?> / <?= number_format((int)($colegio['max_alumnos'] ?? 0), 0, ',', '.') ?>
                                </span>

                                <span class="col-badge soft">
                                    Casos: <?= number_format($casosColegio, 0, ',', '.') ?>
                                </span>
                            </div>

                            <div class="col-meta">
                                Contacto: <?= e(col_pick($colegio, 'contacto_comercial')) ?> ·
                                <?= e(col_pick($colegio, 'email_comercial')) ?> ·
                                <?= e(col_pick($colegio, 'telefono_comercial')) ?>
                            </div>

                            <div class="col-meta">
                                Vigencia:
                                <?= e('-') ?>
                                al
                                <?= e(col_fecha($colegio['fecha_vencimiento'] ?? null)) ?>
                                · Estado comercial:
                                <?= e((string)($colegio['estado_comercial'] ?? 'activo')) ?>
                            </div>

                            <div class="col-row-actions">
                                <a class="col-link" href="<?= APP_URL ?>/modules/colegios/index.php?edit=<?= (int)$colegio['id'] ?>">
                                    <i class="bi bi-pencil-square"></i>
                                    Editar
                                </a>

                                <a class="col-link" href="<?= APP_URL ?>/modules/admin/usuarios.php?colegio_id=<?= (int)$colegio['id'] ?>">
                                    <i class="bi bi-person-gear"></i>
                                    Usuarios
                                </a>

                                <form method="post">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="_accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$colegio['id'] ?>">
                                    <input type="hidden" name="nuevo_activo" value="<?= (int)$nuevoActivo ?>">

                                    <button
                                        class="col-action <?= $estaActivo ? 'red' : 'green' ?>"
                                        type="submit"
                                        onclick="return confirm('¿Confirmas <?= $estaActivo ? 'inactivar' : 'activar' ?> este colegio?');"
                                    >
                                        <i class="bi <?= $estaActivo ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                        <?= $estaActivo ? 'Inactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="col-panel">
            <div class="col-panel-head">
                <h3 class="col-panel-title">
                    <i class="bi <?= $editColegio ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                    <?= $editColegio ? 'Editar colegio' : 'Nuevo colegio' ?>
                </h3>

                <?php if ($editColegio): ?>
                    <a class="col-link" href="<?= APP_URL ?>/modules/colegios/index.php">
                        Nuevo
                    </a>
                <?php endif; ?>
            </div>

            <div class="col-panel-body">
                <form method="post" class="col-form">
                    <?= CSRF::field() ?>

                    <input type="hidden" name="_accion" value="<?= $editColegio ? 'actualizar' : 'crear' ?>">

                    <?php if ($editColegio): ?>
                        <input type="hidden" name="id" value="<?= (int)$editColegio['id'] ?>">
                    <?php endif; ?>

                    <div class="col-field full">
                        <label class="col-label">Nombre del colegio *</label>
                        <input
                            class="col-control"
                            type="text"
                            name="nombre"
                            value="<?= e((string)($editColegio['nombre'] ?? '')) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label class="col-label">RBD</label>
                        <input class="col-control" type="text" name="rbd" value="<?= e((string)($editColegio['rbd'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">RUT sostenedor</label>
                        <input class="col-control" type="text" name="rut_sostenedor" value="<?= e((string)($editColegio['rut_entidad'] ?? '')) ?>">
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Sostenedor</label>
                        <input class="col-control" type="text" name="sostenedor_nombre" value="<?= e((string)($editColegio['nombre'] ?? '')) ?>">
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Director/a</label>
                        <input class="col-control" type="text" name="director_nombre" value="<?= e((string)($editColegio['director_nombre'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Contacto</label>
                        <input class="col-control" type="text" name="contacto_nombre" value="<?= e((string)($editColegio['contacto_comercial'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Correo contacto</label>
                        <input class="col-control" type="email" name="contacto_email" value="<?= e((string)($editColegio['email_comercial'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Teléfono contacto</label>
                        <input class="col-control" type="text" name="contacto_telefono" value="<?= e((string)($editColegio['telefono_comercial'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Comuna</label>
                        <input class="col-control" type="text" name="comuna" value="<?= e((string)($editColegio['comuna'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Región</label>
                        <input class="col-control" type="text" name="region" value="<?= e((string)($editColegio['region'] ?? '')) ?>">
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Dirección</label>
                        <input class="col-control" type="text" name="direccion" value="<?= e((string)($editColegio['direccion'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Plan</label>
                        <?php $planActual = (string)($editColegio['plan'] ?? 'base'); ?>
                        <select class="col-control" name="plan_codigo">
                            <option value="demo" <?= $planActual === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="base" <?= $planActual === 'base' ? 'selected' : '' ?>>Base</option>
                            <option value="profesional" <?= $planActual === 'profesional' ? 'selected' : '' ?>>Profesional</option>
                            <option value="enterprise" <?= $planActual === 'enterprise' ? 'selected' : '' ?>>Enterprise</option>
                        </select>
                    </div>

                    <div>
                        <label class="col-label">UF mensual</label>
                        <input
                            class="col-control"
                            type="number"
                            step="0.01"
                            min="0"
                            name="precio_uf_mensual"
                            value="<?= e((string)($editColegio['precio_uf_mensual'] ?? '0')) ?>"
                        >
                    </div>

                    <div>
                        <label class="col-label">Máx. usuarios</label>
                        <input class="col-control" type="number" min="1" name="max_usuarios" value="<?= e((string)($editColegio['max_usuarios'] ?? '10')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Máx. alumnos</label>
                        <input class="col-control" type="number" min="1" name="max_alumnos" value="<?= e((string)($editColegio['max_alumnos'] ?? '1000')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Fecha inicio</label>
                        <input class="col-control" type="date" name="fecha_inicio" value="<?= e('') ?>">
                    </div>

                    <div>
                        <label class="col-label">Fecha vencimiento</label>
                        <input class="col-control" type="date" name="fecha_vencimiento" value="<?= e((string)($editColegio['fecha_vencimiento'] ?? '')) ?>">
                    </div>

                    <div>
                        <label class="col-label">Estado comercial</label>
                        <?php $estadoComercial = (string)($editColegio['estado_comercial'] ?? 'activo'); ?>
                        <select class="col-control" name="estado_comercial">
                            <option value="activo" <?= $estadoComercial === 'activo' ? 'selected' : '' ?>>Activo</option>
                            <option value="demo" <?= $estadoComercial === 'demo' ? 'selected' : '' ?>>Demo</option>
                            <option value="suspendido" <?= $estadoComercial === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
                            <option value="vencido" <?= $estadoComercial === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                            <option value="cerrado" <?= $estadoComercial === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                        </select>
                    </div>

                    <div>
                        <label class="col-label">Estado sistema</label>
                        <label style="display:flex;align-items:center;gap:.45rem;font-weight:900;color:#334155;margin-top:.7rem;">
                            <input
                                type="checkbox"
                                name="activo"
                                value="1"
                                <?= (int)($editColegio['activo'] ?? 1) === 1 ? 'checked' : '' ?>
                            >
                            Colegio activo
                        </label>
                    </div>

                    <div class="col-field full">
                        <label class="col-label">Observaciones</label>
                        <textarea class="col-control" name="observaciones" rows="4"><?= e((string)($editColegio['observaciones'] ?? '')) ?></textarea>
                    </div>

                    <div class="col-field full">
                        <button class="col-submit green" type="submit">
                            <i class="bi bi-save"></i>
                            <?= $editColegio ? 'Guardar cambios' : 'Crear colegio' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
