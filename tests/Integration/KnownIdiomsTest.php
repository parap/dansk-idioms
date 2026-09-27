<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\ReviewRepository;
use Dansk\Support\KnownIdioms;

/** Whether a piece the bot was sent names an idiom the corpus already has. */
final class KnownIdiomsTest extends IntegrationTestCase
{
    public function testAKnownIdiomIsFoundUnderItsStoredTerm(): void
    {
        // The key is the import's own: "at" and the bracketed object do not count.
        (new ReviewRepository())->addByHand('regne med', 'рассчитывать');

        $term = (new KnownIdioms())("\u{200B}at regne med (noget) — рассчитывать на (что-то). Фразовый глагол.");

        self::assertSame('regne med', $term);
    }

    public function testAnIdiomNotYetThereIsNew(): void
    {
        (new ReviewRepository())->addByHand('regne med', 'рассчитывать');

        self::assertNull((new KnownIdioms())('at regne ud — вычислить'));
    }

    public function testTheExplanationDoesNotDecide(): void
    {
        // The same idiom explained in other words is still the same idiom.
        (new ReviewRepository())->addByHand('tie stille', 'молчать');

        self::assertSame('tie stille', (new KnownIdioms())('at tie stille — помалкивать, соблюдать тишину'));
    }
}
