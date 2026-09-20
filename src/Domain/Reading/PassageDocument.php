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
    private const KINDS = ['mc', 'insert', 'cloze', 'quiz'];

    private const GAP_KINDS = ['insert', 'cloze'];

    /** A knowledge paper is questions alone: there is nothing to read before answering. */
    private const TEXTLESS_KINDS = ['quiz'];

    /** The blocks a knowledge paper divides its questions into, in the order it asks them. */
    private const BLOCKS = ['laeremateriale', 'aktuelle', 'vaerdier'];

    private const MARKER = '/\{\{(\d+)\}\}/';

    /** @return array<string,mixed> the argument ReadingRepository::save() takes */
    public function parse(string $source): array
    {
        $lines    = preg_split('/\R/u', $source) ?: [];
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
        $textless = in_array($kind, self::TEXTLESS_KINDS, true);
        if ($sections['text'] === [] && !$textless) {
            throw new InvalidPassage('The document has no --- text --- section.');
        }
        if ($sections['text'] !== [] && $textless) {
            throw new InvalidPassage("A {$kind} paper has nothing to read, so it takes no --- text --- section.");
        }
        if ($sections['questions'] === []) {
            throw new InvalidPassage('The document has no --- questions --- section.');
        }

        $body  = $textless ? null : $this->body($sections['text']);
        $bank  = $kind === 'insert' ? $this->bank($sections['parts']) : [];
        $items = $this->items($sections['questions'], $kind, $bank);

        if ($body !== null) {
            $this->checkMarkers($body, $kind, $items);
        }

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
        if ($textless) {
            $doc['pass']         = $this->threshold($headers, 'pass');
            $doc['vaerdier_min'] = $this->threshold($headers, 'vaerdier_min');
        }

        return $doc;
    }

    /**
     * The pass mark travels with the paper because it is the paper's own: the exam asked
     * for 32 of 40 before the values block existed and asks for 36 of 45 with it.
     *
     * @param array<string,string> $headers
     */
    private function threshold(array $headers, string $name): ?int
    {
        $value = trim($headers[$name] ?? '');
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidPassage("The '{$name}' header must be a whole number, not '{$value}'.");
        }

        return (int) $value;
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

            if (preg_match('/^\s*([A-Z])\s+(\S.*)$/', $line, $m)) {
                if (in_array($m[1], array_column($bank, 'label'), true)) {
                    throw new InvalidPassage("Part '{$m[1]}' is listed twice, on line {$no}.");
                }
                if ($m[1] === chr(ord('A') + count($bank))) {
                    $bank[] = ['label' => $m[1], 'text' => trim($m[2])];
                    continue;
                }
            }

            // Parts are lettered in order, so a line that does not open the next letter
            // continues the part above it, as lines in the text section do. A part runs
            // 25-35 words and wraps where it is written, and a wrapped line may open with
            // a capital of its own -- "I Danmark er der ..." is a sentence, not part I.
            if ($bank === []) {
                throw new InvalidPassage("Expected 'A some text' in the parts section on line {$no}.");
            }
            $bank[array_key_last($bank)]['text'] .= ' ' . trim($line);
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
        $block   = null;
        $labels  = range('A', 'Z');
        $textless = in_array($kind, self::TEXTLESS_KINDS, true);

        $close = function () use (&$items, &$current, $kind): void {
            if ($current === null) {
                return;
            }
            if ($kind !== 'insert') {
                $this->checkOptions($current, $kind);
            }
            unset($current['line']);
            $items[] = $current;
            $current = null;
        };

        foreach ($lines as [$no, $line]) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*\[([a-z_]+)\]\s*$/', $line, $m)) {
                $close();
                if (!in_array($m[1], self::BLOCKS, true)) {
                    throw new InvalidPassage(
                        "Line {$no} opens block '{$m[1]}', which is not one of " . implode(', ', self::BLOCKS) . '.'
                    );
                }
                if (!$textless) {
                    throw new InvalidPassage("A {$kind} passage has no blocks, but line {$no} opens one.");
                }
                $block = $m[1];
                continue;
            }

            if (preg_match('/^(\d+)\.\s*(.*)$/', $line, $m)) {
                $close();
                $current = ['position' => (int) $m[1], 'line' => $no, 'options' => []];
                if ($textless) {
                    if ($block === null) {
                        throw new InvalidPassage("Question {$m[1]} on line {$no} belongs to no block.");
                    }
                    $current['section'] = $block;
                }

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

    /**
     * The exam's values questions are answered Ja or Nej, so a knowledge paper offers two
     * options where a reading task needs three to be worth asking.
     *
     * @param array<string,mixed> $item
     */
    private function checkOptions(array $item, string $kind): void
    {
        $least = in_array($kind, self::TEXTLESS_KINDS, true) ? 2 : 3;
        $count = count($item['options']);
        if ($count < $least) {
            $word = $least === 2 ? 'two' : 'three';
            throw new InvalidPassage("Question {$item['position']} on line {$item['line']} offers {$count} options, and needs at least {$word}.");
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
