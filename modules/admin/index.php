<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/context_actions.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');

$puedeAdmin = in_array($rolCodigo, ['superadmin', 'director'], true)
    || Auth::can('admin_sistema');

if (!$puedeAdmin) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pageTitle = 'Administración · Metis';
$pageSubtitle = 'Centro administrativo del sistema, colegios, usuarios, controles y operación técnica';

function adm_table_exists(PDO $pdo, string $table): bool
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

function adm_column_exists(PDO $pdo, string $table, string $column): bool
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

function adm_count(PDO $pdo, string $table, ?string $where = null, array $params = []): int
{
    if (!adm_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`';

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

function adm_sum(PDO $pdo, string $table, string $column, ?string $where = null, array $params = []): float
{
    if (!adm_table_exists($pdo, $table) || !adm_column_exists($pdo, $table, $column)) {
        return 0.0;
    }

    try {
        $sql = 'SELECT COALESCE(SUM(`' . str_replace('`', '``', $column) . '`), 0) FROM `' . str_replace('`', '``', $table) . '`';

        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

$totalColegios = adm_count($pdo, 'colegios');
$totalColegiosActivos = adm_count($pdo, 'colegios', 'activo = 1');
$totalColegiosVencidos = adm_count($pdo, 'colegios', 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE()');
$totalColegiosPorVencer = adm_count($pdo, 'colegios', 'fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)');
$mrrUf = adm_sum($pdo, 'colegios', 'precio_uf_mensual', "activo = 1 AND estado_comercial IN ('activo', 'demo')");

$totalUsuarios = adm_count($pdo, 'usuarios');
$totalUsuariosActivos = adm_count($pdo, 'usuarios', 'activo = 1');
$totalRoles = adm_count($pdo, 'roles');
$totalPermisos = adm_count($pdo, 'permisos');
$totalEventosHoy = adm_count($pdo, 'logs_sistema', 'DATE(created_at) = CURDATE()');

$totalPruebas = adm_count($pdo, 'pruebas_integrales');
$pruebasOk = adm_count($pdo, 'pruebas_integrales', "estado = 'ok'");
$pruebasObservadas = adm_count($pdo, 'pruebas_integrales', "estado = 'observado'");

$totalPreprod = adm_count($pdo, 'checklist_preproduccion');
$preprodOk = adm_count($pdo, 'checklist_preproduccion', "estado = 'ok'");
$preprodObservados = adm_count($pdo, 'checklist_preproduccion', "estado = 'observado'");

$avancePruebas = $totalPruebas > 0 ? round(($pruebasOk / $totalPruebas) * 100) : 0;
$avancePreprod = $totalPreprod > 0 ? round(($preprodOk / $totalPreprod) * 100) : 0;

$bloqueadores = $pruebasObservadas + $preprodObservados + $totalColegiosVencidos;

$herramientas = [
    [
        'titulo' => 'Colegios',
        'texto' => 'Administrar establecimientos, planes, vigencias, límites y datos institucionales.',
        'icono' => 'bi-building',
        'url' => APP_URL . '/modules/colegios/index.php',
        'tag' => $totalColegios . ' colegio(s)',
        'class' => 'green',
    ],
    [
        'titulo' => 'Usuarios',
        'texto' => 'Crear usuarios, asignar colegio, rol y estado de acceso.',
        'icono' => 'bi-person-gear',
        'url' => APP_URL . '/modules/admin/usuarios.php',
        'tag' => $totalUsuarios . ' usuario(s)',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Panel financiero',
        'texto' => 'Controlar MRR, ARR, colegios vencidos, planes y riesgo comercial.',
        'icono' => 'bi-cash-coin',
        'url' => APP_URL . '/modules/admin/financiero.php',
        'tag' => 'MRR ' . number_format($mrrUf, 2, ',', '.') . ' UF',
        'class' => $mrrUf > 0 ? 'green' : 'blue',
    ],
    [
        'titulo' => 'Centro de control',
        'texto' => 'Ver avance global, bloqueadores, pruebas integrales y preproducción.',
        'icono' => 'bi-kanban',
        'url' => APP_URL . '/modules/admin/control_proyecto.php',
        'tag' => $bloqueadores . ' alerta(s)',
        'class' => $bloqueadores > 0 ? 'warn' : 'green',
    ],
    [
        'titulo' => 'Pruebas integrales',
        'texto' => 'Controlar validación funcional por área antes de pasar a producción.',
        'icono' => 'bi-clipboard2-check',
        'url' => APP_URL . '/modules/admin/pruebas_integrales.php',
        'tag' => $avancePruebas . '% avance',
        'class' => $avancePruebas >= 80 ? 'green' : 'blue',
    ],
    [
        'titulo' => 'Checklist preproducción',
        'texto' => 'Controlar hosting, base de datos, SSL, respaldos, seguridad y operación.',
        'icono' => 'bi-rocket-takeoff',
        'url' => APP_URL . '/modules/admin/preproduccion.php',
        'tag' => $avancePreprod . '% avance',
        'class' => $avancePreprod >= 80 ? 'green' : 'blue',
    ],
    [
        'titulo' => 'Diagnóstico técnico',
        'texto' => 'Revisar rutas rotas, código antiguo, tablas críticas y salud general.',
        'icono' => 'bi-shield-check',
        'url' => APP_URL . '/modules/admin/diagnostico.php',
        'tag' => 'Control',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Respaldo SQL',
        'texto' => 'Generar respaldo descargable de la base activa antes de cambios críticos.',
        'icono' => 'bi-database-down',
        'url' => APP_URL . '/modules/admin/respaldo.php',
        'tag' => 'Backup',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Manual operativo',
        'texto' => 'Guía interna de uso del sistema para operación, reportes y administración.',
        'icono' => 'bi-journal-text',
        'url' => APP_URL . '/modules/admin/manual_usuario.php',
        'tag' => 'Manual',
        'class' => 'green',
    ],
    [
        'titulo' => 'Roles',
        'texto' => 'Revisar perfiles disponibles y permisos generales del sistema.',
        'icono' => 'bi-person-badge',
        'url' => APP_URL . '/modules/roles/index.php',
        'tag' => $totalRoles . ' rol(es)',
        'class' => 'blue',
    ],
    [
        'titulo' => 'Permisos',
        'texto' => 'Administrar matriz de permisos por rol para controlar acciones críticas.',
        'icono' => 'bi-sliders',
        'url' => APP_URL . '/modules/admin/permisos.php',
        'tag' => $totalPermisos . ' permiso(s)',
        'class' => 'warn',
    ],
    [
        'titulo' => 'Auditoría',
        'texto' => 'Consultar bitácora de eventos, cambios y trazabilidad operacional.',
        'icono' => 'bi-activity',
        'url' => APP_URL . '/modules/auditoria/index.php',
        'tag' => $totalEventosHoy . ' hoy',
        'class' => 'blue',
    ],
];

$pageHeaderActions = metis_context_actions([
    metis_context_action('Colegios', APP_URL . '/modules/colegios/index.php', 'bi-building', 'primary'),
    metis_context_action('Usuarios', APP_URL . '/modules/admin/usuarios.php', 'bi-person-gear', 'secondary'),
    metis_context_action('Financiero', APP_URL . '/modules/admin/financiero.php', 'bi-cash-coin', 'secondary'),
    metis_context_action('Permisos', APP_URL . '/modules/admin/permisos.php', 'bi-sliders', 'secondary'),
    metis_context_action('Centro de control', APP_URL . '/modules/admin/control_proyecto.php', 'bi-kanban', 'secondary'),
    metis_context_action('Diagnóstico', APP_URL . '/modules/admin/diagnostico.php', 'bi-shield-check', 'success'),
    metis_context_action('Dashboard', APP_URL . '/modules/dashboard/index.php', 'bi-speedometer2', 'secondary'),
]);

require_once dirname(__DIR__, 2) . '/core/layout_header.php';
?>

<style>
.adm-hero {
    background:
        radial-gradient(circle at 90% 16%, rgba(16,185,129,.22), transparent 28%),
        linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2563eb 100%);
    color: #fff;
    border-radius: 22px;
    padding: 2rem;
    margin-bottom: 1.2rem;
    box-shadow: 0 18px 45px rgba(15,23,42,.18);
}

.adm-hero h2 {
    margin: 0 0 .45rem;
    font-size: 1.9rem;
    font-weight: 900;
}

.adm-hero p {
    margin: 0;
    color: #bfdbfe;
    max-width: 920px;
    line-height: 1.55;
}

.adm-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    margin-top: 1rem;
}

.adm-btn {
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

.adm-btn:hover {
    color: #fff;
}

.adm-kpis {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: .9rem;
    margin-bottom: 1.2rem;
}

.adm-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
}

.adm-kpi span {
    color: #64748b;
    display: block;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.adm-kpi strong {
    display: block;
    color: #0f172a;
    font-size: 1.8rem;
    line-height: 1;
    margin-top: .35rem;
}

.adm-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 1.2rem;
}

.adm-panel-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.adm-panel-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.adm-panel-body {
    padding: 1.2rem;
}

.adm-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .9rem;
}

.adm-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: .9rem;
    align-items: start;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem;
    text-decoration: none;
    color: inherit;
}

.adm-card:hover {
    background: #f1f5f9;
}

.adm-icon {
    width: 46px;
    height: 46px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    font-size: 1.25rem;
}

.adm-icon.green {
    background: #ecfdf5;
    color: #047857;
}

.adm-icon.blue {
    background: #eff6ff;
    color: #1d4ed8;
}

.adm-icon.warn {
    background: #fffbeb;
    color: #92400e;
}

.adm-card-title {
    color: #0f172a;
    font-weight: 900;
    margin-bottom: .2rem;
}

.adm-card-text {
    color: #64748b;
    font-size: .8rem;
    line-height: 1.4;
}

.adm-badge {
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
    margin-top: .5rem;
}

.adm-badge.green {
    background: #ecfdf5;
    border-color: #bbf7d0;
    color: #047857;
}

.adm-badge.blue {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.adm-badge.warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.adm-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 16px;
    padding: 1rem;
    line-height: 1.5;
    font-size: .9rem;
}

@media (max-width: 1200px) {
    .adm-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .adm-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .adm-kpis,
    .adm-grid {
        grid-template-columns: 1fr;
    }

    .adm-hero {
        padding: 1.35rem;
    }
}
</style>

<section class="adm-hero">
    <h2>Hub de administración</h2>
    <p>
        Centro de control administrativo para colegios, usuarios, roles, pruebas integrales,
        preproducción, diagnóstico, respaldos, auditoría y documentación operativa.
    </p>


</section>

<section class="adm-kpis">
    <div class="adm-kpi">
        <span>Colegios</span>
        <strong><?= number_format($totalColegios, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Colegios activos</span>
        <strong style="color:#047857;"><?= number_format($totalColegiosActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Vencidos</span>
        <strong style="color:#b91c1c;"><?= number_format($totalColegiosVencidos, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Por vencer</span>
        <strong style="color:#92400e;"><?= number_format($totalColegiosPorVencer, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Usuarios activos</span>
        <strong><?= number_format($totalUsuariosActivos, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>MRR UF</span>
        <strong><?= number_format($mrrUf, 2, ',', '.') ?></strong>
    </div>
</section>

<section class="adm-kpis">
    <div class="adm-kpi">
        <span>Pruebas integrales</span>
        <strong><?= number_format($avancePruebas, 0, ',', '.') ?>%</strong>
    </div>

    <div class="adm-kpi">
        <span>Preproducción</span>
        <strong><?= number_format($avancePreprod, 0, ',', '.') ?>%</strong>
    </div>

    <div class="adm-kpi">
        <span>Observados</span>
        <strong style="color:<?= $bloqueadores > 0 ? '#b91c1c' : '#047857' ?>;">
            <?= number_format($bloqueadores, 0, ',', '.') ?>
        </strong>
    </div>

    <div class="adm-kpi">
        <span>Usuarios</span>
        <strong><?= number_format($totalUsuarios, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Roles</span>
        <strong><?= number_format($totalRoles, 0, ',', '.') ?></strong>
    </div>

    <div class="adm-kpi">
        <span>Eventos hoy</span>
        <strong><?= number_format($totalEventosHoy, 0, ',', '.') ?></strong>
    </div>
</section>

<section class="adm-panel">
    <div class="adm-panel-head">
        <h3 class="adm-panel-title">
            <i class="bi bi-grid"></i>
            Herramientas administrativas
        </h3>
    </div>

    <div class="adm-panel-body">
        <div class="adm-grid">
            <?php foreach ($herramientas as $item): ?>
                <a class="adm-card" href="<?= e($item['url']) ?>">
                    <div class="adm-icon <?= e($item['class']) ?>">
                        <i class="bi <?= e($item['icono']) ?>"></i>
                    </div>

                    <div>
                        <div class="adm-card-title"><?= e($item['titulo']) ?></div>
                        <div class="adm-card-text"><?= e($item['texto']) ?></div>
                        <span class="adm-badge <?= e($item['class']) ?>">
                            <?= e($item['tag']) ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="adm-panel">
    <div class="adm-panel-head">
        <h3 class="adm-panel-title">
            <i class="bi bi-info-circle"></i>
            Recomendación de uso
        </h3>
    </div>

    <div class="adm-panel-body">
        <div class="adm-note">
            Antes de pasar a producción, mantén el siguiente orden: colegios configurados,
            usuarios y roles revisados, pruebas integrales ejecutadas, checklist de preproducción
            validado, diagnóstico técnico en cero y respaldo SQL generado.
        </div>
    </div>
</section>

<?php require_once dirname(__DIR__, 2) . '/core/layout_footer.php'; ?>
