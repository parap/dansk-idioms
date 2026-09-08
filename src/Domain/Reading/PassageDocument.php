<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

/**
 * Reads the plain-text authoring format into the structure the repository stores.
 *
 * A passage is only coherent as a whole: the gap markers in the text have to agree with
 * the questions underneath it, and neither half can be reviewed alone. Writing it as one
 * document keeps that agreement visible, makes a passage diffable, and lets content be
 * loaded from the command line before any editing screen exists.
 *
 *     kind: cloze
 *     slug: cykler-i-byen
 *     title: Cykler i byen
 *
 *     --- text ---
 *     Hver morgen ruller tusindvis ind mod centrum. {{1}} har kommunen
 *     bygget nye stier, og det {{2}} at flere tør cykle.
 *
 *     --- questions ---
 *     1.
 *     * Derfor
 *       Alligevel
 *       Dernæst
 *
 * A star marks the correct option. Multiple choice puts its question on the numbered
 * line; insertion names a lettered part from a `--- parts ---` section instead.
 *
 * Parsing is pure: it never touches the database, so a draft can be checked as it is
 * typed.
 */
final class PassageDocument
{
    private const KINDS = ['mc', 'insert', 'cloze'];

    private const GAP_KINDS = ['insert', 'cloze'];

    private const MARKER = '/\{\{(\d+)\}\}/';

    /** @return array<string,mixed> the argument ReadingRepository::save() takes */
    public function parse(string $source): array
    {
        $lines    = preg_split('/\R/', $source) ?: [];
        $headers  = [];
        $sections = ['text' => [], 'parts' => [], 'questions' => []];
        $current  = null;

        foreach ($lines as $i => $line) {
            $no = $i + 1;

            if (preg_match('/^---\s*([a-z]+)\s*---$/', trim($line), $m)) {
                if (!array_key_exists($m[1], $sections)) {
                    throw new InvalidPassage("Unknown section '{$m[1]}' on line {$no}.");
                }
                $current = $m[1];
                continue;
            }

            if ($current === null) {
                if (trim($line) === '') {
                    continue;
                }
                if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m)) {
                    throw new InvalidPassage("Expected a 'key: value' header on line {$no}.");
                }
                $headers[$m[1]] = trim($m[2]);
                continue;
            }

