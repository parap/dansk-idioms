<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * Makes guessing the admin password cost time.
 *
 * The password is a single shared secret on a publicly reachable URL, and a fixed 300 ms
 * delay per attempt is about three guesses a second for as long as somebody cares to
 * keep going.
 *
 * Two counters are consulted. The per-client one is the useful limit. The global one is
 * the backstop: the client is derived from a proxy header, which a caller can set to
 * anything, so per-client alone is bypassed by rotating it. The global cap sits well
 * above one client's, so ordinary fumbling never trips it.
 */
final class AdminLoginThrottle
{
    public const MAX_FAILURES = 8;

    public const GLOBAL_MAX_FAILURES = 40;

    public const WINDOW_SECONDS = 900;

    private const GLOBAL_KEY = 'global';

    /**
     * Seconds the caller must wait, or null when they may try now.
     */
    public function retryAfter(string $client): ?int
    {
        $mine = $this->wait($this->key($client), self::MAX_FAILURES);
        $all  = $this->wait(self::GLOBAL_KEY, self::GLOBAL_MAX_FAILURES);

        $waits = array_filter([$mine, $all], static fn(?int $w): bool => $w !== null);

        return $waits === [] ? null : max($waits);
    }

    public function recordFailure(string $client): void
    {
        foreach ([$this->key($client), self::GLOBAL_KEY] as $key) {
            Db::execute(
                'INSERT INTO admin_login_attempts (client, failures) VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE
                    -- A window that has run out starts again rather than accumulating
                    -- forever, so an old mistake cannot combine with a new one.
                    failures     = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, failures + 1),
                    window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), window_start),
                    last_seen_at = NOW()',
                [$key, self::WINDOW_SECONDS, self::WINDOW_SECONDS]
            );
        }
    }

    /** Called on a successful sign-in: the owner getting it right clears their count. */
    public function clear(string $client): void
    {
        Db::execute('DELETE FROM admin_login_attempts WHERE client = ?', [$this->key($client)]);
    }

    /**
     * The best available identity for the caller.
     *
     * Behind the tunnel every request arrives from the same container address, so
     * REMOTE_ADDR alone would treat the whole internet as one client and lock everybody
     * out together. The forwarded headers are not trustworthy, which is what the global
     * backstop is for.
     */
    public static function clientFrom(array $server): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            $value = trim((string) ($server[$header] ?? ''));
            if ($value === '') {
                continue;
            }
            // X-Forwarded-For is a chain; the first hop is the closest thing to a client.
            return trim(explode(',', $value)[0]);
        }

        return 'unknown';
    }

    private function wait(string $key, int $limit): ?int
    {
        $row = Db::fetchOne(
            'SELECT failures, TIMESTAMPDIFF(SECOND, window_start, NOW()) AS age
             FROM admin_login_attempts WHERE client = ?',
            [$key]
        );
        if ($row === null) {
            return null;
        }

        $age = (int) $row['age'];
        if ($age >= self::WINDOW_SECONDS || (int) $row['failures'] < $limit) {
            return null;
        }

        return self::WINDOW_SECONDS - $age;
    }

    /** Stored hashed: it is only ever compared for equality. */
    private function key(string $client): string
    {
        return hash('sha256', $client);
    }
}
