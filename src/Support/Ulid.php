<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * Public identifiers for rows whose database id must not leave the server.
 *
 * Crockford base32: 26 characters, so a Telegram callback_data payload stays inside its
 * 64-byte limit, and I/L/O/U are absent so a transcribed id cannot be read back as 1 or 0.
 * The leading 10 characters encode milliseconds big-endian, so lexicographic order is
 * generation order.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const TIME_CHARS = 10;

    private const RANDOM_CHARS = 16;

    public static function generate(): string
    {
        $time = (int) (microtime(true) * 1000);
        $out  = '';

        for ($i = 0; $i < self::TIME_CHARS; $i++) {
            $out  = self::ALPHABET[$time % 32] . $out;
            $time = intdiv($time, 32);
        }

        for ($i = 0; $i < self::RANDOM_CHARS; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
}
