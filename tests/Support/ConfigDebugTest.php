<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Whether the app hands its internals to whoever asked.
 *
 * With debug on, an API error returns the exception message and the health endpoint
 * returns the database error, which between them leak SQL, table names and paths. This
 * app is reachable from the internet through a tunnel, so the question is not academic
 * and the answer must not depend on remembering to set an environment variable.
 */
final class ConfigDebugTest extends TestCase
{
    public function testDebugIsOffWhenNothingIsSaid(): void
    {
        self::assertFalse(Config::debugFromEnv(null, null));
    }

    public function testDebugIsOffInDevUnlessItIsAskedFor(): void
    {
        // The dangerous default: "dev" used to be enough to publish exception text, and
        // dev is exactly what a machine serving a tunnel is left running as.
        self::assertFalse(Config::debugFromEnv('dev', null));
    }

    public function testDebugIsOnOnlyWhenExplicitlyEnabled(): void
    {
        foreach (['1', 'true', 'yes', 'on'] as $truthy) {
            self::assertTrue(Config::debugFromEnv('dev', $truthy), "APP_DEBUG={$truthy}");
        }
    }

    public function testDebugStaysOffForValuesThatOnlyLookTrue(): void
    {
        foreach (['0', 'false', 'no', 'off', '', 'maybe'] as $falsy) {
            self::assertFalse(Config::debugFromEnv('dev', $falsy), "APP_DEBUG={$falsy}");
        }
    }

    public function testProductionRefusesDebugEvenWhenAskedFor(): void
    {
        // A stray APP_DEBUG left in an environment file must not be able to turn a
        // production deployment into a debugging one.
        self::assertFalse(Config::debugFromEnv('prod', 'true'));
    }

    public function testTheShippedConfigurationHasDebugOff(): void
    {
        self::assertFalse(Config::get('debug'), 'the running configuration leaks internals');
    }
}
