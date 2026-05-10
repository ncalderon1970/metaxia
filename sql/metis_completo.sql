-- ============================================================
-- METIS - Sistema de Gestión de Convivencia Escolar
-- SQL Completo - 29 tablas
-- Generado desde conversación de desarrollo
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- LIMPIEZA PREVIA (orden inverso a dependencias)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS caso_alertas;
DROP TABLE IF EXISTS caso_seguimiento_avances;
DROP TABLE IF EXISTS caso_plan_intervencion;
DROP TABLE IF EXISTS caso_seguimiento;
DROP TABLE IF EXISTS aula_segura_procedimientos;
DROP TABLE IF EXISTS caso_evidencias;
DROP TABLE IF EXISTS caso_historial;
DROP TABLE IF EXISTS caso_declaraciones;
DROP TABLE IF EXISTS caso_participantes;
DROP TABLE IF EXISTS ia_consumo;
DROP TABLE IF EXISTS logs_sistema;
DROP TABLE IF EXISTS casos;
DROP TABLE IF EXISTS denuncia_aspectos;
DROP TABLE IF EXISTS denuncia_areas;
DROP TABLE IF EXISTS faltas;
DROP TABLE IF EXISTS alumno_apoderado;
DROP TABLE IF EXISTS alumnos;
DROP TABLE IF EXISTS apoderados;
DROP TABLE IF EXISTS docentes;
DROP TABLE IF EXISTS asistentes;
DROP TABLE IF EXISTS colegio_suscripciones;
DROP TABLE IF EXISTS colegio_modulos;
DROP TABLE IF EXISTS modulos_catalogo;
DROP TABLE IF EXISTS estado_caso;
DROP TABLE IF EXISTS rol_permiso;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS permisos;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS colegios;

