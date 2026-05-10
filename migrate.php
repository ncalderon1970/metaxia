<?php
declare(strict_types=1);

/**
 * Metis · Runner de migraciones SQL
 *
 * Uso (desde la raíz del proyecto):
 *   php migrate.php           — aplica migraciones pendientes
 *   php migrate.php --status  — muestra estado sin aplicar nada
 *
 * Las migraciones son archivos .sql numerados en sql/migrations/
 * Se ejecutan en orden ascendente; cada archivo se ejecuta solo una vez.
 */

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde CLI.');
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/DB.php';

$soloStatus = in_array('--status', $argv ?? [], true);

$pdo = DB::conn();

// Crear tabla de control si no existe
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `archivo`      VARCHAR(120) NOT NULL,
        `ejecutado_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_archivo` (`archivo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Migraciones ya ejecutadas
$ejecutadas = $pdo->query("SELECT archivo FROM migrations ORDER BY archivo ASC")
    ->fetchAll(PDO::FETCH_COLUMN);
$ejecutadas = array_flip($ejecutadas);

// Archivos disponibles
$dir      = __DIR__ . '/sql/migrations';
$archivos = glob($dir . '/*.sql');
sort($archivos);

if (empty($archivos)) {
    echo "No hay archivos de migración en sql/migrations/\n";
    exit(0);
}

$pendientes = 0;
$aplicadas  = 0;
$errores    = 0;

foreach ($archivos as $ruta) {
    $nombre = basename($ruta);
    $estado = isset($ejecutadas[$nombre]) ? 'OK' : 'PENDIENTE';

    if ($soloStatus) {
        echo "[{$estado}] {$nombre}\n";
        continue;
    }

    if (isset($ejecutadas[$nombre])) {
        echo "  ✓ {$nombre} (ya aplicada)\n";
        $aplicadas++;
        continue;
    }

    echo "  → Aplicando {$nombre}... ";

    $sql = file_get_contents($ruta);
    if ($sql === false || trim($sql) === '') {
        echo "VACÍA, omitida.\n";
        continue;
    }

    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO migrations (archivo) VALUES (?)")->execute([$nombre]);
        echo "OK\n";
        $aplicadas++;
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $errores++;
    }

    $pendientes++;
}

if (!$soloStatus) {
    echo "\n";
    if ($pendientes === 0) {
        echo "Todo al día. No había migraciones pendientes.\n";
    } else {
        echo "Aplicadas: {$aplicadas} | Errores: {$errores}\n";
    }
}
