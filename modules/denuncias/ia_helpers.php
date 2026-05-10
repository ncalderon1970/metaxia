<?php
declare(strict_types=1);

require_once '../../config/app.php';
require_once '../../core/DB.php';
require_once '../../core/Auth.php';

if (!function_exists('moduloActivoColegio')) {
    function moduloActivoColegio(int $colegioId, string $moduloCodigo): bool
    {
        $pdo = DB::conn();

        $stmt = $pdo->prepare("
            SELECT activo, fecha_expiracion
            FROM colegio_modulos
            WHERE colegio_id = ?
              AND modulo_codigo = ?
            LIMIT 1
        ");
        $stmt->execute([$colegioId, $moduloCodigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        if ((int)$row['activo'] !== 1) {
            return false;
        }

        if (!empty($row['fecha_expiracion']) && strtotime((string)$row['fecha_expiracion']) < time()) {
            return false;
        }

        return true;
    }
}

if (!function_exists('registrarConsumoIA')) {
    function registrarConsumoIA(int $colegioId, ?int $usuarioId, int $casoId, string $tipoAnalisis, int $tokens = 0, ?float $costo = null): void
    {
        $pdo = DB::conn();

        $stmt = $pdo->prepare("
            INSERT INTO ia_consumo (
                colegio_id,
                usuario_id,
                caso_id,
                tipo_analisis,
                tokens_usados,
                costo_estimado
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $colegioId,
            $usuarioId,
            $casoId,
            $tipoAnalisis,
            $tokens,
            $costo
        ]);
    }
}