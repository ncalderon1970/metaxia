-- Migración 002: Columna para registrar tokens ahorrados por prompt caching
-- Permite monitorear el ahorro real de costos de API por colegio.

ALTER TABLE `caso_analisis_ia`
    ADD COLUMN IF NOT EXISTS `tokens_cache_hit` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `tokens_usados`;
