<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * Decides whether the admin surface answers the request at hand.
 *
 * Apache serves the application on two ports. Compose publishes one of them to the world
 * and the other to the loopback, so reaching the second from anywhere else takes an ssh
 * tunnel. The review queue and everything under /api/v1/admin/ answer through the
 * loopback listener alone; through the public one they behave as if they did not exist.
 *
 * The listener marks itself with a server variable that only the virtual host can set.
 * SERVER_PORT cannot do this job: with UseCanonicalName off -- the default -- Apache
 * takes it from the Host header, so the client picks its own value and the check answers
 * to whoever asks. A header of the same name arrives as HTTP_DANSK_ADMIN_LISTENER and
 * never collides with this one.
 */
final class AdminReach
{
    public const LISTENER = 'DANSK_ADMIN_LISTENER';

    /** @param array<string,mixed> $server normally $_SERVER */
    public static function reachable(array $server): bool
    {
        return ($server[self::LISTENER] ?? null) === '1';
    }

    public static function isAdminHandler(string $handler): bool
    {
        return str_starts_with($handler, 'admin.');
    }

    public static function isAdminPath(string $uri): bool
    {
        return str_starts_with($uri, '/admin');
    }
}
