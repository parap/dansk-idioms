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

    public function testTheShapeIsGuessedWhenTheAuthorDoesNotSayIt(): void
    {
        $id = $this->repo->addByHand('at forholde sig til (noget)', 'отреагировать');

        self::assertSame('verbal', Db::fetchValue('SELECT shape FROM idioms WHERE id = ?', [$id]));
    }

    public function testAnAuthorMayOverrideTheGuessedShape(): void
    {
        // The classifier reads "så vidt (nogen) er bekendt" as nominal. It is a clause
        // used adverbially, and shape is what the distractor picker scores options on.
        $id = $this->repo->addByHand(
            'så vidt (nogen) er bekendt', 'насколько кому-либо известно',
            [], 'phrase', null, null, 'adverbial',
        );

        self::assertSame('adverbial', Db::fetchValue('SELECT shape FROM idioms WHERE id = ?', [$id]));
    }

    public function testAnUnknownShapeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->addByHand('med det samme', 'сразу же', [], 'phrase', null, null, 'sideways');
    }

    public function testReloadingCorrectsTheClassificationOfAnIdiomAlreadyStored(): void
    {
        // content/idioms/ is the source of truth and the database is derived from it, so
        // fixing a kind or a shape in the file has to reach a row that already exists.
        $id = $this->repo->addByHand('så vidt (nogen) er bekendt', 'насколько известно');
        self::assertSame('nominal', Db::fetchValue('SELECT shape FROM idioms WHERE id = ?', [$id]));

        $again = $this->repo->addByHand(
            'så vidt (nogen) er bekendt', 'насколько кому-либо известно',
            [], 'collocation', null, null, 'adverbial',
        );

        self::assertSame($id, $again);
        $row = Db::fetchOne('SELECT kind, shape FROM idioms WHERE id = ?', [$id]);
        self::assertSame('adverbial', $row['shape']);
        self::assertSame('collocation', $row['kind']);
    }

    public function testASenseCanBeRetiredSoItStopsBeingOfferedAtAll(): void
    {
        // Demoting a bad primary is not enough: it stays quiz_usable and goes on being
        // offered as a distractor for other questions, where obvious metalanguage tells
        // a reader which option to rule out.
        $id = $this->repo->addByHand('ny single', 'устойчивое разговорное сочетание');

        $this->repo->addByHand(
            term: 'ny single',
            primary: 'свежеиспечённый одиночка',
            retire: ['устойчивое разговорное сочетание'],
        );

        self::assertSame('свежеиспечённый одиночка', Db::fetchValue(
            'SELECT text FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1', [$id]
        ));
        self::assertSame(0, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM idiom_translations WHERE idiom_id = ? AND text = ?',
            [$id, 'устойчивое разговорное сочетание']
        ));
    }

    public function testRetiringTheSenseBeingPromotedIsIgnored(): void
    {
        // Otherwise a careless file could leave an idiom published with no answer at all.
        $id = $this->repo->addByHand(
            term: 'uden effekt',
            primary: 'безрезультатно',
            retire: ['безрезультатно'],
        );

        self::assertSame('безрезультатно', Db::fetchValue(
            'SELECT text FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1', [$id]
        ));
        $this->assertCorpusInvariants();
    }

    public function testTheCorpusInvariantsStillHold(): void
    {
        $this->repo->addByHand('under alle omstændigheder', 'в любом случае', ['при любых обстоятельствах']);

        $this->assertCorpusInvariants();
    }
}
