<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
CSRF::requireValid($_POST['_token'] ?? null);

$pdo = DB::conn();
$user = Auth::user() ?? [];

$rolCodigo = (string)($user['rol_codigo'] ?? '');
$userId = (int)($user['id'] ?? 0);
$colegioId = (int)($user['colegio_id'] ?? 0);

$puedeGestionar = in_array($rolCodigo, ['superadmin', 'director', 'convivencia', 'encargado_convivencia', 'admin_colegio'], true)
    || (method_exists('Auth', 'can') && (Auth::can('admin_sistema') || Auth::can('gestionar_usuarios')));

if (!$puedeGestionar) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

function imp_redirect(string $status, string $msg, string $tipo = 'apoderados'): void
{
    $url = APP_URL . '/modules/importar/index.php?tipo=' . urlencode($tipo);
    $url .= '&status=' . urlencode($status) . '&msg=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

function imp_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function imp_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function imp_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function imp_create_pendientes_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS importacion_pendientes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            colegio_id INT UNSIGNED DEFAULT NULL,
            tipo VARCHAR(40) NOT NULL,
            fila INT UNSIGNED DEFAULT NULL,
            run VARCHAR(30) DEFAULT NULL,
            motivo TEXT NOT NULL,
            datos_json LONGTEXT DEFAULT NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'pendiente',
            creado_por INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_importacion_pendientes_tipo (tipo),
            INDEX idx_importacion_pendientes_estado (estado),
            INDEX idx_importacion_pendientes_colegio (colegio_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function imp_pending(PDO $pdo, int $colegioId, string $tipo, int $fila, ?string $run, string $motivo, array $row, int $userId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO importacion_pendientes (colegio_id, tipo, fila, run, motivo, datos_json, estado, creado_por, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?, NOW())
    ");
    $stmt->execute([
        $colegioId > 0 ? $colegioId : null,
        $tipo,
        $fila,
        $run,
        $motivo,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $userId > 0 ? $userId : null,
    ]);
}

function imp_to_utf8(string $value): string
{
    $value = str_replace("\xEF\xBB\xBF", '', $value);

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
    }

    return trim($value);
}

function imp_strip_accents(string $value): string
{
    $from = ['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ'];
    $to = ['a','e','i','o','u','A','E','I','O','U','n','N'];
    return str_replace($from, $to, $value);
}

function imp_header_key(string $value): string
{
    $value = imp_to_utf8($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = imp_strip_accents($value);
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    $value = trim($value, '_');

    $aliases = [
        'rut' => 'run',
        'r_u_n' => 'run',
        'run_estudiante' => 'run',
        'run_alumno' => 'run',
        'run_apoderado' => 'run',
        'nombre' => 'nombres',
        'nombre_s' => 'nombres',
        'nombre_completo' => 'nombre_completo',
        'apellido_paterno' => 'apellido_paterno',
        'ap_paterno' => 'apellido_paterno',
        'paterno' => 'apellido_paterno',
        'primer_apellido' => 'apellido_paterno',
        'apellido_materno' => 'apellido_materno',
        'ap_materno' => 'apellido_materno',
        'materno' => 'apellido_materno',
        'segundo_apellido' => 'apellido_materno',
        'correo' => 'email',
        'correo_electronico' => 'email',
        'mail' => 'email',
        'fono' => 'telefono',
        'celular' => 'telefono',
        'telefono_movil' => 'telefono',
        'domicilio' => 'direccion',
        'direccion_particular' => 'direccion',
        'fecha_nac' => 'fecha_nacimiento',
        'fecha_de_nacimiento' => 'fecha_nacimiento',
    ];

    return $aliases[$value] ?? $value;
}

function imp_upper(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : mb_strtoupper($value, 'UTF-8');
}

function imp_lower(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : mb_strtolower($value, 'UTF-8');
}

function imp_clean_text(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function imp_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('/', '-', $value);
    $ts = strtotime($value);

    return $ts ? date('Y-m-d', $ts) : null;
}

function imp_normalize_run(?string $run): string
{
    $run = mb_strtoupper(trim((string)$run), 'UTF-8');
    $run = str_replace(['.', ' ', '\t'], '', $run);
    $run = preg_replace('/[^0-9Kk\-]/u', '', $run) ?? '';

    if ($run === '') {
        return '';
    }

    if (!str_contains($run, '-') && mb_strlen($run) >= 2) {
        $body = mb_substr($run, 0, -1);
        $dv = mb_substr($run, -1);
        $run = $body . '-' . $dv;
    }

    [$body, $dv] = array_pad(explode('-', $run, 2), 2, '');
    $body = preg_replace('/\D/u', '', $body) ?? '';
    $dv = mb_strtoupper(preg_replace('/[^0-9K]/u', '', $dv) ?? '', 'UTF-8');

    return $body !== '' && $dv !== '' ? $body . '-' . $dv : '';
}

function imp_valid_run(string $run): bool
{
    $run = imp_normalize_run($run);
    if ($run === '' || !str_contains($run, '-')) {
        return false;
    }

    [$body, $dv] = explode('-', $run, 2);
    if ($body === '' || $dv === '') {
        return false;
    }

    $sum = 0;
    $multiplier = 2;

    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $sum += ((int)$body[$i]) * $multiplier;
        $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
    }

    $expected = 11 - ($sum % 11);
    $expectedDv = $expected === 11 ? '0' : ($expected === 10 ? 'K' : (string)$expected);

    return $expectedDv === mb_strtoupper($dv, 'UTF-8');
}

function imp_full_name(array $row): ?string
{
    $parts = [];
    foreach (['nombres', 'apellido_paterno', 'apellido_materno'] as $key) {
        if (!empty($row[$key])) {
            $parts[] = (string)$row[$key];
        }
    }

    if (!$parts && !empty($row['nombre_completo'])) {
        $parts[] = (string)$row['nombre_completo'];
    }

    $full = trim(implode(' ', $parts));
    return $full === '' ? null : mb_strtoupper($full, 'UTF-8');
}

function imp_add_if_column(array &$data, PDO $pdo, string $table, string $column, mixed $value, bool $allowNull = false): void
{
    if (!imp_column_exists($pdo, $table, $column)) {
        return;
    }

    if ($value === null && !$allowNull) {
        return;
    }

    if (is_string($value) && trim($value) === '' && !$allowNull) {
        return;
    }

    $data[$column] = $value;
}

function imp_table_for_tipo(string $tipo): string
{
    return match ($tipo) {
        'alumnos' => 'alumnos',
        'apoderados' => 'apoderados',
        'docentes' => 'docentes',
        'asistentes' => 'asistentes',
        default => throw new RuntimeException('Tipo de importación no válido.'),
    };
}

function imp_build_payload(PDO $pdo, string $table, string $tipo, array $row, int $colegioId, int $userId): array
{
    $run = imp_normalize_run((string)($row['run'] ?? ''));
    $nombres = imp_upper($row['nombres'] ?? null);
    $apellidoPaterno = imp_upper($row['apellido_paterno'] ?? null);
    $apellidoMaterno = imp_upper($row['apellido_materno'] ?? null);
    $nombreCompleto = imp_full_name($row);
    $email = imp_lower($row['email'] ?? null);
    $telefono = imp_clean_text($row['telefono'] ?? null);
    $direccion = imp_upper($row['direccion'] ?? null);
    $parentesco = imp_upper($row['parentesco'] ?? null);
    $cargo = imp_upper($row['cargo'] ?? null);
    $especialidad = imp_upper($row['especialidad'] ?? null);
    $curso = imp_upper($row['curso'] ?? null);
    $fechaNacimiento = imp_date($row['fecha_nacimiento'] ?? null);

    $data = [];

    imp_add_if_column($data, $pdo, $table, 'run', $run);
    imp_add_if_column($data, $pdo, $table, 'rut', $run);

    /*
     * Regla definitiva de nombres:
     * - Si la tabla tiene columnas separadas de apellidos, `nombre` guarda SOLO los nombres.
     * - El nombre completo se guarda en `nombres_completos_cache` o `nombre_completo`, si existen.
     * - Si la tabla antigua solo tiene `nombre` y no tiene apellidos separados, `nombre` guarda el nombre completo.
     */
    imp_add_if_column($data, $pdo, $table, 'nombres', $nombres);

    $tablaTieneApellidosSeparados = imp_column_exists($pdo, $table, 'apellido_paterno')
        || imp_column_exists($pdo, $table, 'apellido_materno')
        || imp_column_exists($pdo, $table, 'paterno')
        || imp_column_exists($pdo, $table, 'materno')
        || imp_column_exists($pdo, $table, 'ap_paterno')
        || imp_column_exists($pdo, $table, 'ap_materno');

    $valorColumnaNombre = $tablaTieneApellidosSeparados
        ? ($nombres ?? $nombreCompleto)
        : ($nombreCompleto ?? $nombres);

    imp_add_if_column($data, $pdo, $table, 'nombre', $valorColumnaNombre);
    imp_add_if_column($data, $pdo, $table, 'nombre_completo', $nombreCompleto);
    imp_add_if_column($data, $pdo, $table, 'nombres_completos_cache', $nombreCompleto);

    imp_add_if_column($data, $pdo, $table, 'apellido_paterno', $apellidoPaterno);
    imp_add_if_column($data, $pdo, $table, 'paterno', $apellidoPaterno);
    imp_add_if_column($data, $pdo, $table, 'ap_paterno', $apellidoPaterno);
    imp_add_if_column($data, $pdo, $table, 'primer_apellido', $apellidoPaterno);

    imp_add_if_column($data, $pdo, $table, 'apellido_materno', $apellidoMaterno);
    imp_add_if_column($data, $pdo, $table, 'materno', $apellidoMaterno);
    imp_add_if_column($data, $pdo, $table, 'ap_materno', $apellidoMaterno);
    imp_add_if_column($data, $pdo, $table, 'segundo_apellido', $apellidoMaterno);

    imp_add_if_column($data, $pdo, $table, 'email', $email);
    imp_add_if_column($data, $pdo, $table, 'correo', $email);
    imp_add_if_column($data, $pdo, $table, 'correo_electronico', $email);

    imp_add_if_column($data, $pdo, $table, 'telefono', $telefono);
    imp_add_if_column($data, $pdo, $table, 'fono', $telefono);
    imp_add_if_column($data, $pdo, $table, 'celular', $telefono);

    imp_add_if_column($data, $pdo, $table, 'direccion', $direccion);
    imp_add_if_column($data, $pdo, $table, 'domicilio', $direccion);

    if ($tipo === 'apoderados') {
        imp_add_if_column($data, $pdo, $table, 'parentesco', $parentesco);
    }

    if ($tipo === 'alumnos') {
        imp_add_if_column($data, $pdo, $table, 'curso', $curso);
        imp_add_if_column($data, $pdo, $table, 'fecha_nacimiento', $fechaNacimiento);
    }

    if (in_array($tipo, ['docentes', 'asistentes'], true)) {
        imp_add_if_column($data, $pdo, $table, 'cargo', $cargo);
        imp_add_if_column($data, $pdo, $table, 'especialidad', $especialidad);
    }

    if ($colegioId > 0) {
        imp_add_if_column($data, $pdo, $table, 'colegio_id', $colegioId);
    }

    imp_add_if_column($data, $pdo, $table, 'activo', 1);
    imp_add_if_column($data, $pdo, $table, 'estado', 'activo');
    imp_add_if_column($data, $pdo, $table, 'creado_por', $userId > 0 ? $userId : null);
    imp_add_if_column($data, $pdo, $table, 'updated_at', date('Y-m-d H:i:s'));

    return $data;
}

function imp_find_existing(PDO $pdo, string $table, string $run, int $colegioId): ?int
{
    $conditions = [];
    $params = [];

    $runConditions = [];
    foreach (['run', 'rut'] as $column) {
        if (imp_column_exists($pdo, $table, $column)) {
            $runConditions[] = imp_quote($column) . ' = ?';
            $params[] = $run;
        }
    }

    if (!$runConditions) {
        throw new RuntimeException('La tabla ' . $table . ' no tiene columna RUN/RUT compatible.');
    }

    $conditions[] = '(' . implode(' OR ', $runConditions) . ')';

    if ($colegioId > 0 && imp_column_exists($pdo, $table, 'colegio_id')) {
        $conditions[] = 'colegio_id = ?';
        $params[] = $colegioId;
    }

    $stmt = $pdo->prepare('SELECT id FROM ' . imp_quote($table) . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
    $stmt->execute($params);
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function imp_insert(PDO $pdo, string $table, array $data): int
{
    if (imp_column_exists($pdo, $table, 'created_at') && !isset($data['created_at'])) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }

    $columns = array_keys($data);
    $sql = 'INSERT INTO ' . imp_quote($table)
        . ' (' . implode(', ', array_map('imp_quote', $columns)) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));

    return (int)$pdo->lastInsertId();
}

