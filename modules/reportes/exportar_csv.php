<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];
$colegioId = (int)($user['colegio_id'] ?? 0);

function rex_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function rex_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $allowed = [
        'casos', 'estado_caso', 'caso_clasificacion_normativa', 'caso_pauta_riesgo',
        'caso_gestion_ejecutiva', 'caso_alertas'
    ];

    if (!in_array($table, $allowed, true)) {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $pdo->query('SELECT 1 FROM ' . rex_quote($table) . ' LIMIT 1');
        return $cache[$table] = true;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function rex_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $schema = [
        'casos' => ['id','colegio_id','estado','estado_caso_id','fecha_ingreso','created_at','prioridad','fecha_hora_incidente'],
        'caso_clasificacion_normativa' => ['id','caso_id','tipo_conducta','violencia_sexual'],
        'caso_pauta_riesgo' => ['id','caso_id','nivel_final','puntaje_total'],
        'caso_gestion_ejecutiva' => ['id','caso_id','estado'],
        'caso_alertas' => ['id','caso_id','estado'],
    ];

    return in_array($column, $schema[$table] ?? [], true);
}

$desde = clean((string)($_GET['desde'] ?? ''));
$hasta = clean((string)($_GET['hasta'] ?? ''));

$dateWhere = 'c.colegio_id = ?';
$dateParams = [$colegioId];

if ($desde !== '' && rex_column_exists($pdo, 'casos', 'fecha_ingreso')) {
    $dateWhere .= ' AND DATE(c.fecha_ingreso) >= ?';
    $dateParams[] = $desde;
}
if ($hasta !== '' && rex_column_exists($pdo, 'casos', 'fecha_ingreso')) {
    $dateWhere .= ' AND DATE(c.fecha_ingreso) <= ?';
    $dateParams[] = $hasta;
}

$periodo = ($desde !== '' || $hasta !== '')
    ? 'Desde ' . ($desde ?: 'inicio') . ' hasta ' . ($hasta ?: 'hoy')
    : 'Todos los datos históricos';

// ── Recopilar estadísticas ────────────────────────────────
$stats = [];

