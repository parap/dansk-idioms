<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\PassMark;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\PaperDocument;
use Dansk\Import\Indfoedsret\PaperParser;
use Dansk\Support\Db;
use RuntimeException;

/**
 * Sitting an indfødsretsprøve: the paper is served with no text to read, answered under
 * the server's clock, and judged by the mark its own answer sheet states rather than by
 * the reading exam's grading scale.
 */
final class IndfoedsretExamTest extends IntegrationTestCase
{
    private ReadingSessionService $service;
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReadingSessionService();
        $this->repo    = new ReadingRepository();
    }

    private function publishPaper(string $paper = 'paper-2026-06.txt', string $key = 'key-2026-06.xml'): string
    {
        $dir = dirname(__DIR__) . '/fixtures/indfoedsret/';
        $doc = (new PassageDocument())->parse((new PaperDocument())->render(
            (new PaperParser())->parse((string) file_get_contents($dir . $paper)),
            (new AnswerKey())->parse((string) file_get_contents($dir . $key))
        ));

        $this->repo->publish($this->repo->save($doc));

        return $doc['slug'];
    }

    private function start(?string $slug = null, bool $currentAffairs = true): string
    {
        return $this->service->startPaper(null, str_repeat('i', 32), $slug, $currentAffairs)['session_id'];
    }

    private function correctIndex(string $sid, int $position): int
    {
        return (int) Db::fetchValue(
            'SELECT si.correct_index FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = ?',
            [$sid, $position]
        );
    }

    /** @param list<int> $wrongAt positions to answer wrongly; everything else is right */
    private function sit(string $sid, array $wrongAt = []): array
    {
        foreach ($this->service->session($sid)['items'] as $item) {
            $correct = $this->correctIndex($sid, $item['position']);
            $chosen  = in_array($item['position'], $wrongAt, true)
                ? ($correct + 1) % count($item['options'])
                : $correct;

            $this->service->answer($sid, $item['position'], $chosen);
        }

        $this->service->submit($sid);

        return $this->service->result($sid);
    }

    // ---- serving -----------------------------------------------------------

    public function testServesTheWholePaperWithNothingToRead(): void
    {
        $slug  = $this->publishPaper();
        $paper = $this->service->session($this->start($slug));

        self::assertSame('exam', $paper['mode']);
        self::assertCount(45, $paper['items']);
        self::assertSame('quiz', $paper['passages'][0]['kind']);
        self::assertNull($paper['passages'][0]['body']);
        self::assertSame('Indfødsretsprøven 3. juni 2026', $paper['passages'][0]['title']);
        self::assertSame(36, $paper['pass']);
        self::assertSame(4, $paper['vaerdier_min']);
    }

    public function testEveryQuestionSaysWhichBlockItCameFrom(): void
    {
        $sections = array_count_values(
            array_column($this->service->session($this->start($this->publishPaper()))['items'], 'section')
        );

        self::assertSame(['laeremateriale' => 35, 'aktuelle' => 5, 'vaerdier' => 5], $sections);
    }

    /** 45 minutes, counted by the server. */
    public function testThePaperIsTimedLikeTheExam(): void
    {
        $paper = $this->service->session($this->start($this->publishPaper()));

        self::assertGreaterThan(2690, $paper['remaining_s']);
        self::assertLessThanOrEqual(2700, $paper['remaining_s']);
    }

    public function testAServedPaperNeverCarriesTheAnswer(): void
    {
        $paper = $this->service->session($this->start($this->publishPaper()));

        foreach ($paper['items'] as $item) {
            self::assertArrayNotHasKey('correct_index', $item);
            foreach ($item['options'] as $option) {
                self::assertSame(['index', 'text'], array_keys($option));
            }
        }
    }

    /** Answering is revising until the paper is handed in, as in any timed exam. */
    public function testAnAnswerSaysNothingBeforeTheHandIn(): void
    {
        $slug = $this->publishPaper();
        $sid  = $this->start($slug);

        self::assertSame(['recorded' => true], $this->service->answer($sid, 1, 0));
        self::assertSame(['recorded' => true], $this->service->answer($sid, 1, 1));
    }

    // ---- the verdict -------------------------------------------------------

    public function testAPerfectPaperPasses(): void
    {
        $result = $this->sit($this->start($this->publishPaper()));

        self::assertSame(PassMark::PASSED, $result['verdict']);
        self::assertSame(45, $result['tally']['correct']);
        self::assertNull($result['karakter']);
    }

    public function testThePassMarkItselfPasses(): void
    {
        // Nine wrong, all of them outside the values block: 36 correct of 45.
        $result = $this->sit($this->start($this->publishPaper()), range(1, 9));

        self::assertSame(PassMark::PASSED, $result['verdict']);
        self::assertSame(36, $result['tally']['correct']);
        self::assertSame(5, $result['tally']['vaerdier_correct']);
    }

    public function testEnoughCorrectStillFailsOnTheValuesBlock(): void
    {
        // Two wrong in the values block leaves three of five, with 43 correct overall.
        $result = $this->sit($this->start($this->publishPaper()), [41, 42]);

        self::assertSame(PassMark::FAILED, $result['verdict']);
        self::assertSame(43, $result['tally']['correct']);
        self::assertSame(3, $result['tally']['vaerdier_correct']);
    }

    public function testTheVerdictIsStoredWithTheSession(): void
    {
        $sid = $this->start($this->publishPaper());
        $this->sit($sid);

        self::assertSame(
            PassMark::PASSED,
            Db::fetchValue('SELECT verdict FROM reading_sessions WHERE public_id = ?', [$sid])
        );
    }

    // ---- rounds that are not the paper -------------------------------------

    public function testARoundMayLeaveTheCurrentAffairsBlockOut(): void
    {
        $sid   = $this->start($this->publishPaper(), false);
        $paper = $this->service->session($sid);

        self::assertCount(40, $paper['items']);
        self::assertNotContains('aktuelle', array_column($paper['items'], 'section'));
    }

    public function testAShortenedRoundIsScoredButNotJudged(): void
    {
        $result = $this->sit($this->start($this->publishPaper(), false));

        self::assertNull($result['verdict']);
        self::assertSame('partial_round', $result['tally']['reason']);
        self::assertSame(40, $result['tally']['correct']);
    }

    // ---- choosing a paper --------------------------------------------------

    public function testAPaperCanBeAskedForByName(): void
    {
        $this->publishPaper();
        $older = $this->publishPaper('paper-2020-06.txt', 'key-2020-06.xml');

        $paper = $this->service->session($this->start($older));

        self::assertCount(40, $paper['items']);
        self::assertSame('Indfødsretsprøven 3. juni 2020', $paper['passages'][0]['title']);
        self::assertNull($paper['pass']);
    }

    public function testAnUnpublishedPaperIsNeverSat(): void
    {
        $slug = $this->publishPaper();
        $this->repo->unpublish(
            (int) Db::fetchValue('SELECT id FROM reading_passages WHERE slug = ?', [$slug])
        );

        $this->expectException(RuntimeException::class);
        $this->start($slug);
    }

    public function testThePapersOnOfferSayWhatSittingTheyAre(): void
    {
        $this->publishPaper();
        $this->publishPaper('paper-2020-06.txt', 'key-2020-06.xml');

        $papers = $this->repo->publishedPapers();

        self::assertSame(
            ['indfoedsret-2020-06-03', 'indfoedsret-2026-06-03'],
            array_column($papers, 'slug')
        );
        self::assertSame(45, (int) $papers[1]['questions']);
        self::assertSame(36, (int) $papers[1]['pass']);
        self::assertSame(5, (int) $papers[1]['aktuelle']);
    }
}
