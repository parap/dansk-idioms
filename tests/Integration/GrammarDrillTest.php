<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Support\Db;
use RuntimeException;

/**
 * A set of questions on one grammar rule, sat as a drill: each answer is checked at once
 * and explained, and the round ends in a score, not a grade.
 */
final class GrammarDrillTest extends IntegrationTestCase
{
    private const DOC = <<<'DOC'
        kind: grammar
        slug: datid-foernutid-1
        title: Datid eller førnutid

        --- questions ---
        1. I går ___ jeg en ny jakke. (købe)
        > «I går» — законченный момент в прошлом → datid.
        * købte
          har købt
          køber
          købede

        2. Vi ___ i Aalborg siden 2022. (bo)
        > Действие длится до сих пор → førnutid.
        * har boet
          boede
          er boet
          boet
        DOC;

    private ReadingSessionService $sessions;
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new ReadingSessionService();
        $this->repo     = new ReadingRepository();
    }

    private function publish(string $doc = self::DOC): void
    {
        $this->repo->publish($this->repo->save((new PassageDocument())->parse($doc)));
    }

    /** A set of $n questions, each with its own answer, so a round says which ones it drew. */
    private static function setOf(int $n, string $slug = 'datid-foernutid-1'): string
    {
        $doc = "kind: grammar\nslug: {$slug}\ntitle: Datid eller førnutid\n\n--- questions ---\n";
        for ($i = 1; $i <= $n; $i++) {
            $doc .= "{$i}. Spørgsmål {$i}: ___ jeg?\n> Объяснение {$i}.\n* svar {$i}\n  forkert\n  helt forkert\n\n";
        }
        return $doc;
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

    /** @return list<string> the prompts of a round, in the order it serves them */
    private function prompts(string $sessionId): array
    {
        return array_column($this->sessions->session($sessionId)['items'], 'prompt');
    }

    public function testSitsTheNamedSetWithAllItsQuestionsAndNoClock(): void
    {
        $this->publish();

        $round = $this->sessions->session($this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1')['session_id']);

        self::assertCount(2, $round['items']);
        self::assertSame('datid-foernutid-1', $round['passages'][0]['slug']);
        self::assertNull($round['remaining_s']);
    }


    public function testRefusesAVerbSetAskedForAsGrammar(): void
    {
        $this->publish(str_replace('kind: grammar', 'kind: verbs', self::DOC));

        $this->expectException(RuntimeException::class);

        $this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1');
    }


    public function testListsOnlyPublishedGrammarSetsWithTheirQuestionCounts(): void
    {
        $this->publish();
        $this->repo->save((new PassageDocument())->parse(str_replace('datid-foernutid-1', 'kladde', self::DOC)));
        $this->publish(str_replace(['kind: grammar', 'datid-foernutid-1'], ['kind: verbs', 'verber-001-025'], self::DOC));

        self::assertSame(
            [['slug' => 'datid-foernutid-1', 'title' => 'Datid eller førnutid', 'questions' => 2, 'pool' => 2]],
            $this->repo->publishedGrammarSets()
        );
    }


    public function testAListedSetSaysHowManyARoundAsksOutOfHowManyItHolds(): void
    {
        $this->publish(self::setOf(30));

        self::assertSame(
            [['slug' => 'datid-foernutid-1', 'title' => 'Datid eller førnutid', 'questions' => 20, 'pool' => 30]],
            $this->repo->publishedGrammarSets()
        );
    }


    public function testARoundAsksTwentyOfALargerSetInTheSetsOrder(): void
    {
        $this->publish(self::setOf(30));

        $round = $this->sessions->session($this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1')['session_id']);

        self::assertCount(20, $round['items']);
        self::assertSame(range(1, 20), array_column($round['items'], 'position'));
        $numbers = array_map(static fn(string $p): int => (int) substr($p, strlen('Spørgsmål ')), array_column($round['items'], 'prompt'));
        self::assertSame($numbers, array_values(array_unique($numbers)), 'a question is asked twice');
        $sorted = $numbers;
        sort($sorted);
        self::assertSame($sorted, $numbers, 'the questions are not in the order of the set');
    }


    /** Five rounds of twenty go through a set of a hundred before any question comes back. */
    public function testTheNextRoundAsksWhatThisLearnerHasNotBeenAskedYet(): void
    {
        $this->publish(self::setOf(30));

        $first  = $this->prompts($this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1')['session_id']);
        $second = $this->prompts($this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1')['session_id']);
        $other  = $this->prompts($this->sessions->startGrammar(null, 'anon-2', 'datid-foernutid-1')['session_id']);

        self::assertCount(30, array_unique([...$first, ...$second]), 'the second round repeats a question while ten wait unasked');
        self::assertCount(20, $other, 'another learner gets a round of their own');
    }


    public function testAnotherLearnersRoundsDoNotDecideWhatIsAskedNext(): void
    {
        $this->publish(self::setOf(100));
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $seen = [...$seen, ...$this->prompts($this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1')['session_id'])];
        }

        // Counted for anon-2, anon-1's four rounds leave exactly twenty unasked, and the
        // round would be those; drawn at random, it is those once in C(100, 20).
        $other = $this->prompts($this->sessions->startGrammar(null, 'anon-2', 'datid-foernutid-1')['session_id']);
        self::assertNotSame([], array_intersect($other, $seen));
    }


    /** A shipped set grows by appending; the deploy cannot replace one a round has served. */
    public function testQuestionsAddedToAServedSetReachItOnTheNextLoad(): void
    {
        $this->publish(self::setOf(2));
        $this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1');

        self::assertSame(3, $this->repo->appendItems((new PassageDocument())->parse(self::setOf(5))));
        self::assertSame(0, $this->repo->appendItems((new PassageDocument())->parse(self::setOf(5))), 'a second load adds them again');

        $round = $this->sessions->session($this->sessions->startGrammar(null, 'anon-2', 'datid-foernutid-1')['session_id']);
        self::assertSame(
            ['Spørgsmål 1: ___ jeg?', 'Spørgsmål 2: ___ jeg?', 'Spørgsmål 3: ___ jeg?', 'Spørgsmål 4: ___ jeg?', 'Spørgsmål 5: ___ jeg?'],
            array_column($round['items'], 'prompt')
        );
        $answer = $this->sessions->answer($round['session_id'], 5, $this->correctIndex($round['session_id'], 5));
        self::assertTrue($answer['is_correct'], 'an added question has no right answer');
    }


    public function testAppendingLeavesAQuestionAlreadyStoredAsItIs(): void
    {
        $this->publish(self::setOf(2));

        $changed = str_replace('* svar 1', '* nyt svar 1', self::setOf(3));
        self::assertSame(1, $this->repo->appendItems((new PassageDocument())->parse($changed)));

        $round = $this->sessions->session($this->sessions->startGrammar(null, 'anon-1', 'datid-foernutid-1')['session_id']);
        self::assertContains('svar 1', array_column($round['items'][0]['options'], 'text'));
    }


    public function testAppendingToASlugOfAnotherKindAddsNothing(): void
    {
        $this->publish(str_replace('kind: grammar', 'kind: verbs', self::setOf(2)));

        self::assertSame(0, $this->repo->appendItems((new PassageDocument())->parse(self::setOf(3))));
    }


    /** The point of a grammar question is the explanation after it, so none may lack one. */
    public function testEveryShippedGrammarQuestionLoadsWithAnExplanation(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/content/grammar/*.txt') ?: [];
        self::assertNotSame([], $files, 'no grammar sets are shipped');

        foreach ($files as $file) {
            $doc = (new PassageDocument())->parse((string) file_get_contents($file));
            self::assertSame('grammar', $doc['kind'], basename($file));
            foreach ($doc['items'] as $item) {
                self::assertNotSame('', trim((string) ($item['note'] ?? '')), basename($file) . " question {$item['position']} has no explanation");
            }
            $this->repo->save($doc);
        }
    }
}
