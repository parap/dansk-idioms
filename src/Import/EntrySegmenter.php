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
        $pieces  = [];
        $current = null;

        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                continue;
            }

            $opens = preg_match('/^\p{Latin}/u', trim(Text::stripBoldMarkers($line))) === 1;
            if ($current === null || $opens) {
                if ($current !== null) {
                    $pieces[] = $current;
                }
                $current = $line;
                continue;
            }

            $current .= "\n" . $line;
        }

        if ($current !== null) {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Whether this text can be split with confidence.
     *
     * Splitting is decided by U+200B, which prefixes every structural line in the
     * group's own posts -- 190 of its 199 messages carry it. Text typed fresh with
     * plain newlines carries none, and then the segmenter sees one entry: the first
     * headword becomes the term and everything after it becomes that term's
     * explanation. Nothing errors, and the corpus gains a plausible lie.
     *
     * So one headword is always safe -- one idiom and its explanation is one entry
     * however it was typed -- while several headwords with no separator are not.
     */
    public function boundariesAreClear(string $text): bool
    {
        if (str_contains($text, Text::ZWSP)) {
            return true;
        }

        $heads = 0;
        foreach (explode("\n", $text) as $line) {
            $line = trim(Text::stripBoldMarkers($line));
            if ($line !== '' && preg_match('/^\p{Latin}/u', $line) === 1) {
                $heads++;
            }
        }

        return $heads <= 1;
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
