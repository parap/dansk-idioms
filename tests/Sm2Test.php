<?php declare(strict_types=1);

namespace Dansk\Tests;

use Dansk\Domain\Sm2;
use PHPUnit\Framework\TestCase;

/**
 * The review scheduler's arithmetic. It is pure: no clock, no database, no randomness,
 * so every schedule the app produces is reproducible from these five inputs alone.
 */
final class Sm2Test extends TestCase
{
    public function testTheFirstCorrectAnswerSchedulesOneDayAhead(): void
    {
        $next = Sm2::next(2.50, 0, 0, 0, true);

        self::assertSame(1, $next['interval_days']);
        self::assertSame(1, $next['repetitions']);
        self::assertSame(0, $next['lapses']);
        self::assertEqualsWithDelta(2.60, $next['ease'], 0.0001);
    }

    public function testTheSecondCorrectAnswerSchedulesSixDaysAhead(): void
    {
        $next = Sm2::next(2.60, 1, 1, 0, true);

        self::assertSame(6, $next['interval_days']);
        self::assertSame(2, $next['repetitions']);
    }

    public function testTheThirdIntervalMultipliesByTheEaseHeldBeforeThisAnswer(): void
    {
        // 6 x 2.50 = 15. Using the post-answer ease of 2.60 would give 16, so this
        // pins down which of the two eases the multiplication uses.
        $next = Sm2::next(2.50, 6, 2, 0, true);

        self::assertSame(15, $next['interval_days']);
        self::assertSame(3, $next['repetitions']);
        self::assertEqualsWithDelta(2.60, $next['ease'], 0.0001);
    }

    public function testAWrongAnswerResetsRepetitionsAndTheIntervalAndCountsALapse(): void
    {
        $next = Sm2::next(2.50, 15, 3, 1, false);

        self::assertSame(0, $next['repetitions']);
        self::assertSame(1, $next['interval_days']);
        self::assertSame(2, $next['lapses']);
        self::assertEqualsWithDelta(2.30, $next['ease'], 0.0001);
    }

    public function testEaseNeverFallsBelowTheFloor(): void
    {
        // Repeated failures must not drive the multiplier to zero or negative, which
        // would collapse every future interval to nothing.
        $ease = 2.50;
        for ($i = 0; $i < 20; $i++) {
            $ease = Sm2::next($ease, 1, 0, 0, false)['ease'];
        }

        self::assertEqualsWithDelta(1.30, $ease, 0.0001);
    }

    public function testEaseNeverRisesAboveTheCap(): void
    {
        $ease = 2.50;
        for ($i = 0; $i < 20; $i++) {
            $ease = Sm2::next($ease, 1, 1, 0, true)['ease'];
        }

        self::assertEqualsWithDelta(3.00, $ease, 0.0001);
    }

    public function testALapseCountIsCarriedThroughACorrectAnswerUntouched(): void
    {
        $next = Sm2::next(2.50, 6, 2, 4, true);

        self::assertSame(4, $next['lapses']);
    }
}
