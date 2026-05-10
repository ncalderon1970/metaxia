<?php
declare(strict_types=1);

final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        $file = rtrim($path, '/\\') . '/.env';

        if (!is_file($file)) {
            self::$loaded = true;
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // Quitar comillas opcionales: VAR="valor" o VAR='valor'
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[-1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($name === '') {
                continue;
            }

            // Solo setear si no está ya definida por el entorno del servidor
            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value !== false ? $value : ($ENV[$key] ?? $default);
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === '') {
            throw new RuntimeException("Variable de entorno requerida no definida: {$key}");
        }
        return $value;
    }
}
