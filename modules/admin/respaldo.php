<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
if (!Auth::can('admin_sistema')) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$pdo    = DB::conn();
$action = (string)($_GET['action'] ?? '');

// ── Exportar SQL ────────────────────────────────────────────
if ($action === 'sql') {
    $dbConf = require dirname(__DIR__, 2) . '/config/database.php';
    $dbName = $dbConf['database'];

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="metis_backup_' . date('Ymd_His') . '.sql"');
    header('Cache-Control: no-cache');

    echo "-- Metis SGCE — Respaldo SQL\n";
    echo "-- Base de datos: {$dbName}\n";
    echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Generado por módulo de respaldo\n\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    // Listar tablas
    $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tablas as $tabla) {
        // CREATE TABLE
        $create = $pdo->query("SHOW CREATE TABLE `{$tabla}`")->fetch();
        echo "-- --------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `{$tabla}`;\n";
        echo $create['Create Table'] . ";\n\n";

        // INSERT data
        $rows = $pdo->query("SELECT * FROM `{$tabla}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;

        $cols   = '`' . implode('`, `', array_keys($rows[0])) . '`';
        $chunks = array_chunk($rows, 100);

        foreach ($chunks as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string)$v);
                }, array_values($row));
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            echo "INSERT INTO `{$tabla}` ({$cols}) VALUES\n";
            echo implode(",\n", $values) . ";\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    ob_end_flush();
    exit;
}

