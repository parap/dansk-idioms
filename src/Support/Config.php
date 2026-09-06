<?php declare(strict_types=1);

namespace Dansk\Support;

final class Config
{
    private static ?array $data = null;

    public static function all(): array
    {
        return self::$data ??= require dirname(__DIR__, 2) . '/config/config.php';
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
