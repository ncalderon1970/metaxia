<?php
declare(strict_types=1);
/**
 * Metis 2.0 · Denuncias › Guardar
 * Orquestador limpio — Fase 2B
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once __DIR__ . '/actions/guardar_helpers.php';

Auth::requireLogin();
if (!Auth::canOperate()) {
    http_response_code(403);
    header('Location: ' . APP_URL . '/modules/denuncias/index.php');
    exit;
}

$pdo    = DB::conn();
$user   = Auth::user() ?? [];
$userId = (int)($user['id']         ?? 0);
$cid    = (int)($user['colegio_id'] ?? 0);


try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . APP_URL . '/modules/denuncias/crear.php');
        exit;
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $esBorrador  = (string)($_POST['_submit_mode'] ?? '') === 'borrador';
    $borradorId  = (int)($_POST['_borrador_id'] ?? 0); // ID de caso en estado borrador a actualizar

    // Para borrador: obtener el estado_caso_id con código 'borrador'
    if ($esBorrador) {
        try {
            $sb = $pdo->prepare("SELECT id FROM estado_caso WHERE codigo = 'borrador' LIMIT 1");
            $sb->execute();
            $idBorrador = (int)$sb->fetchColumn();
            if ($idBorrador === 0) {
                // Si no existe, insertar el estado borrador
                $pdo->prepare("INSERT IGNORE INTO estado_caso (codigo, nombre, orden_visual) VALUES ('borrador', 'Borrador', 0)")->execute();
                $idBorrador = (int)$pdo->lastInsertId();
            }
        } catch (Throwable $e) { $idBorrador = 0; }
    }

    if (!gd_table_exists($pdo, 'casos')) {
        throw new RuntimeException('No existe la tabla casos.');
    }

    $relato = gd_clean((string)($_POST['relato'] ?? ''));
    $contexto = gd_upper((string)($_POST['contexto'] ?? ''));
    $lugarHechos = gd_upper((string)($_POST['lugar_hechos'] ?? ''));
    $fechaHoraIncidente = gd_fecha_hora_post('fecha_hora_incidente');
    $fechaHechos = date('Y-m-d', strtotime($fechaHoraIncidente));

    $ley21809Permitidos = [
        'afecta_buen_trato',
        'acoso_escolar',
        'violencia_fisica',
        'violencia_psicologica',
        'discriminacion',
        'ciberacoso_medios_digitales',
        'afecta_salud_mental',
        'requiere_derivacion',
    ];

    $rex782Permitidos = [
        'requiere_medida_formativa',
        'requiere_apoyo_psicosocial',
        'requiere_medida_reparatoria',
        'posible_medida_disciplinaria',
        'requiere_justo_procedimiento',
        'requiere_proporcionalidad',
        'gestion_colaborativa_conflicto',
        'posible_expulsion_cancelacion',
    ];

    $ley21809Flags = gd_flags_permitidos('ley21809_flags', $ley21809Permitidos);
    $rex782Flags = gd_flags_permitidos('rex782_flags', $rex782Permitidos);
    $observacionNormativa = gd_upper((string)($_POST['denuncia_normativa_observacion'] ?? ''));
    $canalIngreso = gd_clean((string)($_POST['canal_ingreso'] ?? 'presencial')) ?? 'presencial';

    $canalesPermitidos = ['presencial', 'correo', 'telefono', 'formulario', 'derivacion_funcionario', 'otro'];
    if (!in_array($canalIngreso, $canalesPermitidos, true)) {
        $canalIngreso = 'presencial';
    }
    $comunicacionModalidad = gd_clean((string)($_POST['comunicacion_apoderado_modalidad'] ?? ''));
    $comunicacionEstado = gd_clean((string)($_POST['comunicacion_apoderado_estado'] ?? 'pendiente')) ?? 'pendiente';
    $comunicacionFecha = gd_clean((string)($_POST['comunicacion_apoderado_fecha'] ?? ''));
    $comunicacionNotas = gd_upper((string)($_POST['comunicacion_apoderado_notas'] ?? ''));

    $modalidadesComunicacionPermitidas = ['presencial', 'telefono', 'correo'];
    $estadosComunicacionPermitidos = ['pendiente', 'realizada', 'no_corresponde'];

    if ($comunicacionModalidad !== null && !in_array($comunicacionModalidad, $modalidadesComunicacionPermitidas, true)) {
        $comunicacionModalidad = null;
    }

    if (!in_array($comunicacionEstado, $estadosComunicacionPermitidos, true)) {
        $comunicacionEstado = 'pendiente';
    }

    if ($comunicacionFecha !== null) {
        $tsComunicacion = strtotime($comunicacionFecha);
        if (!$tsComunicacion) {
            throw new RuntimeException('La fecha de comunicación al apoderado no es válida.');
        }
        $comunicacionFecha = date('Y-m-d', $tsComunicacion);
    }

    if (($comunicacionModalidad !== null && $comunicacionFecha === null) || ($comunicacionModalidad === null && $comunicacionFecha !== null)) {
        throw new RuntimeException('Para registrar comunicación al apoderado debe indicar modalidad y fecha.');
    }

    if ($comunicacionModalidad !== null && $comunicacionFecha !== null) {
        $comunicacionEstado = 'realizada';
    }

    if ($comunicacionEstado === 'realizada' && ($comunicacionModalidad === null || $comunicacionFecha === null)) {
        throw new RuntimeException('Si la comunicación al apoderado está realizada, debe indicar modalidad y fecha.');
    }

    $comunicacionRealizada = $comunicacionEstado === 'realizada' ? 1 : 0;


    if ($relato === null) {
        throw new RuntimeException('El relato es obligatorio.');
    }

    $participantes = gd_normalizar_participantes_desde_post();
    $denunciante = gd_primer_denunciante($participantes);

    $denuncianteNombre = $denunciante['nombre'] ?? gd_upper((string)($_POST['denunciante'] ?? ''));
    $denuncianteRun = $denunciante['run'] ?? null;
    $denunciantePersonaId = (int)($denunciante['persona_id'] ?? 0);
    $esDenuncianteAnonimo = (int)($denunciante['es_anonimo'] ?? 0);

    $prioridad = gd_clean((string)($_POST['prioridad'] ?? 'media')) ?? 'media';
    $semaforo = gd_clean((string)($_POST['semaforo'] ?? 'verde')) ?? 'verde';

    if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
        $prioridad = 'media';
    }

    if (!in_array($semaforo, ['verde', 'amarillo', 'rojo'], true)) {
        $semaforo = 'verde';
    }

    $estadoCasoIdPost = (int)($_POST['estado_caso_id'] ?? 0);
    $estadoCasoId = $estadoCasoIdPost > 0 ? $estadoCasoIdPost : gd_estado_ingresado($pdo);

    $posibleAulaSegura = isset($_POST['posible_aula_segura']) ? 1 : 0;
    $causales = $_POST['aula_segura_causales'] ?? [];

    if (!is_array($causales)) {
        $causales = [];
    }

    $permitidas = ['agresion_sexual', 'agresion_fisica_lesiones', 'armas', 'artefactos_incendiarios', 'infraestructura_esencial', 'grave_reglamento'];
    $causales = array_values(array_intersect($permitidas, array_map('strval', $causales)));
    $observacionAula = gd_upper((string)($_POST['aula_segura_observacion_preliminar'] ?? ''));

    if ($posibleAulaSegura === 1 && !$causales) {
        throw new RuntimeException('Debe seleccionar al menos una causal preliminar de Aula Segura.');
    }

    $fechaIngreso = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    $numeroInfo = gd_generar_numero_caso($pdo, $fechaIngreso);
    $numero = (string)$numeroInfo['numero_caso'];

    $casoData = [
        'colegio_id' => $cid > 0 ? $cid : null,
        'numero_caso' => $numero,
        'codigo' => $numero,
        'anio_caso' => (int)$numeroInfo['anio_caso'],
        'correlativo_anual' => (int)$numeroInfo['correlativo_anual'],
        'numero_caso_base' => (string)$numeroInfo['numero_caso_base'],
        'numero_caso_dv' => (string)$numeroInfo['numero_caso_dv'],
        'fecha_ingreso' => $fechaIngreso,
        'fecha_hora_incidente' => $fechaHoraIncidente,
        'fecha_hechos' => $fechaHechos,
        'canal_ingreso' => $canalIngreso,
        'comunicacion_apoderado_estado' => $comunicacionEstado,
        'comunicacion_apoderado_realizada' => $comunicacionRealizada,
        'comunicacion_apoderado_modalidad' => $comunicacionModalidad,
        'comunicacion_apoderado_fecha' => $comunicacionFecha,
        'comunicacion_apoderado_notas' => $comunicacionNotas,
        'comunicacion_apoderado_registrado_por' => ($comunicacionModalidad !== null || $comunicacionNotas !== null) && $userId > 0 ? $userId : null,
        'comunicacion_apoderado_registrado_at' => ($comunicacionModalidad !== null || $comunicacionNotas !== null) ? $fechaIngreso : null,
        'denunciante_nombre' => $denuncianteNombre,
        'denunciante' => $denuncianteNombre,
        'denunciante_run' => $denuncianteRun,
        'denunciante_persona_id' => $denunciantePersonaId > 0 ? $denunciantePersonaId : null,
        'es_anonimo' => $esDenuncianteAnonimo,
        'relato' => $relato,
        'descripcion' => $relato,
        'contexto' => $contexto,
        'lugar_hechos' => $lugarHechos,
        'ley21809_flags' => json_encode($ley21809Flags, JSON_UNESCAPED_UNICODE),
        'rex782_flags' => json_encode($rex782Flags, JSON_UNESCAPED_UNICODE),
        'denuncia_normativa_observacion' => $observacionNormativa,
        'involucra_moviles' => in_array('ciberacoso_medios_digitales', $ley21809Flags, true) ? 1 : 0,
        'estado' => $esBorrador ? 'borrador' : 'abierto',
        'estado_caso_id' => $esBorrador ? ($idBorrador ?: $estadoCasoId) : $estadoCasoId,
        'semaforo' => $semaforo,
        'prioridad' => $prioridad,
        'posible_aula_segura' => $posibleAulaSegura,
        'aula_segura_estado' => $posibleAulaSegura === 1 ? 'posible' : 'no_aplica',
        'aula_segura_marcado_por' => $posibleAulaSegura === 1 && $userId > 0 ? $userId : null,
        'aula_segura_marcado_at' => $posibleAulaSegura === 1 ? $fechaIngreso : null,
        'aula_segura_causales_preliminares' => $posibleAulaSegura === 1 ? json_encode($causales, JSON_UNESCAPED_UNICODE) : null,
        'aula_segura_observacion_preliminar' => $posibleAulaSegura === 1 ? $observacionAula : null,
        'creado_por' => $userId > 0 ? $userId : null,
        'created_at' => $fechaIngreso,
        // ── Fase 3: Ley 21.545 y Ley 21.430 ──────────────────
        'marco_legal'                     => gd_clean($_POST['marco_legal'] ?? 'ley21809'),
        'involucra_nna_tea'               => (int)($_POST['involucra_nna_tea'] ?? 0),
        'interés_superior_aplicado'       => (int)($_POST['interes_superior_aplicado'] ?? 1),
        'autonomia_progresiva_considerada'=> (int)($_POST['autonomia_progresiva_considerada'] ?? 0),
        'requiere_coordinacion_senape'    => (int)($_POST['requiere_coordinacion_senape'] ?? 0),
        'requiere_coordinacion_salud'     => (int)($_POST['requiere_coordinacion_salud'] ?? 0),
    ];

    $casoId = gd_insert_dynamic($pdo, 'casos', $casoData);

    // Si venía de un borrador, eliminar el borrador anterior
    if ($borradorId > 0 && $casoId !== $borradorId) {
        try {
            $pdo->prepare("DELETE FROM casos WHERE id = ? AND colegio_id = ? AND estado = 'borrador'")
                ->execute([$borradorId, $colegioId]);
        } catch (Throwable $e) {}
    }

    gd_historial($pdo, $casoId, 'creacion', 'Caso creado', 'Se registra la denuncia inicial en el sistema con ' . count($participantes) . ' interviniente(s). Fecha/hora incidente: ' . $fechaHoraIncidente . '.', $userId);

    if ($comunicacionEstado !== 'pendiente' || $comunicacionModalidad !== null || $comunicacionNotas !== null) {
        $detalleComunicacion = 'Estado: ' . $comunicacionEstado;
        if ($comunicacionModalidad !== null) {
            $detalleComunicacion .= ' | Modalidad: ' . $comunicacionModalidad;
        }
        if ($comunicacionFecha !== null) {
            $detalleComunicacion .= ' | Fecha: ' . $comunicacionFecha;
        }
        if ($comunicacionNotas !== null) {
            $detalleComunicacion .= ' | Notas: ' . $comunicacionNotas;
        }

        gd_historial(
            $pdo,
            $casoId,
            'comunicacion_apoderado',
            'Comunicación inicial al apoderado',
            $detalleComunicacion,
            $userId
        );
    }

    if ($ley21809Flags || $rex782Flags) {
        gd_historial(
            $pdo,
            $casoId,
            'marcadores_normativos',
            'Marcadores normativos preliminares',
            'Ley 21.809: ' . implode(', ', $ley21809Flags) . ' | REX 782: ' . implode(', ', $rex782Flags),
            $userId
        );
    }

    foreach ($participantes as $p) {
        if (!empty($p['es_nn'])) {
            gd_historial(
                $pdo,
                $casoId,
                'interviniente_nn',
                'Interviniente N/N',
                'Se registra un interviniente como N/N y RUN 0-0 por no contar inicialmente con antecedentes de identificación.',
                $userId
            );
        }
    }

    if ($esDenuncianteAnonimo === 1) {
        gd_historial(
            $pdo,
            $casoId,
            'reserva_identidad',
            'Identidad reservada del denunciante',
            'El denunciante solicitó reserva de identidad para informes dirigidos a apoderados o comunidad escolar.',
            $userId
        );
    }

    if ($posibleAulaSegura === 1) {
        gd_historial($pdo, $casoId, 'aula_segura', 'Posible Aula Segura', 'Se marca alerta preliminar de posible Aula Segura. Requiere evaluación directiva.', $userId);
        gd_guardar_aula_segura($pdo, $casoId, $cid, $userId, $causales, $observacionAula, $relato);
    }

    gd_guardar_participantes($pdo, $casoId, $participantes);

    gd_bitacora(
        'denuncias',
        'crear_caso',
        'casos',
        $casoId,
        'Se crea el caso ' . $numero . ' con ' . count($participantes) . ' interviniente(s)' . ($posibleAulaSegura === 1 ? ' y alerta preliminar Aula Segura.' : '.')
    );

    $pdo->commit();

    invalidar_cache_dashboard($cid);

    header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . ($posibleAulaSegura === 1 ? '&tab=aula_segura' : ''));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    gd_redirect_error($e->getMessage());
}