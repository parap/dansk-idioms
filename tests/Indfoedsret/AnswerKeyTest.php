<?php declare(strict_types=1);

namespace Dansk\Tests\Indfoedsret;

use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\InvalidPaper;
use PHPUnit\Framework\TestCase;

final class AnswerKeyTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/indfoedsret/' . $name);
    }

    public function testReadsEveryAnswerOfAFortyFiveQuestionKey(): void
    {
        $key = (new AnswerKey())->parse($this->fixture('key-2025-11.xml'));

        self::assertCount(45, $key['answers']);
        self::assertSame([], array_diff(array_keys($key['answers']), range(1, 45)));

        // Read off the printed sheet, rows 31-45. Rows 31 and 34 sit a fraction off the
        // baseline their number does, so a rule that demanded an exact match would drop
        // them; they are asserted here for that reason.
        self::assertSame(
            ['C', 'C', 'A', 'B', 'C', 'B', 'A', 'C', 'C', 'B', 'A', 'A', 'B', 'C', 'C'],
            array_values(array_slice($key['answers'], 30, 15, true))
        );
    }

    /**
     * The November 2025 sheet draws a letter the reader never sees: row 19 carries an
     * unrendered B six tenths of a point above the C that is printed. Taking the glyph a
     * text extraction happens to emit first marks a correct answer wrong, and nothing
     * about the import looks broken afterwards.
     */
    public function testIgnoresAGlyphThatIsNotOnItsRow(): void
    {
        $key = (new AnswerKey())->parse($this->fixture('key-2025-11.xml'));

        self::assertSame('C', $key['answers'][19]);
        self::assertSame(1, $key['ignored']);
    }

    public function testReadsThePassMarkAndTheValuesRequirement(): void
    {
        $key = (new AnswerKey())->parse($this->fixture('key-2025-11.xml'));

        self::assertSame(36, $key['pass']);
        self::assertSame(4, $key['vaerdier_min']);
    }

    public function testReadsAFortyQuestionKeyWithNoValuesRequirement(): void
    {
        $key = (new AnswerKey())->parse($this->fixture('key-2021-06.xml'));

        self::assertCount(40, $key['answers']);
        self::assertSame(32, $key['pass']);
        self::assertNull($key['vaerdier_min']);
        self::assertSame(0, $key['ignored']);
    }

    /**
     * The sheets say the same thing five ways: "4 ud af 5 af spørgsmålene 41-45", "4 ud
     * af de 5 sidste spørgsmål (nr. 41-45)". A wording that does not parse must not pass
     * silently as a paper with no values requirement -- that is a pass mark quietly made
     * easier than the one the exam applies.
     */
    public function testReadsTheValuesRequirementHoweverTheSheetWordsIt(): void
    {
        $wordings = [
            'For at bestå prøven skal prøvedeltageren svare rigtigt på mindst 36 ud af de 45 spørgsmål, '
            . 'herunder skal prøvedeltageren svare korrekt på mindst 4 ud af 5 af spørgsmålene 41-45 om danske værdier.',
            'For at bestå prøven skal prøvedeltageren svare rigtigt på mindst 36 ud af de 45 spørgsmål, og '
            . 'herunder skal prøvedeltageren svare korrekt på mindst 4 ud af de 5 sidste spørgsmål (nr. 41-45) om danske værdier.',
        ];

        foreach ($wordings as $prose) {
            $key = (new AnswerKey())->parse($this->page($this->rows(45), $prose));

            self::assertSame(36, $key['pass'], $prose);
            self::assertSame(4, $key['vaerdier_min'], $prose);
        }
    }

    public function testRefusesASheetWhoseValuesRequirementItCannotRead(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/værdier/u');

        (new AnswerKey())->parse($this->page(
            $this->rows(1),
            'Prøvedeltageren skal svare korrekt på de fleste spørgsmål om danske værdier.'
        ));
    }

    public function testRefusesARowWhoseLettersAreEquallyClose(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/[Rr]ow 2\b/');

        (new AnswerKey())->parse($this->page([
            [56.6, 100.0, '1'], [301.2, 100.0, 'A'],
            [56.6, 114.0, '2'], [301.2, 113.5, 'B'], [301.2, 114.5, 'C'],
        ]));
    }

    public function testRefusesARowWithNoLetterBesideIt(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/[Rr]ow 2\b/');

        (new AnswerKey())->parse($this->page([
            [56.6, 100.0, '1'], [301.2, 100.0, 'A'],
            [56.6, 114.0, '2'],
        ]));
    }

    public function testRefusesAKeyWhoseRowsSkipANumber(): void
    {
        $this->expectException(InvalidPaper::class);
        $this->expectExceptionMessageMatches('/3/');

        (new AnswerKey())->parse($this->page([
            [56.6, 100.0, '1'], [301.2, 100.0, 'A'],
            [56.6, 114.0, '2'], [301.2, 114.0, 'B'],
            [56.6, 128.0, '4'], [301.2, 128.0, 'C'],
        ]));
    }

    /** @return list<array{0:float,1:float,2:string}> a sheet of n rows, every answer A */
    private function rows(int $n): array
    {
        $words = [];
        for ($i = 1; $i <= $n; $i++) {
            $y       = 100.0 + $i * 14.0;
            $words[] = [56.6, $y, (string) $i];
            $words[] = [301.2, $y, 'A'];
        }

        return $words;
    }

    /** @param list<array{0:float,1:float,2:string}> $words */
    private function page(array $words, string $prose = ''): string
    {
        // Prose sits above the table and well clear of the column the row numbers share.
        foreach (explode(' ', trim($prose)) as $i => $word) {
            if ($word !== '') {
                $words[] = [120.0 + $i * 12.0, 40.0, $word];
            }
        }

        $xml = "<doc>\n<page width=\"595\" height=\"842\">\n";
        foreach ($words as [$x, $y, $text]) {
            $xml .= sprintf(
                "<word xMin=\"%.6f\" yMin=\"%.6f\" xMax=\"%.6f\" yMax=\"%.6f\">%s</word>\n",
                $x, $y, $x + 7.0, $y + 14.0, $text
            );
        }

        return $xml . "</page>\n</doc>\n";
    }
}
