<?php declare(strict_types=1);

namespace Dansk\Tests;

use Dansk\Support\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Public session identifiers. The shape is load-bearing in two places: the routes match
 * {sid:[0-9A-Z]{26}}, and a Telegram callback_data payload must stay inside 64 bytes.
 */
final class UlidTest extends TestCase
{
    public function testAnIdentifierIsTwentySixCharacters(): void
    {
        self::assertSame(26, strlen(Ulid::generate()));
    }

    public function testAnIdentifierUsesOnlyCrockfordBase32(): void
    {
        // I, L, O and U are excluded so a transcribed id cannot be confused with 1/0.
        self::assertMatchesRegularExpression('/\A[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}\z/', Ulid::generate());
    }

    public function testAnIdentifierMatchesTheRoutePattern(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9A-Z]{26}\z/', Ulid::generate());
    }

    public function testIdentifiersDoNotRepeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[Ulid::generate()] = true;
        }

        self::assertCount(1000, $seen);
    }

    public function testTheLeadingTimestampSortsLaterIdentifiersAfterEarlierOnes(): void
    {
        $first = Ulid::generate();
        usleep(2000);
        $second = Ulid::generate();

        self::assertLessThan(0, strcmp(substr($first, 0, 10), substr($second, 0, 10)));
    }
}
