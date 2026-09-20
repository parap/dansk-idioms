<?php declare(strict_types=1);

namespace Dansk\Tests\Indfoedsret;

use Dansk\Import\Indfoedsret\InvalidPaper;
use Dansk\Import\Indfoedsret\PaperParser;
use PHPUnit\Framework\TestCase;

final class PaperParserTest extends TestCase
{
    private function paper(string $name): array
    {
        return (new PaperParser())->parse(
            (string) file_get_contents(__DIR__ . '/../fixtures/indfoedsret/' . $name)
        );
    }

    public function testReadsEveryQuestionOfACurrentPaper(): void
    {
        $paper = $this->paper('paper-2026-06.txt');

        self::assertSame(45, $paper['total']);
        self::assertCount(45, $paper['questions']);
        self::assertSame(range(1, 45), array_column($paper['questions'], 'position'));
        self::assertSame('2026-06-03', $paper['date']);
    }

    public function testJoinsAPromptThatWrapsOverTwoLines(): void
    {
        $paper = $this->paper('paper-2026-06.txt');

        self::assertSame(
            'I hvilket årti blev Danmark ramt af en flerårig økonomisk krise, '
            . 'som blev udløst af store prisstigninger på olie?',
            $paper['questions'][0]['prompt']
        );
        self::assertSame(['1930’erne', '1950’erne', '1970’erne'], $paper['questions'][0]['options']);
    }

    /**
     * The instructions demonstrate answering with "Hvad hedder Danmarks hovedstad?" and
     * its three options. It carries no number, so nothing may attach to it.
     */
    public function testLeavesTheWorkedExampleOutOfThePaper(): void
    {
        foreach (['paper-2026-06.txt', 'paper-2020-06.txt'] as $fixture) {
            $prompts = array_column($this->paper($fixture)['questions'], 'prompt');
            self::assertNotContains('Hvad hedder Danmarks hovedstad?', $prompts);
        }
    }

    public function testKeepsAPageFooterOutOfAQuestion(): void
    {
        foreach (['paper-2026-06.txt', 'paper-2020-06.txt'] as $fixture) {
            foreach ($this->paper($fixture)['questions'] as $question) {
                self::assertStringNotContainsString('kl. 13', $question['prompt']);
                foreach ($question['options'] as $option) {
                    self::assertStringNotContainsString('Indfødsretsprøven', $option);
                }
            }
        }
    }

    public function testAssignsEachQuestionToTheSectionTheInstructionsDeclare(): void
    {
        $sections = array_column($this->paper('paper-2026-06.txt')['questions'], 'section', 'position');

        self::assertSame('laeremateriale', $sections[1]);
        self::assertSame('laeremateriale', $sections[35]);
        self::assertSame('aktuelle', $sections[36]);
        self::assertSame('aktuelle', $sections[40]);
        self::assertSame('vaerdier', $sections[41]);
        self::assertSame('vaerdier', $sections[45]);
    }

    /** The values questions arrived in late 2021; before that a paper was 40 questions. */
    public function testReadsTheFortyQuestionPaperThatHasNoValuesSection(): void
    {
        $paper = $this->paper('paper-2020-06.txt');

        self::assertSame(40, $paper['total']);
        self::assertCount(40, $paper['questions']);
        self::assertSame('2020-06-03', $paper['date']);

        $sections = array_column($paper['questions'], 'section', 'position');
        self::assertSame('laeremateriale', $sections[35]);
        self::assertSame('aktuelle', $sections[36]);
        self::assertNotContains('vaerdier', $sections);
    }

    public function testReadsAQuestionThatOffersOnlyTwoAnswers(): void
    {
        $questions = array_column($this->paper('paper-2026-06.txt')['questions'], 'options', 'position');

        self::assertSame(['Ja', 'Nej'], $questions[41]);
    }

    public function testRefusesAPaperWithFewerQuestionsThanItDeclares(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/declares 45 .*carries 1/');

        (new PaperParser())->parse($this->synthetic());
    }

    public function testRefusesAQuestionWithOnlyOneAnswer(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/[Qq]uestion 2\b/');

        (new PaperParser())->parse(
            $this->synthetic("1. Første?\n  A: Ja\n  B: Nej\n2. Anden?\n  A: Ja\n")
        );
    }

    private function synthetic(string $questions = "1. Første?\n  A: Ja\n  B: Nej\n"): string
    {
        return "Indfødsretsprøven\n\n"
            . "Indfødsretsprøven er en prøve i danske samfundsforhold, dansk kultur og historie og danske\n"
            . "værdier. Prøven består af 45 spørgsmål, der skal besvares indenfor 45 minutter. 35 spørgsmål\n"
            . "er udarbejdet på baggrund af materialet Læremateriale til Indfødsretsprøven, 5 spørgsmål\n"
            . "vedrører aktuelle emner, og 5 spørgsmål vedrører værdier i det danske samfund.\n\n"
            . "2 · Indfødsretsprøven · 3. juni 2026, kl. 13.00-13.45\n\n"
            . $questions;
    }
}
