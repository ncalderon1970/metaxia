<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();
CSRF::requireValid($_POST['_token'] ?? null);

if (!Auth::canOperate()) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

$pdo = DB::conn();
$user = Auth::user() ?? [];
$userId = (int)($user['id'] ?? 0);
$colegioId = (int)($user['colegio_id'] ?? 0);

function imp_redirect(string $status, string $msg, string $tipo = 'alumnos'): void
{
    $url = APP_URL . '/modules/importar/index.php?tipo=' . urlencode($tipo);
    $url .= '&status=' . urlencode($status) . '&msg=' . urlencode($msg);
    header('Location: ' . $url);
    exit;
}

function imp_allowed_tipo(string $tipo): string
{
    $allowed = ['alumnos', 'apoderados', 'docentes', 'asistentes'];
    if (!in_array($tipo, $allowed, true)) {
        throw new RuntimeException('Tipo de importación no válido.');
    }
    return $tipo;
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
    $value = mb_strtolower(imp_strip_accents(imp_to_utf8($value)), 'UTF-8');
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    $value = trim($value, '_');

    $aliases = [
        'rut' => 'run',
        'run_estudiante' => 'run',
        'run_alumno' => 'run',
        'run_apoderado' => 'run',
        'nombre' => 'nombres',
        'nombre_s' => 'nombres',
        'nombres_completos' => 'nombre_completo',
        'nombre_completo' => 'nombre_completo',
        'ap_paterno' => 'apellido_paterno',
        'paterno' => 'apellido_paterno',
        'primer_apellido' => 'apellido_paterno',
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

function imp_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function imp_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;

    $value = str_replace('/', '-', $value);
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function imp_normalize_run(?string $run): string
{
    $run = mb_strtoupper(trim((string)$run), 'UTF-8');
    $run = str_replace(['.', ' ', "\t"], '', $run);
    $run = preg_replace('/[^0-9Kk\-]/u', '', $run) ?? '';

    if ($run === '') return '';

    if (!str_contains($run, '-') && mb_strlen($run) >= 2) {
        $run = mb_substr($run, 0, -1) . '-' . mb_substr($run, -1);
    }

    [$body, $dv] = array_pad(explode('-', $run, 2), 2, '');
    $body = preg_replace('/\D/u', '', $body) ?? '';
    $dv = mb_strtoupper(preg_replace('/[^0-9K]/u', '', $dv) ?? '', 'UTF-8');

    return $body !== '' && $dv !== '' ? $body . '-' . $dv : '';
}

function imp_valid_run(string $run): bool
{
    $run = imp_normalize_run($run);
    if ($run === '' || !str_contains($run, '-')) return false;

    [$body, $dv] = explode('-', $run, 2);
    if ($body === '' || $dv === '') return false;

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
        if (!empty($row[$key])) $parts[] = (string)$row[$key];
    }
    if (!$parts && !empty($row['nombre_completo'])) $parts[] = (string)$row['nombre_completo'];
    $full = trim(implode(' ', $parts));
    return $full === '' ? null : mb_strtoupper($full, 'UTF-8');
}

function imp_detect_delimiter(string $path): string
{
    $handle = fopen($path, 'rb');
    if (!$handle) return ';';
    $line = '';
    while (($line = fgets($handle)) !== false) {
        if (trim($line) !== '') break;
    }
    fclose($handle);
    if (str_starts_with(mb_strtolower(trim($line)), 'sep=')) {
        $sep = substr(trim($line), 4, 1);
        return in_array($sep, [';', ',', "\t"], true) ? $sep : ';';
    }
    $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
    arsort($counts);
    return (string)array_key_first($counts);
}

function imp_read_rows(string $path): array
{
    $delimiter = imp_detect_delimiter($path);
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('No fue posible leer el archivo CSV.');

    $headers = [];
    $rows = [];
    $lineNumber = 0;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;
        if (count($data) === 1 && trim((string)$data[0]) === '') continue;
        if ($lineNumber === 1 && isset($data[0]) && str_starts_with(mb_strtolower(trim((string)$data[0])), 'sep=')) continue;

        if (!$headers) {
            foreach ($data as $header) $headers[] = imp_header_key((string)$header);
            continue;
        }

        $row = [];
        foreach ($headers as $idx => $header) {
            if ($header === '') continue;
            $row[$header] = isset($data[$idx]) ? imp_to_utf8((string)$data[$idx]) : '';
        }

        $isEmpty = true;
        foreach ($row as $value) {
            if (trim((string)$value) !== '') { $isEmpty = false; break; }
        }
        if (!$isEmpty) $rows[] = ['line' => $lineNumber, 'data' => $row];
    }

    fclose($handle);
    if (!$headers) throw new RuntimeException('El archivo CSV no tiene encabezados.');
    return $rows;
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
        $colegioId,
        $tipo,
        $fila,
        $run,
        $motivo,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $userId > 0 ? $userId : null,
    ]);
}

