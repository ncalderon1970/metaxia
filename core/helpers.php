<?php
declare(strict_types=1);

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('clean')) {
    function clean(?string $value): string
    {
        return trim((string)$value);
    }
}

if (!function_exists('cleanText')) {
    function cleanText(?string $value): string
    {
        return trim((string)$value);
    }
}

if (!function_exists('cleanInt')) {
    function cleanInt(mixed $value): int
    {
        return (int)$value;
    }
}

if (!function_exists('cleanRun')) {
    function cleanRun(?string $value): string
    {
        $value = strtoupper(trim((string)$value));
        $value = str_replace(['.', ' '], '', $value);
        $value = preg_replace('/[^0-9K\-]/', '', $value);

        return $value !== '' ? $value : '0-0';
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return class_exists('Auth') ? Auth::user() : null;
    }
}

if (!function_exists('user_can')) {
    function user_can(string $permission): bool
    {
        return class_exists('Auth') && Auth::can($permission);
    }
}

if (!function_exists('tiene_permiso')) {
    function tiene_permiso(string $permission): bool
    {
        return user_can($permission);
    }
}

/*
|--------------------------------------------------------------------------
| Bitácora flexible
|--------------------------------------------------------------------------
| Soporta dos formas de uso:
|
| Forma nueva:
| registrar_bitacora('denuncias', 'crear_caso', 'casos', 10, 'Caso creado');
|
| Forma antigua:
| registrar_bitacora($pdo, $colegioId, $usuarioId, 'alumnos', 'crear', 'alumno', 5, 'Alumno creado');
*/
if (!function_exists('registrar_bitacora')) {
    function registrar_bitacora(...$args): void
    {
        try {
            $pdo = null;
            $colegioId = null;
            $usuarioId = null;
            $modulo = '';
            $accion = '';
            $entidad = null;
            $entidadId = null;
            $descripcion = null;

            if (isset($args[0]) && $args[0] instanceof PDO) {
                $pdo         = $args[0];
                $colegioId   = isset($args[1]) ? (int)$args[1] : null;
                $usuarioId   = isset($args[2]) ? (int)$args[2] : null;
                $modulo      = (string)($args[3] ?? '');
                $accion      = (string)($args[4] ?? '');
                $entidad     = isset($args[5]) ? (string)$args[5] : null;
                $entidadId   = isset($args[6]) ? (int)$args[6] : null;
                $descripcion = isset($args[7]) ? (string)$args[7] : null;
            } else {
                if (!class_exists('DB') || !class_exists('Auth')) {
                    return;
                }

                $pdo = DB::conn();
                $user = Auth::user();

                $colegioId   = isset($user['colegio_id']) ? (int)$user['colegio_id'] : null;
                $usuarioId   = isset($user['id']) ? (int)$user['id'] : null;
                $modulo      = (string)($args[0] ?? '');
                $accion      = (string)($args[1] ?? '');
                $entidad     = isset($args[2]) ? (string)$args[2] : null;
                $entidadId   = isset($args[3]) ? (int)$args[3] : null;
                $descripcion = isset($args[4]) ? (string)$args[4] : null;
            }

            if ($modulo === '' || $accion === '') {
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO logs_sistema (
                    colegio_id,
                    usuario_id,
                    modulo,
                    accion,
                    entidad,
                    entidad_id,
                    descripcion,
                    ip,
                    user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $colegioId,
                $usuarioId,
                $modulo,
                $accion,
                $entidad,
                $entidadId,
                $descripcion,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            // La bitácora nunca debe botar el sistema principal.
        }
    }
}

/**
 * Calcula si un caso está dentro del plazo legal según Ley 21.809.
 *
 * Plazos vigentes:
 *  - Notificación al apoderado:  2 días hábiles desde el registro
 *  - Investigación interna:      5 días hábiles desde la denuncia
 *  - Resolución y medidas:      10 días hábiles desde la denuncia
 *
 * @param  string $fechaRegistro  Fecha de creación del caso ('Y-m-d' o 'Y-m-d H:i:s')
 * @param  string $tipo           'notificacion' | 'investigacion' | 'resolucion'
 * @return array{
 *   plazo_dias: int,
 *   fecha_limite: string,
 *   dias_restantes: int,
 *   vencido: bool,
 *   urgente: bool
 * }
 */
if (!function_exists('calcular_plazo_legal')) {
    function calcular_plazo_legal(string $fechaRegistro, string $tipo): array
    {
        $plazos = [
            'notificacion' => 2,
            'investigacion' => 5,
            'resolucion'   => 10,
        ];

        $diasHabiles = $plazos[$tipo] ?? 5;

        $inicio    = new DateTimeImmutable(substr($fechaRegistro, 0, 10));
        $limite    = $inicio;
        $contados  = 0;

        while ($contados < $diasHabiles) {
            $limite   = $limite->modify('+1 day');
            $diaSemana = (int)$limite->format('N'); // 1=Lunes, 7=Domingo
            if ($diaSemana <= 5) { // días hábiles: lunes a viernes
                $contados++;
            }
        }

        $hoy           = new DateTimeImmutable('today');
        $diff          = (int)$hoy->diff($limite)->days;
        $vencido       = $hoy > $limite;
        $diasRestantes = $vencido ? -$diff : $diff;

        return [
            'plazo_dias'     => $diasHabiles,
            'fecha_limite'   => $limite->format('Y-m-d'),
            'dias_restantes' => $diasRestantes,
            'vencido'        => $vencido,
            'urgente'        => !$vencido && $diasRestantes <= 1,
        ];
    }
}

/**
 * Invalida el caché del dashboard para un colegio.
 * Llamar después de crear, cerrar o reabrir un caso.
 */
if (!function_exists('invalidar_cache_dashboard')) {
    function invalidar_cache_dashboard(int $colegioId): void
    {
        if (!class_exists('Cache')) {
            $cacheFile = __DIR__ . '/Cache.php';
            if (is_file($cacheFile)) {
                require_once $cacheFile;
            } else {
                return;
            }
        }

        Cache::forget("dash_kpis_{$colegioId}");
        Cache::forget("dash_alertas_{$colegioId}");
    }
}