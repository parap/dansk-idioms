<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\Scheme;
use PHPUnit\Framework\TestCase;

/**
 * A cookie marked secure is never sent over http, so getting this wrong in either
 * direction is silent: too eager and sign-in stops working on a plain-http deployment,
 * too shy and the session cookie travels in the clear behind a terminating proxy.
 */
final class SchemeTest extends TestCase
{
    public function testADirectTlsConnectionIsHttps(): void
    {
        self::assertTrue(Scheme::isHttps(['HTTPS' => 'on']));
        self::assertTrue(Scheme::isHttps(['HTTPS' => '1']));
    }

    public function testApachesOffIsNotHttps(): void
    {
        // mod_ssl sets HTTPS to the string "off" rather than leaving it unset.
        self::assertFalse(Scheme::isHttps(['HTTPS' => 'off']));
        self::assertFalse(Scheme::isHttps([]));
    }

    public function testATerminatingProxyIsBelievedAboutTheScheme(): void
    {
        self::assertTrue(Scheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
        self::assertFalse(Scheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'http']));
    }

    public function testTheNearestHopDecidesInAChain(): void
    {
        // The hop next to this application appends last, so a client that opens the
        // chain with "https" does not talk the application into marking cookies secure
        // on a connection that is not.
        self::assertFalse(Scheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https, http']));
        self::assertTrue(Scheme::isHttps(['HTTP_X_FORWARDED_PROTO' => 'http, https']));
    }

    public function testCookiesAreSecureExactlyWhenTheRequestIs(): void
    {
        $over = Scheme::cookieParams(['HTTP_X_FORWARDED_PROTO' => 'https']);
        self::assertTrue($over['secure']);
        self::assertTrue($over['httponly']);
        self::assertSame('Lax', $over['samesite']);

        self::assertFalse(Scheme::cookieParams([])['secure']);
    }
}