function imp_update(PDO $pdo, string $table, int $id, array $data): void
{
    unset($data['created_at'], $data['creado_por']);

    if (!$data) {
        return;
    }

    $sets = [];
    $params = [];

    foreach ($data as $column => $value) {
        $sets[] = imp_quote($column) . ' = ?';
        $params[] = $value;
    }

    $params[] = $id;

    $stmt = $pdo->prepare('UPDATE ' . imp_quote($table) . ' SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1');
    $stmt->execute($params);
}

function imp_detect_delimiter(string $path): string
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return ';';
    }

    $line = '';
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            break;
        }
    }
    fclose($handle);

    if (str_starts_with(mb_strtolower($line), 'sep=')) {
        $sep = substr($line, 4, 1);
        return in_array($sep, [';', ',', "\t"], true) ? $sep : ';';
    }

    $counts = [
        ';' => substr_count($line, ';'),
        ',' => substr_count($line, ','),
        "\t" => substr_count($line, "\t"),
    ];

    arsort($counts);
    return (string)array_key_first($counts);
}

function imp_read_rows(string $path): array
{
    $delimiter = imp_detect_delimiter($path);
    $handle = fopen($path, 'rb');

    if (!$handle) {
        throw new RuntimeException('No fue posible leer el archivo CSV.');
    }

    $headers = [];
    $rows = [];
    $lineNumber = 0;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;

        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }

        if ($lineNumber === 1 && isset($data[0]) && str_starts_with(mb_strtolower(trim((string)$data[0])), 'sep=')) {
            continue;
        }

        if (!$headers) {
            foreach ($data as $header) {
                $headers[] = imp_header_key((string)$header);
            }
            continue;
        }

        $row = [];
        foreach ($headers as $idx => $header) {
            if ($header === '') {
                continue;
            }
            $row[$header] = isset($data[$idx]) ? imp_to_utf8((string)$data[$idx]) : '';
        }

        $isEmpty = true;
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                $isEmpty = false;
                break;
            }
        }

        if (!$isEmpty) {
            $rows[] = ['line' => $lineNumber, 'data' => $row];
        }
    }

    fclose($handle);

    if (!$headers) {
        throw new RuntimeException('El archivo CSV no tiene encabezados.');
    }

    return $rows;
}

