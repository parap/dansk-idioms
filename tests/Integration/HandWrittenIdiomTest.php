<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\ReviewRepository;
use Dansk\Support\Db;
use InvalidArgumentException;

/**
 * Adding an idiom by hand.
 *
 * Everything in the corpus arrived through the Telegram importer, so the only way to
 * publish one was to have a raw_entries row to accept. An idiom somebody simply knows had
 * nowhere to go.
 */
final class HandWrittenIdiomTest extends IntegrationTestCase
{
    private ReviewRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ReviewRepository();
    }

    public function testAHandWrittenIdiomIsPublishedAndUsableInAQuiz(): void
    {
        $id = $this->repo->addByHand('med det samme', 'сразу же');

        $row = Db::fetchOne('SELECT term, term_norm, is_published FROM idioms WHERE id = ?', [$id]);
        self::assertSame('med det samme', $row['term']);
        self::assertSame(1, (int) $row['is_published']);

        $tr = Db::fetchOne(
            "SELECT text, is_primary, quiz_usable, source FROM idiom_translations
             WHERE idiom_id = ? AND lang_code = 'ru'", [$id]
        );
        self::assertSame('сразу же', $tr['text']);
        self::assertSame(1, (int) $tr['is_primary']);
        self::assertSame(1, (int) $tr['quiz_usable']);
        self::assertSame('manual', $tr['source']);
    }

    public function testExtraSensesAreKeptWithoutBecomingThePrimary(): void
    {
        $id = $this->repo->addByHand('uden effekt', 'безрезультатно', ['без толку', 'без эффекта']);

        self::assertSame(3, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM idiom_translations WHERE idiom_id = ?', [$id]
        ));
        self::assertSame(1, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1', [$id]
        ));
        self::assertSame('безрезультатно', Db::fetchValue(
            'SELECT text FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1', [$id]
        ));
    }

    public function testAnOverlongPrimaryIsRefused(): void
    {
        // The same limit the review screen applies: a long gloss has no distractors of
        // comparable length, which makes the question answerable on sight.
        $this->expectException(InvalidArgumentException::class);
        $this->repo->addByHand(
            'at være indforstået med',
            'быть согласным с чем-либо, принимать условия и правила, относиться с пониманием'
        );
    }

    public function testAParentheticalPlaceholderStaysVisibleButLeavesTheKey(): void
    {
        $id = $this->repo->addByHand('at forholde sig til (noget)', 'отреагировать на что-либо');

        $row = Db::fetchOne('SELECT term, term_norm FROM idioms WHERE id = ?', [$id]);
        self::assertStringContainsString('(noget)', $row['term']);
        self::assertStringNotContainsString('(', $row['term_norm']);
    }

    public function testAddingTheSameTermTwiceReusesTheIdiom(): void
    {
        $first  = $this->repo->addByHand('i flere omgange', 'неоднократно');
        $second = $this->repo->addByHand('i flere omgange', 'несколько раз');

        self::assertSame($first, $second);
        self::assertSame(1, (int) Db::fetchValue('SELECT COUNT(*) FROM idioms WHERE id = ?', [$first]));
        // The newer translation takes the primary slot, and only one holds it.
        self::assertSame('несколько раз', Db::fetchValue(
            'SELECT text FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1', [$first]
        ));
        self::assertSame(2, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM idiom_translations WHERE idiom_id = ?', [$first]
        ));
    }

    public function testATermMustNotBeWrittenInTheWrongAlphabet(): void
    {
        // "forholде" with a Cyrillic д and е looks right and is a different word. Under
        // the as_cs collation it stores as its own idiom that nothing will ever match.
        $this->expectException(InvalidArgumentException::class);
        $this->repo->addByHand('at forholде sig til', 'отреагировать');
    }

    public function testTheFullGlossIsKeptAsTheExplanationTheLearnerSees(): void
    {
        // The primary has to be short enough to work as a quiz option, so the whole
        // meaning would otherwise be lost. It belongs where the quiz already looks for
        // it after an answer.
        $gloss = 'получить замечание, быть проинформированным о чём-либо, когда чьё-то '
               . 'внимание обращают на проблему';

        $id = $this->repo->addByHand(
            'at blive gjort opmærksom på (noget)',
            'быть проинформированным о чём-либо',
            ['получить замечание'],
            'phrase',
            null,
            $gloss,
        );

        self::assertSame($gloss, Db::fetchValue(
            "SELECT body FROM idiom_explanations WHERE idiom_id = ? AND lang_code = 'ru'", [$id]
        ));
    }

    public function testTheCorpusInvariantsStillHold(): void
    {
        $this->repo->addByHand('under alle omstændigheder', 'в любом случае', ['при любых обстоятельствах']);

        $this->assertCorpusInvariants();
    }
}
