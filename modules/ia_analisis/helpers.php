<?php
declare(strict_types=1);

if (!function_exists('ia_modulo_activo')) {
    function ia_modulo_activo(PDO $pdo, int $colegioId, string $codigo = 'IA_ANALISIS'): bool
    {
        $stmt = $pdo->prepare("SELECT activo, fecha_expiracion FROM colegio_modulos WHERE colegio_id = ? AND modulo_codigo = ? LIMIT 1");
        $stmt->execute([$colegioId, $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['activo'] !== 1) {
            return false;
        }

        if (!empty($row['fecha_expiracion']) && strtotime((string)$row['fecha_expiracion']) < time()) {
            return false;
        }

        return true;
    }
}

if (!function_exists('ia_registrar_consumo')) {
    function ia_registrar_consumo(PDO $pdo, array $payload): void
    {
        $stmt = $pdo->prepare("INSERT INTO ia_consumo (colegio_id, usuario_id, caso_id, tipo_analisis, proveedor, modelo, tokens_entrada, tokens_salida, costo_estimado, metadata_json, created_at) VALUES (:colegio_id, :usuario_id, :caso_id, :tipo_analisis, :proveedor, :modelo, :tokens_entrada, :tokens_salida, :costo_estimado, :metadata_json, NOW())");
        $stmt->execute([
            ':colegio_id' => $payload['colegio_id'],
            ':usuario_id' => $payload['usuario_id'] ?? null,
            ':caso_id' => $payload['caso_id'] ?? null,
            ':tipo_analisis' => $payload['tipo_analisis'] ?? 'caso',
            ':proveedor' => $payload['proveedor'] ?? 'manual_demo',
            ':modelo' => $payload['modelo'] ?? 'heuristico_v1',
            ':tokens_entrada' => $payload['tokens_entrada'] ?? 0,
            ':tokens_salida' => $payload['tokens_salida'] ?? 0,
            ':costo_estimado' => $payload['costo_estimado'] ?? 0,
            ':metadata_json' => isset($payload['metadata_json']) ? json_encode($payload['metadata_json'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}

if (!function_exists('ia_generar_borrador_analisis')) {
    function ia_generar_borrador_analisis(array $caso): array
    {
        $relato = mb_strtolower((string)($caso['relato'] ?? ''));
        $contexto = mb_strtolower((string)($caso['contexto'] ?? ''));
        $moviles = (int)($caso['involucra_moviles'] ?? 0) === 1;

        $clasificacion = 'Conflicto de convivencia';
        $gravedad = 'Leve';
        $riesgo = 'Bajo';
        $requiereProtocolo = '';
        $aulaSegura = 0;
        $derivacion = 0;
        $resguardo = 0;
        $alertas = [];
        $medidas = [];
        $articulos = [];

        if ($moviles) {
            $clasificacion = 'Uso indebido de dispositivos móviles';
            $gravedad = 'Leve';
            $medidas[] = 'Aplicar medida formativa proporcional vinculada al reglamento sobre dispositivos móviles.';
            $medidas[] = 'Registrar comunicación con apoderado si existe reiteración.';
            $articulos[] = 'Revisar sección del reglamento interno sobre dispositivos móviles y excepciones.';
        }

        if (str_contains($relato, 'golpe') || str_contains($relato, 'agresión') || str_contains($relato, 'pega')) {
            $clasificacion = 'Posible infracción a la convivencia';
            $gravedad = 'Grave';
            $riesgo = 'Medio';
            $requiereProtocolo = 'violencia_escolar';
            $resguardo = 1;
            $medidas[] = 'Adoptar medidas de resguardo inmediatas para las personas involucradas.';
            $medidas[] = 'Evaluar apertura de procedimiento disciplinario con debido proceso.';
            $articulos[] = 'Revisar protocolo de violencia escolar y faltas graves del reglamento.';
            $alertas[] = 'Verificar proporcionalidad y debido proceso antes de cualquier sanción.';
        }

        if (str_contains($relato, 'amenaza') || str_contains($relato, 'cuchillo') || str_contains($relato, 'arma')) {
            $clasificacion = 'Posible afectación grave a la convivencia';
            $gravedad = 'Grave';
            $riesgo = 'Alto';
            $requiereProtocolo = 'violencia_grave';
            $aulaSegura = 1;
            $resguardo = 1;
            $alertas[] = 'Evaluar si concurren supuestos de Aula Segura con revisión humana.';
            $medidas[] = 'Levantar antecedentes pedagógicos, conductuales y de resguardo.';
            $medidas[] = 'Convocar análisis del equipo de convivencia y dirección.';
            $articulos[] = 'Revisar faltas gravísimas y procedimiento especial del reglamento.';
        }

        if (str_contains($relato, 'discrimin') || str_contains($relato, 'burla') || str_contains($relato, 'hostiga')) {
            $derivacion = 1;
            $alertas[] = 'Evaluar medidas de protección y acompañamiento para evitar revictimización.';
            $medidas[] = 'Priorizar intervención formativa y medidas de protección.';
        }

        if (!$medidas) {
            $medidas[] = 'Realizar entrevista inicial con involucrados y levantar antecedentes.';
            $medidas[] = 'Definir intervención pedagógica/formativa proporcional.';
            $articulos[] = 'Revisar capítulo de convivencia, medidas formativas y procedimientos del reglamento vigente.';
        }

        return [
            'clasificacion_sugerida' => $clasificacion,
            'gravedad_sugerida' => $gravedad,
            'riesgo_sugerido' => $riesgo,
            'resumen_hechos' => 'Síntesis automática preliminar del caso para apoyo al equipo de convivencia.',
            'orientacion_equipo' => 'Analizar el caso con foco formativo, proporcionalidad, debido proceso y resguardo de las personas involucradas.',
            'sugerencia_intervencion' => implode("\n", $medidas),
            'sugerencia_fundamento' => 'La intervención debe vincularse al reglamento vigente, distinguiendo conflicto, falta, apoyo y procedimiento aplicable.',
            'articulos_relacionados' => implode("\n", $articulos),
            'medidas_sugeridas' => implode("\n", $medidas),
            'alertas_detectadas' => $alertas ? implode("\n", $alertas) : null,
            'requiere_protocolo' => $requiereProtocolo !== '' ? $requiereProtocolo : null,
            'requiere_aula_segura' => $aulaSegura,
            'requiere_derivacion' => $derivacion,
            'requiere_medidas_resguardo' => $resguardo,
            'confianza' => 72.50,
        ];
    }
}
