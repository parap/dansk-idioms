<?php declare(strict_types=1);

namespace Dansk\Tests\Indfoedsret;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\InvalidPaper;
use Dansk\Import\Indfoedsret\PaperDocument;
use Dansk\Import\Indfoedsret\PaperParser;
use PHPUnit\Framework\TestCase;

/**
 * The paper and its answers arrive as two unrelated PDFs, and nothing in either one says
 * they belong together. The document they are written into is the join, so it is made
 * here -- where both are in hand and can be checked against each other -- rather than
 * left to whoever reads the files next.
 */
final class PaperDocumentTest extends TestCase
{
    private function render(string $paper, string $key): string
    {
        $dir = __DIR__ . '/../fixtures/indfoedsret/';

        return (new PaperDocument())->render(
            (new PaperParser())->parse((string) file_get_contents($dir . $paper)),
            (new AnswerKey())->parse((string) file_get_contents($dir . $key))
        );
    }

    public function testWritesADocumentTheAuthoringFormatCanReadBack(): void
    {
        $doc = (new PassageDocument())->parse($this->render('paper-2026-06.txt', 'key-2026-06.xml'));

        self::assertSame('quiz', $doc['kind']);
        self::assertSame('indfoedsret-2026-06-03', $doc['slug']);
        self::assertSame('Indfødsretsprøven 3. juni 2026', $doc['title']);
        self::assertNull($doc['body']);
        self::assertCount(45, $doc['items']);
        self::assertSame(36, $doc['pass']);
        self::assertSame(4, $doc['vaerdier_min']);
    }

    public function testStarsTheOptionTheAnswerSheetNames(): void
    {
        $document = $this->render('paper-2026-06.txt', 'key-2026-06.xml');
        $doc      = (new PassageDocument())->parse($document);
        $key      = (new AnswerKey())->parse(
            (string) file_get_contents(__DIR__ . '/../fixtures/indfoedsret/key-2026-06.xml')
        );

        foreach ($doc['items'] as $item) {
            $starred = array_values(array_filter(
                $item['options'],
                static fn(array $o): bool => !empty($o['correct'])
            ));

            self::assertCount(1, $starred);
            self::assertSame($key['answers'][$item['position']], $starred[0]['label']);
        }
    }

    public function testKeepsTheBlockEachQuestionWasAskedIn(): void
    {
        $doc      = (new PassageDocument())->parse($this->render('paper-2026-06.txt', 'key-2026-06.xml'));
        $sections = array_column($doc['items'], 'section', 'position');

        self::assertSame('laeremateriale', $sections[35]);
        self::assertSame('aktuelle', $sections[36]);
        self::assertSame('vaerdier', $sections[45]);
    }

    /** The 2020 sheet states no pass mark, and a number nobody wrote down is not invented. */
    public function testLeavesThePassMarkOutWhenTheSheetDoesNotStateIt(): void
    {
        $doc = (new PassageDocument())->parse($this->render('paper-2020-06.txt', 'key-2020-06.xml'));

        self::assertCount(40, $doc['items']);
        self::assertNull($doc['pass']);
        self::assertNull($doc['vaerdier_min']);
        self::assertSame('Indfødsretsprøven 3. juni 2020', $doc['title']);
    }

    public function testRefusesAnAnswerSheetThatBelongsToAnotherPaper(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/45 questions.*40 answers|40 answers.*45 questions/');

        $this->render('paper-2026-06.txt', 'key-2020-06.xml');
    }

    public function testRefusesAnAnswerThatNamesAnOptionTheQuestionDoesNotOffer(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/[Qq]uestion 1\b.*C/');

        (new PaperDocument())->render(
            [
                'date'      => '2026-06-03',
                'total'     => 1,
                'sections'  => ['vaerdier' => [1, 1]],
                'questions' => [
                    ['position' => 1, 'section' => 'vaerdier', 'prompt' => 'Er det rigtigt?', 'options' => ['Ja', 'Nej']],
                ],
            ],
            ['answers' => [1 => 'C'], 'pass' => null, 'vaerdier_min' => null, 'ignored' => 0]
        );
    }
}
