<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Splits a message into idiom entries.
 *
 * U+200B is NOT an entry delimiter -- it prefixes every structural line, including
 * continuation lines belonging to the entry above. Measured over the real export,
 * splitting naively yields 619 chunks of which only 354 are entries.
 *
 * The rule that separates them, exact on 618 of 619 chunks:
 *   Latin-initial chunk    -> new entry head
 *   Cyrillic-initial chunk -> continuation of the preceding entry
 *
 * Exception: a message may open in Russian prose with the headword bolded mid-sentence
 * ("Глагольная конструкция **at mærke efter** — ..."). With no entry open yet, a bold
 * Latin span promotes such a chunk to an entry head rather than discarding it as preamble.
 */
final class EntrySegmenter
{
    /**
     * A split to show a person, never one to apply on its own.
     *
     * Without U+200B there is nothing authoritative to cut on, so this guesses: a line
     * opening with a Latin letter starts a piece, anything else belongs to the piece
     * above. On the group's own posts that rule is right far more often than not, but
     * "far more often" is exactly what must not be acted on unseen -- a Danish word
     * opening a continuation line would silently become an idiom of its own.
     *
     * So the guess is rendered, numbered, and applied only once someone has looked at
     * it. Whoever calls this owes the reader that look.
     *
     * @return list<string>
     */
    public function proposeSplit(string $text): array
    {
        return array_map(
            static fn(array $part): string => $part['text'],
            $this->proposeSplitParts($text)
        );
    }

