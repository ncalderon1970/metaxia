<?php
declare(strict_types=1);

final class DB
{
    private static ?PDO $instance = null;

    public static function conn(): PDO
    {
        if (self::$instance instanceof PDO) {
            // Verificar que la conexión sigue activa antes de devolverla
            try {
                self::$instance->query('SELECT 1');
                return self::$instance;
            } catch (PDOException $e) {
                // Conexión caída — forzar reconexión
                self::$instance = null;
            }
        }

        return self::$instance = self::connect();
    }

    private static function connect(): PDO
    {
        $configFile = __DIR__ . '/../config/database.php';

        if (!is_file($configFile)) {
            throw new RuntimeException('No se encontró el archivo de configuración de base de datos.');
        }

        $config = require $configFile;

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}