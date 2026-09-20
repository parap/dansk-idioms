<?php declare(strict_types=1);

namespace Dansk\Import\Indfoedsret;

/**
 * Reads a retteark -- the sheet that gives the correct letter for every question.
 *
 * It reads glyph positions (`pdftotext -bbox`) rather than extracted lines, because the
 * sheets are a two-column table and some of them carry a letter that is never rendered:
 * the November 2025 sheet draws a B six tenths of a point above the C printed on row 19,
 * and a line-based extraction emits both, in an order that has nothing to do with which
 * one a reader sees. A row is therefore resolved by geometry -- the letter sharing the
 * row number's baseline -- and a row whose two nearest letters are equally close is
 * refused rather than decided by a coin toss.
 */
final class AnswerKey
{
    /** A letter belongs to a row only if it sits within this many points of its number. */
    private const ROW_TOLERANCE = 3.0;

    /** How much closer the winning letter must be than the runner-up to be unambiguous. */
    private const MARGIN = 0.5;

    /** Row numbers cluster on one x; a stray digit in the prose does not land on it. */
    private const COLUMN_TOLERANCE = 1.0;

    /** The answer column stands clear of the number column by far more than this. */
    private const COLUMN_GAP = 10.0;

    /**
     * @return array{answers:array<int,string>, pass:?int, vaerdier_min:?int, ignored:int}
     */
    public function parse(string $bboxXml): array
    {
        $pages = $this->pages($bboxXml);
        $rows  = $this->rows($pages);

        if ($rows === []) {
            throw new InvalidPaper('The answer sheet has no numbered rows.');
        }

        $answers = [];
        $used    = 0;
        foreach ($rows as [$page, $y, $x, $number]) {
            $answers[$number] = $this->letterFor($pages[$page], $number, $y, $x);
            $used++;
        }

        $prose            = $this->prose($pages);
        [$pass, $values]  = $this->thresholds($prose, count($answers));

        return [
            'answers'      => $answers,
            'pass'         => $pass,
            'vaerdier_min' => $values,
            'ignored'      => $this->letterCount($pages) - $used,
        ];
    }

    /**
     * Glyphs keep their page, because y coordinates restart on every one of them and a
     * row on page two would otherwise claim a letter from page one.
     *
     * @return array<int,list<array{0:float,1:float,2:string}>> page => [x, y, text]
     */
    private function pages(string $xml): array
    {
        $pages = [];
        $page  = 0;

        foreach (preg_split('/\R/u', $xml) ?: [] as $line) {
            if (str_contains($line, '<page ')) {
                $page++;
                $pages[$page] = [];
                continue;
            }
            if ($page === 0) {
                continue;
            }
            if (preg_match('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="[\d.]+" yMax="[\d.]+">(.*)<\/word>/u', $line, $m)) {
                $pages[$page][] = [(float) $m[1], (float) $m[2], html_entity_decode($m[3], ENT_QUOTES | ENT_XML1, 'UTF-8')];
            }
        }

        return $pages;
    }

