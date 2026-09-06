<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Splits one entry into term / note / explanation.
 *
 * The load-bearing observation: the Danish term is Latin, the Russian explanation is
 * Cyrillic, so the separator always lies BEFORE the first Cyrillic character. Confining
 * the search to that prefix makes interior colons and dashes structurally unreachable
 * ("устойчивое сочетание: «сжатый кулак»", "Fanden — «дьявол, чёрт»") with no heuristic.
 */
final class EntryParser
{
    /** Explicit Russian field labels; far more reliable than inferring from phrasing. */
    private const LABELS = ['Значение', 'Объяснение', 'Перевод', 'Дословно', 'Этимология', 'Пример'];

    /** Rightmost match in the highest non-empty tier wins. */
    private const SEPARATORS = [
        ['em_dash', '/\s+[—–]\s+/u'],
        ['colon',   '/\s*:\s+/u'],
        ['hyphen',  '/\s+-{1,2}\s+/u'],
        ['hyphen',  '/\s*=\s+/u'],
        ['en_dash', '/[—–]/u'],
    ];

    public function parse(string $entryText): ParsedEntry
    {
        $entry = new ParsedEntry(rawText: $entryText);

        [$head, $labels, $trailing] = $this->splitLabels($entryText);
        $entry->labels = $labels;

        $this->extractTerm($entry, $head);

        $entry->headRemainder = $entry->explanation;

        $parts = array_filter([
            $entry->explanation,
            $trailing !== '' ? $trailing : null,
            ...array_values($labels),
        ]);
        $entry->explanation = $parts === [] ? null : implode("\n", $parts);

        $this->harvestInflected($entry);
        (new ConfidenceScorer())->score($entry);

        return $entry;
    }

