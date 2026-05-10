<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

final class IAAnalisisService
{
    public static function analizarCaso(PDO $pdo, array $caso, array $usuario): int
    {
        $colegioId = (int)($usuario['colegio_id'] ?? 0);
        $usuarioId = (int)($usuario['id'] ?? 0);

        if ($colegioId <= 0) {
            throw new RuntimeException('Usuario sin colegio asociado.');
        }

        if (!ia_modulo_activo($pdo, $colegioId, 'IA_ANALISIS')) {
            throw new RuntimeException('El módulo premium IA no está activo para este colegio.');
        }

        $data = ia_generar_borrador_analisis($caso);

        $stmt = $pdo->prepare("INSERT INTO caso_analisis_ia (caso_id, colegio_id, usuario_id, version_motor, estado, clasificacion_sugerida, gravedad_sugerida, riesgo_sugerido, resumen_hechos, orientacion_equipo, sugerencia_intervencion, sugerencia_fundamento, articulos_relacionados, medidas_sugeridas, alertas_detectadas, requiere_protocolo, requiere_aula_segura, requiere_derivacion, requiere_medidas_resguardo, confianza, created_at, updated_at) VALUES (:caso_id, :colegio_id, :usuario_id, 'v1_demo', 'generado', :clasificacion_sugerida, :gravedad_sugerida, :riesgo_sugerido, :resumen_hechos, :orientacion_equipo, :sugerencia_intervencion, :sugerencia_fundamento, :articulos_relacionados, :medidas_sugeridas, :alertas_detectadas, :requiere_protocolo, :requiere_aula_segura, :requiere_derivacion, :requiere_medidas_resguardo, :confianza, NOW(), NOW())");
        $stmt->execute([
            ':caso_id' => (int)$caso['id'],
            ':colegio_id' => $colegioId,
            ':usuario_id' => $usuarioId ?: null,
            ':clasificacion_sugerida' => $data['clasificacion_sugerida'],
            ':gravedad_sugerida' => $data['gravedad_sugerida'],
            ':riesgo_sugerido' => $data['riesgo_sugerido'],
            ':resumen_hechos' => $data['resumen_hechos'],
            ':orientacion_equipo' => $data['orientacion_equipo'],
            ':sugerencia_intervencion' => $data['sugerencia_intervencion'],
            ':sugerencia_fundamento' => $data['sugerencia_fundamento'],
            ':articulos_relacionados' => $data['articulos_relacionados'],
            ':medidas_sugeridas' => $data['medidas_sugeridas'],
            ':alertas_detectadas' => $data['alertas_detectadas'],
            ':requiere_protocolo' => $data['requiere_protocolo'],
            ':requiere_aula_segura' => $data['requiere_aula_segura'],
            ':requiere_derivacion' => $data['requiere_derivacion'],
            ':requiere_medidas_resguardo' => $data['requiere_medidas_resguardo'],
            ':confianza' => $data['confianza'],
        ]);

        $analisisId = (int)$pdo->lastInsertId();

        ia_registrar_consumo($pdo, [
            'colegio_id' => $colegioId,
            'usuario_id' => $usuarioId ?: null,
            'caso_id' => (int)$caso['id'],
            'tipo_analisis' => 'caso_convivencia',
            'proveedor' => 'manual_demo',
            'modelo' => 'heuristico_v1',
            'tokens_entrada' => 120,
            'tokens_salida' => 180,
            'costo_estimado' => 0,
            'metadata_json' => ['analisis_id' => $analisisId],
        ]);

        $stmt = $pdo->prepare("INSERT INTO logs_ia_detalle (analisis_id, colegio_id, usuario_id, caso_id, accion, input_payload, output_payload, created_at) VALUES (?, ?, ?, ?, 'analizar_caso', ?, ?, NOW())");
        $stmt->execute([
            $analisisId,
            $colegioId,
            $usuarioId ?: null,
            (int)$caso['id'],
            json_encode([
                'relato' => $caso['relato'] ?? null,
                'contexto' => $caso['contexto'] ?? null,
                'involucra_moviles' => $caso['involucra_moviles'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);

        return $analisisId;
    }
}
