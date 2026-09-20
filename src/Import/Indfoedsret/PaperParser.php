<?php declare(strict_types=1);

namespace Dansk\Import\Indfoedsret;

/**
 * Reads an indfødsretsprøve paper as `pdftotext -layout` renders it.
 *
 * The papers span seven years and three layouts: the checkbox is a glyph in the newest
 * two and invisible furniture in the rest, the questions carry section headings only
 * since late 2025, and a paper was 40 questions before the values block was added. What
 * every one of them does carry is an instruction paragraph stating how many questions
 * there are and how they are divided, so the structure is read from the paper's own
 * declaration and then checked against what was parsed. A paper that does not add up is
 * refused: a set that silently loses a question still looks like a finished exam.
 *
 * Nothing attaches to a question until one is open, which is what keeps the worked
 * example in the instructions -- "Hvad hedder Danmarks hovedstad?", with three options --
 * out of the paper. It is the only unnumbered question in the file.
 */
final class PaperParser
{
    /** In the order the paper lays them out. */
    private const SECTIONS = ['laeremateriale', 'aktuelle', 'vaerdier'];

    private const MONTHS = [
        'januar' => 1, 'februar' => 2, 'marts' => 3, 'april' => 4, 'maj' => 5, 'juni' => 6,
        'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'december' => 12,
    ];

    /** A page footer: the page number, the exam's name, and the date it was held. */
    private const FOOTER = '/^\s*\d+\s*[·\x{2013}\x{2014}-]?\s*Indfødsretsprøven/u';

    private const QUESTION = '/^\s*(\d+)\.\s+(\S.*)$/u';

    private const OPTION = '/^\s*(?:\x{2610}\s*)?([A-C]):\s+(\S.*)$/u';

    /**
     * @return array{date:string, total:int, sections:array<string,array{0:int,1:int}>,
     *               questions:list<array{position:int,section:string,prompt:string,options:list<string>}>}
     */
    public function parse(string $text): array
    {
        $lines     = preg_split('/\R/u', $text) ?: [];
        $questions = $this->questions($lines);
        $preamble  = $this->preamble($lines);

        $total = $this->declaredTotal($preamble);
        if (count($questions) !== $total) {
            throw new InvalidPaper(
                "The paper declares {$total} questions and carries " . count($questions) . '.'
            );
        }

        $sections = $this->sections($preamble, $total);
        $this->checkHeadings($lines, $sections);

        foreach ($questions as $i => $question) {
            $questions[$i]['section'] = $this->sectionOf($question['position'], $sections);
        }

        return [
            'date'      => $this->date($lines),
            'total'     => $total,
            'sections'  => $sections,
            'questions' => $questions,
        ];
    }

    /**
     * Everything before the first question, as one line. The declaration wraps mid
     * sentence, and a regex over the wrapped text would read a different paper on every
     * layout the ministry has used.
     *
     * @param list<string> $lines
     */
    private function preamble(array $lines): string
    {
        $head = [];
        foreach ($lines as $line) {
            if (preg_match(self::QUESTION, $line, $m) && (int) $m[1] === 1) {
                break;
            }
            $head[] = trim($line);
        }

        return (string) preg_replace('/\s+/u', ' ', implode(' ', $head));
    }

    private function declaredTotal(string $preamble): int
    {
        if (!preg_match('/består\s+af\s+(\d+)\s+spørgsmål/u', $preamble, $m)) {
            throw new InvalidPaper('The paper does not say how many questions it has.');
        }

        return (int) $m[1];
    }

    /**
     * The instruction paragraph counts each block; the blocks then follow in the order
     * they are counted here, which every published paper and every retteark naming
     * "spørgsmålene 41-45 om danske værdier" agrees on.
     *
     * @return array<string,array{0:int,1:int}>
     */
    private function sections(string $preamble, int $total): array
    {
        $counts = [
            'laeremateriale' => $this->count('/(\d+)\s+(?:øvrige\s+)?spørgsmål\s+er\s+udarbejdet\s+på\s+baggrund/u', $preamble),
            'aktuelle'       => $this->count('/(\d+)\s+(?:af\s+)?spørgsmål(?:ene)?\s+vedrører\s+aktuelle\s+emner/u', $preamble),
            'vaerdier'       => $this->count('/(\d+)\s+(?:af\s+)?spørgsmål(?:ene)?\s+vedrører\s+værdier/u', $preamble),
        ];

        if ($counts['laeremateriale'] === null || $counts['aktuelle'] === null) {
            throw new InvalidPaper('The paper does not say how its questions are divided.');
        }
        if (array_sum($counts) !== $total) {
            throw new InvalidPaper(
                'The declared blocks add up to ' . array_sum($counts) . ", not the {$total} questions the paper has."
            );
        }

        $sections = [];
        $from     = 1;
        foreach (self::SECTIONS as $name) {
            if (($counts[$name] ?? null) === null) {
                continue;
            }
            $sections[$name] = [$from, $from + $counts[$name] - 1];
            $from           += $counts[$name];
        }

        return $sections;
    }

