<?php
declare(strict_types=1);
ob_start();

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/DB.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/CSRF.php';

Auth::requireLogin();

$pdo       = DB::conn();
$user      = Auth::user() ?? [];
$userId    = (int)($user['id'] ?? 0);
$colegioId = (int)($user['colegio_id'] ?? 0);

function ab_clean(?string $v): string { return trim((string)$v); }
function ab_upper(?string $v): string { return mb_strtoupper(trim((string)$v), 'UTF-8'); }
function ab_redirect(string $url): void { header('Location: ' . $url); exit; }
function ab_error(string $msg, int $id): void {
    ab_redirect(APP_URL . '/modules/denuncias/completar_borrador.php?id=' . $id . '&error=' . urlencode($msg));
}

// Helpers para inserción dinámica (solo inserta columnas que existen en la tabla)
function ab_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$table, $col]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

function ab_insert_dynamic(PDO $pdo, string $table, array $data): void {
    $cols = []; $placeholders = []; $params = [];
    foreach ($data as $col => $val) {
        if (!ab_col_exists($pdo, $table, $col)) continue;
        $cols[]         = '`' . $col . '`';
        $placeholders[] = '?';
        $params[]       = $val;
    }
    if (!$cols) return;
    $pdo->prepare("INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")")
        ->execute($params);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ab_redirect(APP_URL . '/modules/denuncias/index.php');
    }

    CSRF::requireValid($_POST['_token'] ?? null);

    $casoId     = (int)($_POST['_caso_id'] ?? 0);
    $registrar  = (string)($_POST['_submit_mode'] ?? '') === 'registrar';

    if ($casoId <= 0) ab_redirect(APP_URL . '/modules/denuncias/index.php');

    // Verificar que el caso existe, es borrador y pertenece al colegio
    $sc = $pdo->prepare("
        SELECT c.id FROM casos c
        LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
        WHERE c.id = ? AND c.colegio_id = ?
          AND (c.estado = 'borrador' OR ec.codigo = 'borrador')
        LIMIT 1
    ");
    $sc->execute([$casoId, $colegioId]);
    if (!$sc->fetchColumn()) {
        ab_error('Borrador no encontrado o sin acceso.', $casoId);
    }

    // ── Recoger campos ─────────────────────────────────────
    $relato      = ab_clean($_POST['relato'] ?? '');
    $contexto    = ab_upper($_POST['contexto'] ?? '');
    $lugarHechos = ab_upper($_POST['lugar_hechos'] ?? '');
    $canalIngreso = ab_clean($_POST['canal_ingreso'] ?? '');
    $marcoLegal   = ab_clean($_POST['marco_legal'] ?? 'ley21809');

    $fechaHoraRaw = ab_clean($_POST['fecha_hora_incidente'] ?? '');
    $fechaHora    = ($fechaHoraRaw !== '' && strtotime($fechaHoraRaw))
                    ? date('Y-m-d H:i:s', strtotime($fechaHoraRaw))
                    : null;

    // Comunicación apoderado
    $comEstado    = ab_clean($_POST['comunicacion_apoderado_estado'] ?? 'pendiente');
    $comModalidad = ab_clean($_POST['comunicacion_apoderado_modalidad'] ?? '') ?: null;
    $comFecha     = ab_clean($_POST['comunicacion_apoderado_fecha'] ?? '') ?: null;
    $comNotas     = ab_clean($_POST['comunicacion_apoderado_notas'] ?? '') ?: null;

    // Flags normativos
    $ley21809Permitidos = ['afecta_buen_trato','acoso_escolar','violencia_fisica','violencia_psicologica',
        'discriminacion','ciberacoso_medios_digitales','afecta_salud_mental','requiere_derivacion'];
    $ley21809Flags = array_values(array_filter(
        (array)($_POST['ley21809_flags'] ?? []),
        fn($f) => in_array($f, $ley21809Permitidos, true)
    ));
    $rex782Permitidos = ['acoso_escolar_grave','violencia_extrema','discriminacion_grave','abuso_autoridad',
        'riesgo_vida','coordinacion_ext','medida_disciplinaria','medida_pedagogica'];
    $rex782Flags = array_values(array_filter(
        (array)($_POST['rex782_flags'] ?? []),
        fn($f) => in_array($f, $rex782Permitidos, true)
    ));

    // Participantes
    $tiposBusqueda = (array)($_POST['p_tipo_busqueda'] ?? []);
    $personaIds    = (array)($_POST['p_persona_id'] ?? []);
    $tiposPersona  = (array)($_POST['p_tipo_persona'] ?? []);
    $runs          = (array)($_POST['p_run'] ?? []);
    $nombresRef    = (array)($_POST['p_nombre_referencial'] ?? []);
    $roles         = (array)($_POST['p_rol_en_caso'] ?? []);
    $anonimos      = (array)($_POST['p_es_anonimo'] ?? []);

    // ── Estado destino ──────────────────────────────────────
    $estadoNuevo = 'borrador';
    $estadoCasoId = null;

    if ($registrar) {
        if ($relato === '') ab_error('El relato es obligatorio para registrar la denuncia.', $casoId);
        if (empty($tiposBusqueda)) ab_error('Debe registrar al menos un interviniente.', $casoId);
        // Obtener estado 'ingresado'
        try {
            $si = $pdo->prepare("SELECT id FROM estado_caso WHERE codigo = 'ingresado' LIMIT 1");
            $si->execute(); $estadoCasoId = (int)$si->fetchColumn() ?: null;
        } catch (Throwable $e) {}
        $estadoNuevo = 'abierto';
    } else {
        // Mantener borrador
        try {
            $sb = $pdo->prepare("SELECT id FROM estado_caso WHERE codigo = 'borrador' LIMIT 1");
            $sb->execute(); $estadoCasoId = (int)$sb->fetchColumn() ?: null;
        } catch (Throwable $e) {}
    }

    $pdo->beginTransaction();

    // ── UPDATE caso ─────────────────────────────────────────
    $fields = [
        'relato'                              => $relato ?: null,
        'descripcion'                         => $relato ?: null,
        'contexto'                            => $contexto ?: null,
        'lugar_hechos'                        => $lugarHechos ?: null,
        'fecha_hora_incidente'                => $fechaHora,
        'canal_ingreso'                       => $canalIngreso ?: null,
        'marco_legal'                         => $marcoLegal,
        'ley21809_flags'                      => json_encode($ley21809Flags, JSON_UNESCAPED_UNICODE),
        'rex782_flags'                        => json_encode($rex782Flags, JSON_UNESCAPED_UNICODE),
        'comunicacion_apoderado_estado'       => $comEstado,
        'comunicacion_apoderado_modalidad'    => $comModalidad,
        'comunicacion_apoderado_fecha'        => $comFecha,
        'comunicacion_apoderado_notas'        => $comNotas,
        'estado'                              => $estadoNuevo,
        'estado_caso_id'                      => $estadoCasoId,
        'updated_at'                          => date('Y-m-d H:i:s'),
    ];

    $setParts = array_map(fn($k) => "`$k` = ?", array_keys($fields));
    $vals = array_values($fields);
    $vals[] = $casoId;
    $vals[] = $colegioId;

    $pdo->prepare("UPDATE casos SET " . implode(', ', $setParts) . " WHERE id = ? AND colegio_id = ?")
        ->execute($vals);

    // ── UPDATE participantes: borrar y re-insertar ──────────
    $pdo->prepare("DELETE FROM caso_participantes WHERE caso_id = ?")->execute([$casoId]);

    foreach ($tiposBusqueda as $i => $tipo) {
        $nombre   = ab_clean($nombresRef[$i] ?? '');
        $run      = ab_clean($runs[$i] ?? '');
        $rol      = ab_clean($roles[$i] ?? '');
        $anon     = (int)($anonimos[$i] ?? 0);
        $pid      = (int)($personaIds[$i] ?? 0);
        $tpersona = ab_clean($tiposPersona[$i] ?? $tipo);

        if ($nombre === '' && $run === '') continue;

        // Columnas reales de caso_participantes (verificadas contra .frm)
        $pdo->prepare("
            INSERT INTO caso_participantes
                (caso_id, tipo_persona, persona_id, nombre_referencial,
                 rol_en_caso, solicita_reserva_identidad, observacion, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $casoId,
            $tpersona,
            $pid > 0 ? $pid : null,
            ($nombre ?: 'N/N') . ($run ? ' (RUN: ' . $run . ')' : ''),
            $rol ?: null,
            $anon,
            $run ? 'RUN: ' . $run : null,
        ]);
    }

    // ── Historial ───────────────────────────────────────────
    $accion  = $registrar ? 'registro_desde_borrador' : 'actualizar_borrador';
    $detalle = $registrar ? 'Denuncia registrada desde borrador.' : 'Borrador actualizado.';
    try {
        $pdo->prepare("
            INSERT INTO caso_historial (caso_id, accion, detalle, usuario_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$casoId, $accion, $detalle, $userId > 0 ? $userId : null]);
    } catch (Throwable $e) {}

    $pdo->commit();

    if ($registrar) {
        ab_redirect(APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&msg_ok=Denuncia+registrada+correctamente.');
    } else {
        ab_redirect(APP_URL . '/modules/denuncias/completar_borrador.php?id=' . $casoId . '&msg_ok=Borrador+guardado.');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $id = (int)($_POST['_caso_id'] ?? 0);
    ab_error('Error al guardar: ' . $e->getMessage(), $id);
}
ob_end_flush();
