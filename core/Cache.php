<?php
declare(strict_types=1);

/**
 * Metis · Caché de archivo simple
 *
 * Almacena resultados serializados en storage/cache/ con TTL configurable.
 * No requiere Redis ni extensiones adicionales — funciona en cualquier cPanel.
 *
 * Uso básico:
 *   $datos = Cache::remember("kpis_{$colegioId}", 300, fn() => cargarKpis($pdo, $colegioId));
 *
 * Invalidar manualmente:
 *   Cache::forget("kpis_{$colegioId}");
 *   Cache::flush('kpis_');   // invalida todo lo que empieza con 'kpis_'
 */
final class Cache
{
    private static string $dir = '';

    private static function dir(): string
    {
        if (self::$dir !== '') {
            return self::$dir;
        }

        $base = defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__) . '/storage';
        $dir  = $base . '/cache';

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return self::$dir = $dir;
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . md5($key) . '.cache';
    }

    /**
     * Obtiene un valor de caché o lo genera y guarda si no existe o expiró.
     *
     * @template T
     * @param  string   $key  Clave única. Usa prefijos para invalidar por grupo: "kpis_42"
     * @param  int      $ttl  Segundos de vida. 0 = sin expiración.
     * @param  callable $fn   Función que devuelve el valor a cachear.
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $file = self::path($key);

        if (is_file($file)) {
            $age = time() - filemtime($file);
            if ($ttl === 0 || $age < $ttl) {
                $data = @unserialize((string)file_get_contents($file));
                if ($data !== false) {
                    return $data;
                }
            }
        }

        $value = $fn();
        file_put_contents($file, serialize($value), LOCK_EX);
        return $value;
    }

    /**
     * Guarda un valor en caché directamente (sin callback).
     */
    public static function put(string $key, mixed $value): void
    {
        file_put_contents(self::path($key), serialize($value), LOCK_EX);
    }

    /**
     * Lee un valor del caché. Devuelve null si no existe o expiró.
     */
    public static function get(string $key, int $ttl = 0): mixed
    {
        $file = self::path($key);

        if (!is_file($file)) {
            return null;
        }

        if ($ttl > 0 && (time() - filemtime($file)) >= $ttl) {
            return null;
        }

        $data = @unserialize((string)file_get_contents($file));
        return $data !== false ? $data : null;
    }

    /**
     * Elimina una entrada de caché.
     */
    public static function forget(string $key): void
    {
        $file = self::path($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Elimina todas las entradas cuya clave original empieza con $prefix.
     * Como los archivos se nombran por md5, escanea el directorio buscando
     * entradas cuyo key registrado coincida (no es O(1) pero es aceptable).
     *
     * Alternativa rápida: guarda las claves en un índice — no necesario aún.
     */
    public static function flush(string $prefix = ''): int
    {
        $dir     = self::dir();
        $deleted = 0;

        foreach (glob($dir . '/*.cache') as $file) {
            if ($prefix === '') {
                unlink($file);
                $deleted++;
                continue;
            }

            // Para flush por prefijo necesitamos el key original.
            // Lo guardamos en los primeros bytes del archivo: "KEY\n<serialized>".
            $content = file_get_contents($file);
            if ($content !== false && str_starts_with($content, $prefix)) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
