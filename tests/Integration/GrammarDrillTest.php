<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
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
            [['slug' => 'datid-foernutid-1', 'title' => 'Datid eller førnutid', 'questions' => 2]],
            $this->repo->publishedGrammarSets()
        );
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