            $sections[$current][] = [$no, $line];
        }

        $kind = $headers['kind'] ?? '';
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidPassage(
                "The 'kind' header must be one of " . implode(', ', self::KINDS) . ", not '{$kind}'."
            );
        }
        foreach (['slug', 'title'] as $required) {
            if (($headers[$required] ?? '') === '') {
                throw new InvalidPassage("The '{$required}' header is missing.");
            }
        }
        if ($sections['text'] === []) {
            throw new InvalidPassage('The document has no --- text --- section.');
        }
        if ($sections['questions'] === []) {
            throw new InvalidPassage('The document has no --- questions --- section.');
        }

        $body  = $this->body($sections['text']);
        $bank  = $kind === 'insert' ? $this->bank($sections['parts']) : [];
        $items = $this->items($sections['questions'], $kind, $bank);

        $this->checkMarkers($body, $kind, $items);

        $doc = [
            'slug'  => $headers['slug'],
            'kind'  => $kind,
            'title' => $headers['title'],
            'body'  => $body,
            'items' => $items,
        ];
        if ($kind === 'insert') {
            $doc['bank'] = $bank;
        }

        return $doc;
    }

    /**
     * Wrapped lines rejoin into one line; a blank line stays a paragraph break, because a
     * 600-word passage read as a single block is not the passage the author wrote.
     *
     * @param list<array{0:int,1:string}> $lines
     */
    private function body(array $lines): string
    {
        $paragraphs = [];
        $current    = [];

        foreach ($lines as [, $line]) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $paragraphs[] = implode(' ', $current);
                    $current      = [];
                }
                continue;
            }
            $current[] = trim($line);
        }
        if ($current !== []) {
            $paragraphs[] = implode(' ', $current);
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param  list<array{0:int,1:string}> $lines
     * @return list<array{label:string,text:string}>
     */
    private function bank(array $lines): array
    {
        $bank = [];
        foreach ($lines as [$no, $line]) {
            if (trim($line) === '') {
                continue;
            }
            if (!preg_match('/^\s*([A-Z])\s+(\S.*)$/', $line, $m)) {
                throw new InvalidPassage("Expected 'A some text' in the parts section on line {$no}.");
            }
            if (in_array($m[1], array_column($bank, 'label'), true)) {
                throw new InvalidPassage("Part '{$m[1]}' is listed twice, on line {$no}.");
            }
            $bank[] = ['label' => $m[1], 'text' => trim($m[2])];
        }

        if ($bank === []) {
            throw new InvalidPassage('An insertion document needs a --- parts --- section.');
        }

        return $bank;
    }

    /**
     * @param  list<array{0:int,1:string}>          $lines
     * @param  list<array{label:string,text:string}> $bank
     * @return list<array<string,mixed>>
     */
    private function items(array $lines, string $kind, array $bank): array
    {
        $items   = [];
        $current = null;
        $labels  = range('A', 'Z');

        $close = function () use (&$items, &$current, $kind): void {
            if ($current === null) {
                return;
            }
            if ($kind !== 'insert') {
                $this->checkOptions($current);
            }
            unset($current['line']);
            $items[] = $current;
            $current = null;
        };

        foreach ($lines as [$no, $line]) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^(\d+)\.\s*(.*)$/', $line, $m)) {
                $close();
                $current = ['position' => (int) $m[1], 'line' => $no, 'options' => []];

                $tail = trim($m[2]);
                if ($kind === 'insert') {
                    if (!in_array($tail, array_column($bank, 'label'), true)) {
                        throw new InvalidPassage(
                            "Gap {$m[1]} on line {$no} names part '{$tail}', which the bank does not offer."
                        );
                    }
                    $current['correct_label'] = $tail;
                    unset($current['options']);
                } elseif ($tail !== '') {
                    $current['prompt'] = $tail;
                }
                continue;
            }

            if ($current === null) {
                throw new InvalidPassage("An option on line {$no} belongs to no question.");
            }
            if ($kind === 'insert') {
                throw new InvalidPassage("An insertion gap takes only a letter, but line {$no} has more.");
            }

            $correct = str_starts_with(ltrim($line), '*');
            $text    = trim(ltrim(ltrim($line), '*'));
            if ($text === '') {
                throw new InvalidPassage("An option on line {$no} is empty.");
            }

            $option = ['label' => $labels[count($current['options'])], 'text' => $text];
            if ($correct) {
                $option['correct'] = true;
            }
            $current['options'][] = $option;
        }
        $close();

        $this->checkPositions($items, $kind, $bank);

        return $items;
    }

    /** @param array<string,mixed> $item */
    private function checkOptions(array $item): void
    {
        $count = count($item['options']);
        if ($count < 3) {
            throw new InvalidPassage("Question {$item['position']} on line {$item['line']} offers {$count} options, and needs at least three.");
        }

        $correct = array_filter($item['options'], static fn(array $o): bool => !empty($o['correct']));
        if (count($correct) !== 1) {
            throw new InvalidPassage(
                "Question {$item['position']} on line {$item['line']} marks " . count($correct)
                . ' options with a star, and needs exactly one.'
            );
        }
    }

    /**
     * @param list<array<string,mixed>>              $items
     * @param list<array{label:string,text:string}>  $bank
     */
    private function checkPositions(array $items, string $kind, array $bank): void
    {
        $positions = array_column($items, 'position');
        if (count($positions) !== count(array_unique($positions))) {
            throw new InvalidPassage('Two questions share a number.');
        }

        if ($kind !== 'insert') {
            return;
        }

        $used = array_column($items, 'correct_label');
        if (count($used) !== count(array_unique($used))) {
            throw new InvalidPassage('A part fills more than one gap; each letter may be used once.');
        }
        if (count($bank) <= count($items)) {
            throw new InvalidPassage(
                'The parts section must offer more parts than there are gaps, so that some fit nowhere.'
            );
        }
    }

    /** @param list<array<string,mixed>> $items */
    private function checkMarkers(string $body, string $kind, array $items): void
    {
        preg_match_all(self::MARKER, $body, $m);
        $markers = array_map('intval', $m[1]);

        if (!in_array($kind, self::GAP_KINDS, true)) {
            if ($markers !== []) {
                throw new InvalidPassage("A {$kind} text must not contain gap markers.");
            }
            return;
        }

        $positions = array_column($items, 'position');
        sort($markers);
        sort($positions);

        if ($markers !== $positions) {
            throw new InvalidPassage(
                'The gaps in the text ' . json_encode($markers)
                . ' do not match the questions ' . json_encode($positions) . '.'
            );
        }
    }
}
