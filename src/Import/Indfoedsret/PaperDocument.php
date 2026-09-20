<?php declare(strict_types=1);

namespace Dansk\Import\Indfoedsret;

/**
 * Writes a parsed paper and its answer sheet into the authoring format the reading
 * module loads.
 *
 * The document is the source of truth from here on, and the PDFs are only where it came
 * from: a question whose wording a reader wants to correct is corrected in a diffable
 * text file rather than by re-running an extractor. That is also why the options keep
 * the paper's own A/B/C order instead of being shuffled -- a document and the published
 * PDF can then be read side by side.
 *
 * The two PDFs know nothing about each other, so everything that could pair them wrongly
 * is checked here: a sheet with a different number of rows than the paper has questions,
 * and an answer naming an option the question never offered.
 */
final class PaperDocument
{
    private const MONTHS = [
        1 => 'januar', 2 => 'februar', 3 => 'marts', 4 => 'april', 5 => 'maj', 6 => 'juni',
        7 => 'juli', 8 => 'august', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];

    /**
     * @param array{date:string, total:int, sections:array<string,array{0:int,1:int}>,
     *              questions:list<array{position:int,section:string,prompt:string,options:list<string>}>} $paper
     * @param array{answers:array<int,string>, pass:?int, vaerdier_min:?int, ignored:int}                   $key
     */
    public function render(array $paper, array $key): string
    {
        $this->check($paper, $key);

        $lines = [
            'kind: quiz',
            'slug: indfoedsret-' . $paper['date'],
            'title: ' . $this->title($paper['date']),
        ];
        if ($key['pass'] !== null) {
            $lines[] = 'pass: ' . $key['pass'];
        }
        if ($key['vaerdier_min'] !== null) {
            $lines[] = 'vaerdier_min: ' . $key['vaerdier_min'];
        }

        $lines[] = '';
        $lines[] = '--- questions ---';

        $block = null;
        foreach ($paper['questions'] as $question) {
            if ($question['section'] !== $block) {
                $block   = $question['section'];
                $lines[] = "[{$block}]";
                $lines[] = '';
            }

            $lines[] = "{$question['position']}. {$question['prompt']}";
            foreach ($question['options'] as $i => $option) {
                $label   = chr(ord('A') + $i);
                $lines[] = ($label === $key['answers'][$question['position']] ? '* ' : '  ') . $option;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $paper
     * @param array<string,mixed> $key
     */
    private function check(array $paper, array $key): void
    {
        $questions = count($paper['questions']);
        $answers   = count($key['answers']);

        if ($questions !== $answers) {
            throw new InvalidPaper(
                "The paper has {$questions} questions and the answer sheet {$answers} answers; "
                . 'they are not the same paper.'
            );
        }

        foreach ($paper['questions'] as $question) {
            $position = $question['position'];
            $letter   = $key['answers'][$position] ?? null;

            if ($letter === null) {
                throw new InvalidPaper("The answer sheet says nothing about question {$position}.");
            }

            $offered = chr(ord('A') + count($question['options']) - 1);
            if ($letter > $offered) {
                throw new InvalidPaper(
                    "Question {$position} offers A-{$offered}, but the answer sheet names {$letter}."
                );
            }
        }
    }

    private function title(string $date): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return sprintf('Indfødsretsprøven %d. %s %d', $day, self::MONTHS[$month], $year);
    }
}