function imp_payload(string $tipo, array $row, int $colegioId): array
{
    $run = imp_normalize_run($row['run'] ?? '');
    $nombres = imp_upper($row['nombres'] ?? null);
    $apellidoPaterno = imp_upper($row['apellido_paterno'] ?? null);
    $apellidoMaterno = imp_upper($row['apellido_materno'] ?? null);
    $nombreCompleto = imp_full_name($row) ?? $nombres;

    if ($nombres === null && $nombreCompleto !== null) {
        $nombres = $nombreCompleto;
    }

    if ($nombres === null) {
        throw new RuntimeException('Nombres no informados.');
    }

    $email = imp_lower($row['email'] ?? null);
    $telefono = imp_clean($row['telefono'] ?? null);
    $direccion = imp_upper($row['direccion'] ?? null);

    if ($tipo === 'alumnos') {
        if ($apellidoPaterno === null) {
            throw new RuntimeException('Apellido paterno no informado para alumno.');
        }
        return [
            'colegio_id' => $colegioId,
            'run' => $run,
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'fecha_nacimiento' => imp_date($row['fecha_nacimiento'] ?? null),
            'curso' => imp_upper($row['curso'] ?? null),
            'genero' => imp_upper($row['genero'] ?? null),
            'direccion' => $direccion,
            'telefono' => $telefono,
            'email' => $email,
            'observacion' => imp_clean($row['observacion'] ?? null),
            'activo' => 1,
        ];
    }

    if ($tipo === 'apoderados') {
        return [
            'colegio_id' => $colegioId,
            'run' => $run,
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'nombre' => $nombreCompleto ?? $nombres,
            'telefono' => $telefono,
            'telefono_secundario' => imp_clean($row['telefono_secundario'] ?? null),
            'email' => $email,
            'direccion' => $direccion,
            'observacion' => imp_clean($row['observacion'] ?? ($row['parentesco'] ?? null)),
            'activo' => 1,
        ];
    }

    if ($tipo === 'docentes') {
        return [
            'colegio_id' => $colegioId,
            'run' => $run,
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'nombre' => $nombreCompleto ?? $nombres,
            'email' => $email,
            'telefono' => $telefono,
            'cargo' => imp_upper($row['cargo'] ?? ($row['especialidad'] ?? null)),
            'activo' => 1,
        ];
    }

    if ($tipo === 'asistentes') {
        return [
            'colegio_id' => $colegioId,
            'run' => $run,
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'nombre' => $nombreCompleto ?? $nombres,
            'cargo' => imp_upper($row['cargo'] ?? null),
            'email' => $email,
            'telefono' => $telefono,
            'activo' => 1,
        ];
    }

    throw new RuntimeException('Tipo no soportado.');
}

function imp_find_existing(PDO $pdo, string $table, string $run, int $colegioId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE colegio_id = ? AND run = ? LIMIT 1");
    $stmt->execute([$colegioId, $run]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function imp_insert_or_update(PDO $pdo, string $table, array $data, ?int $existingId): void
{
    $now = date('Y-m-d H:i:s');
    $data['updated_at'] = $now;

    if ($existingId === null) {
        $data['created_at'] = $now;
        $columns = array_keys($data);
        $sql = "INSERT INTO {$table} (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return;
    }

    unset($data['colegio_id'], $data['run'], $data['created_at']);
    $sets = [];
    $params = [];
    foreach ($data as $column => $value) {
        $sets[] = "`{$column}` = ?";
        $params[] = $value;
    }
    $params[] = $existingId;
    $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1");
    $stmt->execute($params);
}

$tipo = isset($_POST['tipo']) ? trim((string)$_POST['tipo']) : 'alumnos';

try {
    $tipo = imp_allowed_tipo($tipo);

    if ($colegioId <= 0) {
        throw new RuntimeException('No se pudo determinar el establecimiento activo.');
    }

    if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
        throw new RuntimeException('Debe seleccionar un archivo CSV.');
    }

    $file = $_FILES['archivo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo cargar el archivo CSV.');
    }

    $originalName = (string)($file['name'] ?? '');
    $tmp = (string)$file['tmp_name'];
    $size = (int)($file['size'] ?? 0);

    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('El archivo debe pesar entre 1 byte y 10 MB.');
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        throw new RuntimeException('Solo se permiten archivos CSV.');
    }

    $rows = imp_read_rows($tmp);
    imp_create_pendientes_table($pdo);

    $insertados = 0;
    $actualizados = 0;
    $pendientes = 0;
    $omitidos = 0;

    $pdo->beginTransaction();

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

        $row['run'] = $run;

        try {
            $payload = imp_payload($tipo, $row, $colegioId);
            $existingId = imp_find_existing($pdo, $tipo, $run, $colegioId);
            imp_insert_or_update($pdo, $tipo, $payload, $existingId);

            if ($existingId === null) {
                $insertados++;
            } else {
                $actualizados++;
            }
        } catch (Throwable $rowError) {
            imp_pending($pdo, $colegioId, $tipo, $line, $run, $rowError->getMessage(), $row, $userId);
            $pendientes++;
        }
    }

    $pdo->commit();

    if (function_exists('registrar_bitacora')) {
        try {
            registrar_bitacora(
                'importar',
                'importar_' . $tipo,
                $tipo,
                null,
                'Importación CSV: insertados=' . $insertados . ', actualizados=' . $actualizados . ', pendientes=' . $pendientes . ', omitidos=' . $omitidos
            );
        } catch (Throwable $bitacoraError) {}
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
