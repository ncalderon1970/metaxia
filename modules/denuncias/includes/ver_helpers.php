<?php
declare(strict_types=1);

if (!function_exists('caso_fecha')) {
function caso_fecha(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y H:i', $ts) : $value;
}
}

if (!function_exists('caso_fecha_corta')) {
function caso_fecha_corta(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $ts = strtotime($value);

    return $ts ? date('d-m-Y', $ts) : $value;
}
}

if (!function_exists('caso_label')) {
function caso_label(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Sin dato';
    }

    $valueLower = mb_strtolower($value, 'UTF-8');

    $labels = [
        'victima'     => 'Víctima',
        'víctima'    => 'Víctima',
        'denunciante' => 'Denunciante',
        'denunciado'  => 'Denunciado/a',
        'testigo'     => 'Testigo',
        'involucrado' => 'Otro interviniente',
        'otro'        => 'Otro interviniente',
    ];

    if (isset($labels[$valueLower])) {
        return $labels[$valueLower];
    }

    return ucwords(str_replace(['_', '-'], ' ', $value));
}
}

if (!function_exists('caso_badge_class')) {
function caso_badge_class(string $value): string
{
    return match (strtolower($value)) {
        'rojo', 'alta', 'critica', 'crítica', 'pendiente', 'vencida', 'vencido' => 'danger',
        'amarillo', 'media', 'revision', 'revisión', 'investigacion', 'investigación', 'en_proceso' => 'warn',
        'verde', 'baja', 'cerrado', 'resuelta', 'cumplida', 'corregido' => 'ok',
        'descartada', 'descartado' => 'soft',
        default => 'soft',
    };
}
}

if (!function_exists('caso_redirect')) {
function caso_redirect(int $casoId, string $tab): void
{
    header('Location: ' . APP_URL . '/modules/denuncias/ver.php?id=' . $casoId . '&tab=' . urlencode($tab));
    exit;
}
}


if (!function_exists('ver_quote')) {
function ver_quote(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
}

if (!function_exists('ver_clean_nullable')) {
function ver_clean_nullable(?string $value): ?string
{
    $value = trim((string)$value);

    return $value === '' ? null : $value;
}
}

if (!function_exists('registrar_hito')) {
function registrar_hito(PDO $pdo, int $casoId, int $colegioId, int $codigo, int $userId): void
{
    static $nombres = [
        101 => 'Declaración registrada',
        102 => 'Pauta de riesgo completada',
        103 => 'Plan de acción creado',
        104 => 'Sesión de seguimiento registrada',
        105 => 'Aula Segura activada',
        106 => 'Evidencia incorporada',
        107 => 'Clasificación normativa completada',
        110 => 'Caso cerrado',
    ];

    // Registrar el hito en la bitácora (fallo aquí no bloquea el cambio de estado)
    try {
        $nombre = $nombres[$codigo] ?? 'Actividad registrada';
        $pdo->prepare("
            INSERT IGNORE INTO caso_hitos (caso_id, colegio_id, codigo, nombre, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$casoId, $colegioId, $codigo, $nombre, $userId ?: null]);
    } catch (Throwable $e) {
        // caso_hitos no existe aún o error transitorio — continuar de todas formas
    }

    // Avanzar estado siempre, independiente del resultado del INSERT anterior
    if ($codigo >= 101 && $codigo < 110) {
        avanzar_a_investigacion($pdo, $casoId, $userId);
    }
}
}

if (!function_exists('avanzar_a_investigacion')) {
function avanzar_a_investigacion(PDO $pdo, int $casoId, int $userId): void
{
    try {
        // Leer estado actual del caso
        $stmtActual = $pdo->prepare("
            SELECT ec.codigo
            FROM casos c
            LEFT JOIN estado_caso ec ON ec.id = c.estado_caso_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmtActual->execute([$casoId]);
        $estadoActual = (string)$stmtActual->fetchColumn();

        // Solo avanza desde recepción o sin estado — nunca retrocede
        if ($estadoActual !== '' && !in_array($estadoActual, ['borrador', 'recepcion'], true)) {
            return;
        }

        // Obtener el id del estado 'investigacion' — sin filtro activo (estado de sistema)
        $stmtId = $pdo->prepare("
            SELECT id FROM estado_caso WHERE codigo = 'investigacion' LIMIT 1
        ");
        $stmtId->execute();
        $estadoInvestigacionId = (int)$stmtId->fetchColumn();

        // Respaldo: si por alguna razón falla la búsqueda por código, usar id conocido
        if ($estadoInvestigacionId === 0) {
            $stmtId2 = $pdo->prepare("SELECT id FROM estado_caso WHERE id = 2 LIMIT 1");
            $stmtId2->execute();
            $estadoInvestigacionId = (int)$stmtId2->fetchColumn();
        }

        if ($estadoInvestigacionId === 0) {
            error_log("[Metis] avanzar_a_investigacion: estado 'investigacion' no encontrado en estado_caso (caso_id={$casoId})");
            return;
        }

        $stmtUp = $pdo->prepare("
            UPDATE casos
            SET estado_caso_id = ?,
                estado = 'abierto',
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $stmtUp->execute([$estadoInvestigacionId, $casoId]);

        if ($stmtUp->rowCount() === 0) {
            $stmtDiag = $pdo->prepare("SELECT id, estado, estado_caso_id FROM casos WHERE id = ? LIMIT 1");
            $stmtDiag->execute([$casoId]);
            $diag = $stmtDiag->fetch(PDO::FETCH_ASSOC) ?: [];
            error_log("[Metis] avanzar_a_investigacion: UPDATE afectó 0 filas (caso_id={$casoId}, estado_caso_id_target={$estadoInvestigacionId}, fila_actual=" . json_encode($diag) . ")");
            return;
        }

        $pdo->prepare("
            INSERT INTO caso_historial (caso_id, tipo_evento, titulo, detalle, user_id)
            VALUES (?, 'estado', 'Estado actualizado', 'Caso pasó a En investigación por registro de actividad.', ?)
        ")->execute([$casoId, $userId ?: null]);

    } catch (Throwable $e) {
        error_log("[Metis] avanzar_a_investigacion excepción (caso_id={$casoId}): " . $e->getMessage());
    }
}
}
