<?php declare(strict_types=1);

namespace Dansk\Support;

final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
            session_start();
        }
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function login(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['user_id']);
    }

    /**
     * Stable per-browser key so guests keep a history without an account, and can
     * have it adopted if they register later.
     */
    public static function anonKey(): string
    {
        if (!empty($_COOKIE['anon_key']) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE['anon_key'])) {
            return $_COOKIE['anon_key'];
        }
        $key = bin2hex(random_bytes(16));
        setcookie('anon_key', $key, [
            'expires' => time() + 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE['anon_key'] = $key;
        return $key;
    }
}