    /**
     * The same split, each piece knowing where it begins.
     *
     * The offset is what lets a bold span follow its own piece into its own post, and
     * it is counted in UTF-16 units because that is what Telegram counts. Trailing
     * whitespace is trimmed off a piece's text but still counted here: the position a
     * piece starts at does not move because the line above ended untidily.
     *
     * @return list<array{text:string, offset:int}>
     */
    public function proposeSplitParts(string $text): array
    {
        $parts   = [];
        $current = null;
        $offset  = 0;

        foreach (explode("\n", $text) as $line) {
            $units   = Text::utf16Length($line) + 1;   // the newline that followed it
            $trimmed = rtrim($line);

            if (trim($trimmed) === '') {
                $offset += $units;
                continue;
            }

            $opens = preg_match('/^\p{Latin}/u', trim(Text::stripBoldMarkers($trimmed))) === 1;
            if ($current === null || $opens) {
                if ($current !== null) {
                    $parts[] = $current;
                }
                $current = ['text' => $trimmed, 'offset' => $offset];
            } else {
                $current['text'] .= "\n" . $trimmed;
            }

            $offset += $units;
        }

        if ($current !== null) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * The entries of a message as pieces to publish, or null when the cut is a guess.
     *
     * U+200B before every structural line makes the cut certain, and so does text whose
     * every Latin-initial line is "headword — explanation". Several bare Latin lines do
     * not: one may be an example sentence, and cutting there invents an idiom.
     *
     * @return ?list<array{text:string, offset:int}>
     */
    public function entryParts(string $text): ?array
    {
        if (str_contains($text, Text::ZWSP)) {
            return $this->partsAtSeparators($text);
        }

        $heads = 0;
        $bare  = false;
        foreach (explode("\n", $text) as $line) {
            $line = trim(Text::stripBoldMarkers($line));
            if ($line === '' || preg_match('/^\p{Latin}/u', $line) !== 1) {
                continue;
            }
            $heads++;
            $bare = $bare || preg_match(self::HEADED_LINE, $line) !== 1;
        }

        if ($heads <= 1) {
            return [['text' => rtrim($text), 'offset' => 0]];
        }

        return $bare ? null : $this->proposeSplitParts($text);
    }

    /** "headword — explanation": Latin before a spaced dash, Cyrillic after it. */
    private const HEADED_LINE = '/^[^\p{Cyrillic}\n]+?\s[—–-]\s.*\p{Cyrillic}/u';

    /**
     * Cut before each U+200B that opens an entry, keeping the separator with its piece.
     * Whatever precedes the first entry travels with it rather than being dropped.
     *
     * @return list<array{text:string, offset:int}>
     */
    private function partsAtSeparators(string $text): array
    {
        $parts   = [];
        $current = null;
        $offset  = 0;

        foreach (explode(Text::ZWSP, $text) as $index => $chunk) {
            $start   = $index === 0 ? $offset : $offset - 1;   // the separator before it
            $offset += Text::utf16Length($chunk) + 1;
            $piece   = $index === 0 ? $chunk : Text::ZWSP . $chunk;

            if ($current !== null && $this->isEntryHead($chunk, true) && !$this->isPreamble($current)) {
                $parts[] = $current;
                $current = null;
            }
            if ($current === null) {
                $current = ['text' => $piece, 'offset' => $start, 'head' => $this->isEntryHead($chunk, false)];
                continue;
            }
            $current['text'] .= $piece;
            $current['head'] = $current['head'] || $this->isEntryHead($chunk, false);
        }

        $parts[] = $current;

        return array_values(array_filter(array_map(
            static fn(array $part): array => ['text' => rtrim($part['text']), 'offset' => $part['offset']],
            $parts
        ), static fn(array $part): bool => trim(str_replace(Text::ZWSP, '', $part['text'])) !== ''));
    }

    /** A piece holding no entry yet is preamble, and the next entry joins it. */
    private function isPreamble(array $part): bool
    {
        return !$part['head'];
    }

    /** @return array{entries: list<string>, preamble: string|null} */
    public function segment(string $messageText): array
    {
        $chunks = $messageText === ''
            ? []
            : array_values(array_filter(
                array_map('trim', explode(Text::ZWSP, $messageText)),
                static fn(string $c): bool =>
                    $c !== '' && preg_match('/\p{L}/u', Text::stripBoldMarkers($c)) === 1
            ));

        $entries  = [];
        $preamble = [];
        $current  = null;

        foreach ($chunks as $chunk) {
            if ($this->isEntryHead($chunk, $current !== null)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = $chunk;
                continue;
            }

            if ($current !== null) {
                $current .= "\n" . $chunk;   // continuation line
            } else {
                $preamble[] = $chunk;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return [
            'entries'  => $entries,
            'preamble' => $preamble === [] ? null : implode("\n", $preamble),
        ];
    }

    private function isEntryHead(string $chunk, bool $entryOpen): bool
    {
        if (Text::openingScript($chunk) === 'lat') {
            return true;
        }

        // Cyrillic-initial prose whose headword is bolded, or sits alone on its own
        // line ("Конструкция\nfå at vide\n(буквально: ...)"). Only when nothing is open
        // yet -- otherwise "Значение: ..." lines would wrongly start new entries.
        if (!$entryOpen && ($this->hasLatinBoldSpan($chunk) || $this->hasLatinOnlyLine($chunk))) {
            return true;
        }

        return false;
    }

    private function hasLatinBoldSpan(string $chunk): bool
    {
        if (!preg_match(
            '/' . Text::BOLD_OPEN . '(.*?)' . Text::BOLD_CLOSE . '/su',
            $chunk,
            $m
        )) {
            return false;
        }
        return Text::hasLatin($m[1]) && !Text::hasCyrillic($m[1]);
    }

    /** A short line of pure Latin among Russian prose is the headword. */
    private function hasLatinOnlyLine(string $chunk): bool
    {
        foreach (preg_split('/\R/u', $chunk) ?: [] as $line) {
            $line = trim(Text::stripBoldMarkers($line));
            if ($line !== '' && Text::hasLatin($line) && !Text::hasCyrillic($line)
                && Text::wordCount($line) <= 8) {
                return true;
            }
        }
        return false;
    }
}
