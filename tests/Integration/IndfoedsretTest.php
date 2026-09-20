<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\InvalidPassage;
use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\PaperDocument;
use Dansk\Import\Indfoedsret\PaperParser;
use Dansk\Support\Db;
use RuntimeException;

/**
 * A whole indfødsretsprøve, from the two published PDFs to rows that can be served.
 *
 * The paper and the answer sheet are read in the unit suite; what is proved here is that
 * the result survives the trip into SQL with every answer still attached to the question
 * it belongs to. An exam that loses one answer on the way in is indistinguishable from a
 * correct one until a learner is marked wrong for being right.
 */
final class IndfoedsretTest extends IntegrationTestCase
{
    private function load(string $paper = 'paper-2026-06.txt', string $key = 'key-2026-06.xml'): int
    {
        $dir      = dirname(__DIR__) . '/fixtures/indfoedsret/';
        $document = (new PaperDocument())->render(
            (new PaperParser())->parse((string) file_get_contents($dir . $paper)),
            (new AnswerKey())->parse((string) file_get_contents($dir . $key))
        );

        return (new ReadingRepository())->save((new PassageDocument())->parse($document));
    }

    public function testStoresTheWholePaper(): void
    {
        $id      = $this->load();
        $passage = Db::fetchOne('SELECT * FROM reading_passages WHERE id = ?', [$id]);

        self::assertSame('quiz', $passage['kind']);
        self::assertNull($passage['body']);
        self::assertSame(36, (int) $passage['pass_points']);
        self::assertSame(4, (int) $passage['vaerdier_min']);
        self::assertSame(0, (int) $passage['is_published']);

        self::assertSame(
            45,
            (int) Db::fetchValue('SELECT COUNT(*) FROM reading_items WHERE passage_id = ?', [$id])
        );
    }

    public function testEveryQuestionKeepsExactlyOneAnswerOfItsOwn(): void
    {
        $id = $this->load();

        $unanswered = (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_items WHERE passage_id = ? AND correct_option_id IS NULL',
            [$id]
        );
        self::assertSame(0, $unanswered);

        // The answer must be one of the options offered for that same question -- an
        // option belonging to another question would grade as correct just as happily.
        $foreign = (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_items i
             JOIN reading_options o ON o.id = i.correct_option_id
             WHERE i.passage_id = ? AND o.item_id <> i.id',
            [$id]
        );
        self::assertSame(0, $foreign);
    }

    public function testKeepsTheBlockEveryQuestionWasAskedIn(): void
    {
        $id = $this->load();

        $blocks = Db::fetchAll(
            'SELECT section, COUNT(*) AS n FROM reading_items WHERE passage_id = ? GROUP BY section ORDER BY section',
            [$id]
        );

        // ORDER BY an ENUM sorts by the order the column declares, which is the order the
        // paper asks the blocks in.
        self::assertSame(
            [['section' => 'laeremateriale', 'n' => 35], ['section' => 'aktuelle', 'n' => 5], ['section' => 'vaerdier', 'n' => 5]],
            array_map(static fn(array $r): array => ['section' => $r['section'], 'n' => (int) $r['n']], $blocks)
        );
    }

    public function testKeepsTheTwoOptionValuesQuestions(): void
    {
        $id = $this->load();

        $counts = Db::fetchAll(
            'SELECT i.position, COUNT(o.id) AS n FROM reading_items i
             JOIN reading_options o ON o.item_id = i.id
             WHERE i.passage_id = ? AND i.section = ? GROUP BY i.position ORDER BY i.position',
            [$id, 'vaerdier']
        );

        self::assertSame([2, 2, 2, 2, 3], array_map(static fn(array $r): int => (int) $r['n'], $counts));
    }

    public function testLoadsThePaperThatPredatesTheValuesBlock(): void
    {
        $id      = $this->load('paper-2020-06.txt', 'key-2020-06.xml');
        $passage = Db::fetchOne('SELECT * FROM reading_passages WHERE id = ?', [$id]);

        self::assertNull($passage['pass_points']);
        self::assertSame(
            40,
            (int) Db::fetchValue('SELECT COUNT(*) FROM reading_items WHERE passage_id = ?', [$id])
        );
        self::assertSame(
            0,
            (int) Db::fetchValue(
                'SELECT COUNT(*) FROM reading_items WHERE passage_id = ? AND section = ?',
                [$id, 'vaerdier']
            )
        );
    }

    /** A paper nobody can pass, or one that grades a block it does not ask, is a typo. */
    public function testRefusesAPassMarkThePaperCannotReach(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->expectExceptionMessageMatches('/pass mark/');

        (new ReadingRepository())->save([
            'kind'  => 'quiz',
            'slug'  => 'umulig',
            'title' => 'Umulig prøve',
            'body'  => null,
            'pass'  => 3,
            'vaerdier_min' => null,
            'items' => [
                [
                    'position' => 1,
                    'section'  => 'vaerdier',
                    'prompt'   => 'Er det rigtigt?',
                    'options'  => [['label' => 'A', 'text' => 'Ja', 'correct' => true], ['label' => 'B', 'text' => 'Nej']],
                ],
            ],
        ]);
    }

    public function testRefusesAQuizQuestionWithNoBlock(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->expectExceptionMessageMatches('/block/');

        (new ReadingRepository())->save([
            'kind'  => 'quiz',
            'slug'  => 'uden-blok',
            'title' => 'Uden blok',
            'body'  => null,
            'pass'  => null,
            'vaerdier_min' => null,
            'items' => [
                [
                    'position' => 1,
                    'prompt'   => 'Er det rigtigt?',
                    'options'  => [['label' => 'A', 'text' => 'Ja', 'correct' => true], ['label' => 'B', 'text' => 'Nej']],
                ],
            ],
        ]);
    }

    /**
     * A reading round asked for no particular task type draws whatever is published, and
     * a 45-question knowledge paper has no text to read: served as a reading task it
     * would render an empty passage and grade against the reading exam's karakter scale.
     * Publishing an exam paper therefore must not change what a reading round serves.
     */
    public function testAKnowledgePaperNeverTurnsUpInAReadingRound(): void
    {
        (new ReadingRepository())->publish($this->load());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No published passage/');

        (new ReadingSessionService())->start(null, str_repeat('a', 32), ReadingSessionService::DRILL);
    }

}
