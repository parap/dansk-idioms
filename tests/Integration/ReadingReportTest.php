<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\ReadingReportRepository;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Support\Db;
use RuntimeException;

/**
 * The appeal path. A learner who believes an item is wrong routes it to a person instead
 * of arguing with a scoreboard, and two independent voices take it out of circulation
 * until someone has looked.
 */
final class ReadingReportTest extends IntegrationTestCase
{
    private ReadingSessionService $service;
    private ReadingReportRepository $reports;
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo    = new ReadingRepository();
        $this->service = new ReadingSessionService();
        $this->reports = new ReadingReportRepository();
        $this->publish();
    }

    private function publish(): void
    {
        $id = $this->repo->save([
            'slug' => 'cykler', 'kind' => 'cloze', 'title' => 'Cykler',
            'body' => 'En tekst med {{1}} og {{2}}.',
            'items' => [
                ['position' => 1, 'options' => [
                    ['label' => 'A', 'text' => 'et', 'correct' => true],
                    ['label' => 'B', 'text' => 'to'], ['label' => 'C', 'text' => 'tre'],
                ]],
                ['position' => 2, 'options' => [
                    ['label' => 'A', 'text' => 'fire', 'correct' => true],
                    ['label' => 'B', 'text' => 'fem'], ['label' => 'C', 'text' => 'seks'],
                ]],
            ],
        ]);
        $this->repo->publish($id);
    }

    private function start(string $anon): string
    {
        return $this->service->start(null, $anon, ReadingSessionService::DRILL)['session_id'];
    }

    private function itemIdAt(string $sid, int $position): int
    {
        return (int) Db::fetchValue(
            'SELECT si.item_id FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = ?',
            [$sid, $position]
        );
    }

    public function testReportingMarksTheRowInTheRoundItCameFrom(): void
    {
        $sid = $this->start(str_repeat('a', 32));
        $this->reports->report($sid, 1, null, str_repeat('a', 32), 'also_correct', null);

        self::assertSame(1, (int) Db::fetchValue(
            'SELECT si.reported FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = 1', [$sid]
        ));
    }

    public function testOneReportIsNotEnoughToWithdrawAnItem(): void
    {
        $sid = $this->start(str_repeat('a', 32));
        $this->reports->report($sid, 1, null, str_repeat('a', 32), 'also_correct', null);

        self::assertSame(0, (int) Db::fetchValue(
            'SELECT is_flagged FROM reading_items WHERE id = ?', [$this->itemIdAt($sid, 1)]
        ));
    }

    public function testTwoIndependentReportsWithdrawTheItem(): void
    {
        $first  = $this->start(str_repeat('a', 32));
        $itemId = $this->itemIdAt($first, 1);
        $this->reports->report($first, 1, null, str_repeat('a', 32), 'also_correct', null);

        $second = $this->start(str_repeat('b', 32));
        $pos    = (int) Db::fetchValue(
            'SELECT si.position FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.item_id = ?', [$second, $itemId]
        );
        $this->reports->report($second, $pos, null, str_repeat('b', 32), 'no_correct', 'Ingen af dem passer.');

        self::assertSame(1, (int) Db::fetchValue('SELECT is_flagged FROM reading_items WHERE id = ?', [$itemId]));
    }

    public function testOneLearnerCannotBuryAnItemAlone(): void
    {
        $anon = str_repeat('a', 32);
        $sid  = $this->start($anon);
        $this->reports->report($sid, 1, null, $anon, 'also_correct', null);
        $this->reports->report($sid, 1, null, $anon, 'unclear', null);

        $itemId = $this->itemIdAt($sid, 1);
        self::assertSame(1, (int) Db::fetchValue('SELECT report_count FROM reading_items WHERE id = ?', [$itemId]));
        self::assertSame(0, (int) Db::fetchValue('SELECT is_flagged FROM reading_items WHERE id = ?', [$itemId]));
    }

    public function testAReportedItemStillScoresInTheRoundItWasReportedIn(): void
    {
        $sid = $this->start(str_repeat('a', 32));
        $this->reports->report($sid, 1, null, str_repeat('a', 32), 'also_correct', null);

        $correct = (int) Db::fetchValue(
            'SELECT si.correct_index FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = 1', [$sid]
        );

        self::assertTrue($this->service->answer($sid, 1, $correct)['is_correct']);
    }

    public function testReportingAnItemThatIsNotInTheRoundIsRefused(): void
    {
        $sid = $this->start(str_repeat('a', 32));

        // Named rather than merely typed: PDOException extends RuntimeException, so a
        // database error raised further down would otherwise satisfy this assertion and
        // the guard could be deleted unnoticed.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No such item in this round.');
        $this->reports->report($sid, 99, null, str_repeat('a', 32), 'other', null);
    }

    public function testTheQueueShowsFlaggedItemsWithWhatWasSaidAboutThem(): void
    {
        $first  = $this->start(str_repeat('a', 32));
        $itemId = $this->itemIdAt($first, 1);
        $this->reports->report($first, 1, null, str_repeat('a', 32), 'also_correct', 'Mit svar var også rigtigt.');

        $second = $this->start(str_repeat('b', 32));
        $pos = (int) Db::fetchValue(
            'SELECT si.position FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.item_id = ?', [$second, $itemId]
        );
        $this->reports->report($second, $pos, null, str_repeat('b', 32), 'no_correct', null);

        $queue = $this->reports->flagged();

        self::assertCount(1, $queue);
        self::assertSame('Cykler', $queue[0]['passage_title']);
        self::assertCount(2, $queue[0]['reports']);
        self::assertContains('Mit svar var også rigtigt.', array_column($queue[0]['reports'], 'note'));
    }

    public function testClearingAFlagPutsTheItemBackInCirculation(): void
    {
        $sid    = $this->start(str_repeat('a', 32));
        $itemId = $this->itemIdAt($sid, 1);
        Db::execute('UPDATE reading_items SET is_flagged = 1, report_count = 2 WHERE id = ?', [$itemId]);

        $this->reports->clear($itemId);

        self::assertSame(0, (int) Db::fetchValue('SELECT is_flagged FROM reading_items WHERE id = ?', [$itemId]));
        self::assertSame([], $this->reports->flagged());
    }
}
