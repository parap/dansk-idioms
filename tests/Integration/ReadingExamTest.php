<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Support\Db;
use RuntimeException;

/**
 * The timed paper. An exam differs from a drill in three ways that all have to hold at
 * once: it draws one text of every task type, it tells the learner nothing until they
 * submit, and its clock belongs to the server.
 */
final class ReadingExamTest extends IntegrationTestCase
{
    private ReadingSessionService $service;
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo    = new ReadingRepository();
        $this->service = new ReadingSessionService();
    }

    private function publishAll(): void
    {
        $mc = $this->repo->save([
            'slug' => 'brancheskift', 'kind' => 'mc', 'title' => 'At skifte branche',
            'body' => 'Der kan vaere mange grunde til at man vaelger at skifte branche midt i livet.',
            'items' => [
                ['position' => 1, 'prompt' => 'Hvorfor overvejede hun et skift?', 'options' => [
                    ['label' => 'A', 'text' => 'Hun savnede tid til fordybelse', 'correct' => true],
                    ['label' => 'B', 'text' => 'Hun ville tjene mere'],
                    ['label' => 'C', 'text' => 'Hun flyttede til en anden by'],
                ]],
                ['position' => 2, 'prompt' => 'Hvad er den almindeligste grund til at blive?', 'options' => [
                    ['label' => 'A', 'text' => 'Frygt for at miste privilegier', 'correct' => true],
                    ['label' => 'B', 'text' => 'Mangel paa uddannelse'],
                    ['label' => 'C', 'text' => 'Lang transporttid'],
                ]],
            ],
        ]);
        $ins = $this->repo->save([
            'slug' => 'fire-dages-uge', 'kind' => 'insert', 'title' => 'En fire-dages arbejdsuge?',
            'body' => 'Siden fagforeningernes opkomst {{1}} har man diskuteret arbejdstiden. {{2}}',
            'bank' => [
                ['label' => 'A', 'text' => 'I Danmark er der hver dag 35.000 sygemeldinger.'],
                ['label' => 'B', 'text' => 'Det forventes at nye medarbejdere moeder ind.'],
                ['label' => 'C', 'text' => 'Der er dog udfordringer ved tilpassede forhold.'],
            ],
            'items' => [
                ['position' => 1, 'correct_label' => 'A'],
                ['position' => 2, 'correct_label' => 'C'],
            ],
        ]);
        $cloze = $this->repo->save([
            'slug' => 'bymidter', 'kind' => 'cloze', 'title' => 'Nethandel og bymidter',
            'body' => 'Folk benytter sig i stigende grad af nethandel. {{1}} er bymidterne {{2}} pres.',
            'items' => [
                ['position' => 1, 'options' => [
                    ['label' => 'A', 'text' => 'Derfor', 'correct' => true],
                    ['label' => 'B', 'text' => 'Alligevel'],
                    ['label' => 'C', 'text' => 'Dernaest'],
                    ['label' => 'D', 'text' => 'Nemlig'],
                ]],
                ['position' => 2, 'options' => [
                    ['label' => 'A', 'text' => 'under', 'correct' => true],
                    ['label' => 'B', 'text' => 'over'],
                    ['label' => 'C', 'text' => 'uden'],
                    ['label' => 'D', 'text' => 'omkring'],
                ]],
            ],
        ]);
        foreach ([$mc, $ins, $cloze] as $id) {
            $this->repo->publish($id);
        }
    }

    private function startExam(?int $userId = null): string
    {
        return $this->service->start($userId, str_repeat('e', 32), ReadingSessionService::EXAM)['session_id'];
    }

    /** @return list<array{position:int,correct:int,points:int}> */
    private function key(string $sid): array
    {
        return array_map(
            static fn(array $r): array => [
                'position' => (int) $r['position'],
                'correct'  => (int) $r['correct_index'],
                'points'   => (int) $r['points'],
            ],
            Db::fetchAll(
                'SELECT si.position, si.correct_index, si.points FROM reading_session_items si
                 JOIN reading_sessions s ON s.id = si.session_id
                 WHERE s.public_id = ? ORDER BY si.position',
                [$sid]
            )
        );
    }

    private function answerAll(string $sid, bool $correctly): void
    {
        foreach ($this->key($sid) as $item) {
            $chosen = $correctly ? $item['correct'] : ($item['correct'] + 1) % 3;
            $this->service->answer($sid, $item['position'], $chosen);
        }
    }

    // ---- assembly ----------------------------------------------------------

    public function testAnExamDrawsOnePassageOfEveryTaskKind(): void
    {
        $this->publishAll();
        $paper = $this->service->session($this->startExam());

        $kinds = array_column($paper['passages'], 'kind');
        sort($kinds);

        self::assertSame(['cloze', 'insert', 'mc'], $kinds);
    }

    public function testThePaperTotalIsSummedFromTheItemsRatherThanAssumed(): void
    {
        $this->publishAll();
        $paper = $this->service->session($this->startExam());

        // 2 mc x 2 + 2 insert x 2 + 2 cloze x 1
        self::assertSame(10, $paper['points_max']);
    }

    public function testAnExamCannotBeBuiltWhenATaskKindHasNoPublishedText(): void
    {
        $this->publishAll();
        Db::execute("UPDATE reading_passages SET is_published = 0 WHERE kind = 'insert'");

        $this->expectException(RuntimeException::class);
        $this->startExam();
    }

    // ---- silence until submit ----------------------------------------------

    public function testAnsweringAnExamReturnsNothingButAnAcknowledgement(): void
    {
        $this->publishAll();
        $sid = $this->startExam();

        $result = $this->service->answer($sid, 1, 0);

        self::assertSame(['recorded' => true], $result);
    }

    public function testAnAnswerCanBeChangedUntilSubmitAndNeverAfter(): void
    {
        $this->publishAll();
        $sid = $this->startExam();

        $this->service->answer($sid, 1, 0);
        $this->service->answer($sid, 1, 1);

        self::assertSame(1, (int) Db::fetchValue(
            'SELECT si.chosen_index FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = 1', [$sid]
        ));

        $this->service->submit($sid);

        $this->expectException(RuntimeException::class);
        $this->service->answer($sid, 1, 2);
    }

    // ---- scoring -----------------------------------------------------------

    public function testSubmitTotalsThePointsAndResolvesAKarakter(): void
    {
        $this->publishAll();
        $sid = $this->startExam();
        $this->answerAll($sid, true);

        $result = $this->service->submit($sid);

        self::assertSame(10, $result['points_scored']);
        self::assertSame(10, $result['points_max']);
        self::assertSame('12', $result['karakter']);
    }

    public function testAPaperWorthFewerPointsIsScaledOntoTheGradeTable(): void
    {
        // Content is thin, so papers will not always be worth the scale's maximum. The
        // karakter has to describe the proportion answered, not the raw count, or a
        // short paper reads as a failed one.
        $this->publishAll();
        $sid = $this->startExam();
        $this->answerAll($sid, false);

        $result = $this->service->submit($sid);

        self::assertSame(0, $result['points_scored']);
        self::assertSame('-3', $result['karakter']);
    }

    public function testTheKarakterComesFromTheBandTableRatherThanTheCode(): void
    {
        $this->publishAll();
        $sid = $this->startExam();
        $this->answerAll($sid, true);

        // A different scale must produce a different verdict for the same performance.
        Db::execute("UPDATE reading_grade_scales SET is_active = NULL WHERE code = 'pd3_reading2_24_v1'");
        Db::execute("INSERT INTO reading_grade_scales (code, label, max_points, is_active)
                     VALUES ('harsh', 'Harsh', 24, 1)");
        $scale = (int) Db::fetchValue("SELECT id FROM reading_grade_scales WHERE code = 'harsh'");
        Db::execute('INSERT INTO reading_grade_bands (scale_id, min_points, max_points, karakter)
                     VALUES (?, 0, 24, ?)', [$scale, '00']);

        self::assertSame('00', $this->service->submit($sid)['karakter']);
    }

    // ---- the clock ---------------------------------------------------------

    public function testTheDeadlineIsStampedByTheServerWhenTheExamStarts(): void
    {
        $this->publishAll();
        $sid = $this->startExam();

        self::assertSame(3900, (int) Db::fetchValue(
            'SELECT duration_s FROM reading_sessions WHERE public_id = ?', [$sid]
        ));
    }

    public function testASubmitAfterTheDeadlineIsMarkedLate(): void
    {
        $this->publishAll();
        $sid = $this->startExam();
        Db::execute(
            'UPDATE reading_sessions SET started_at = DATE_SUB(NOW(), INTERVAL 66 MINUTE) WHERE public_id = ?',
            [$sid]
        );

        $result = $this->service->submit($sid);

        self::assertTrue($result['is_late']);
        self::assertGreaterThan(3900, $result['elapsed_s']);
    }

    public function testAnHonestSubmitIsNotMarkedLate(): void
    {
        $this->publishAll();
        $result = $this->service->submit($this->startExam());

        self::assertFalse($result['is_late']);
    }

    // ---- the review --------------------------------------------------------

    public function testTheResultRevealsTheCorrectOptionForEveryItem(): void
    {
        $this->publishAll();
        $sid = $this->startExam();
        $this->answerAll($sid, false);
        $this->service->submit($sid);

        $review = $this->service->result($sid);

        self::assertCount(6, $review['items']);
        foreach ($review['items'] as $item) {
            self::assertArrayHasKey('correct_index', $item);
            self::assertFalse($item['is_correct']);
        }
    }

    public function testTheResultIsRefusedBeforeSubmit(): void
    {
        $this->publishAll();
        $sid = $this->startExam();

        $this->expectException(RuntimeException::class);
        $this->service->result($sid);
    }
}