$tipo = isset($_POST['tipo']) ? trim((string)$_POST['tipo']) : 'apoderados';

try {
    $table = imp_table_for_tipo($tipo);

    if (!imp_table_exists($pdo, $table)) {
        throw new RuntimeException('No existe la tabla requerida: ' . $table);
    }

    if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
        throw new RuntimeException('Debe seleccionar un archivo CSV.');
    }

    $file = $_FILES['archivo'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo cargar el archivo CSV.');
    }

    $tmp = (string)$file['tmp_name'];
    $rows = imp_read_rows($tmp);

    $insertados = 0;
    $actualizados = 0;
    $pendientes = 0;
    $omitidos = 0;

    // Importante: esta tabla se crea antes de iniciar la transacción.
    // MySQL hace COMMIT implícito al ejecutar DDL como CREATE TABLE; si se ejecuta
    // dentro de una transacción, después PDO puede lanzar: "There is no active transaction".
    imp_create_pendientes_table($pdo);

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    foreach ($rows as $entry) {
        $line = (int)$entry['line'];
        $row = $entry['data'];
        $run = imp_normalize_run((string)($row['run'] ?? ''));

        if ($run === '') {
            imp_pending($pdo, $colegioId, $tipo, $line, null, 'RUN vacío o no informado.', $row, $userId);
            $pendientes++;
            continue;
        }

        if (!imp_valid_run($run)) {
            imp_pending($pdo, $colegioId, $tipo, $line, $run, 'RUN con dígito verificador inválido.', $row, $userId);
            $pendientes++;
            continue;
        }

        $payload = imp_build_payload($pdo, $table, $tipo, $row, $colegioId, $userId);

        if (count($payload) <= 1) {
            imp_pending($pdo, $colegioId, $tipo, $line, $run, 'No se encontraron datos compatibles para importar.', $row, $userId);
            $pendientes++;
            continue;
        }

        $existingId = imp_find_existing($pdo, $table, $run, $colegioId);

        if ($existingId !== null) {
            imp_update($pdo, $table, $existingId, $payload);
            $actualizados++;
        } else {
            imp_insert($pdo, $table, $payload);
            $insertados++;
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    if (function_exists('registrar_bitacora')) {
        try {
            registrar_bitacora(
                'importar',
                'importar_' . $tipo,
                $table,
                null,
                'Importación CSV: insertados=' . $insertados . ', actualizados=' . $actualizados . ', pendientes=' . $pendientes
            );
        } catch (Throwable $bitacoraError) {
            // La importación ya fue confirmada. No se revierte por una falla secundaria de bitácora.
        }
    }

    $msg = 'Importación finalizada. Insertados: ' . $insertados
        . ' · Actualizados: ' . $actualizados
        . ' · Pendientes: ' . $pendientes
        . ' · Omitidos: ' . $omitidos;

    imp_redirect('ok', $msg, $tipo);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    imp_redirect('error', $e->getMessage(), $tipo);
}