try {
    // Totales por estado
    if (rex_table_exists($pdo, 'casos')) {
        $s = $pdo->prepare("SELECT COALESCE(ec.nombre, c.estado, 'sin_estado') AS val, COUNT(*) AS n
            FROM casos c
            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
            WHERE {$dateWhere} AND c.estado != 'borrador'
            GROUP BY val ORDER BY n DESC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Estado', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Por prioridad
    if (rex_column_exists($pdo, 'casos', 'prioridad')) {
        $s = $pdo->prepare("SELECT COALESCE(prioridad,'sin_dato') AS val, COUNT(*) AS n
            FROM casos c WHERE {$dateWhere} GROUP BY val ORDER BY n DESC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Prioridad', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Por tipo de conducta
    if (rex_table_exists($pdo, 'caso_clasificacion_normativa') && rex_column_exists($pdo, 'caso_clasificacion_normativa', 'tipo_conducta')) {
        $s = $pdo->prepare("SELECT COALESCE(cn.tipo_conducta,'sin_clasificar') AS val, COUNT(*) AS n
            FROM caso_clasificacion_normativa cn
            INNER JOIN casos c ON c.id = cn.caso_id
            WHERE {$dateWhere} AND cn.tipo_conducta IS NOT NULL AND cn.tipo_conducta != ''
            GROUP BY val ORDER BY n DESC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Tipo de conducta', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Por nivel de riesgo
    if (rex_table_exists($pdo, 'caso_pauta_riesgo') && rex_column_exists($pdo, 'caso_pauta_riesgo', 'nivel_final')) {
        $s = $pdo->prepare("SELECT COALESCE(pr.nivel_final,'sin_pauta') AS val, COUNT(*) AS n
            FROM caso_pauta_riesgo pr
            INNER JOIN casos c ON c.id = pr.caso_id
            WHERE {$dateWhere} AND pr.puntaje_total > 0
            GROUP BY val ORDER BY n DESC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Nivel de riesgo', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Casos por mes de registro (fecha_ingreso)
    if (rex_table_exists($pdo, 'casos')) {
        $s = $pdo->prepare("SELECT DATE_FORMAT(COALESCE(fecha_ingreso, created_at), '%Y-%m') AS val,
               COUNT(*) AS n
            FROM casos c WHERE {$dateWhere} AND c.estado != 'borrador'
            GROUP BY val ORDER BY val ASC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Casos por mes (registro)', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Casos por mes del hecho (fecha_hora_incidente)
    if (rex_table_exists($pdo, 'casos') && rex_column_exists($pdo, 'casos', 'fecha_hora_incidente')) {
        $s = $pdo->prepare("SELECT DATE_FORMAT(c.fecha_hora_incidente, '%Y-%m') AS val,
               COUNT(*) AS n
            FROM casos c WHERE {$dateWhere}
              AND c.estado != 'borrador'
              AND c.fecha_hora_incidente IS NOT NULL
              AND c.fecha_hora_incidente <= NOW()
            GROUP BY val ORDER BY val ASC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Casos por mes (hecho)', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Develaciones tardías: brecha > 30 días entre hecho y registro
    if (rex_table_exists($pdo, 'casos') && rex_column_exists($pdo, 'casos', 'fecha_hora_incidente')) {
        $s = $pdo->prepare("SELECT
               SUM(DATEDIFF(DATE(COALESCE(c.fecha_ingreso, c.created_at)), DATE(c.fecha_hora_incidente)) > 30) AS tardias
            FROM casos c WHERE {$dateWhere}
              AND c.estado != 'borrador'
              AND c.fecha_hora_incidente IS NOT NULL
              AND c.fecha_hora_incidente <= NOW()");
        $s->execute($dateParams);
        $rT = $s->fetch();
        $stats[] = ['seccion' => 'Develación tardía (>30d)', 'categoria' => 'Hecho registrado >30 días después de ocurrido', 'valor' => (int)($rT['tardias'] ?? 0)];

        // Casos de violencia sexual con develación tardía (imprescriptibles)
        if (rex_table_exists($pdo, 'caso_clasificacion_normativa') && rex_column_exists($pdo, 'caso_clasificacion_normativa', 'violencia_sexual')) {
            $s2 = $pdo->prepare("SELECT COUNT(DISTINCT c.id) AS n
                FROM casos c
                INNER JOIN caso_clasificacion_normativa cn ON cn.caso_id = c.id AND cn.violencia_sexual = 1
                WHERE {$dateWhere}
                  AND c.estado != 'borrador'
                  AND c.fecha_hora_incidente IS NOT NULL
                  AND c.fecha_hora_incidente <= NOW()
                  AND DATEDIFF(DATE(COALESCE(c.fecha_ingreso, c.created_at)), DATE(c.fecha_hora_incidente)) > 30");
            $s2->execute($dateParams);
            $stats[] = ['seccion' => 'Develación tardía (>30d)', 'categoria' => 'Violencia sexual — acción imprescriptible (Art. 369 quáter CP)', 'valor' => (int)$s2->fetchColumn()];
        }
    }

    // Acciones ejecutivas por estado
    if (rex_table_exists($pdo, 'caso_gestion_ejecutiva') && rex_column_exists($pdo, 'caso_gestion_ejecutiva', 'estado')) {
        $s = $pdo->prepare("SELECT COALESCE(ge.estado,'sin_dato') AS val, COUNT(*) AS n
            FROM caso_gestion_ejecutiva ge
            INNER JOIN casos c ON c.id = ge.caso_id
            WHERE {$dateWhere}
            GROUP BY val ORDER BY n DESC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Acciones ejecutivas', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }

    // Alertas por estado
    if (rex_table_exists($pdo, 'caso_alertas') && rex_column_exists($pdo, 'caso_alertas', 'estado')) {
        $s = $pdo->prepare("SELECT COALESCE(a.estado,'sin_dato') AS val, COUNT(*) AS n
            FROM caso_alertas a
            INNER JOIN casos c ON c.id = a.caso_id
            WHERE {$dateWhere}
            GROUP BY val ORDER BY n DESC");
        $s->execute($dateParams);
        foreach ($s->fetchAll() as $r) {
            $stats[] = ['seccion' => 'Alertas', 'categoria' => $r['val'], 'valor' => (int)$r['n']];
        }
    }
} catch (Throwable $e) {
    // Continúa con los datos disponibles
}

$filename = 'metis_estadisticas_' . date('Ymd_His') . '.csv';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'wb');

fputcsv($out, ['Metis SGCE · Estadísticas de convivencia · ' . $periodo . ' · Generado: ' . date('d-m-Y H:i')], ';');
fputcsv($out, [], ';');
fputcsv($out, ['Sección', 'Categoría', 'Cantidad'], ';');

$seccionActual = '';
foreach ($stats as $row) {
    if ($row['seccion'] !== $seccionActual) {
        if ($seccionActual !== '') {
            fputcsv($out, [], ';');
        }
        $seccionActual = $row['seccion'];
    }
    fputcsv($out, [$row['seccion'], $row['categoria'], $row['valor']], ';');
}

fclose($out);
exit;
