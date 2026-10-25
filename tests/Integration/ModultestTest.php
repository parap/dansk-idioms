<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Support\Db;
use RuntimeException;

/**
 * A Modultest DU3.4 reading paper: its four tasks sat together under the test's own clock,
 * with Opgave 3 answered by typing a word, and none of it drawn into the PD3 reading rounds.
 */
final class ModultestTest extends IntegrationTestCase
{
    private const CLOZE = <<<'DOC'
        kind: cloze
        slug: du3m4-v9-opg1-mad
        paper: du3m4-v9
        title: Mad uden kød

        --- text ---
        Der står kød på menuen, {{1}} danskerne laver mad. Det er {{2}} for kroppen.

        --- questions ---
        1.
        * når
          hvis
          om

        2.
        * usundt
          usund
          usunde
        DOC;

    private const INSERT = <<<'DOC'
        kind: insert
        slug: du3m4-v9-opg2-job
        paper: du3m4-v9
        title: Fritidsjob

        --- text ---
        Astrid arbejder i en butik. {{1}} Hun sparer op. {{2}}

        --- parts ---
        A Hun pakker varer ud.
        B Hun vil rejse til Rom.
        C Hun kan ikke lide kaffe.

        --- questions ---
        1. A
        2. B
        DOC;

    private const WRITE = <<<'DOC'
        kind: write
        slug: du3m4-v9-opg3-gartner
        paper: du3m4-v9
        title: Thomas er gartner

        --- text ---
        Jeg har ingen ansatte, {{1}} jeg har mange kunder. Han har en kollega, {{2}} hjælper ham.

        --- questions ---
        1.
        men
        > men соединяет два главных предложения.

        2.
        der
        som
        DOC;

    private const MC = <<<'DOC'
        kind: mc
        slug: du3m4-v9-opg4-hverdag
        paper: du3m4-v9
        title: Seks korte tekster

        --- text ---
        Tekst 1. Biblioteket holder lukket mandag.

        --- questions ---
        1. Tekst 1: Hvornår er biblioteket lukket?
        * Mandag.
          Tirsdag.
          Hver dag.
        DOC;

