<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * Says whether the request reached the browser over TLS.
 *
 * Behind a terminating proxy the connection to this application is plain http, so
 * $_SERVER['HTTPS'] is unset and a cookie marked secure here would never be set at all.
 * The proxy states the original scheme in X-Forwarded-Proto and overwrites whatever the
 * client sent, so the value is the proxy's, not the caller's.
 *
 * Read only to decide whether a cookie carries the secure flag. Nothing here may gate
 * access: a deployment without a proxy answers plain http on the loopback, and the
 * interface checks drive it that way.
 */
final class Scheme
{
    /** @param array<string,mixed> $server normally $_SERVER */
    public static function isHttps(array $server): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        // A chain is possible when another proxy sits in front. The hop nearest this
        // application wrote the last entry, so it is the one this application can rely on.
        $forwarded = (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($forwarded === '') {
            return false;
        }
        $hops = array_map(trim(...), explode(',', $forwarded));

        return strtolower((string) end($hops)) === 'https';
    }

    /**
     * Cookie parameters every cookie this application sets shares.
     *
     * @param  array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function cookieParams(array $server): array
    {
        return [
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps($server),
        ];
    }
}