    private function count(string $pattern, string $preamble): ?int
    {
        return preg_match($pattern, $preamble, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The newest papers print the ranges above each block. Where they do, they must say
     * what the instruction paragraph says.
     *
     * @param list<string>                      $lines
     * @param array<string,array{0:int,1:int}>  $sections
     */
    private function checkHeadings(array $lines, array $sections): void
    {
        foreach ($lines as $line) {
            if (!preg_match('/^\s*Spørgsmål\s+(\d+)-(\d+):/u', $line, $m)) {
                continue;
            }

            $range = [(int) $m[1], (int) $m[2]];
            if (!in_array($range, array_values($sections), true)) {
                throw new InvalidPaper(
                    "The paper prints a block heading for questions {$m[1]}-{$m[2]}, which the "
                    . 'instructions do not describe.'
                );
            }
        }
    }

    /** @param array<string,array{0:int,1:int}> $sections */
    private function sectionOf(int $position, array $sections): string
    {
        foreach ($sections as $name => [$from, $to]) {
            if ($position >= $from && $position <= $to) {
                return $name;
            }
        }

        throw new InvalidPaper("Question {$position} falls outside every declared block.");
    }

    /**
     * @param  list<string> $lines
     * @return list<array{position:int,section:string,prompt:string,options:list<string>}>
     */
    private function questions(array $lines): array
    {
        $questions = [];
        $current   = null;
        $expected  = 1;

        foreach ($lines as $line) {
            if (trim($line) === '' || preg_match(self::FOOTER, $line)) {
                continue;
            }

            if (preg_match(self::QUESTION, $line, $m) && (int) $m[1] === $expected) {
                if ($current !== null) {
                    $questions[] = $this->close($current);
                }
                $current  = ['position' => $expected, 'section' => '', 'prompt' => trim($m[2]), 'options' => []];
                $expected++;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match(self::OPTION, $line, $m)) {
                $label = chr(ord('A') + count($current['options']));
                if ($m[1] !== $label) {
                    throw new InvalidPaper(
                        "Question {$current['position']} offers '{$m[1]}' where '{$label}' was due."
                    );
                }
                $current['options'][] = trim($m[2]);
                continue;
            }

            // A wrapped line continues whatever is open: the last option once options have
            // started, the prompt before that.
            if ($current['options'] === []) {
                $current['prompt'] .= ' ' . trim($line);
                continue;
            }
            $current['options'][array_key_last($current['options'])] .= ' ' . trim($line);
        }

        if ($current !== null) {
            $questions[] = $this->close($current);
        }

        return $questions;
    }

    /**
     * @param  array{position:int,section:string,prompt:string,options:list<string>} $question
     * @return array{position:int,section:string,prompt:string,options:list<string>}
     */
    private function close(array $question): array
    {
        $count = count($question['options']);
        if ($count < 2) {
            throw new InvalidPaper(
                "Question {$question['position']} offers {$count} answer(s); the exam offers two or three."
            );
        }

        return $question;
    }

    /** @param list<string> $lines */
    private function date(array $lines): string
    {
        foreach ($lines as $line) {
            if (!preg_match(self::FOOTER, $line)) {
                continue;
            }
            if (preg_match('/(\d{1,2})\.\s+([a-zæøå]+)\s+(\d{4})/u', $line, $m)
                && isset(self::MONTHS[$m[2]])
            ) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], self::MONTHS[$m[2]], (int) $m[1]);
            }
        }

        throw new InvalidPaper('The paper carries no page footer naming the date it was held.');
    }
}
