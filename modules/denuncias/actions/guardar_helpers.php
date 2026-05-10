<?php
/**
 * Metis 2.0 · Denuncias › Guardar — Funciones auxiliares
 * Fase 2B
 */
declare(strict_types=1);

function gd_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function gd_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function gd_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function gd_clean(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function gd_upper(?string $value): ?string
{
    $value = gd_clean($value);
    return $value === null ? null : mb_strtoupper($value, 'UTF-8');
}

function gd_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    if (!gd_table_exists($pdo, $table)) {
        throw new RuntimeException('No existe la tabla requerida: ' . $table);
    }

    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!gd_column_exists($pdo, $table, $column)) {
            continue;
        }
        $columns[] = gd_quote($column);
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para insertar en ' . $table . '.');
    }

    $stmt = $pdo->prepare('INSERT INTO ' . gd_quote($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function gd_estado_ingresado(PDO $pdo): ?int
{
    if (!gd_table_exists($pdo, 'estado_caso') || !gd_column_exists($pdo, 'estado_caso', 'codigo')) {
        return null;
    }

    try {
        // Try known initial-state codes in order of preference
        foreach (['ingresado', 'recepcion', 'inicial', 'nuevo'] as $codigo) {
            $stmt = $pdo->prepare("SELECT id FROM estado_caso WHERE codigo = ? AND activo = 1 LIMIT 1");
            $stmt->execute([$codigo]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }
        // Fallback: first non-borrador active estado by orden_visual
        $stmt = $pdo->query("SELECT id FROM estado_caso WHERE codigo != 'borrador' AND activo = 1 ORDER BY orden_visual ASC, id ASC LIMIT 1");
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function gd_redirect_error(string $msg): void
{
    header('Location: ' . APP_URL . '/modules/denuncias/crear.php?status=error&msg=' . urlencode($msg));
    exit;
}

function gd_historial(PDO $pdo, int $casoId, string $tipo, string $titulo, string $detalle, int $userId): void
{
    if (!gd_table_exists($pdo, 'caso_historial')) {
        return;
    }

    try {
        gd_insert_dynamic($pdo, 'caso_historial', [
            'caso_id' => $casoId,
            'tipo_evento' => $tipo,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'detalle' => $detalle,
            'descripcion' => $detalle,
            'user_id' => $userId > 0 ? $userId : null,
            'usuario_id' => $userId > 0 ? $userId : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
    }
}

function gd_bitacora(string $modulo, string $accion, string $tabla, int $registroId, string $detalle): void
{
    if (!function_exists('registrar_bitacora')) {
        return;
    }

    try {
        registrar_bitacora($modulo, $accion, $tabla, $registroId, $detalle);
    } catch (Throwable $e) {
    }
}

function gd_post_array(string $key): array
{
    $value = $_POST[$key] ?? [];

    if (is_array($value)) {
        return array_values($value);
    }

    $value = trim((string)$value);
    return $value === '' ? [] : [$value];
}

function gd_array_value(array $values, int $index, mixed $default = null): mixed
{
    return array_key_exists($index, $values) ? $values[$index] : $default;
}

function gd_tipo_persona_normalizado(?string $tipoBusqueda, ?string $tipoPersona): string
{
    $tipoPersona = gd_clean($tipoPersona);
    $tipoBusqueda = gd_clean($tipoBusqueda);

    $permitidos = ['alumno', 'docente', 'asistente', 'apoderado', 'externo'];

    if ($tipoPersona !== null && in_array($tipoPersona, $permitidos, true)) {
        return $tipoPersona;
    }

    if ($tipoBusqueda === 'alumno') {
        return 'alumno';
    }

    if ($tipoBusqueda === 'apoderado') {
        return 'apoderado';
    }

    if ($tipoBusqueda === 'funcionario') {
        return 'docente';
    }

    return 'externo';
}


function gd_fecha_hora_post(string $key): string
{
    $raw = gd_clean((string)($_POST[$key] ?? ''));

    if ($raw === null) {
        throw new RuntimeException('Debe indicar fecha y hora del incidente.');
    }

    $raw = str_replace('T', ' ', $raw);
    $ts = strtotime($raw);

    if (!$ts) {
        throw new RuntimeException('La fecha y hora del incidente no es válida.');
    }

    return date('Y-m-d H:i:s', $ts);
}

function gd_flags_permitidos(string $key, array $permitidos): array
{
    $values = $_POST[$key] ?? [];

    if (!is_array($values)) {
        $values = [$values];
    }

    $out = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && in_array($value, $permitidos, true)) {
            $out[] = $value;
        }
    }

    return array_values(array_unique($out));
}
function gd_calcular_dv_mod11(string $numero): string
{
    $numero = preg_replace('/\D+/', '', $numero) ?? '';

    if ($numero === '') {
        throw new RuntimeException('No se puede calcular DV de un número vacío.');
    }

    $factor = 2;
    $suma = 0;

    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $suma += ((int)$numero[$i]) * $factor;
        $factor++;

        if ($factor > 7) {
            $factor = 2;
        }
    }

    $resto = $suma % 11;
    $dv = 11 - $resto;

    if ($dv === 11) {
        return '0';
    }

    if ($dv === 10) {
        return 'K';
    }

    return (string)$dv;
}

function gd_generar_numero_caso(PDO $pdo, string $fechaIngreso): array
{
    if (!gd_table_exists($pdo, 'caso_correlativos')) {
        throw new RuntimeException('Falta la tabla caso_correlativos. Ejecuta primero el SQL de la Fase 0.5.38H.');
    }

    $anio = (int)date('Y', strtotime($fechaIngreso));
    $anioDosDigitos = substr((string)$anio, -2);

    $stmtInit = $pdo->prepare(
        "INSERT INTO caso_correlativos (anio, ultimo_correlativo, created_at) " .
        "VALUES (?, 0, NOW()) " .
        "ON DUPLICATE KEY UPDATE anio = VALUES(anio)"
    );
    $stmtInit->execute([$anio]);

    $stmtLock = $pdo->prepare("SELECT ultimo_correlativo FROM caso_correlativos WHERE anio = ? FOR UPDATE");
    $stmtLock->execute([$anio]);
    $ultimo = (int)$stmtLock->fetchColumn();

    $siguiente = $ultimo + 1;

    if ($siguiente > 9999999) {
        throw new RuntimeException('Se agotó el rango anual de numeración de casos para el año ' . $anio . '.');
    }

    $base = $anioDosDigitos . str_pad((string)$siguiente, 7, '0', STR_PAD_LEFT);
    $dv = gd_calcular_dv_mod11($base);
    $numeroCaso = $base . '-' . $dv;

    $stmtUpdate = $pdo->prepare(
        "UPDATE caso_correlativos " .
        "SET ultimo_correlativo = ?, updated_at = NOW() " .
        "WHERE anio = ? " .
        "LIMIT 1"
    );
    $stmtUpdate->execute([$siguiente, $anio]);

    return [
        'anio_caso' => $anio,
        'correlativo_anual' => $siguiente,
        'numero_caso_base' => $base,
        'numero_caso_dv' => $dv,
        'numero_caso' => $numeroCaso,
    ];
}
function gd_normalizar_participantes_desde_post(): array
{
    $tiposBusqueda = gd_post_array('p_tipo_busqueda');
    $personaIds = gd_post_array('p_persona_id');
    $tiposPersona = gd_post_array('p_tipo_persona');
    $runs = gd_post_array('p_run');
    $nombres = gd_post_array('p_nombre_referencial');
    $roles = gd_post_array('p_rol_en_caso');
    $anonimos = gd_post_array('p_es_anonimo');

    $max = max(
        count($tiposBusqueda),
        count($personaIds),
        count($tiposPersona),
        count($runs),
        count($nombres),
        count($roles),
        count($anonimos)
    );

    $participantes = [];
    $rolesPermitidos = ['victima', 'denunciante', 'testigo', 'denunciado', 'otro'];

    for ($i = 0; $i < $max; $i++) {
        $rol = gd_clean((string)gd_array_value($roles, $i, ''));
        $nombre = gd_upper((string)gd_array_value($nombres, $i, ''));
        $run = gd_upper((string)gd_array_value($runs, $i, ''));
        $personaId = (int)gd_array_value($personaIds, $i, 0);
        $tipoBusqueda = gd_clean((string)gd_array_value($tiposBusqueda, $i, 'externo')) ?? 'externo';
        $tipoPersonaRaw = gd_clean((string)gd_array_value($tiposPersona, $i, ''));
        $anonimo = (int)gd_array_value($anonimos, $i, 0) === 1 ? 1 : 0;

        $tieneAlgunaInfo = $rol !== null || $nombre !== null || $run !== null || $personaId > 0;

        if (!$tieneAlgunaInfo) {
            continue;
        }

        if ($rol === null || !in_array($rol, $rolesPermitidos, true)) {
            throw new RuntimeException('Cada interviniente registrado debe tener una condición válida.');
        }

        if ($nombre === null) {
            throw new RuntimeException('Cada interviniente registrado debe tener nombre completo o N/N.');
        }

        if ($run === null) {
            $run = '0-0';
        }

        if ($nombre === 'N/N') {
            $run = '0-0';
            $personaId = 0;
        }

        $tipoPersona = gd_tipo_persona_normalizado($tipoBusqueda, $tipoPersonaRaw);

        $participantes[] = [
            'tipo_persona' => $tipoPersona,
            'persona_id' => $personaId > 0 ? $personaId : null,
            'run' => $run,
            'nombre' => $nombre,
            'rol' => $rol,
            'es_anonimo' => $rol === 'denunciante' ? $anonimo : 0,
            'es_nn' => $nombre === 'N/N' || $run === '0-0',
        ];
    }

    if (!$participantes) {
        throw new RuntimeException('Debe registrar al menos un interviniente. Si no conoce los datos, use N/N y RUN 0-0.');
    }

    return $participantes;
}

function gd_guardar_participantes(PDO $pdo, int $casoId, array $participantes): void
{
    if (!gd_table_exists($pdo, 'caso_participantes')) {
        return;
    }

    foreach ($participantes as $idx => $p) {
        $detalle = 'Participante registrado en el ingreso inicial de la denuncia.';

        if (!empty($p['es_nn'])) {
            $detalle = 'Participante registrado como N/N y RUN 0-0 por no contar inicialmente con antecedentes de identificación.';
        }

        if ((int)($p['es_anonimo'] ?? 0) === 1) {
            $detalle = 'Participante denunciante registrado con identidad reservada para informes a apoderados o comunidad escolar.';
        }

        gd_insert_dynamic($pdo, 'caso_participantes', [
            'caso_id' => $casoId,
            'tipo_persona' => $p['tipo_persona'],
            'persona_id' => $p['persona_id'],
            'nombre_referencial' => $p['nombre'],
            'nombre_ref' => $p['nombre'],
            'nombre' => $p['nombre'],
            'run' => $p['run'],
            'rol_en_caso' => $p['rol'],
            'rol' => $p['rol'],
            'es_anonimo' => (int)$p['es_anonimo'],
            'anonimo' => (int)$p['es_anonimo'],
            'identidad_reservada' => (int)$p['es_anonimo'],
            'orden' => $idx + 1,
            'observacion' => $detalle,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

function gd_primer_denunciante(array $participantes): ?array
{
    foreach ($participantes as $p) {
        if (($p['rol'] ?? '') === 'denunciante') {
            return $p;
        }
    }

    return null;
}

function gd_guardar_aula_segura(PDO $pdo, int $casoId, int $colegioId, int $userId, array $causales, ?string $observacion, string $relato): void
{
    if (!gd_table_exists($pdo, 'caso_aula_segura')) {
        return;
    }

    $map = [
        'agresion_sexual' => 'causal_agresion_sexual',
        'agresion_fisica_lesiones' => 'causal_agresion_fisica_lesiones',
        'armas' => 'causal_armas',
        'artefactos_incendiarios' => 'causal_artefactos_incendiarios',
        'infraestructura_esencial' => 'causal_infraestructura_esencial',
        'grave_reglamento' => 'causal_grave_reglamento',
    ];

    $data = [
        'caso_id' => $casoId,
        'colegio_id' => $colegioId > 0 ? $colegioId : null,
        'posible_aula_segura' => 1,
        'estado' => 'posible',
        'descripcion_hecho' => $relato,
        'fuente_informacion' => 'denuncia_inicial',
        'observaciones' => $observacion,
        'creado_por' => $userId > 0 ? $userId : null,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    foreach ($map as $codigo => $column) {
        $data[$column] = in_array($codigo, $causales, true) ? 1 : 0;
    }

    $aulaId = gd_insert_dynamic($pdo, 'caso_aula_segura', $data);

    if (gd_table_exists($pdo, 'caso_aula_segura_historial')) {
        gd_insert_dynamic($pdo, 'caso_aula_segura_historial', [
            'caso_id' => $casoId,
            'caso_aula_segura_id' => $aulaId > 0 ? $aulaId : null,
            'colegio_id' => $colegioId > 0 ? $colegioId : null,
            'accion' => 'marcar_posible_aula_segura',
            'estado_anterior' => 'no_aplica',
            'estado_nuevo' => 'posible',
            'detalle' => 'Se marca posible Aula Segura en el ingreso inicial de la denuncia.',
            'usuario_id' => $userId > 0 ? $userId : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}