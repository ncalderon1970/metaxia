-- ============================================================
-- METIS · Comunidad Educativa
-- Ejecutar en la base de datos: metis
-- ============================================================

-- ── 1. ALUMNOS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alumnos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id          INT UNSIGNED NOT NULL,
    run                 VARCHAR(12)  NOT NULL DEFAULT '0-0'  COMMENT 'RUN sin puntos, con guión; 0-0 si no se conoce',
    nombres             VARCHAR(100) NOT NULL DEFAULT 'NN',
    apellido_paterno    VARCHAR(80)  NOT NULL DEFAULT '',
    apellido_materno    VARCHAR(80)  NOT NULL DEFAULT '',
    fecha_nacimiento    DATE         NULL,
    sexo                ENUM('M','F','O','')   NOT NULL DEFAULT '',
    nivel               VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'Básica, Media, etc.',
    curso               VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '1A, 2B, 4M, etc.',
    activo              TINYINT(1)   NOT NULL DEFAULT 1,
    observaciones       TEXT         NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run_colegio (colegio_id, run),
    INDEX idx_alumno_colegio (colegio_id),
    INDEX idx_alumno_curso   (colegio_id, curso),
    CONSTRAINT fk_alumno_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. DOCENTES ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS docentes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id      INT UNSIGNED NOT NULL,
    run             VARCHAR(12)  NOT NULL DEFAULT '0-0',
    nombre          VARCHAR(200) NOT NULL DEFAULT 'NN',
    especialidad    VARCHAR(100) NOT NULL DEFAULT '',
    horas_contrato  DECIMAL(5,1) NOT NULL DEFAULT 0,
    cargo           VARCHAR(100) NOT NULL DEFAULT 'Docente',
    email           VARCHAR(150) NULL,
    telefono        VARCHAR(20)  NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    observaciones   TEXT         NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run_doc_colegio (colegio_id, run),
    INDEX idx_docente_colegio (colegio_id),
    CONSTRAINT fk_docente_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. ASISTENTES DE LA EDUCACIÓN ───────────────────────────
CREATE TABLE IF NOT EXISTS asistentes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id      INT UNSIGNED NOT NULL,
    run             VARCHAR(12)  NOT NULL DEFAULT '0-0',
    nombre          VARCHAR(200) NOT NULL DEFAULT 'NN',
    cargo           VARCHAR(100) NOT NULL DEFAULT 'Asistente de la educación',
    area            VARCHAR(100) NOT NULL DEFAULT '',
    email           VARCHAR(150) NULL,
    telefono        VARCHAR(20)  NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    observaciones   TEXT         NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run_asi_colegio (colegio_id, run),
    INDEX idx_asistente_colegio (colegio_id),
    CONSTRAINT fk_asistente_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. APODERADOS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS apoderados (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    colegio_id      INT UNSIGNED NOT NULL,
    run             VARCHAR(12)  NOT NULL DEFAULT '0-0',
    nombre          VARCHAR(200) NOT NULL DEFAULT 'NN',
    relacion        VARCHAR(60)  NOT NULL DEFAULT 'Apoderado' COMMENT 'Padre, Madre, Tutor, etc.',
    email           VARCHAR(150) NULL,
    telefono        VARCHAR(20)  NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    observaciones   TEXT         NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run_apo_colegio (colegio_id, run),
    INDEX idx_apoderado_colegio (colegio_id),
    CONSTRAINT fk_apoderado_colegio FOREIGN KEY (colegio_id) REFERENCES colegios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. RELACIÓN ALUMNO ↔ APODERADO ──────────────────────────
CREATE TABLE IF NOT EXISTS alumno_apoderado (
    alumno_id       INT UNSIGNED NOT NULL,
    apoderado_id    INT UNSIGNED NOT NULL,
    es_titular      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (alumno_id, apoderado_id),
    CONSTRAINT fk_aa_alumno    FOREIGN KEY (alumno_id)    REFERENCES alumnos(id)    ON DELETE CASCADE,
    CONSTRAINT fk_aa_apoderado FOREIGN KEY (apoderado_id) REFERENCES apoderados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. DATOS DE EJEMPLO (colegio_id = 1) ────────────────────
INSERT IGNORE INTO alumnos (colegio_id, run, nombres, apellido_paterno, apellido_materno, sexo, nivel, curso)
VALUES
    (1, '21000001-1', 'Sofía Valentina', 'Rojas', 'Morales',  'F', 'Media',  '2M'),
    (1, '21000002-2', 'Matías Ignacio',  'Pérez', 'Sánchez',  'M', 'Media',  '2M'),
    (1, '21000003-3', 'Isadora',         'Castro','Vega',      'F', 'Básica', '7A'),
    (1, '21000004-4', 'Tomás Andrés',    'López', 'Fuentes',   'M', 'Básica', '7A'),
    (1, '21000005-5', 'Antonia',         'Muñoz', 'Díaz',      'F', 'Media',  '4M');

INSERT IGNORE INTO docentes (colegio_id, run, nombre, especialidad, cargo, horas_contrato)
VALUES
    (1, '10000001-1', 'Carmen Gloria Fuentes Araya',   'Lenguaje y Comunicación', 'Docente', 44),
    (1, '10000002-2', 'Ricardo Javier Ortiz Cid',      'Matemáticas',             'Docente', 44),
    (1, '10000003-3', 'Margarita Paz Reyes Soto',      'Historia',                'Jefe de UTP', 44);

INSERT IGNORE INTO asistentes (colegio_id, run, nombre, cargo, area)
VALUES
    (1, '12000001-1', 'Pedro Antonio Medina Cabrera', 'Inspector General',    'Convivencia'),
    (1, '12000002-2', 'Ana Luisa Torres Riquelme',    'Psicóloga',            'PIE'),
    (1, '12000003-3', 'Jorge Hernán Vásquez Araya',   'Auxiliar de servicios','Infraestructura');

INSERT IGNORE INTO apoderados (colegio_id, run, nombre, relacion, telefono, email)
VALUES
    (1, '14000001-1', 'Sandra Rojas Morales',     'Madre',    '+56 9 1111 2222', 'srojas@mail.cl'),
    (1, '14000002-2', 'Luis Pérez González',       'Padre',    '+56 9 3333 4444', 'lperez@mail.cl'),
    (1, '14000003-3', 'Lorena Castro Vergara',     'Madre',    '+56 9 5555 6666', NULL);

INSERT IGNORE INTO alumno_apoderado (alumno_id, apoderado_id, es_titular) VALUES
    (1, 1, 1),
    (2, 2, 1),
    (3, 3, 1);
