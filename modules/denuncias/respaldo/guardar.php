<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

Auth::requireLogin();

$pdo = DB::conn();
$user = Auth::user() ?? [];

$colegioId = (int)($user['colegio_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/modules/denuncias/crear.php');
    exit;
}

function den_guardar_col_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function den_guardar_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function den_guardar_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $columns = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!den_guardar_col_exists($pdo, $table, $column)) {
            continue;
        }

        $columns[] = '`' . str_replace('`', '``', $column) . '`';
        $placeholders[] = '?';
        $params[] = $value;
    }

    if (!$columns) {
        throw new RuntimeException('No hay columnas compatibles para insertar en ' . $table . '.');
    }

    $sql = "
        INSERT INTO {$table} (
            " . implode(', ', $columns) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function den_guardar_numero_caso(PDO $pdo): string
{
    $base = 'CASO-' . date('Ymd-His');
    $numero = $base;

    $i = 1;

    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM casos WHERE numero_caso = ?");
        $stmt->execute([$numero]);

        if ((int)$stmt->fetchColumn() === 0) {
            return $numero;
        }

        $numero = $base . '-' . $i;
        $i++;
    }
}

function den_guardar_estado_recepcion(PDO $pdo): ?int
{
    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM estado_caso
            WHERE codigo = 'recepcion'
            LIMIT 1
        ");
        $stmt->execute();

        $id = $stmt->fetchColumn();

        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function den_guardar_error(string $message): void
{
    http_response_code(400);

    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
    echo '<title>Error al guardar denuncia</title>';
    echo '<style>
        body{font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;margin:0;padding:2rem;color:#0f172a;}
        .box{background:#fff;border:1px solid #fecaca;border-radius:18px;padding:1.5rem;max-width:760px;margin:auto;box-shadow:0 16px 40px rgba(15,23,42,.08);}
        h1{margin:0 0 .75rem;color:#991b1b;font-size:1.4rem;}
        p{color:#334155;line-height:1.5;}
        a{display:inline-flex;margin-top:1rem;background:#0f172a;color:#fff;text-decoration:none;border-radius:999px;padding:.65rem 1rem;font-weight:800;}
    </style>';
    echo '</head><body><div class="box">';
    echo '<h1>No fue posible guardar la denuncia</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<a href="' . APP_URL . '/modules/denuncias/crear.php">Volver al formulario</a>';
    echo '</div></body></html>';
    exit;
}

try {
    CSRF::requireValid($_POST['_token'] ?? null);

    if ($colegioId <= 0) {
        throw new RuntimeException('No se pudo identificar el establecimiento asociado al usuario.');
    }

    if (!den_guardar_table_exists($pdo, 'casos')) {
        throw new RuntimeException('La tabla casos no existe.');
    }

    $relato = clean((string)($_POST['relato'] ?? ''));

    if ($relato === '') {
        throw new RuntimeException('El relato de los hechos es obligatorio.');
    }

    $denuncianteNombre = clean((string)($_POST['denunciante_nombre'] ?? ''));
    $denuncianteRun = cleanRun((string)($_POST['denunciante_run'] ?? ''));

    $esAnonimo = isset($_POST['es_anonimo']) ? 1 : 0;

    $contexto = clean((string)($_POST['contexto'] ?? ''));
    $lugarHechos = clean((string)($_POST['lugar_hechos'] ?? ''));
    $fechaHechos = clean((string)($_POST['fecha_hechos'] ?? ''));

    $involucraMoviles = isset($_POST['involucra_moviles']) ? 1 : 0;
    $requiereReanalisis = isset($_POST['requiere_reanalisis_ia']) ? 1 : 0;

    $estadoCasoId = (int)($_POST['estado_caso_id'] ?? 0);

    if ($estadoCasoId <= 0) {
        $estadoCasoId = den_guardar_estado_recepcion($pdo) ?? 0;
    }

    $semaforo = clean((string)($_POST['semaforo'] ?? 'verde'));
    $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));

    if (!in_array($semaforo, ['verde', 'amarillo', 'rojo'], true)) {
        $semaforo = 'verde';
    }

    if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
        $prioridad = 'media';
    }

    $numeroCaso = den_guardar_numero_caso($pdo);

    $pdo->beginTransaction();

    $casoData = [
        'colegio_id' => $colegioId,
        'estado_caso_id' => $estadoCasoId > 0 ? $estadoCasoId : null,
        'numero_caso' => $numeroCaso,
        'fecha_ingreso' => date('Y-m-d H:i:s'),

        'denunciante_nombre' => $denuncianteNombre !== '' ? $denuncianteNombre : null,
        'denunciante_run' => $denuncianteRun !== '' ? $denuncianteRun : null,
        'es_anonimo' => $esAnonimo,

        'relato' => $relato,
        'contexto' => $contexto !== '' ? $contexto : null,
        'lugar_hechos' => $lugarHechos !== '' ? $lugarHechos : null,
        'fecha_hechos' => $fechaHechos !== '' ? str_replace('T', ' ', $fechaHechos) . ':00' : null,

        'involucra_moviles' => $involucraMoviles,

        'clasificacion_ia' => null,
        'resumen_ia' => null,
        'recomendacion_ia' => null,
        'requiere_reanalisis_ia' => $requiereReanalisis,

        'semaforo' => $semaforo,
        'prioridad' => $prioridad,
        'estado' => 'abierto',

        'creado_por' => $userId > 0 ? $userId : null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $casoId = den_guardar_insert_dynamic($pdo, 'casos', $casoData);

    if (den_guardar_table_exists($pdo, 'caso_participantes')) {
        $participanteNombre = clean((string)($_POST['participante_nombre'] ?? ''));
        $participanteRun = cleanRun((string)($_POST['participante_run'] ?? ''));
        $participanteTipo = clean((string)($_POST['participante_tipo_persona'] ?? 'externo'));
        $participanteRol = clean((string)($_POST['participante_rol_en_caso'] ?? 'involucrado'));
        $participanteObservacion = clean((string)($_POST['participante_observacion'] ?? ''));
        $participanteReserva = isset($_POST['participante_reserva']) ? 1 : 0;

        if ($participanteNombre !== '') {
            if ($participanteRun === '') {
                $participanteRun = '0-0';
            }

            den_guardar_insert_dynamic($pdo, 'caso_participantes', [
                'caso_id' => $casoId,
                'tipo_persona' => $participanteTipo,
                'persona_id' => null,
                'nombre_referencial' => $participanteNombre,
                'run' => $participanteRun,
                'rol_en_caso' => $participanteRol,
                'solicita_reserva_identidad' => $participanteReserva,
                'observacion_reserva' => $participanteReserva ? 'Reserva solicitada al registrar la denuncia.' : null,
                'identidad_confirmada' => 0,
                'fecha_identificacion' => null,
                'identificado_por' => null,
                'observacion_identificacion' => null,
                'observacion' => $participanteObservacion !== '' ? $participanteObservacion : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    if (den_guardar_table_exists($pdo, 'caso_historial')) {
        den_guardar_insert_dynamic($pdo, 'caso_historial', [
            'caso_id' => $casoId,
            'tipo_evento' => 'creacion',
            'titulo' => 'Caso creado',
            'detalle' => 'Se creó el expediente ' . $numeroCaso . ' desde el formulario de nueva denuncia.',
            'user_id' => $userId > 0 ? $userId : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    if (
        den_guardar_table_exists($pdo, 'caso_alertas')
        && ($semaforo === 'rojo' || $prioridad === 'alta' || $requiereReanalisis === 1)
    ) {
        $motivos = [];

        if ($semaforo === 'rojo') {
            $motivos[] = 'semáforo rojo';
        }

        if ($prioridad === 'alta') {
            $motivos[] = 'prioridad alta';
        }

        if ($requiereReanalisis === 1) {
            $motivos[] = 'requiere análisis especializado';
        }

        den_guardar_insert_dynamic($pdo, 'caso_alertas', [
            'caso_id' => $casoId,
            'tipo' => 'revision_prioritaria',
            'mensaje' => 'Caso requiere revisión prioritaria por: ' . implode(', ', $motivos) . '.',
            'prioridad' => $prioridad === 'alta' ? 'alta' : 'media',
            'estado' => 'pendiente',
            'fecha_alerta' => date('Y-m-d H:i:s'),
            'resuelta_por' => null,
            'resuelta_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    registrar_bitacora(
        'denuncias',
        'crear_caso',
        'casos',
        $casoId,
        'Caso creado: ' . $numeroCaso
    );

    $pdo->commit();

    header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=resumen');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    den_guardar_error($e->getMessage());
}