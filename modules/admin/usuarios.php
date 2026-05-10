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
$userId = (int)($user['id'] ?? 0);

$puedeAdministrar = in_array($rolCodigoActual, ['superadmin'], true) || Auth::can('admin_sistema');

if (!$puedeAdministrar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Usuarios · Metis';
$pageSubtitle = 'Administración de usuarios, colegios asignados, roles y estado de acceso';

function usr_table_exists(PDO $pdo, string $table): bool
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

function usr_column_exists(PDO $pdo, string $table, string $column): bool
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

function usr_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function usr_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function usr_upper(?string $value): ?string
{
    $value = usr_clean($value);
    return $value === null ? null : mb_strtoupper($value, 'UTF-8');
}

function usr_email(?string $value): ?string
{
    $value = usr_clean($value);
    return $value === null ? null : mb_strtolower($value, 'UTF-8');
}

function usr_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}

function usr_redirect(string $status, string $msg, ?int $editId = null): void
{
    $url = APP_URL . '/modules/admin/usuarios.php?status=' . urlencode($status);
    $url .= '&msg=' . urlencode($msg);

    if ($editId !== null) {
        $url .= '&edit=' . $editId;
    }

    header('Location: ' . $url);
    exit;
}

function usr_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!usr_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = 'SELECT COUNT(*) FROM ' . usr_quote($table);

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

function usr_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!usr_column_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = usr_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para crear el usuario.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO " . usr_quote($table) . "
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function usr_update_dynamic(PDO $pdo, string $table, int $id, array $data): void
{
    $sets = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!usr_column_exists($pdo, $table, $column)) {
            continue;
        }

        $sets[] = usr_quote($column) . ' = ?';
        $params[] = $value;
    }

    if (!$sets) {
        throw new RuntimeException('No hay columnas compatibles para actualizar el usuario.');
    }

    $params[] = $id;

    $stmt = $pdo->prepare("
        UPDATE " . usr_quote($table) . "
        SET " . implode(', ', $sets) . "
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute($params);
}

