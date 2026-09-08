<?php declare(strict_types=1);

namespace Dansk\Support;

final class Config
{
    private static ?array $data = null;

    public static function all(): array
    {
        return self::$data ??= require dirname(__DIR__, 2) . '/config/config.php';
    }

    /**
     * Replace configuration wholesale. Exists so the integration suite can point at a
     * scratch database; nothing in the application calls it.
     */
    public static function override(array $data): void
    {
        self::$data = array_replace_recursive(self::all(), $data);
    }

    /**
     * Whether internal detail may be returned to a caller.
     *
     * Off unless someone explicitly asks for it, and never on in production. Debug turns
     * exception messages and the database error into part of the API response, and this
     * app is reachable from the internet through a tunnel while its environment still
     * says "dev" -- so an environment name must not be what decides it.
     */
    public static function debugFromEnv(?string $appEnv, ?string $appDebug): bool
    {
        if ($appEnv === 'prod') {
            return false;
        }

        return filter_var((string) $appDebug, FILTER_VALIDATE_BOOLEAN);
    }

    /** Dot-path lookup: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