    private ReadingSessionService $sessions;
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new ReadingSessionService();
        $this->repo     = new ReadingRepository();
    }

    private function publish(string ...$docs): void
    {
        foreach ($docs as $doc) {
            $this->repo->publish($this->repo->save((new PassageDocument())->parse($doc)));
        }
    }

    /** The paper in the order a sitting lays it out, tasks shuffled on purpose by the caller. */
    private function publishPaper(): void
    {
        $this->publish(self::MC, self::WRITE, self::CLOZE, self::INSERT);
    }

    private function sit(string $anon = 'anon-1'): array
    {
        return $this->sessions->session($this->sessions->startModultest(null, $anon, 'du3m4-v9')['session_id']);
    }

    private function position(array $round, string $slug, int $nth): int
    {
        $passage = array_search($slug, array_column($round['passages'], 'slug'), true);
        $items   = array_values(array_filter($round['items'], static fn(array $i): bool => $i['passage'] === $passage));

        return $items[$nth - 1]['position'];
    }

    public function testSitsEveryTaskOfThePaperInTheOrderOfTheTestUnderFiftyMinutes(): void
    {
        $this->publishPaper();

        $round = $this->sit();

        self::assertSame(
            ['du3m4-v9-opg1-mad', 'du3m4-v9-opg2-job', 'du3m4-v9-opg3-gartner', 'du3m4-v9-opg4-hverdag'],
            array_column($round['passages'], 'slug')
        );
        self::assertSame('exam', $round['mode']);
        self::assertSame(3000, $round['remaining_s']);
        self::assertCount(7, $round['items']);
        self::assertSame(2 + 4 + 2 + 2, $round['points_max']);
    }

    public function testAProeveIDansk2PaperSitsUnderItsOwnNinetyMinutes(): void
    {
        $this->publish(str_replace('du3m4-v9', 'pd2-2020s', self::CLOZE));

        $round = $this->sessions->session($this->sessions->startModultest(null, 'anon-1', 'pd2-2020s')['session_id']);

        self::assertSame(5400, $round['remaining_s']);
    }

    public function testEveryAnswerOfAProeveIDansk2PaperIsWorthOnePoint(): void
    {
        $this->publish(str_replace('du3m4-v9', 'pd2-2020s', self::INSERT), str_replace('du3m4-v9', 'pd2-2020s', self::CLOZE));

        self::assertSame(
            [['paper' => 'pd2-2020s', 'tasks' => 2, 'questions' => 4, 'points' => 4]],
            $this->repo->publishedModultests()
        );
    }

    public function testAPaperWithAnUnpublishedTaskIsNotOffered(): void
    {
        $this->publish(self::MC, self::WRITE, self::CLOZE);
        $this->repo->save((new PassageDocument())->parse(self::INSERT));

        self::assertSame([], $this->repo->publishedModultests());
        $this->expectException(RuntimeException::class);
        $this->sit();
    }

    public function testListsAPaperWithItsTasksAndPoints(): void
    {
        $this->publishPaper();
        $this->publish(str_replace('du3m4-v9', 'du3m4-v8', self::CLOZE));

        self::assertSame(
            [
                ['paper' => 'du3m4-v8', 'tasks' => 1, 'questions' => 2, 'points' => 2],
                ['paper' => 'du3m4-v9', 'tasks' => 4, 'questions' => 7, 'points' => 10],
            ],
            $this->repo->publishedModultests()
        );
    }

    public function testRefusesAPaperNobodyPublished(): void
    {
        $this->publishPaper();

        $this->expectException(RuntimeException::class);
        $this->sessions->startModultest(null, 'anon-1', 'du3m4-v7');
    }

    /** A paper is sat as a whole; its texts are not Prøve i Dansk 3 texts and the PD3 rounds never draw them. */
    public function testAReadingRoundNeverDrawsATaskOfAPaper(): void
    {
        $this->publishPaper();

        $this->expectException(RuntimeException::class);
        $this->sessions->start(null, 'anon-1', ReadingSessionService::DRILL, 'cloze');
    }

    public function testAWrittenGapServesNoOptions(): void
    {
        $this->publishPaper();
        $round = $this->sit();

        $written = array_filter($round['items'], fn(array $i): bool => $i['passage'] === 2);
        self::assertCount(2, $written);
        foreach ($written as $item) {
            self::assertSame([], $item['options']);
        }
        self::assertStringNotContainsString('"som"', json_encode($round, JSON_UNESCAPED_UNICODE));
    }

    public function testAWrittenAnswerIsRightWhenItMatchesAnyAcceptedAnswer(): void
    {
        $this->publishPaper();
        $round = $this->sit();
        $sid   = $round['session_id'];
        $gap2  = $this->position($round, 'du3m4-v9-opg3-gartner', 2);
        $gap1  = $this->position($round, 'du3m4-v9-opg3-gartner', 1);

        $this->sessions->answerText($sid, $gap2, '  Som. ');
        $this->sessions->answerText($sid, $gap1, 'og');
        $result = $this->sessions->result($this->submit($sid));

        $byPosition = array_column($result['items'], null, 'position');
        self::assertTrue($byPosition[$gap2]['is_correct'], 'an accepted answer typed with a capital, spaces and a full stop');
        self::assertSame('  Som. ', $byPosition[$gap2]['answer_text']);
        self::assertSame(['der', 'som'], $byPosition[$gap2]['accepted']);
        self::assertFalse($byPosition[$gap1]['is_correct']);
        self::assertSame(['men'], $byPosition[$gap1]['accepted']);
        self::assertSame(1, $result['points_scored']);
    }

    public function testAWrittenAnswerTellsNothingBeforeTheHandInAndCanBeRevised(): void
    {
        $this->publishPaper();
        $round = $this->sit();
        $sid   = $round['session_id'];
        $gap1  = $this->position($round, 'du3m4-v9-opg3-gartner', 1);

        self::assertSame(['recorded' => true], $this->sessions->answerText($sid, $gap1, 'og'));
        $this->sessions->answerText($sid, $gap1, 'men');

        $again = $this->sessions->session($sid);
        $item  = array_column($again['items'], null, 'position')[$gap1];
        self::assertTrue($item['answered']);
        self::assertSame('men', $item['answer_text'], 'a sitting reopened loses what was typed');

        $result = $this->sessions->result($this->submit($sid));
        self::assertTrue(array_column($result['items'], null, 'position')[$gap1]['is_correct']);
    }

    public function testABlankWrittenAnswerIsNoAnswer(): void
    {
        $this->publishPaper();
        $round = $this->sit();
        $sid   = $round['session_id'];
        $gap1  = $this->position($round, 'du3m4-v9-opg3-gartner', 1);

        $this->sessions->answerText($sid, $gap1, 'men');
        $this->sessions->answerText($sid, $gap1, '   ');

        $item = array_column($this->sessions->session($sid)['items'], null, 'position')[$gap1];
        self::assertFalse($item['answered']);
    }

    public function testAWrittenGapTakesNoOptionIndex(): void
    {
        $this->publishPaper();
        $round = $this->sit();

        $this->expectException(RuntimeException::class);
        $this->sessions->answer($round['session_id'], $this->position($round, 'du3m4-v9-opg3-gartner', 1), 0);
    }

    public function testAnOptionQuestionTakesNoTypedText(): void
    {
        $this->publishPaper();
        $round = $this->sit();

        $this->expectException(RuntimeException::class);
        $this->sessions->answerText($round['session_id'], $this->position($round, 'du3m4-v9-opg1-mad', 1), 'når');
    }

    public function testAModultestSittingIsMarkedAsOneInTheHistory(): void
    {
        $this->publishPaper();
        $this->submit($this->sit()['session_id']);

        $history = $this->sessions->history(null, 'anon-1');

        self::assertCount(1, $history);
        self::assertSame('du3m4-v9', $history[0]['paper']);
        self::assertNull($history[0]['karakter']);
    }

    /** A variant is grouped after its texts were loaded, and a sat text cannot be replaced. */
    public function testLoadingAgainSetsThePaperOnATaskAlreadyLoaded(): void
    {
        $this->publish(str_replace("paper: du3m4-v9\n", '', self::CLOZE));
        $this->publish(str_replace(["paper: du3m4-v9\n", 'du3m4-v9-opg4'], ['', 'andet'], self::MC));

        self::assertSame(1, $this->repo->refreshPaper((new PassageDocument())->parse(self::CLOZE)));
        self::assertSame('du3m4-v9', Db::fetchValue("SELECT paper FROM reading_passages WHERE slug = 'du3m4-v9-opg1-mad'"));
        self::assertNull(Db::fetchValue("SELECT paper FROM reading_passages WHERE slug = 'andet-hverdag'"), 'another passage was given the paper');
        self::assertSame(0, $this->repo->refreshPaper((new PassageDocument())->parse(self::CLOZE)));
    }

    public function testATypedAnswerIsCutToWhatTheColumnHolds(): void
    {
        $this->publishPaper();
        $round = $this->sit();
        $sid   = $round['session_id'];
        $gap1  = $this->position($round, 'du3m4-v9-opg3-gartner', 1);

        $this->sessions->answerText($sid, $gap1, str_repeat('ø', 300));

        $item = array_column($this->sessions->session($sid)['items'], null, 'position')[$gap1];
        self::assertSame(100, mb_strlen($item['answer_text']));
    }

    private function submit(string $sid): string
    {
        $this->sessions->submit($sid);

        return $sid;
    }

    // ---- the worked example (0) --------------------------------------------

    /** The cloze and insertion tasks as the paper prints them, each opening with an example. */
    private static function withExample(string $doc): string
    {
        return str_replace(
            [
                'Der står kød på menuen,',
                "--- questions ---\n1.\n* når",
                'Astrid arbejder i en butik.',
                "C Hun kan ikke lide kaffe.\n",
                "--- questions ---\n1. A",
            ],
            [
                'Der står {{0}} kød på menuen,',
                "--- questions ---\n0.\n  aldrig\n* som regel\n  sjældent\n\n1.\n* når",
                'Astrid arbejder i en butik. {{0}}',
                "C Hun kan ikke lide kaffe.\nD Hun går i 9. klasse.\n",
                "--- questions ---\n0. D\n1. A",
            ],
            $doc
        );
    }

    private function publishPaperWithExamples(): void
    {
        $this->publish(self::MC, self::WRITE, self::withExample(self::CLOZE), self::withExample(self::INSERT));
    }

    public function testATaskServesItsExampleWithItsText(): void
    {
        $this->publishPaperWithExamples();

        $passages = array_column($this->sit()['passages'], null, 'slug');

        self::assertSame(['aldrig', 'som regel', 'sjældent'], $passages['du3m4-v9-opg1-mad']['example']['options']);
        self::assertSame(1, $passages['du3m4-v9-opg1-mad']['example']['answer']);
        self::assertSame('D', $passages['du3m4-v9-opg2-job']['example']['part']);
        self::assertSame('Hun går i 9. klasse.', $passages['du3m4-v9-opg2-job']['example']['text']);
        self::assertNull($passages['du3m4-v9-opg3-gartner']['example']);
        self::assertStringContainsString('{{0}}', $passages['du3m4-v9-opg1-mad']['body']);
    }

    public function testAnExampleIsNoQuestionAndScoresNothing(): void
    {
        $this->publishPaperWithExamples();

        $round = $this->sit();

        self::assertCount(7, $round['items']);
        self::assertSame(2 + 4 + 2 + 2, $round['points_max']);
        self::assertSame(7, $this->repo->publishedModultests()[0]['questions']);
    }

    /** On the paper the example's part stays in the list, so a gap can still be given it wrongly. */
    public function testAnInsertionExampleStaysAmongTheParts(): void
    {
        $this->publishPaperWithExamples();
        $round = $this->sit();

        $job   = array_search('du3m4-v9-opg2-job', array_column($round['passages'], 'slug'), true);
        $parts = array_column(array_values(array_filter($round['items'], static fn(array $i): bool => $i['passage'] === $job))[0]['options'], 'text');

        self::assertCount(4, $parts);
        self::assertContains('Hun går i 9. klasse.', $parts);
    }

    /** The tasks already sat gain their example on the next load; nothing graded changes. */
    public function testLoadingAgainGivesATaskAlreadySatItsExample(): void
    {
        $this->publishPaper();
        $sat   = $this->sit('anon-early');
        $first = $this->position($sat, 'du3m4-v9-opg1-mad', 1);
        $this->sessions->answer($sat['session_id'], $first, $this->correctIndex($sat['session_id'], $first));
        $this->submit($sat['session_id']);

        foreach ([self::CLOZE, self::INSERT] as $doc) {
            self::assertSame(1, $this->repo->refreshExample((new PassageDocument())->parse(self::withExample($doc))));
            self::assertSame(0, $this->repo->refreshExample((new PassageDocument())->parse(self::withExample($doc))), 'a second load changed it again');
        }

        $passages = array_column($this->sit()['passages'], null, 'slug');
        self::assertSame(1, $passages['du3m4-v9-opg1-mad']['example']['answer']);
        self::assertStringContainsString('{{0}}', $passages['du3m4-v9-opg2-job']['body']);
        self::assertSame(
            4,
            (int) Db::fetchValue("SELECT COUNT(*) FROM reading_options o JOIN reading_passages p ON p.id = o.passage_id
                                   WHERE p.slug = 'du3m4-v9-opg2-job' AND o.item_id IS NULL")
        );
        self::assertSame(1, $this->sessions->result($sat['session_id'])['points_scored']);
    }

    public function testRefusesToStoreAnExampleTheTextHasNoPlaceFor(): void
    {
        $doc = (new PassageDocument())->parse(self::withExample(self::CLOZE));
        $doc['body'] = str_replace('{{0}}', 'som regel', $doc['body']);

        $this->expectException(\Dansk\Domain\Reading\InvalidPassage::class);
        $this->repo->save($doc);
    }

    public function testRefusesToStoreAnExampleWhosePartIsNotInTheBank(): void
    {
        $doc = (new PassageDocument())->parse(self::withExample(self::INSERT));
        $doc['example']['part'] = 'H';

        $this->expectException(\Dansk\Domain\Reading\InvalidPassage::class);
        $this->repo->save($doc);
    }

    private function correctIndex(string $sid, int $position): int
    {
        return (int) Db::fetchValue(
            'SELECT si.correct_index FROM reading_session_items si JOIN reading_sessions s ON s.id = si.session_id
              WHERE s.public_id = ? AND si.position = ?',
            [$sid, $position]
        );
    }
}