function usr_roles(PDO $pdo): array
{
    if (!usr_table_exists($pdo, 'roles')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT id, codigo, nombre, activo
        FROM roles
        ORDER BY activo DESC, nombre ASC
    ");

    return $stmt->fetchAll();
}

function usr_colegios(PDO $pdo): array
{
    if (!usr_table_exists($pdo, 'colegios')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT id, nombre, activo
        FROM colegios
        ORDER BY activo DESC, nombre ASC
    ");

    return $stmt->fetchAll();
}

function usr_role_by_id(PDO $pdo, int $rolId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
    $stmt->execute([$rolId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function usr_email_duplicado(PDO $pdo, string $email, ?int $exceptId = null): bool
{
    if (!usr_column_exists($pdo, 'usuarios', 'email')) {
        return false;
    }

    if ($exceptId !== null) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $exceptId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
    }

    return (int)$stmt->fetchColumn() > 0;
}

function usr_password_payload(string $plain): array
{
    $plain = trim($plain);

    if ($plain === '') {
        return [];
    }

    $hash = password_hash($plain, PASSWORD_DEFAULT);

    return [
        'password_hash' => $hash,
        'clave_hash' => $hash,
        'password' => $hash,
        'clave' => $hash,
    ];
}

function usr_payload_desde_post(PDO $pdo, bool $creating): array
{
    $nombre = usr_upper((string)($_POST['nombre'] ?? ''));
    $email = usr_email((string)($_POST['email'] ?? ''));

    if ($nombre === null) {
        throw new RuntimeException('Debe indicar el nombre del usuario.');
    }

    if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Debe indicar un correo válido.');
    }

    $colegioIdRaw = (int)($_POST['colegio_id'] ?? 0);
    $rolId = (int)($_POST['rol_id'] ?? 0);

    if ($colegioIdRaw <= 0) {
        throw new RuntimeException('Debe seleccionar un colegio para el usuario.');
    }

    if ($rolId <= 0) {
        throw new RuntimeException('Debe seleccionar un rol.');
    }

    $rol = usr_role_by_id($pdo, $rolId);

    if (!$rol) {
        throw new RuntimeException('El rol seleccionado no existe.');
    }

    $rolCodigo = (string)($rol['codigo'] ?? '');

    $data = [
        'nombre' => $nombre,
        'email' => $email,
        'colegio_id' => $colegioIdRaw,
        'rol_id' => $rolId,
        'rol_codigo' => $rolCodigo,
        'activo' => isset($_POST['activo']) ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $password = (string)($_POST['password'] ?? '');

    if ($creating && trim($password) === '') {
        $password = substr(bin2hex(random_bytes(5)), 0, 10);
    }

    foreach (usr_password_payload($password) as $column => $value) {
        if (usr_column_exists($pdo, 'usuarios', $column)) {
            $data[$column] = $value;
        }
    }

    if ($creating) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }

    return $data;
}

if (!usr_table_exists($pdo, 'usuarios') || !usr_table_exists($pdo, 'roles')) {
    http_response_code(500);
    exit('Faltan las tablas usuarios o roles. Ejecuta primero la Fase 0.5.33D-1.');
}

$roles = usr_roles($pdo);
$colegios = usr_colegios($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::requireValid($_POST['_token'] ?? null);

        $accion = clean((string)($_POST['_accion'] ?? ''));

        if ($accion === 'crear') {
            $data = usr_payload_desde_post($pdo, true);
            $email = (string)$data['email'];

            if (usr_email_duplicado($pdo, $email)) {
                throw new RuntimeException('Ya existe un usuario con ese correo.');
            }

            $pdo->beginTransaction();

            $nuevoId = usr_insert_dynamic($pdo, 'usuarios', $data);

            registrar_bitacora(
                'admin',
                'crear_usuario',
                'usuarios',
                $nuevoId,
                'Usuario creado: ' . $email
            );

            $pdo->commit();

            usr_redirect('ok', 'Usuario creado correctamente.');
        }

        if ($accion === 'actualizar') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Usuario no válido.');
            }

            $data = usr_payload_desde_post($pdo, false);
            $email = (string)$data['email'];

            if (usr_email_duplicado($pdo, $email, $id)) {
                throw new RuntimeException('Ya existe otro usuario con ese correo.');
            }

            $pdo->beginTransaction();

            usr_update_dynamic($pdo, 'usuarios', $id, $data);

            registrar_bitacora(
                'admin',
                'actualizar_usuario',
                'usuarios',
                $id,
                'Usuario actualizado: ' . $email
            );

            $pdo->commit();

            usr_redirect('ok', 'Usuario actualizado correctamente.', $id);
        }

        if ($accion === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $nuevoActivo = (int)($_POST['nuevo_activo'] ?? -1);

            if ($id <= 0 || !in_array($nuevoActivo, [0, 1], true)) {
                throw new RuntimeException('Estado de usuario no válido.');
            }

            if ($id === $userId && $nuevoActivo === 0) {
                throw new RuntimeException('No puedes inactivar tu propio usuario desde esta pantalla.');
            }

            $stmt = $pdo->prepare("SELECT email FROM usuarios WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $email = (string)($stmt->fetchColumn() ?: 'usuario');

            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("
                UPDATE usuarios
                SET activo = ?,
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $stmtUpdate->execute([$nuevoActivo, $id]);

            registrar_bitacora(
                'admin',
                $nuevoActivo === 1 ? 'activar_usuario' : 'inactivar_usuario',
                'usuarios',
                $id,
                ($nuevoActivo === 1 ? 'Usuario activado: ' : 'Usuario inactivado: ') . $email
            );

            $pdo->commit();

            usr_redirect(
                'ok',
                $nuevoActivo === 1 ? 'Usuario activado correctamente.' : 'Usuario inactivado correctamente.'
            );
        }

        throw new RuntimeException('Acción no válida.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    usr_redirect('error', $e->getMessage());
}

$q = clean((string)($_GET['q'] ?? ''));
$filtroColegio = (int)($_GET['colegio_id'] ?? 0);
$filtroRol = (int)($_GET['rol_id'] ?? 0);
$filtroEstado = clean((string)($_GET['estado'] ?? 'todos'));
$editId = (int)($_GET['edit'] ?? 0);
$status = clean((string)($_GET['status'] ?? ''));
$msg = clean((string)($_GET['msg'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(u.nombre LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if ($filtroColegio > 0) {
    $where[] = 'u.colegio_id = ?';
    $params[] = $filtroColegio;
}

if ($filtroRol > 0) {
    $where[] = 'u.rol_id = ?';
    $params[] = $filtroRol;
}

if ($filtroEstado === 'activos') {
    $where[] = 'u.activo = 1';
} elseif ($filtroEstado === 'inactivos') {
    $where[] = 'u.activo = 0';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        u.*,
        c.nombre AS colegio_nombre,
        r.codigo AS rol_codigo_join,
        r.nombre AS rol_nombre
    FROM usuarios u
    LEFT JOIN colegios c ON c.id = u.colegio_id
    LEFT JOIN roles r ON r.id = u.rol_id
    {$whereSql}
    ORDER BY u.activo DESC, c.nombre ASC, r.nombre ASC, u.nombre ASC
    LIMIT 400
");
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

$editUsuario = null;

if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
    $stmtEdit->execute([$editId]);
    $editUsuario = $stmtEdit->fetch() ?: null;
}

$totalUsuarios = usr_count($pdo, 'usuarios');
$totalActivos = usr_count($pdo, 'usuarios', 'activo = 1');
$totalInactivos = usr_count($pdo, 'usuarios', 'activo = 0');
$totalSinColegio = usr_count($pdo, 'usuarios', 'colegio_id IS NULL');
$totalSinRol = usr_count($pdo, 'usuarios', 'rol_id IS NULL');

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.usr-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.usr-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.85rem;
    font-weight: 900;
}

.usr-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 900px;
    line-height: 1.55;
}

.usr-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.usr-btn {
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

.usr-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.usr-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.usr-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.usr-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.9rem;
    line-height: 1;
    margin-top: .35rem;
}

.usr-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.08fr) minmax(380px, .92fr);
    gap: 1.2rem;
    align-items: start;
}

.usr-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.usr-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.usr-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.usr-panel-body {
    padding: 1.2rem;
}

.usr-filter {
    display: grid;
    grid-template-columns: 1.15fr .9fr .75fr .7fr auto auto;
    gap: .8rem;
    align-items: end;
}

.usr-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .85rem;
}

.usr-field.full {
    grid-column: 1 / -1;
}

.usr-label {
    display: block;
    color: #334155;
    font-size: .76rem;
    font-weight: 900;
    margin-bottom: .35rem;
}

.usr-control {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 13px;
    padding: .66rem .78rem;
    outline: none;
    background: #fff;
    font-size: .9rem;
}

.usr-submit,
.usr-link,
.usr-action {
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

.usr-submit.green,
.usr-link.green,
.usr-action.green {
    background: #059669;
    color: #fff;
    border: 1px solid #10b981;
}

.usr-link {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.usr-action.red {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.usr-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    margin-bottom: .85rem;
}

.usr-card-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
}

.usr-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .25rem;
}

.usr-meta {
    color: #64748b;
    font-size: .78rem;
    line-height: 1.4;
    margin-top: .25rem;
}

.usr-badge {
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

.usr-badge.ok {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.usr-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.usr-badge.danger {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}

.usr-badge.blue {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.usr-row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-top: .75rem;
}

.usr-row-actions form {
    margin: 0;
}

.usr-msg {
    border-radius: 14px;
    padding: .9rem 1rem;
    margin-bottom: 1rem;
    font-weight: 800;
}

.usr-msg.ok {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.usr-msg.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.usr-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #94a3b8;
}

.usr-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 16px;
    padding: .9rem 1rem;
    line-height: 1.45;
    font-size: .86rem;
    margin-bottom: .85rem;
}

@media (max-width: 1300px) {
    .usr-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .usr-layout {
        grid-template-columns: 1fr;
    }

    .usr-filter {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 720px) {
    .usr-kpis,
    .usr-filter,
    .usr-form {
        grid-template-columns: 1fr;
    }

    .usr-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="usr-hero">
    <h2>Administración de usuarios</h2>
    <p>
        Gestiona usuarios del sistema, asigna colegio, rol y estado de acceso.
        Esta pantalla es de uso administrativo central.
    </p>

    <div class="usr-actions">
        <a class="usr-btn" href="<?= APP_URL ?>/modules/admin/index.php">
            <i class="bi bi-gear"></i>
            Administración
        </a>

        <a class="usr-btn" href="<?= APP_URL ?>/modules/colegios/index.php">
            <i class="bi bi-building"></i>
            Colegios
        </a>

        <a class="usr-btn" href="<?= APP_URL ?>/modules/roles/index.php">
            <i class="bi bi-person-badge"></i>
            Roles
        </a>
    </div>
</section>

<?php if ($status === 'ok' && $msg !== ''): ?>
    <div class="usr-msg ok"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($status === 'error' && $msg !== ''): ?>
    <div class="usr-msg error"><?= e($msg) ?></div>
<?php endif; ?>

<section class="usr-kpis">
    <div class="usr-kpi">
        <span>Total usuarios</span>
        <strong><?= number_format($totalUsuarios, 0, ',', '.') ?></strong>
    </div>

    <div class="usr-kpi">
        <span>Activos</span>
        <strong style="color:#047857;"><?= number_format($totalActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="usr-kpi">
        <span>Inactivos</span>
        <strong><?= number_format($totalInactivos, 0, ',', '.') ?></strong>
    </div>

    <div class="usr-kpi">
        <span>Sin colegio</span>
        <strong style="color:<?= $totalSinColegio > 0 ? '#92400e' : '#047857' ?>;">
            <?= number_format($totalSinColegio, 0, ',', '.') ?>
        </strong>
    </div>

    <div class="usr-kpi">
        <span>Sin rol</span>
        <strong style="color:<?= $totalSinRol > 0 ? '#b91c1c' : '#047857' ?>;">
            <?= number_format($totalSinRol, 0, ',', '.') ?>
        </strong>
    </div>
</section>

<div class="usr-layout">
    <section>
        <div class="usr-panel">
            <div class="usr-panel-head">
                <h3 class="usr-panel-title">
                    <i class="bi bi-funnel"></i>
                    Filtros
                </h3>
            </div>

            <div class="usr-panel-body">
                <form method="get" class="usr-filter">
                    <div>
                        <label class="usr-label">Buscar</label>
                        <input
                            class="usr-control"
                            type="text"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Nombre o correo"
                        >
                    </div>

                    <div>
                        <label class="usr-label">Colegio</label>
                        <select class="usr-control" name="colegio_id">
                            <option value="0">Todos</option>
                            <?php foreach ($colegios as $colegio): ?>
                                <option value="<?= (int)$colegio['id'] ?>" <?= $filtroColegio === (int)$colegio['id'] ? 'selected' : '' ?>>
                                    <?= e((string)$colegio['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="usr-label">Rol</label>
                        <select class="usr-control" name="rol_id">
                            <option value="0">Todos</option>
                            <?php foreach ($roles as $rol): ?>
                                <option value="<?= (int)$rol['id'] ?>" <?= $filtroRol === (int)$rol['id'] ? 'selected' : '' ?>>
                                    <?= e((string)$rol['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="usr-label">Estado</label>
                        <select class="usr-control" name="estado">
                            <option value="todos" <?= $filtroEstado === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="activos" <?= $filtroEstado === 'activos' ? 'selected' : '' ?>>Activos</option>
                            <option value="inactivos" <?= $filtroEstado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                        </select>
                    </div>

                    <div>
                        <button class="usr-submit" type="submit">
                            <i class="bi bi-search"></i>
                            Filtrar
                        </button>
                    </div>

                    <div>
                        <a class="usr-link" href="<?= APP_URL ?>/modules/admin/usuarios.php">
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="usr-panel">
            <div class="usr-panel-head">
                <h3 class="usr-panel-title">
                    <i class="bi bi-people"></i>
                    Usuarios registrados
                </h3>

                <span class="usr-badge"><?= number_format(count($usuarios), 0, ',', '.') ?> mostrado(s)</span>
            </div>

            <div class="usr-panel-body">
                <?php if (!$usuarios): ?>
                    <div class="usr-empty">
                        No hay usuarios con los filtros actuales.
                    </div>
                <?php else: ?>
                    <?php foreach ($usuarios as $usuario): ?>
                        <?php
                        $estaActivo = (int)($usuario['activo'] ?? 1) === 1;
                        $nuevoActivo = $estaActivo ? 0 : 1;
                        $esYo = (int)$usuario['id'] === $userId;
                        ?>

                        <article class="usr-card">
                            <div class="usr-card-head">
                                <div>
                                    <div class="usr-card-title">
                                        <?= e((string)($usuario['nombre'] ?? 'Sin nombre')) ?>
                                        <?= $esYo ? ' · Tú' : '' ?>
                                    </div>

                                    <div class="usr-meta">
                                        <?= e((string)($usuario['email'] ?? '-')) ?>
                                    </div>
                                </div>

                                <div>
                                    <span class="usr-badge <?= $estaActivo ? 'ok' : 'danger' ?>">
                                        <?= $estaActivo ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </div>
                            </div>

                            <div style="margin-top:.6rem;">
                                <span class="usr-badge blue">
                                    Colegio: <?= e((string)($usuario['colegio_nombre'] ?? 'Sin colegio')) ?>
                                </span>

                                <span class="usr-badge">
                                    Rol: <?= e((string)($usuario['rol_nombre'] ?? $usuario['rol_codigo_join'] ?? 'Sin rol')) ?>
                                </span>

                                <?php if (!empty($usuario['ultimo_acceso_at'])): ?>
                                    <span class="usr-badge">
                                        Último acceso: <?= e(usr_fecha((string)$usuario['ultimo_acceso_at'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="usr-meta">
                                Creado: <?= e(usr_fecha((string)($usuario['created_at'] ?? ''))) ?> ·
                                Actualizado: <?= e(usr_fecha((string)($usuario['updated_at'] ?? ''))) ?>
                            </div>

                            <div class="usr-row-actions">
                                <a class="usr-link" href="<?= APP_URL ?>/modules/admin/usuarios.php?edit=<?= (int)$usuario['id'] ?>">
                                    <i class="bi bi-pencil-square"></i>
                                    Editar
                                </a>

                                <?php if (!$esYo): ?>
                                    <form method="post">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="_accion" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
                                        <input type="hidden" name="nuevo_activo" value="<?= (int)$nuevoActivo ?>">

                                        <button
                                            class="usr-action <?= $estaActivo ? 'red' : 'green' ?>"
                                            type="submit"
                                            onclick="return confirm('¿Confirmas <?= $estaActivo ? 'inactivar' : 'activar' ?> este usuario?');"
                                        >
                                            <i class="bi <?= $estaActivo ? 'bi-pause-circle' : 'bi-check-circle' ?>"></i>
                                            <?= $estaActivo ? 'Inactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <aside>
        <div class="usr-panel">
            <div class="usr-panel-head">
                <h3 class="usr-panel-title">
                    <i class="bi <?= $editUsuario ? 'bi-pencil-square' : 'bi-person-plus' ?>"></i>
                    <?= $editUsuario ? 'Editar usuario' : 'Nuevo usuario' ?>
                </h3>

                <?php if ($editUsuario): ?>
                    <a class="usr-link" href="<?= APP_URL ?>/modules/admin/usuarios.php">
                        Nuevo
                    </a>
                <?php endif; ?>
            </div>

            <div class="usr-panel-body">
                <div class="usr-note">
                    Para la versión inicial, cada usuario queda asociado a un colegio y a un rol.
                    Más adelante podremos habilitar múltiples colegios por usuario.
                </div>

                <form method="post" class="usr-form" autocomplete="off">
                    <?= CSRF::field() ?>

                    <input type="hidden" name="_accion" value="<?= $editUsuario ? 'actualizar' : 'crear' ?>">

                    <?php if ($editUsuario): ?>
                        <input type="hidden" name="id" value="<?= (int)$editUsuario['id'] ?>">
                    <?php endif; ?>

                    <div class="usr-field full">
                        <label class="usr-label">Nombre *</label>
                        <input
                            class="usr-control"
                            type="text"
                            name="nombre"
                            value="<?= e((string)($editUsuario['nombre'] ?? '')) ?>"
                            required
                        >
                    </div>

                    <div class="usr-field full">
                        <label class="usr-label">Correo *</label>
                        <input
                            class="usr-control"
                            type="email"
                            name="email"
                            value="<?= e((string)($editUsuario['email'] ?? '')) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label class="usr-label">Colegio</label>
                        <?php $colegioActual = (int)($editUsuario['colegio_id'] ?? 0); ?>
                        <select class="usr-control" name="colegio_id">
                            <option value="0">Sin colegio / central</option>
                            <?php foreach ($colegios as $colegio): ?>
                                <option value="<?= (int)$colegio['id'] ?>" <?= $colegioActual === (int)$colegio['id'] ? 'selected' : '' ?>>
                                    <?= e((string)$colegio['nombre']) ?>
                                    <?= (int)($colegio['activo'] ?? 1) === 1 ? '' : ' (inactivo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="usr-label">Rol *</label>
                        <?php $rolActual = (int)($editUsuario['rol_id'] ?? 0); ?>
                        <select class="usr-control" name="rol_id" required>
                            <option value="">Seleccione</option>
                            <?php foreach ($roles as $rol): ?>
                                <option value="<?= (int)$rol['id'] ?>" <?= $rolActual === (int)$rol['id'] ? 'selected' : '' ?>>
                                    <?= e((string)$rol['nombre']) ?>
                                    <?= (int)($rol['activo'] ?? 1) === 1 ? '' : ' (inactivo)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="usr-field full">
                        <label class="usr-label">
                            Contraseña <?= $editUsuario ? '(opcional, solo si desea cambiarla)' : '(opcional)' ?>
                        </label>
                        <input
                            class="usr-control"
                            type="password"
                            name="password"
                            value=""
                            autocomplete="new-password"
                            placeholder="<?= $editUsuario ? 'Dejar vacío para mantener la actual' : 'Si queda vacío, se generará una interna si la tabla lo permite' ?>"
                        >
                    </div>

                    <div class="usr-field full">
                        <label style="display:flex;align-items:center;gap:.45rem;font-weight:900;color:#334155;">
                            <input
                                type="checkbox"
                                name="activo"
                                value="1"
                                <?= (int)($editUsuario['activo'] ?? 1) === 1 ? 'checked' : '' ?>
                            >
                            Usuario activo
                        </label>
                    </div>

                    <div class="usr-field full">
                        <button class="usr-submit green" type="submit">
                            <i class="bi bi-save"></i>
                            <?= $editUsuario ? 'Guardar cambios' : 'Crear usuario' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </aside>
</div>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>