// ── Exportar archivos ZIP ───────────────────────────────────
if ($action === 'zip') {
    if (!class_exists('ZipArchive')) {
        die('ZipArchive no disponible en este servidor.');
    }

    $tmpFile = sys_get_temp_dir() . '/metis_backup_' . date('Ymd_His') . '.zip';
    $zip     = new ZipArchive();

    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('No se pudo crear el archivo ZIP.');
    }

    $base    = BASE_PATH;
    $excluir = [
        'vendor', 'storage/logs', 'storage/uploads', 'storage/evidencias',
        '.git', 'node_modules', 'setup_admin.php',
        // Archivos con credenciales — nunca incluir en el ZIP
        '.env',
        'config/database.php',
        'config/ia.php',
    ];

    // Patrones de archivo a excluir en cualquier ruta
    $excluirPatrones = ['error_log', '.DS_Store', 'Thumbs.db'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $ruta = str_replace($base . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $ruta = str_replace('\\', '/', $ruta);

        // Excluir por prefijo de ruta
        $excluir_item = false;
        foreach ($excluir as $ex) {
            if (str_starts_with($ruta, $ex)) { $excluir_item = true; break; }
        }
        if ($excluir_item) continue;

        // Excluir por nombre de archivo (en cualquier directorio)
        $nombreArchivo = basename($ruta);
        foreach ($excluirPatrones as $patron) {
            if ($nombreArchivo === $patron) { $excluir_item = true; break; }
        }
        if ($excluir_item) continue;

        if ($file->isDir()) {
            $zip->addEmptyDir($ruta);
        } elseif ($file->isFile() && $file->getSize() < 20 * 1024 * 1024) {
            $zip->addFile($file->getPathname(), $ruta);
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="metis_sistema_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    unlink($tmpFile);
    ob_end_flush();
    exit;
}

// ── Vista ───────────────────────────────────────────────────
$pageTitle = 'Respaldo del sistema · Metis';
require_once dirname(__DIR__, 2) . '/core/layout_header.php';

// Tamaño aproximado de la BD
$dbSize = 0;
try {
    $dbConf = require dirname(__DIR__, 2) . '/config/database.php';
    $st = $pdo->prepare("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
        FROM information_schema.tables
        WHERE table_schema = ?
    ");
    $st->execute([$dbConf['database']]);
    $dbSize = (float)$st->fetchColumn();
} catch (Throwable $e) {}

// Contar tablas y registros
$nTablas = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
?>
<style>
.rb-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #1d4ed8 100%);
    color:#fff; border-radius:16px; padding:1.6rem 2rem; margin-bottom:1.25rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
    box-shadow:0 8px 24px rgba(15,23,42,.18);
}
.rb-hero h2 { margin:0 0 .25rem; font-size:1.3rem; font-weight:700; }
.rb-hero p  { margin:0; font-size:.86rem; color:rgba(255,255,255,.72); }

.rb-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }

.rb-card {
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:1.5rem; box-shadow:0 1px 3px rgba(15,23,42,.05);
}
.rb-card-title {
    font-size:.72rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.09em; margin:0 0 1rem;
    display:flex; align-items:center; gap:.4rem;
}
.rb-card-title.blue { color:#2563eb; }
.rb-card-title.teal { color:#0d9488; }

.rb-stat { display:flex; align-items:baseline; gap:.4rem; margin-bottom:.35rem; }
.rb-stat strong { font-size:1.75rem; font-weight:700; color:#0f172a; }
.rb-stat span { font-size:.82rem; color:#64748b; }

.rb-btn {
    display:inline-flex; align-items:center; gap:.5rem; border:none;
    border-radius:10px; padding:.75rem 1.25rem; font-size:.88rem; font-weight:600;
    cursor:pointer; font-family:inherit; text-decoration:none; width:100%;
    justify-content:center; margin-top:.75rem; transition:transform .12s, box-shadow .12s;
}
.rb-btn:hover { transform:translateY(-1px); }
.rb-btn.primary {
    background:linear-gradient(135deg,#1e3a8a,#2563eb);
    color:#fff; box-shadow:0 4px 14px rgba(37,99,235,.3);
}
.rb-btn.primary:hover { box-shadow:0 6px 20px rgba(37,99,235,.4); }
.rb-btn.teal {
    background:linear-gradient(135deg,#0f766e,#0d9488);
    color:#fff; box-shadow:0 4px 14px rgba(13,148,136,.3);
}
.rb-btn.teal:hover { box-shadow:0 6px 20px rgba(13,148,136,.4); }

.rb-warning {
    background:#fffbeb; border:1px solid #fde68a; border-radius:10px;
    padding:.85rem 1rem; font-size:.82rem; color:#92400e;
    display:flex; align-items:flex-start; gap:.55rem; margin-top:1rem;
    line-height:1.5;
}

.rb-info-list { display:grid; gap:.5rem; }
.rb-info-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:.45rem 0; border-bottom:1px solid #f1f5f9; font-size:.86rem;
}
.rb-info-row:last-child { border-bottom:none; }
.rb-info-row span { color:#64748b; }
.rb-info-row strong { color:#0f172a; font-weight:600; }

@media(max-width:700px){ .rb-grid{grid-template-columns:1fr;} }
</style>

<div class="rb-hero">
    <div>
        <h2><i class="bi bi-cloud-arrow-down-fill"></i> Respaldo del sistema</h2>
        <p>Exporta la base de datos y los archivos del sistema para resguardo y restauración.</p>
    </div>
</div>

<div class="rb-grid">

    <!-- Respaldo BD -->
    <div class="rb-card">
        <p class="rb-card-title blue"><i class="bi bi-database-fill"></i> Base de datos</p>
        <div class="rb-info-list">
            <div class="rb-info-row">
                <span>Tablas</span>
                <strong><?= $nTablas ?></strong>
            </div>
            <div class="rb-info-row">
                <span>Tamaño aprox.</span>
                <strong><?= $dbSize > 0 ? $dbSize . ' MB' : '—' ?></strong>
            </div>
            <div class="rb-info-row">
                <span>Formato</span>
                <strong>.sql (MySQL)</strong>
            </div>
            <div class="rb-info-row">
                <span>Fecha</span>
                <strong><?= date('d/m/Y H:i') ?></strong>
            </div>
        </div>
        <a class="rb-btn primary" href="?action=sql">
            <i class="bi bi-download"></i> Descargar respaldo SQL
        </a>
        <div class="rb-warning">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            Incluye todos los datos del sistema. Guarda el archivo en un lugar seguro.
        </div>
    </div>

    <!-- Respaldo archivos -->
    <div class="rb-card">
        <p class="rb-card-title teal"><i class="bi bi-archive-fill"></i> Archivos del sistema</p>
        <div class="rb-info-list">
            <div class="rb-info-row">
                <span>Incluye</span>
                <strong>PHP, CSS, JS, config</strong>
            </div>
            <div class="rb-info-row">
                <span>Excluye</span>
                <strong>uploads, logs, vendor</strong>
            </div>
            <div class="rb-info-row">
                <span>Formato</span>
                <strong>.zip</strong>
            </div>
            <div class="rb-info-row">
                <span>Fecha</span>
                <strong><?= date('d/m/Y H:i') ?></strong>
            </div>
        </div>
        <a class="rb-btn teal" href="?action=zip">
            <i class="bi bi-file-zip-fill"></i> Descargar respaldo ZIP
        </a>
        <div class="rb-warning">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:.1rem;"></i>
            El ZIP excluye <code>.env</code>, <code>config/database.php</code> y <code>config/ia.php</code>.
            Al restaurar, crea esos archivos manualmente en el servidor destino usando <code>.env.example</code> como guía.
        </div>
    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/core/layout_footer.php';
ob_end_flush();
?>
