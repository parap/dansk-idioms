<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\ReviewRepository;
use Dansk\Import\Importer;
use Dansk\Support\Db;

final class ReviewTest extends IntegrationTestCase
{
    private ReviewRepository $review;

    protected function setUp(): void
    {
        parent::setUp();
        (new Importer())->import($this->fixtureExport(), 'Fixture');
        $this->review = new ReviewRepository();
    }

    private function queuedEntryId(): int
    {
        Db::execute("UPDATE raw_entries SET status = 'needs_review' WHERE id = (SELECT * FROM (
            SELECT MIN(id) FROM raw_entries) x)");
        return (int) Db::fetchValue("SELECT id FROM raw_entries WHERE status = 'needs_review' LIMIT 1");
    }

    /**
     * The review screen was the only way an over-long option could exist: it saved
     * whatever was typed and marked it usable, while the importer enforces six words.
     */
    public function testAnOverLongAnswerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too long/i');

        $this->review->accept(
            $this->queuedEntryId(),
            'at prøve',
            'нечто подсознательное, инстинктивное, уходящее корнями в древние века, '
            . 'чему говорящий не может подобрать точное название'
        );
    }

    public function testAnEmptyTermOrTranslationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->review->accept($this->queuedEntryId(), 'at prøve', '   ');
    }

    public function testAcceptingProducesAPublishedAnswerableIdiom(): void
    {
        $entryId = $this->queuedEntryId();

        $result = $this->review->accept($entryId, 'at prøve på noget', 'пытаться сделать');

        $idiom = Db::fetchOne('SELECT * FROM idioms WHERE id = ?', [$result['idiom_id']]);
        self::assertSame('at prøve på noget', $idiom['term']);
        self::assertSame(1, (int) $idiom['is_published']);

        $primary = Db::fetchOne(
            "SELECT * FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1",
            [$result['idiom_id']]
        );
        self::assertSame('пытаться сделать', $primary['text']);
        self::assertSame(1, (int) $primary['quiz_usable']);
        self::assertSame('manual', $primary['source']);

        $this->assertCorpusInvariants();
    }

    public function testAcceptingMarksTheEntryFixedAndLinksIt(): void
    {
        $entryId = $this->queuedEntryId();
        $result  = $this->review->accept($entryId, 'at prøve på noget', 'пытаться сделать');

        $entry = Db::fetchOne('SELECT status, idiom_id FROM raw_entries WHERE id = ?', [$entryId]);
        self::assertSame('fixed', $entry['status']);
        self::assertSame($result['idiom_id'], (int) $entry['idiom_id']);
    }

    /** A human decision must outlive every later re-parse. */
    public function testAReviewedEntryIsNotReopenedByReimport(): void
    {
        $entryId = $this->queuedEntryId();
        $this->review->accept($entryId, 'at prøve på noget', 'пытаться сделать');

        (new Importer())->import($this->fixtureExport(), 'Fixture');

        $entry = Db::fetchOne('SELECT status, term_guess FROM raw_entries WHERE id = ?', [$entryId]);
        self::assertSame('fixed', $entry['status']);
        self::assertSame('at prøve på noget', $entry['term_guess']);
    }

    public function testAcceptingReplacesAnExistingPrimaryRatherThanColliding(): void
    {
        $entryId = $this->queuedEntryId();
        $first   = $this->review->accept($entryId, 'at prøve på noget', 'пытаться сделать');
        $this->review->accept($entryId, 'at prøve på noget', 'стараться изо всех сил');

        $primaries = Db::fetchAll(
            'SELECT text FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1',
            [$first['idiom_id']]
        );
        self::assertCount(1, $primaries, 'uq_primary allows exactly one primary per language');
        self::assertSame('стараться изо всех сил', $primaries[0]['text']);
    }

    public function testTheQueueIsOrderedByLeastConfidentFirst(): void
    {
        Db::execute("UPDATE raw_entries SET status = 'needs_review'");

        $queue = $this->review->queue(50);
        self::assertNotEmpty($queue);

        $confidences = array_map(static fn(array $r): float => (float) $r['confidence'], $queue);
        $sorted = $confidences;
        sort($sorted);
        self::assertSame($sorted, $confidences, 'the least certain parses must come first');

        self::assertArrayHasKey('candidates', $queue[0], 'the reviewer picks rather than types');
    }
}
