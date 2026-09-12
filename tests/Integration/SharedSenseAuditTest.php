<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\IdiomFile;
use Dansk\Domain\ReviewRepository;
use Dansk\Domain\SharedSenseAudit;
use Dansk\Support\Db;

/**
 * Finds pairs of idioms that can be served as each other's wrong answer.
 *
 * Two idioms sharing a sense is enough. The picker excludes options belonging to the
 * idiom being asked about, but not the other idiom's copy of the same words -- so a
 * learner is offered a translation that is genuinely correct and marked down for
 * choosing it. Declaring the pair as synonyms is what stops it.
 */
final class SharedSenseAuditTest extends IntegrationTestCase
{
    private ReviewRepository $repo;
    private SharedSenseAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo  = new ReviewRepository();
        $this->audit = new SharedSenseAudit();
    }

    private function terms(array $overlaps): array
    {
        return array_map(
            static fn(array $o): string => $o['a_term'] . ' / ' . $o['b_term'] . ': ' . $o['text'],
            $overlaps
        );
    }

    public function testAnUngroupedOverlapIsReported(): void
    {
        $this->repo->addByHand('at melde sig på banen', 'включиться в игру');
        $this->repo->addByHand('at hoppe på', 'согласиться', ['включиться в игру']);

        self::assertSame(
            ['at melde sig på banen / at hoppe på: включиться в игру'],
            $this->terms($this->audit->overlaps())
        );
    }

    public function testAnOverlapInsideASynonymGroupIsNotReported(): void
    {
        $this->repo->addByHand('at melde sig på banen', 'включиться в игру');
        $this->repo->addByHand('at hoppe på', 'согласиться', ['включиться в игру']);
        $this->repo->declareSynonyms('at hoppe på', ['at melde sig på banen']);

        self::assertSame([], $this->audit->overlaps());
    }

    public function testALiteralOverlapIsReportedBecauseLiteralsAreOffered(): void
    {
        // The picker offers a literal gloss on purpose -- (quiz_usable = 1 OR sense_type
        // = 'literal') -- so a literal that happens to be another idiom's answer is just
        // as wrong to serve as any other duplicate.
        $this->repo->addByHand('at smide benene op', 'вытянуть ноги');
        $this->repo->addByHand('at strække sig', 'потянуться', literal: ['вытянуть ноги']);

        self::assertSame(
            ['at smide benene op / at strække sig: вытянуть ноги'],
            $this->terms($this->audit->overlaps())
        );
    }

    public function testALiteralOverlapIsReportedWhicheverSideHoldsIt(): void
    {
        // A pair is keyed by the lower id, so a test that only ever puts the literal on
        // the second idiom leaves the first idiom's half of the offerable test unproven.
        $this->repo->addByHand('at strække sig', 'потянуться', literal: ['вытянуть ноги']);
        $this->repo->addByHand('at smide benene op', 'вытянуть ноги');

        self::assertSame(
            ['at strække sig / at smide benene op: вытянуть ноги'],
            $this->terms($this->audit->overlaps())
        );
    }

    public function testAnUnpublishedIdiomIsNotReported(): void
    {
        $a = $this->repo->addByHand('at melde sig på banen', 'включиться в игру');
        $this->repo->addByHand('at hoppe på', 'согласиться', ['включиться в игру']);
        Db::execute('UPDATE idioms SET is_published = 0 WHERE id = ?', [$a]);

        self::assertSame([], $this->audit->overlaps());
    }

    public function testASenseThatCannotBeOfferedIsNotReported(): void
    {
        $this->repo->addByHand('at melde sig på banen', 'включиться в игру');
        $b = $this->repo->addByHand('at hoppe på', 'согласиться', ['включиться в игру']);
        Db::execute(
            "UPDATE idiom_translations SET quiz_usable = 0, sense_type = 'gloss'
              WHERE idiom_id = ? AND text = ?", [$b, 'включиться в игру']
        );

        self::assertSame([], $this->audit->overlaps());
    }

    public function testAPairIsReportedOnceRatherThanTwice(): void
    {
        $this->repo->addByHand('at melde sig på banen', 'включиться в игру', ['согласиться']);
        $this->repo->addByHand('at hoppe på', 'согласиться', ['включиться в игру']);

        self::assertCount(2, $this->audit->overlaps());
        foreach ($this->audit->overlaps() as $o) {
            self::assertLessThan($o['b_id'], $o['a_id'], 'a pair is reported in both directions');
        }
    }

    public function testTheShippedCorpusHasNoUngroupedOverlaps(): void
    {
        // The audit as a guard: adding a gloss that another idiom already claims fails
        // here rather than showing up as a question that marks a right answer wrong.
        // Groups are declared last: a group can only be declared once both its idioms
        // are stored.
        $dir    = dirname(__DIR__, 2) . '/content/idioms';
        $loader = new IdiomFile();
        foreach (IdiomFile::entryFiles($dir) as $file) {
            $loader->load($file);
        }
        $loader->loadSynonymGroups(IdiomFile::groupFile($dir));

        self::assertSame([], $this->terms($this->audit->overlaps()));
    }
}
