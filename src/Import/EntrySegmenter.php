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
