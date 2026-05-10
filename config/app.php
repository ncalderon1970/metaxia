<?php
declare(strict_types=1);

// Cargar variables de entorno desde .env (si existe)
if (!class_exists('Env')) {
    require_once dirname(__DIR__) . '/core/Env.php';
}
Env::load(dirname(__DIR__));

date_default_timezone_set('America/Santiago');

define('APP_NAME',     'Metis');
define('APP_SUBTITLE', 'Sistema de Gestión de Convivencia Escolar');
define('APP_VERSION',  '1.0.0');

/*
|--------------------------------------------------------------------------
| Entorno: 'local' | 'produccion'
|--------------------------------------------------------------------------
| Se lee desde .env (APP_ENV=produccion) o se mantiene el valor por defecto.
*/
define('APP_ENV', Env::get('APP_ENV', 'produccion'));

/*
|--------------------------------------------------------------------------
| URL base del sistema
|--------------------------------------------------------------------------
| LOCAL   → http://localhost/metis
| cPanel  → https://tudominio.cl/metis  (o https://tudominio.cl si va en raíz)
*/
if (APP_ENV === 'produccion') {
    define('APP_URL', 'https://metis.saberser.cl');   // ✅ URL real producción
} else {
    define('APP_URL', 'http://localhost/metis');
}

/*
|--------------------------------------------------------------------------
| Control de errores
|--------------------------------------------------------------------------
*/
$logsDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0750, true);
}

if (APP_ENV === 'produccion') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $logsDir . '/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', $logsDir . '/php_errors_dev.log');
}
unset($logsDir);

/*
|--------------------------------------------------------------------------
| Rutas internas
|--------------------------------------------------------------------------
*/
define('BASE_PATH',    dirname(__DIR__));
define('CONFIG_PATH',  BASE_PATH . '/config');
define('CORE_PATH',    BASE_PATH . '/core');
define('PUBLIC_PATH',  BASE_PATH . '/public');
define('MODULES_PATH', BASE_PATH . '/modules');
define('STORAGE_PATH', BASE_PATH . '/storage');

define('UPLOAD_PATH',   STORAGE_PATH . '/uploads/');
define('EVIDENCE_PATH', STORAGE_PATH . '/evidencias/');

define('UPLOAD_URL',   APP_URL . '/storage/uploads');
define('EVIDENCE_URL', APP_URL . '/storage/evidencias');
