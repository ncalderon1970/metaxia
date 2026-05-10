<?php
declare(strict_types=1);

if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/app.php';
}

if (!class_exists('DB')) {
    require_once __DIR__ . '/DB.php';
}

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    private static function pdo(): PDO
    {
        if (!class_exists('DB')) {
            throw new RuntimeException('La clase DB no está disponible.');
        }

        return DB::conn();
    }

    public static function attempt(string $email, string $password): bool
    {
        self::startSession();

        $email = mb_strtolower(trim($email));

        if ($email === '' || $password === '') {
            return false;
        }

        $pdo = self::pdo();

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.colegio_id,
                u.rol_id,
                u.run,
                u.nombre,
                u.email,
                u.password_hash,
                u.activo,
                r.codigo AS rol_codigo,
                r.nombre AS rol_nombre,
                c.rbd AS colegio_rbd,
                c.nombre AS colegio_nombre
            FROM usuarios u
            INNER JOIN roles r ON r.id = u.rol_id
            LEFT JOIN colegios c ON c.id = u.colegio_id
            WHERE u.email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        if ((int)$user['activo'] !== 1) {
            return false;
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'             => (int)$user['id'],
            'colegio_id'     => (int)$user['colegio_id'],
            'rol_id'         => (int)$user['rol_id'],
            'run'            => (string)($user['run'] ?? ''),
            'nombre'         => (string)$user['nombre'],
            'email'          => (string)$user['email'],
            'rol_codigo'     => (string)$user['rol_codigo'],
            'rol_nombre'     => (string)$user['rol_nombre'],
            'colegio_rbd'    => (string)($user['colegio_rbd'] ?? ''),
            'colegio_nombre' => (string)($user['colegio_nombre'] ?? ''),
            'permisos'       => self::loadPermissions((int)$user['rol_id']),
        ];

        try {
            $upd = $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
            $upd->execute([(int)$user['id']]);
        } catch (Throwable $e) {
            // No bloquea el login si la columna no existe o hay error menor.
        }

        return true;
    }

    private static function loadPermissions(int $rolId): array
    {
        $pdo = self::pdo();

        $stmt = $pdo->prepare("
            SELECT p.codigo
            FROM rol_permiso rp
            INNER JOIN permisos p ON p.id = rp.permiso_id
            WHERE rp.rol_id = ?
              AND p.activo = 1
        ");

        $stmt->execute([$rolId]);

        return array_values(array_map(
            static fn(array $row): string => (string)$row['codigo'],
            $stmt->fetchAll()
        ));
    }

    public static function check(): bool
    {
        self::startSession();

        return !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        self::startSession();

        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int)$user['id'] : null;
    }

    public static function colegioId(): ?int
    {
        $user = self::user();

        return $user ? (int)$user['colegio_id'] : null;
    }

    public static function role(): ?string
    {
        $user = self::user();

        return $user['rol_codigo'] ?? null;
    }

    public static function hasRole(string|array $roles): bool
    {
        $current = self::role();

        if ($current === null) {
            return false;
        }

        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($current, $roles, true);
    }

    public static function can(string $permission): bool
    {
        $user = self::user();

        if (!$user) {
            return false;
        }

        if (($user['rol_codigo'] ?? '') === 'superadmin') {
            return true;
        }

        if (empty($user['permisos']) || !is_array($user['permisos'])) {
            $_SESSION['user']['permisos'] = self::loadPermissions((int)$user['rol_id']);
            $user = $_SESSION['user'];
        }

        return in_array($permission, $user['permisos'] ?? [], true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . APP_URL . '/public/login.php');
            exit;
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();

        if (!self::can($permission)) {
            http_response_code(403);
            exit('Acceso no autorizado.');
        }
    }

    /**
     * Roles con acceso operacional completo al sistema.
     * Cualquier módulo puede usar Auth::canOperate() en lugar
     * de mantener listas de roles hardcodeadas.
     */
    private static array $rolesOperacionales = [
        'superadmin',
        'admin_colegio',
        'director',
        'convivencia',
        'encargado_convivencia',
    ];

    /**
     * El usuario puede operar el sistema (acceso a módulos generales).
     * Reemplaza los in_array($rol, ['superadmin','director',...]) dispersos.
     */
    public static function canOperate(): bool
    {
        $user = self::user();
        if (!$user) return false;
        return in_array($user['rol_codigo'] ?? '', self::$rolesOperacionales, true)
            || self::can('admin_sistema')
            || self::can('gestionar_usuarios');
    }

    /**
     * El usuario es administrador de establecimiento o superior.
     */
    public static function isAdminColegio(): bool
    {
        $user = self::user();
        if (!$user) return false;
        return in_array($user['rol_codigo'] ?? '', ['superadmin', 'admin_colegio'], true)
            || self::can('admin_sistema')
            || self::can('gestionar_usuarios');
    }

    /**
     * El usuario es superadmin.
     */
    public static function isSuperAdmin(): bool
    {
        return (self::user()['rol_codigo'] ?? '') === 'superadmin';
    }

    /**
     * requireOperate: redirige con 403 si el usuario no puede operar.
     * Usar en módulos operacionales (importar, comunidad, reportes, etc.)
     */
    public static function requireOperate(): void
    {
        self::requireLogin();
        if (!self::canOperate()) {
            http_response_code(403);
            exit('Acceso no autorizado. Tu perfil no tiene permiso para acceder a este módulo.');
        }
    }

    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        session_destroy();
    }
}