    /**
     * @return array{0:string, 1:array<string,string>, 2:string}  head, labels, unlabelled trailing
     */
    private function splitLabels(string $entryText): array
    {
        $lines  = preg_split('/\R/u', $entryText) ?: [];
        $labels = [];
        $trail  = [];
        $currentLabel = null;

        $pattern = '/^\s*(' . implode('|', self::LABELS) . ')\s*:\s*(.*)$/su';

        // The head is every leading line up to the first labelled one. It is not just
        // line 0: an entry may carry its headword on its own line under Russian prose
        // ("Конструкция" / "få at vide" / "(буквально: ...)").
        $headLines = [];
        while ($lines !== [] && !preg_match($pattern, trim($lines[0]))) {
            $headLines[] = trim((string) array_shift($lines));
        }
        $head = trim(implode("\n", $headLines));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match($pattern, $line, $m)) {
                $currentLabel = $m[1];
                $labels[$currentLabel] = trim($m[2]);
                continue;
            }
            if ($currentLabel !== null) {
                $labels[$currentLabel] = trim($labels[$currentLabel] . ' ' . $line);
            } else {
                $trail[] = $line;
            }
        }

        // A label can also appear inline on the head line.
        if (preg_match($pattern, $head, $m)) {
            $labels[$m[1]] = trim($m[2] . ' ' . ($labels[$m[1]] ?? ''));
            $head = '';
        }

        return [$head, $labels, implode("\n", $trail)];
    }

    private function extractTerm(ParsedEntry $entry, string $head): void
    {
        if ($head === '') {
            $entry->strategy = 'fallback';
            $entry->separatorKind = 'none';
            return;
        }

        // --- Strategy A: a Latin-only bold span is the headword ------------------
        if (preg_match(
            '/' . Text::BOLD_OPEN . '\s*(.*?)\s*' . Text::BOLD_CLOSE . '/su',
            $head,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $boldText = $m[1][0];
            if (Text::hasLatin($boldText) && !Text::hasCyrillic($boldText) && Text::wordCount($boldText) <= 10) {
                $entry->strategy = 'bold';
                $entry->separatorKind = 'bold_span';
                $this->assignTerm($entry, $boldText);

                $after = mb_substr(
                    Text::stripBoldMarkers($head),
                    Text::byteToChar($head, $m[0][1]) + mb_strlen($boldText, 'UTF-8'),
                    null,
                    'UTF-8'
                );
                $entry->explanation = $this->trimSeparator($after);
                return;
            }
        }

        $plain  = Text::stripBoldMarkers($head);

        // --- Strategy: Russian prose with the headword alone on its own line -------
        if (Text::openingScript($plain) === 'cyr') {
            $lines = preg_split('/\R/u', $plain) ?: [];
            foreach ($lines as $i => $line) {
                $line = trim($line);
                if ($line === '' || Text::hasCyrillic($line) || !Text::hasLatin($line)
                    || Text::wordCount($line) > 8) {
                    continue;
                }
                $entry->strategy = 'newline';
                $entry->separatorKind = 'newline';
                $this->assignTerm($entry, $line);
                unset($lines[$i]);
                $entry->explanation = $this->trimSeparator(trim(implode(' ', $lines)));
                return;
            }
        }

        $masked = Text::maskParentheticals($plain);

        // --- Strategy C: no Cyrillic outside parentheticals -> whole head is term --
        if (!Text::hasCyrillic($masked)) {
            $entry->strategy = 'separator';
            $entry->separatorKind = 'none';
            $this->assignTerm($entry, $plain);
            $entry->explanation = null;
            return;
        }

        // --- Strategy B: separator inside the pre-Cyrillic prefix -----------------
        preg_match('/\p{Cyrillic}/u', $masked, $cm, PREG_OFFSET_CAPTURE);
        $cyrChar = Text::byteToChar($masked, $cm[0][1]);
        $prefix  = mb_substr($masked, 0, $cyrChar, 'UTF-8');

        foreach (self::SEPARATORS as [$kind, $pattern]) {
            if (!preg_match_all($pattern, $prefix, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $last      = end($mm[0]);
            $sepStart  = Text::byteToChar($prefix, $last[1]);
            $sepLength = mb_strlen($last[0], 'UTF-8');

            $entry->strategy = 'script';
            $entry->separatorKind = $kind;
            $this->assignTerm($entry, mb_substr($plain, 0, $sepStart, 'UTF-8'));
            $entry->explanation = trim(mb_substr($plain, $sepStart + $sepLength, null, 'UTF-8'));
            return;
        }

        // --- Strategy D: split at the first Cyrillic character --------------------
        $entry->strategy = 'fallback';
        $entry->separatorKind = 'none';
        $this->assignTerm($entry, mb_substr($plain, 0, $cyrChar, 'UTF-8'));
        $entry->explanation = trim(mb_substr($plain, $cyrChar, null, 'UTF-8'));
    }

    /** Pulls parentheticals out of the term into termNote / inflectedForm. */
    private function assignTerm(ParsedEntry $entry, string $raw): void
    {
        $raw = trim($raw);

        if (preg_match_all('/\(([^()]*)\)|\[([^\[\]]*)\]/u', $raw, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $inner = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
                if ($inner === '') {
                    continue;
                }
                // "(в тексте: er i sit es)" is the inflected form as it appeared in
                // the source text -- the only attested usage the corpus contains.
                if (preg_match('/^в\s+тексте\s*:?\s*(.+)$/ui', $inner, $im)) {
                    $entry->inflectedForm = trim($im[1]);
                } else {
                    $entry->termNote = $entry->termNote === null
                        ? $inner
                        : $entry->termNote . '; ' . $inner;
                }
            }
            $raw = preg_replace('/\([^()]*\)|\[[^\[\]]*\]/u', ' ', $raw) ?? $raw;
        }

        $entry->term = Text::collapseWhitespace(
            Text::trimPunctuation($raw)
        );
    }

    /**
     * "(в тексте: bryder sammen i sorg)" also occurs at the head of the explanation
     * rather than inside the term, so it has to be harvested from both sides.
     */
    private function harvestInflected(ParsedEntry $entry): void
    {
        if ($entry->inflectedForm !== null || $entry->explanation === null) {
            return;
        }
        if (preg_match('/\(\s*в\s+тексте\s*:?\s*([^()]+)\)/ui', $entry->explanation, $m)) {
            $entry->inflectedForm = trim($m[1]);
            $entry->explanation = trim(preg_replace(
                '/\(\s*в\s+тексте\s*:?\s*[^()]+\)/ui', '', $entry->explanation, 1
            ) ?? $entry->explanation);
        }
    }

    private function trimSeparator(string $s): string
    {
        return trim(preg_replace('/^\s*[—–:\-]\s*/u', '', trim($s)) ?? $s);
    }
}
