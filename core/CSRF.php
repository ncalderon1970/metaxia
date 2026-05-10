<?php
declare(strict_types=1);

if (!class_exists('Auth')) {
    require_once __DIR__ . '/Auth.php';
}

final class CSRF
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        Auth::startSession();

        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            self::regenerate();
        }

        return (string)$_SESSION[self::KEY];
    }

    public static function regenerate(): void
    {
        Auth::startSession();

        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' .
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') .
            '">';
    }

    public static function validate(?string $token): bool
    {
        Auth::startSession();

        $sessionToken = $_SESSION[self::KEY] ?? '';

        return is_string($token)
            && $token !== ''
            && is_string($sessionToken)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $token);
    }

    public static function requireValid(?string $token): void
    {
        if (!self::validate($token)) {
            http_response_code(419);
            exit('La sesión del formulario expiró. Recarga la página e intenta nuevamente.');
        }
    }
}