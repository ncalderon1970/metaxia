-- Migración 001: Tabla de control de migraciones
-- Esta tabla permite saber qué cambios de esquema ya fueron aplicados.

CREATE TABLE IF NOT EXISTS `migrations` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `archivo`      VARCHAR(120) NOT NULL,
    `ejecutado_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_archivo` (`archivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
