<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Support\Db;
use RuntimeException;

/**
 * The drill round. A paper is materialised server-side before any of it is served, the
 * answer never appears in a question response, and a score is computed from the stored
 * row rather than from anything the browser claims.
 */
final class ReadingDrillTest extends IntegrationTestCase
{
    private ReadingSessionService $service;
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo    = new ReadingRepository();
        $this->service = new ReadingSessionService();
    }

    private function publishedCloze(string $slug = 'cykler-i-byen'): int
    {
        $id = $this->repo->save([
            'slug'  => $slug,
            'kind'  => 'cloze',
            'title' => 'Cykler i byen',
            'body'  => 'Hver morgen ruller tusindvis ind mod centrum. {{1}} har kommunen '
                     . 'bygget nye stier, og det {{2}} at flere tor cykle.',
            'items' => [
                ['position' => 1, 'options' => [
                    ['label' => 'A', 'text' => 'Derfor', 'correct' => true],
                    ['label' => 'B', 'text' => 'Alligevel'],
                    ['label' => 'C', 'text' => 'Dernaest'],
                    ['label' => 'D', 'text' => 'Til gengaeld'],
                ]],
                ['position' => 2, 'options' => [
                    ['label' => 'A', 'text' => 'betyder', 'correct' => true],
                    ['label' => 'B', 'text' => 'betyde'],
                    ['label' => 'C', 'text' => 'betydning'],
                    ['label' => 'D', 'text' => 'betydet'],
                ]],
            ],
        ]);
        $this->repo->publish($id);

        return $id;
    }

    private function startDrill(): string
    {
        return $this->service->start(null, str_repeat('a', 32), ReadingSessionService::DRILL)['session_id'];
    }

    /** The index the stored row says is correct, which the client is never told. */
    private function correctIndex(string $sid, int $position): int
    {
        return (int) Db::fetchValue(
            'SELECT i.correct_index FROM reading_session_items i
             JOIN reading_sessions s ON s.id = i.session_id
             WHERE s.public_id = ? AND i.position = ?',
            [$sid, $position]
        );
    }

    // ---- serving -----------------------------------------------------------

    public function testAServedPaperNeverCarriesTheAnswer(): void
    {
        $this->publishedCloze();
        $paper = $this->service->session($this->startDrill());

        foreach ($paper['items'] as $item) {
            self::assertArrayNotHasKey('correct_index', $item);
            self::assertArrayNotHasKey('correct_option_id', $item);
            foreach ($item['options'] as $option) {
                self::assertSame(['index', 'text'], array_keys($option));
            }
        }
    }

    public function testAnUnpublishedPassageIsNeverServed(): void
    {
        $id = $this->publishedCloze();
        $this->repo->unpublish($id);

        $this->expectException(RuntimeException::class);
        $this->startDrill();
    }

    public function testAFlaggedItemIsLeftOutAndTheTotalDropsWithIt(): void
    {
        $id = $this->publishedCloze();
        Db::execute('UPDATE reading_items SET is_flagged = 1 WHERE passage_id = ? AND position = 1', [$id]);

        $paper = $this->service->session($this->startDrill());

        self::assertCount(1, $paper['items']);
        self::assertSame(1, $paper['points_max']);
    }

    public function testOptionOrderIsDecidedPerSessionRatherThanByTheAuthor(): void
    {
        $this->publishedCloze();

        $orders = [];
        for ($i = 0; $i < 20; $i++) {
            $paper = $this->service->session($this->startDrill());
            $orders[] = implode('|', array_column($paper['items'][0]['options'], 'text'));
        }

        self::assertGreaterThan(1, count(array_unique($orders)));
    }

    // ---- grading -----------------------------------------------------------

    public function testACorrectAnswerScoresTheItemsPoints(): void
    {
        $this->publishedCloze();
        $sid = $this->startDrill();

        $result = $this->service->answer($sid, 1, $this->correctIndex($sid, 1));

        self::assertTrue($result['is_correct']);
        self::assertSame(1, $result['points_scored']);
    }

    public function testGradingReadsTheStoredRowNotTheRequest(): void
    {
        $this->publishedCloze();
        $sid   = $this->startDrill();
        $wrong = ($this->correctIndex($sid, 1) + 1) % 4;

        $result = $this->service->answer($sid, 1, $wrong);

        self::assertFalse($result['is_correct']);
        self::assertSame(0, (int) Db::fetchValue(
            'SELECT points_scored FROM reading_sessions WHERE public_id = ?', [$sid]
        ));
    }

    public function testDrillModeReturnsTheCorrectAnswerOnceTheItemIsAnswered(): void
    {
        $this->publishedCloze();
        $sid = $this->startDrill();

        $result = $this->service->answer($sid, 1, $this->correctIndex($sid, 1));

        self::assertArrayHasKey('correct_index', $result);
        self::assertSame($this->correctIndex($sid, 1), $result['correct_index']);
    }

    public function testAnItemCannotBeAnsweredTwice(): void
    {
        $this->publishedCloze();
        $sid = $this->startDrill();
        $this->service->answer($sid, 1, 0);

        $this->expectException(RuntimeException::class);
        $this->service->answer($sid, 1, 1);
    }

    public function testAResponseTimeFromTheClientCannotPoisonTheStatistics(): void
    {
        $this->publishedCloze();
        $sid = $this->startDrill();

        $this->service->answer($sid, 1, 0, 99_999_999);

        self::assertSame(300_000, (int) Db::fetchValue(
            'SELECT i.response_ms FROM reading_session_items i
             JOIN reading_sessions s ON s.id = i.session_id
             WHERE s.public_id = ? AND i.position = 1', [$sid]
        ));
    }

    // ---- identity ----------------------------------------------------------

    public function testAGuestKeepsTheirRoundByAnonKey(): void
    {
        $this->publishedCloze();
        $sid = $this->startDrill();

        self::assertSame(str_repeat('a', 32), Db::fetchValue(
            'SELECT anon_key FROM reading_sessions WHERE public_id = ?', [$sid]
        ));
    }

    public function testASignedInLearnerGetsAReviewDateForTheItemTheyAnswered(): void
    {
        $this->publishedCloze();
        Db::execute("INSERT INTO users (email, password_hash) VALUES ('l@example.com', 'x')");
        $userId = (int) Db::pdo()->lastInsertId();

        $sid = $this->service->start($userId, null, ReadingSessionService::DRILL)['session_id'];
        $this->service->answer($sid, 1, $this->correctIndex($sid, 1));

        $progress = Db::fetchOne('SELECT repetitions, interval_days, due_at FROM user_reading_progress WHERE user_id = ?', [$userId]);

        self::assertNotNull($progress);
        self::assertSame(1, (int) $progress['repetitions']);
        self::assertSame(1, (int) $progress['interval_days']);
        self::assertNotNull($progress['due_at']);
    }

    public function testAGuestIsNotGivenProgressRowsToWriteInto(): void
    {
        $this->publishedCloze();
        $sid = $this->startDrill();

        $this->service->answer($sid, 1, $this->correctIndex($sid, 1));

        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM user_reading_progress'));
    }

    public function testThePublicIdIsAUlidAndTheRowIdIsNeverExposed(): void
    {
        $this->publishedCloze();

        self::assertMatchesRegularExpression('/\A[0-9A-Z]{26}\z/', $this->startDrill());
    }
}
