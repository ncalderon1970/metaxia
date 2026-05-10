<?php
declare(strict_types=1);

function com_safe_tipo(string $tipo): string
{
    return in_array($tipo, ['alumnos', 'apoderados', 'docentes', 'asistentes'], true) ? $tipo : 'alumnos';
}

function com_tipo_meta(string $tipo): array
{
    $map = [
        'alumnos' => ['label'=>'Alumnos','singular'=>'Alumno/a','icon'=>'bi-mortarboard','desc'=>'Estudiantes registrados en el establecimiento.'],
        'apoderados' => ['label'=>'Apoderados','singular'=>'Apoderado/a','icon'=>'bi-people','desc'=>'Adultos responsables y contactos familiares.'],
        'docentes' => ['label'=>'Docentes','singular'=>'Docente','icon'=>'bi-person-video3','desc'=>'Profesionales docentes del establecimiento.'],
        'asistentes' => ['label'=>'Asistentes','singular'=>'Asistente','icon'=>'bi-person-workspace','desc'=>'Asistentes de la educación y funcionarios de apoyo.'],
    ];
    return $map[com_safe_tipo($tipo)];
}

function com_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function com_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function com_upper(?string $value): ?string
{
    $value = com_clean($value);
    return $value === null ? null : mb_strtoupper($value, 'UTF-8');
}

function com_email(?string $value): ?string
{
    $value = com_clean($value);
    return $value === null ? null : mb_strtolower($value, 'UTF-8');
}

function com_normalize_run(string $run): string
{
    $run = mb_strtoupper(trim($run), 'UTF-8');
    $run = str_replace(['.', ' ', '\t'], '', $run);
    $run = str_replace('–', '-', $run);
    if ($run === '') {
        throw new RuntimeException('Debe ingresar RUN.');
    }
    if (!str_contains($run, '-')) {
        $body = substr($run, 0, -1);
        $dv = substr($run, -1);
        $run = $body . '-' . $dv;
    }
    [$body, $dv] = array_pad(explode('-', $run, 2), 2, '');
    $body = preg_replace('/\D+/', '', $body) ?? '';
    $dv = mb_strtoupper(preg_replace('/[^0-9K]/i', '', $dv) ?? '', 'UTF-8');
    if ($body === '' || $dv === '') {
        throw new RuntimeException('RUN inválido.');
    }
    $sum = 0; $mul = 2;
    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $sum += ((int)$body[$i]) * $mul;
        $mul = $mul === 7 ? 2 : $mul + 1;
    }
    $res = 11 - ($sum % 11);
    $expected = $res === 11 ? '0' : ($res === 10 ? 'K' : (string)$res);
    if ($dv !== $expected) {
        throw new RuntimeException('RUN inválido: dígito verificador no corresponde.');
    }
    return $body . '-' . $dv;
}

function com_nombre_persona(array $row): string
{
    $parts = [];
    foreach (['nombres', 'apellido_paterno', 'apellido_materno'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    $name = trim(implode(' ', $parts));
    if ($name !== '') return $name;
    return trim((string)($row['nombre'] ?? '')) ?: 'Sin nombre';
}

function com_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function com_back_index(string $tipo, string $status = '', string $msg = ''): string
{
    $url = APP_URL . '/modules/comunidad/index.php?tipo=' . urlencode(com_safe_tipo($tipo));
    if ($status !== '') $url .= '&status=' . urlencode($status);
    if ($msg !== '') $url .= '&msg=' . urlencode($msg);
    return $url;
}

function com_require_operate(): void
{
    if (!Auth::canOperate()) {
        http_response_code(403);
        exit('Acceso no autorizado.');
    }
}

function com_fetch_person(PDO $pdo, string $tipo, int $id, int $colegioId): ?array
{
    $tipo = com_safe_tipo($tipo);
    $stmt = $pdo->prepare("SELECT * FROM {$tipo} WHERE id = ? AND colegio_id = ? LIMIT 1");
    $stmt->execute([$id, $colegioId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function com_table_name(string $tipo): string
{
    return com_safe_tipo($tipo);
}

function com_register_log(PDO $pdo, int $colegioId, int $userId, string $accion, string $entidad, int $entidadId, string $descripcion): void
{
    try {
        if (function_exists('registrar_bitacora')) {
            registrar_bitacora($pdo, $colegioId, $userId, 'comunidad', $accion, $entidad, $entidadId, $descripcion);
        }
    } catch (Throwable $e) {
        error_log('[Metis comunidad bitacora] ' . $e->getMessage());
    }
}