    /**
     * @param  array<int,list<array{0:float,1:float,2:string}>> $pages
     * @return list<array{0:int,1:float,2:float,3:int}>         page, y, x, number
     */
    private function rows(array $pages): array
    {
        $digits = [];
        foreach ($pages as $page => $words) {
            foreach ($words as [$x, $y, $text]) {
                if (preg_match('/^\d{1,2}$/', $text) && (int) $text >= 1 && (int) $text <= 45) {
                    $digits[] = [$page, $y, $x, (int) $text];
                }
            }
        }

        $column = $this->column($digits);
        $rows   = array_values(array_filter(
            $digits,
            static fn(array $d): bool => abs($d[2] - $column) <= self::COLUMN_TOLERANCE
        ));

        usort($rows, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($rows as $i => $row) {
            if ($row[3] !== $i + 1) {
                throw new InvalidPaper(
                    'The answer sheet numbers its rows ' . ($i === 0 ? '' : 'up to ' . $rows[$i - 1][3] . ' and then ')
                    . "jumps to {$row[3]}; row " . ($i + 1) . ' is missing.'
                );
            }
        }

        return $rows;
    }

    /**
     * The x every row number shares. A digit in the instruction sentence -- "mindst 4 ud
     * af 5" -- sits mid-line and so never joins the column, which is what keeps it from
     * being read as row 4.
     *
     * @param list<array{0:int,1:float,2:float,3:int}> $digits
     */
    private function column(array $digits): float
    {
        $counts = [];
        foreach ($digits as [, , $x]) {
            $key           = (string) round($x, 1);
            $counts[$key]  = ($counts[$key] ?? 0) + 1;
        }
        if ($counts === []) {
            throw new InvalidPaper('The answer sheet has no numbered rows.');
        }

        arsort($counts);

        return (float) array_key_first($counts);
    }

    /** @param list<array{0:float,1:float,2:string}> $words */
    private function letterFor(array $words, int $number, float $y, float $x): string
    {
        $candidates = [];
        foreach ($words as [$lx, $ly, $text]) {
            if (!in_array($text, ['A', 'B', 'C'], true) || $lx < $x + self::COLUMN_GAP) {
                continue;
            }
            $distance = abs($ly - $y);
            if ($distance <= self::ROW_TOLERANCE) {
                $candidates[] = [$distance, $text];
            }
        }

        if ($candidates === []) {
            throw new InvalidPaper("The answer sheet has no letter beside row {$number}.");
        }

        sort($candidates);
        if (count($candidates) > 1 && $candidates[1][0] - $candidates[0][0] < self::MARGIN) {
            throw new InvalidPaper(
                "Row {$number} has two letters equally close to it, '{$candidates[0][1]}' and "
                . "'{$candidates[1][1]}'; which one is printed cannot be decided from the file."
            );
        }

        return $candidates[0][1];
    }

    /** @param array<int,list<array{0:float,1:float,2:string}>> $pages */
    private function letterCount(array $pages): int
    {
        $count = 0;
        foreach ($pages as $words) {
            foreach ($words as [, , $text]) {
                if (in_array($text, ['A', 'B', 'C'], true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * The pass mark is a sentence the sheet carries, not a constant: 32 of 40 before the
     * values questions existed, 36 of 45 with them, and the sheets word the second
     * requirement differently from year to year -- "4 ud af 5 af spørgsmålene 41-45" on
     * one, "4 ud af de 5 sidste spørgsmål (nr. 41-45)" on the next.
     *
     * Both requirements are the same sentence shape, "mindst X ud af Y spørgsmål", and
     * the sheet states the paper's before the block's. A sheet that talks about danske
     * værdier in a shape this cannot read is refused: reading it as a paper with no
     * values requirement would quietly grade the exam more leniently than it is graded.
     *
     * @return array{0:?int,1:?int} the paper's pass mark, and the values block's
     */
    private function thresholds(string $prose, int $questions): array
    {
        preg_match_all(
            '/mindst\s+(\d+)\s+ud\s+af\s+(?:de\s+)?(\d+)\s+(?:af\s+|sidste\s+)*spørgsmål/u',
            $prose,
            $found,
            PREG_SET_ORDER
        );

        $paper = $found[0] ?? null;
        $block = $found[1] ?? null;

        if ($paper !== null && (int) $paper[2] !== $questions) {
            throw new InvalidPaper(
                "The sheet says the paper has {$paper[2]} questions but carries {$questions} rows."
            );
        }

        if (str_contains($prose, 'værdier') && $block === null) {
            throw new InvalidPaper(
                'The sheet states a requirement about danske værdier in a wording this cannot read.'
            );
        }

        if ($block !== null && (int) $block[2] >= $questions) {
            throw new InvalidPaper(
                "The sheet's second requirement covers {$block[2]} questions, which is the whole paper "
                . 'rather than the values block.'
            );
        }

        return [
            $paper === null ? null : (int) $paper[1],
            $block === null ? null : (int) $block[1],
        ];
    }

    /** @param array<int,list<array{0:float,1:float,2:string}>> $pages */
    private function prose(array $pages): string
    {
        $words = [];
        foreach ($pages as $page) {
            foreach ($page as [, , $text]) {
                $words[] = $text;
            }
        }

        return implode(' ', $words);
    }
}