-- ============================================================
-- 1. COLEGIOS
-- ============================================================
CREATE TABLE colegios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rbd VARCHAR(20) NOT NULL,
    rut_entidad VARCHAR(20) DEFAULT NULL,
    nombre VARCHAR(150) NOT NULL,
    logo_url VARCHAR(255) NULL,
    director_nombre VARCHAR(150) NULL,
    firma_url VARCHAR(255) NULL,
    dependencia VARCHAR(50) DEFAULT NULL,
    comuna VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    direccion VARCHAR(255) DEFAULT NULL,
    telefono VARCHAR(50) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_colegios_rbd (rbd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. ROLES
-- ============================================================
CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. PERMISOS
-- ============================================================
CREATE TABLE permisos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(80) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    modulo VARCHAR(80) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. USUARIOS
-- ============================================================
CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    rol_id INT UNSIGNED NOT NULL,
    run VARCHAR(20) NULL,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acceso DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_usuarios_colegio (colegio_id),
    KEY idx_usuarios_rol (rol_id),
    CONSTRAINT fk_usuarios_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. ROL_PERMISO
-- ============================================================
CREATE TABLE rol_permiso (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rol_id INT UNSIGNED NOT NULL,
    permiso_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_rol_permiso (rol_id, permiso_id),
    CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. ESTADO_CASO
-- ============================================================
CREATE TABLE estado_caso (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    orden_visual INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. ALUMNOS
-- ============================================================
CREATE TABLE alumnos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    run VARCHAR(12) NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellido_paterno VARCHAR(80) NOT NULL,
    apellido_materno VARCHAR(80) NULL,
    fecha_nacimiento DATE NULL,
    curso VARCHAR(50) NULL,
    genero VARCHAR(30) NULL,
    direccion VARCHAR(255) NULL,
    telefono VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    observacion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_baja DATETIME NULL,
    motivo_baja VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alumno_run_colegio (run, colegio_id),
    KEY idx_alumnos_colegio (colegio_id),
    KEY idx_alumnos_nombre (nombres, apellido_paterno),
    CONSTRAINT fk_alumnos_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. APODERADOS
-- ============================================================
CREATE TABLE apoderados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    run VARCHAR(12) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30) NULL,
    telefono_secundario VARCHAR(50) NULL,
    email VARCHAR(120) NULL,
    direccion VARCHAR(255) NULL,
    observacion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_baja DATETIME NULL,
    motivo_baja VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apoderado_run_colegio (run, colegio_id),
    KEY idx_apoderados_colegio (colegio_id),
    KEY idx_apoderados_nombre (nombre),
    CONSTRAINT fk_apoderados_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. ALUMNO_APODERADO
-- ============================================================
CREATE TABLE alumno_apoderado (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alumno_id INT UNSIGNED NOT NULL,
    apoderado_id INT UNSIGNED NOT NULL,
    tipo_relacion VARCHAR(50) DEFAULT NULL,
    es_titular TINYINT(1) NOT NULL DEFAULT 0,
    puede_retirar TINYINT(1) NOT NULL DEFAULT 0,
    recibe_notificaciones TINYINT(1) NOT NULL DEFAULT 1,
    vive_con_estudiante TINYINT(1) NOT NULL DEFAULT 0,
    observacion VARCHAR(255) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alumno_apoderado (alumno_id, apoderado_id),
    KEY idx_alumno_apoderado_alumno (alumno_id),
    KEY idx_alumno_apoderado_apoderado (apoderado_id),
    CONSTRAINT fk_rel_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rel_apoderado FOREIGN KEY (apoderado_id) REFERENCES apoderados(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. DOCENTES
-- ============================================================
CREATE TABLE docentes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    run VARCHAR(12) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(120) NULL,
    telefono VARCHAR(30) NULL,
    cargo VARCHAR(100) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_baja DATETIME NULL,
    motivo_baja VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_docente_run_colegio (run, colegio_id),
    KEY idx_docentes_colegio (colegio_id),
    KEY idx_docentes_nombre (nombre),
    CONSTRAINT fk_docentes_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. ASISTENTES
-- ============================================================
CREATE TABLE asistentes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    run VARCHAR(12) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    cargo VARCHAR(100) NULL,
    email VARCHAR(120) NULL,
    telefono VARCHAR(30) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_baja DATETIME NULL,
    motivo_baja VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asistente_run_colegio (run, colegio_id),
    KEY idx_asistentes_colegio (colegio_id),
    KEY idx_asistentes_nombre (nombre),
    CONSTRAINT fk_asistentes_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. MODULOS_CATALOGO
-- ============================================================
CREATE TABLE modulos_catalogo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    es_premium TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. COLEGIO_MODULOS
-- ============================================================
CREATE TABLE colegio_modulos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    modulo_codigo VARCHAR(60) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    fecha_activacion DATETIME NULL,
    fecha_expiracion DATETIME NULL,
    plan VARCHAR(60) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_colegio_modulo (colegio_id, modulo_codigo),
    CONSTRAINT fk_colegio_modulos_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. COLEGIO_SUSCRIPCIONES
-- ============================================================
CREATE TABLE colegio_suscripciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    modulo_codigo VARCHAR(60) NOT NULL,
    plan VARCHAR(60) NULL,
    precio DECIMAL(10,2) NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'vigente',
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_suscripciones_colegio (colegio_id),
    CONSTRAINT fk_suscripciones_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. FALTAS
-- ============================================================
CREATE TABLE faltas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(50) NULL,
    nombre VARCHAR(255) NOT NULL,
    gravedad VARCHAR(20) NOT NULL DEFAULT 'media',
    descripcion TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_faltas_colegio (colegio_id),
    CONSTRAINT fk_faltas_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. DENUNCIA_AREAS
-- ============================================================
CREATE TABLE denuncia_areas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NULL,
    nombre VARCHAR(180) NOT NULL,
    descripcion TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. DENUNCIA_ASPECTOS
-- ============================================================
CREATE TABLE denuncia_aspectos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(50) NULL,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_denuncia_aspectos_area (area_id),
    CONSTRAINT fk_denuncia_aspectos_area FOREIGN KEY (area_id) REFERENCES denuncia_areas(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. CASOS
-- ============================================================
CREATE TABLE casos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    numero_caso VARCHAR(50) NOT NULL,
    fecha_ingreso DATETIME NOT NULL,
    denunciante_nombre VARCHAR(150) NULL,
    es_anonimo TINYINT(1) NOT NULL DEFAULT 0,
    relato LONGTEXT NOT NULL,
    contexto VARCHAR(255) NULL,
    involucra_moviles TINYINT(1) NOT NULL DEFAULT 0,
    estado VARCHAR(30) NOT NULL DEFAULT 'abierto',
    estado_caso_id INT UNSIGNED NULL,
    falta_id INT UNSIGNED NULL,
    denuncia_aspecto_id INT UNSIGNED NULL,
    requiere_reanalisis_ia TINYINT(1) NOT NULL DEFAULT 0,
    semaforo VARCHAR(20) NOT NULL DEFAULT 'verde',
    prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
    creado_por INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_caso_colegio_numero (colegio_id, numero_caso),
    KEY idx_casos_colegio (colegio_id),
    KEY idx_casos_estado (estado_caso_id),
    KEY idx_casos_falta_id (falta_id),
    KEY idx_casos_aspecto (denuncia_aspecto_id),
    CONSTRAINT fk_casos_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_casos_estado_caso FOREIGN KEY (estado_caso_id) REFERENCES estado_caso(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_casos_falta FOREIGN KEY (falta_id) REFERENCES faltas(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_casos_denuncia_aspecto FOREIGN KEY (denuncia_aspecto_id) REFERENCES denuncia_aspectos(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_casos_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. AULA_SEGURA_PROCEDIMIENTOS
-- ============================================================
CREATE TABLE aula_segura_procedimientos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    aplica TINYINT(1) NOT NULL DEFAULT 0,
    causal VARCHAR(255) NULL,
    medida_cautelar_suspension TINYINT(1) NOT NULL DEFAULT 0,
    fecha_notificacion_suspension DATE NULL,
    fecha_limite_resolucion DATE NULL,
    fecha_notificacion_resolucion DATE NULL,
    fecha_limite_reconsideracion DATE NULL,
    reconsideracion_presentada TINYINT(1) NOT NULL DEFAULT 0,
    fecha_reconsideracion DATE NULL,
    estado VARCHAR(50) NOT NULL DEFAULT 'no_aplica',
    observaciones TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aula_segura_caso (caso_id),
    CONSTRAINT fk_aula_segura_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. CASO_PARTICIPANTES
-- ============================================================
CREATE TABLE caso_participantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    tipo_persona VARCHAR(30) NOT NULL,
    persona_id INT UNSIGNED NULL,
    nombre_referencial VARCHAR(150) NOT NULL DEFAULT 'NN',
    run VARCHAR(20) NOT NULL DEFAULT '0-0',
    identidad_confirmada TINYINT(1) NOT NULL DEFAULT 0,
    fecha_identificacion DATETIME NULL,
    identificado_por INT UNSIGNED NULL,
    rol_en_caso VARCHAR(50) NOT NULL,
    solicita_reserva_identidad TINYINT(1) NOT NULL DEFAULT 0,
    observacion_reserva VARCHAR(255) NULL,
    observacion VARCHAR(255) NULL,
    observacion_identificacion VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_participantes_caso (caso_id),
    CONSTRAINT fk_participantes_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_participantes_identificado_por FOREIGN KEY (identificado_por) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. CASO_DECLARACIONES
-- ============================================================
CREATE TABLE caso_declaraciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    participante_id INT UNSIGNED NULL,
    tipo_declarante VARCHAR(30) NOT NULL,
    nombre_declarante VARCHAR(150) NOT NULL,
    run_declarante VARCHAR(20) NULL,
    calidad_procesal VARCHAR(50) NOT NULL,
    fecha_declaracion DATETIME NOT NULL,
    texto_declaracion LONGTEXT NOT NULL,
    observaciones TEXT NULL,
    tomada_por INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_declaraciones_caso (caso_id),
    CONSTRAINT fk_declaraciones_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_declaraciones_participante FOREIGN KEY (participante_id) REFERENCES caso_participantes(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_declaraciones_usuario FOREIGN KEY (tomada_por) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 22. CASO_HISTORIAL
-- ============================================================
CREATE TABLE caso_historial (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    tipo_evento VARCHAR(50) NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    detalle TEXT NULL,
    user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_historial_caso (caso_id),
    CONSTRAINT fk_historial_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_historial_usuario FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 23. CASO_EVIDENCIAS
-- ============================================================
CREATE TABLE caso_evidencias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    descripcion VARCHAR(255) NULL,
    mime_type VARCHAR(120) NULL,
    tamano_bytes BIGINT NULL,
    subido_por INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_evidencias_caso (caso_id),
    CONSTRAINT fk_evidencias_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_evidencias_usuario FOREIGN KEY (subido_por) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 24. CASO_SEGUIMIENTO
-- ============================================================
CREATE TABLE caso_seguimiento (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    fecha_apertura DATETIME NOT NULL,
    objetivo_general TEXT NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'activo',
    responsable_general_id INT UNSIGNED NULL,
    fecha_cierre DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_caso_seguimiento_caso (caso_id),
    CONSTRAINT fk_seguimiento_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_seguimiento_responsable FOREIGN KEY (responsable_general_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 25. CASO_PLAN_INTERVENCION
-- ============================================================
CREATE TABLE caso_plan_intervencion (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    seguimiento_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    responsable_id INT UNSIGNED NULL,
    fecha_inicio DATE NULL,
    fecha_vencimiento DATE NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
    prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_plan_seg (seguimiento_id),
    CONSTRAINT fk_plan_seg FOREIGN KEY (seguimiento_id) REFERENCES caso_seguimiento(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_plan_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 26. CASO_SEGUIMIENTO_AVANCES
-- ============================================================
CREATE TABLE caso_seguimiento_avances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    descripcion TEXT NOT NULL,
    porcentaje_avance INT UNSIGNED NOT NULL DEFAULT 0,
    registrado_por INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_avances_plan (plan_id),
    CONSTRAINT fk_avances_plan FOREIGN KEY (plan_id) REFERENCES caso_plan_intervencion(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_avances_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 27. CASO_ALERTAS
-- ============================================================
CREATE TABLE caso_alertas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    caso_id INT UNSIGNED NOT NULL,
    seguimiento_id INT UNSIGNED NULL,
    plan_id INT UNSIGNED NULL,
    tipo VARCHAR(50) NOT NULL,
    mensaje TEXT NOT NULL,
    prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    fecha_alerta DATETIME NOT NULL,
    atendida_por INT UNSIGNED NULL,
    fecha_atendida DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_alertas_caso (caso_id),
    CONSTRAINT fk_alertas_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_alertas_seg FOREIGN KEY (seguimiento_id) REFERENCES caso_seguimiento(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_alertas_plan FOREIGN KEY (plan_id) REFERENCES caso_plan_intervencion(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_alertas_usuario FOREIGN KEY (atendida_por) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 28. IA_CONSUMO
-- ============================================================
CREATE TABLE ia_consumo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL,
    caso_id INT UNSIGNED NULL,
    tipo_analisis VARCHAR(60) NOT NULL,
    tokens_usados INT UNSIGNED DEFAULT 0,
    costo_estimado DECIMAL(10,4) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ia_consumo_caso (caso_id),
    KEY idx_ia_consumo_colegio (colegio_id),
    CONSTRAINT fk_ia_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ia_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_ia_caso FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 29. LOGS_SISTEMA
-- ============================================================
CREATE TABLE logs_sistema (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id INT UNSIGNED NULL,
    usuario_id INT UNSIGNED NULL,
    modulo VARCHAR(100) NOT NULL,
    accion VARCHAR(100) NOT NULL,
    entidad VARCHAR(100) NULL,
    entidad_id INT UNSIGNED NULL,
    descripcion TEXT NULL,
    ip VARCHAR(50) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_colegio (colegio_id),
    KEY idx_logs_usuario (usuario_id),
    CONSTRAINT fk_logs_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_logs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DATOS SEMILLA
-- ============================================================

-- Colegio demo
INSERT INTO colegios (id, rbd, nombre, director_nombre) VALUES
(1, '99999', 'Colegio Demo Metis', 'Director(a) Demo');

-- Roles
INSERT INTO roles (id, codigo, nombre, descripcion) VALUES
(1, 'superadmin', 'Superadministrador', 'Acceso total al sistema'),
(2, 'director', 'Director/a', 'Dirección del establecimiento'),
(3, 'convivencia', 'Encargado/a de convivencia', 'Gestión de convivencia escolar'),
(4, 'consulta', 'Consulta', 'Solo lectura');

-- Permisos
INSERT INTO permisos (codigo, nombre, modulo) VALUES
('ver_dashboard', 'Ver dashboard', 'dashboard'),
('crear_denuncia', 'Crear denuncia', 'denuncias'),
('ver_denuncias', 'Ver denuncias', 'denuncias'),
('gestionar_casos', 'Gestionar casos', 'casos'),
('ver_reportes', 'Ver reportes', 'reportes'),
('ver_informes', 'Ver informes', 'informes'),
('admin_sistema', 'Administración sistema', 'admin'),
('gestionar_comunidad', 'Gestionar comunidad educativa', 'comunidad'),
('gestionar_reglamentos', 'Gestionar reglamentos', 'reglamentos'),
('usar_ia', 'Usar análisis IA', 'ia');

-- Permisos por rol (superadmin = todo)
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;

-- Director
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 2, id FROM permisos
WHERE codigo IN ('ver_dashboard','ver_denuncias','gestionar_casos','ver_reportes','ver_informes','usar_ia');

-- Convivencia
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 3, id FROM permisos
WHERE codigo IN ('ver_dashboard','crear_denuncia','ver_denuncias','gestionar_casos','gestionar_comunidad','usar_ia');

-- Consulta
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT 4, id FROM permisos
WHERE codigo IN ('ver_dashboard','ver_denuncias','ver_reportes');

-- Estados de caso
INSERT INTO estado_caso (codigo, nombre, orden_visual) VALUES
('recepcion', 'Recepción', 1),
('investigacion', 'En investigación', 2),
('resolucion', 'En resolución', 3),
('seguimiento', 'En seguimiento', 4),
('cerrado', 'Cerrado', 5);

-- Áreas y aspectos de denuncia (catálogo MINEDUC)
INSERT INTO denuncia_areas (id, codigo, nombre) VALUES
(10, 'DISCRIMINACION', 'DISCRIMINACIÓN'),
(20, 'MALTRATO_ADULTOS', 'MALTRATO A MIEMBROS ADULTOS DE LA COMUNIDAD EDUCATIVA'),
(30, 'MALTRATO_ESTUDIANTES', 'MALTRATO A PÁRVULOS Y/O ESTUDIANTES');

INSERT INTO denuncia_aspectos (id, area_id, codigo, nombre) VALUES
(101, 10, 'DISC_DESARROLLO', 'DISCRIMINACIÓN A CONSECUENCIA DEL PROCESO DE DESARROLLO INTEGRAL DEL PÁRVULO'),
(102, 10, 'DISC_EMBARAZO', 'DISCRIMINACIÓN A ESTUDIANTES POR EMBARAZO, MATERNIDAD O PATERNIDAD'),
(103, 10, 'DISC_APARIENCIA', 'DISCRIMINACIÓN POR APARIENCIA PERSONAL Y/O FÍSICA'),
(104, 10, 'DISC_SOCIOECONOMICA', 'DISCRIMINACIÓN POR CONDICIONES SOCIOECONÓMICAS'),
(105, 10, 'DISC_ESTADO_CIVIL', 'DISCRIMINACIÓN POR ESTADO CIVIL DE LOS PADRES, APODERADOS Y/O DEL ESTUDIANTE'),
(106, 10, 'DISC_GENERO', 'DISCRIMINACIÓN POR GÉNERO'),
(107, 10, 'DISC_IDENTIDAD_GENERO', 'DISCRIMINACIÓN POR IDENTIDAD DE GÉNERO'),
(108, 10, 'DISC_NACIONALIDAD', 'DISCRIMINACIÓN POR NACIONALIDAD Y/U ORIGEN RACIAL'),
(109, 10, 'DISC_NEE', 'DISCRIMINACIÓN POR NECESIDADES EDUCATIVAS ESPECIALES PERMANENTES Y/O TRANSITORIAS'),
(110, 10, 'DISC_ORIENTACION', 'DISCRIMINACIÓN POR ORIENTACIÓN SEXUAL'),
(111, 10, 'DISC_OTROS', 'DISCRIMINACIÓN POR OTROS MOTIVOS'),
(112, 10, 'DISC_PUEBLO_ORIGINARIO', 'DISCRIMINACIÓN POR PERTENECER A UN PUEBLO ORIGINARIO Y/O ETNIA'),
(113, 10, 'DISC_SALUD', 'DISCRIMINACIÓN POR PROBLEMAS DE SALUD'),
(114, 10, 'DISC_RELIGION', 'DISCRIMINACIÓN POR RELIGIÓN O CREENCIA'),
(115, 10, 'DISC_RENDIMIENTO', 'DISCRIMINACIÓN POR RENDIMIENTO ACADÉMICO PASADO O POTENCIAL'),
(201, 20, 'MALT_EST_PERSONAL', 'MALTRATO DE ESTUDIANTE HACIA PERSONAL DEL ESTABLECIMIENTO'),
(202, 20, 'MALT_APO_PERSONAL', 'MALTRATO DE PADRE, MADRE Y/O APODERADO HACIA PERSONAL DEL ESTABLECIMIENTO'),
(203, 20, 'MALT_PERSONAL_APO', 'MALTRATO DE PERSONAL DEL ESTABLECIMIENTO A PADRE, MADRE Y/O APODERADO'),
(204, 20, 'MALT_ENTRE_APO', 'MALTRATO ENTRE PADRES, MADRES Y/O APODERADOS MIEMBROS DE LA MISMA COMUNIDAD'),
(205, 20, 'MALT_ENTRE_PERSONAL', 'MALTRATO ENTRE PERSONAL DEL ESTABLECIMIENTO'),
(301, 30, 'MALT_ADULTO_EST', 'MALTRATO DE ADULTO A PÁRVULO Y/O ESTUDIANTE'),
(302, 30, 'MALT_ENTRE_EST', 'MALTRATO ENTRE ESTUDIANTES'),
(303, 30, 'CONFLICTO_PARVULOS', 'SITUACIONES DE CONFLICTO ENTRE PÁRVULOS QUE AFECTAN LA CONVIVENCIA ESCOLAR'),
(304, 30, 'SEXUAL_ADULTO_EST', 'SITUACIONES DE CONNOTACIÓN SEXUAL DE ADULTO A PÁRVULOS Y/O ESTUDIANTES'),
(305, 30, 'SEXUAL_ENTRE_EST', 'SITUACIONES DE CONNOTACIÓN SEXUAL ENTRE PÁRVULOS Y/O ESTUDIANTES');

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================
