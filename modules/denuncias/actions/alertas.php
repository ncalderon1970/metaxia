<?php
declare(strict_types=1);
/**
 * Metis · Actions — Alertas del expediente
 * Fase 16: acciones seguras, filtradas por caso/colegio desde el contexto cargado.
 *
 * Este archivo se ejecuta como partial dentro del flujo de acciones del expediente.
 * Requiere disponibles: $accion, $pdo, $casoId, $colegioId y $userId.
 */

if ($accion === 'agregar_alerta') {
    $tipo = clean((string)($_POST['tipo'] ?? 'alerta'));
    $mensaje = clean((string)($_POST['mensaje'] ?? ''));
    $prioridad = clean((string)($_POST['prioridad'] ?? 'media'));

    if ($mensaje === '') {
        throw new RuntimeException('El mensaje de la alerta es obligatorio.');
    }

    if (!in_array($prioridad, ['baja', 'media', 'alta'], true)) {
        $prioridad = 'media';
    }

    if ($tipo === '') {
        $tipo = 'alerta';
    }

    $stmtCaso = $pdo->prepare("\n        SELECT id\n        FROM casos\n        WHERE id = ?\n          AND colegio_id = ?\n        LIMIT 1\n    ");
    $stmtCaso->execute([$casoId, $colegioId]);

    if (!$stmtCaso->fetchColumn()) {
        throw new RuntimeException('El expediente no pertenece al establecimiento activo.');
    }

    $stmt = $pdo->prepare("\n        INSERT INTO caso_alertas (\n            caso_id,\n            tipo,\n            mensaje,\n            prioridad,\n            estado,\n            fecha_alerta\n        ) VALUES (?, ?, ?, ?, 'pendiente', NOW())\n    ");
    $stmt->execute([
        $casoId,
        $tipo,
        $mensaje,
        $prioridad,
    ]);

    $alertaId = (int)$pdo->lastInsertId();

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO caso_historial (\n                caso_id,\n                tipo_evento,\n                titulo,\n                detalle,\n                user_id\n            ) VALUES (?, 'alerta', 'Alerta registrada', ?, ?)\n        ");
        $stmt->execute([
            $casoId,
            'Se registró alerta: ' . $mensaje,
            $userId ?: null,
        ]);
    } catch (Throwable $e) {
        // El historial no debe impedir la creación de la alerta.
    }

    registrar_bitacora(
        'denuncias',
        'agregar_alerta',
        'caso_alertas',
        $alertaId,
        'Alerta agregada al caso.'
    );

    caso_redirect($casoId, 'historial');
}

if ($accion === 'resolver_alerta') {
    $alertaId = (int)($_POST['alerta_id'] ?? 0);

    if ($alertaId <= 0) {
        throw new RuntimeException('Alerta no válida.');
    }

    $stmt = $pdo->prepare("\n        SELECT a.id, a.estado, a.tipo, a.mensaje\n        FROM caso_alertas a\n        INNER JOIN casos c ON c.id = a.caso_id\n        WHERE a.id = ?\n          AND a.caso_id = ?\n          AND c.colegio_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$alertaId, $casoId, $colegioId]);
    $alerta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alerta) {
        throw new RuntimeException('La alerta no existe o no pertenece al expediente activo.');
    }

    if ((string)$alerta['estado'] !== 'pendiente') {
        throw new RuntimeException('Solo se pueden resolver alertas pendientes.');
    }

    $stmt = $pdo->prepare("\n        UPDATE caso_alertas a\n        INNER JOIN casos c ON c.id = a.caso_id\n        SET a.estado = 'resuelta',\n            a.resuelta_por = ?,\n            a.resuelta_at = NOW(),\n            a.updated_at = NOW()\n        WHERE a.id = ?\n          AND a.caso_id = ?\n          AND c.colegio_id = ?\n          AND a.estado = 'pendiente'\n    ");
    $stmt->execute([
        $userId ?: null,
        $alertaId,
        $casoId,
        $colegioId,
    ]);

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO caso_historial (\n                caso_id,\n                tipo_evento,\n                titulo,\n                detalle,\n                user_id\n            ) VALUES (?, 'alerta', 'Alerta resuelta', ?, ?)\n        ");
        $stmt->execute([
            $casoId,
            'Se marcó como resuelta la alerta ID ' . $alertaId . '.',
            $userId ?: null,
        ]);
    } catch (Throwable $e) {
        // El historial no debe impedir la resolución.
    }

    registrar_bitacora(
        'denuncias',
        'resolver_alerta',
        'caso_alertas',
        $alertaId,
        'Alerta resuelta desde expediente.'
    );

    caso_redirect($casoId, 'historial');
}
