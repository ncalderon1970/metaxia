<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo "<pre>";

// 1. Constantes básicas
echo "=== CONFIG ===\n";
try {
    require_once 'config/app.php';
    echo "APP_URL: " . (defined('APP_URL') ? APP_URL : '❌ NO definida') . "\n";
    echo "APP_ENV: " . (defined('APP_ENV') ? APP_ENV : '❌ NO definida') . "\n";
} catch (Throwable $e) {
    echo "❌ config/app.php: " . $e->getMessage() . "\n";
}

// 2. Conexión a BD
echo "\n=== BASE DE DATOS ===\n";
try {
    require_once 'config/database.php';
    require_once 'core/DB.php';
    $pdo = DB::conn();
    $row = $pdo->query("SELECT COUNT(*) as total FROM colegios")->fetch();
    echo "✅ Conexión OK — colegios en BD: " . $row['total'] . "\n";
} catch (Throwable $e) {
    echo "❌ BD FALLA: " . $e->getMessage() . "\n";
}

// 3. Dónde va el redirect post-login
echo "\n=== LOGIN POST-REDIRECT ===\n";
if (file_exists('public/login.php')) {
    $src = file_get_contents('public/login.php');
    preg_match_all('/(Location|header|redirect)[^\n]{0,80}/i', $src, $m);
    foreach ($m[0] as $line) echo "  " . trim($line) . "\n";
} else {
    echo "❌ public/login.php no existe\n";
}

// 4. Cargar el módulo que se abre tras login
echo "\n=== DASHBOARD ===\n";
try {
    ob_start();
    require 'modules/dashboard/index.php';
    ob_end_clean();
    echo "✅ dashboard/index.php OK\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "❌ dashboard: " . $e->getMessage() . "\n";
    echo "   en " . $e->getFile() . " línea " . $e->getLine() . "\n";
}

// 5. Versiones de archivos core modificados
echo "\n=== VERSIÓN ARCHIVOS CORE ===\n";
$check = [
    'core/helpers.php'  => 'calcular_plazo_legal',
    'core/Cache.php'    => 'class Cache',
    'core/Env.php'      => 'class Env',
    'modules/dashboard/includes/data.php' => 'Cache::',
    'modules/denuncias/guardar.php'       => 'invalidar_cache_dashboard',
];
foreach ($check as $file => $buscar) {
    if (!file_exists($file)) { echo "❌ $file no existe\n"; continue; }
    $tiene = strpos(file_get_contents($file), $buscar) !== false;
    echo ($tiene ? "🆕" : "📦") . " $file " . ($tiene ? "(versión NUEVA — contiene '$buscar')" : "(versión ORIGINAL)") . "\n";
}

echo "</pre>";