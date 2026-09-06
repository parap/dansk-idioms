<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Pulls short, quiz-usable translations out of a Russian explanation.
 *
 * The trap this exists to avoid: in
 *   "fandeme — ... (происходит от Fanden — «дьявол, чёрт»). Переводится как «чёрт возьми»..."
 * the span «дьявол, чёрт» glosses *Fanden*, a different word, not the headword. A naive
 * "collect every «...»" pass teaches fandeme = дьявол. Spans introduced by происходит от /
 * заимствован, or sitting inside a parenthetical, are therefore classed as 'gloss' and
 * excluded from quiz use.
 */
final class TranslationExtractor
{
    private const MAX_QUIZ_WORDS = 6;
    private const MAX_QUIZ_CHARS = 60;

    private const LITERAL_CUE   = '/(дословно|буквально|букв\.|дословный перевод)\s*[:\-–—]?\s*$/ui';
    /**
     * Introduces a gloss of some OTHER word -- a component of the idiom, or its
     * etymological source -- never of the headword itself. "Слово grus означает
     * «гравий, щебень, труха»" defines grus, not "at få verden til at styrte i grus".
     * These cues must be tested before the idiomatic ones, since they also contain
     * "означает".
     */
    private const GLOSS_CUE = '/(происходит от|заимствован\w*|этимолог\w*'
        . '|слов[оаеу]\s+\S+\s+(означа\w*|значит|переводится|это)\s*'
        . '|\p{Latin}+\s+(означа\w*|значит|переводится)\s*'
        . '|от\s+\p{Latin}+\s*)$/ui';
    /**
     * Lexicographic meta-description, not a translation. These open most Значение
     * values ("Устойчивое идиоматическое выражение, описывающее ...") and would
     * otherwise be served as the correct answer.
     */
    private const META_DESCRIPTION = '/^\s*(устойчив\w*|разговорн\w*|идиоматическ\w*|идиом\w*|фразеологизм\w*|фразеологическ\w*|словосочетани\w*|выражени\w*|оборот\w*|конструкци\w*|глагольн\w*|глагол|существительн\w*|прилагательн\w*|наречи\w*|частиц\w*|союз\w*|предлог\w*|междомети\w*|описани\w*|обозначени\w*|термин\w*|сокращени\w*|заимствован\w*|возвратн\w*|собирательн\w*|формальн\w*|неформальн\w*|литературн\w*|поговорк\w*|пословиц\w*)\b/ui';

    private const IDIOMATIC_CUE = '/(переводится как|означает\w*|означающ\w+|значит|в значении|аналог\w*|смысл\w*)\s*[:\-–—]?\s*$/ui';

    /** @return list<array{text:string,sense_type:string,quiz_usable:bool,is_primary:bool,confidence:float}> */
    public function extract(ParsedEntry $entry): array
    {
        $candidates = [];

        // 1. The head-line remainder. In the two-line shape
        //      "slået ud af kurs — сбит с курса / сбит с толку"
        //      "Значение: <long prose>"
        //    the translation is here and Значение is merely explanation, so this
        //    outranks the labels. Quoted spans inside it are handled in step 3.
        $head = $entry->headRemainder;
        if ($head !== null && $head !== '' && !str_contains($head, '«')) {
            $clause = $this->firstClause($head);
            if (mb_strlen($clause, 'UTF-8') <= 90) {
                foreach ($this->splitVariants($clause) as $v) {
                    // Above every quoted-span confidence: the head line is the author's
                    // own translation of THIS idiom, while a «...» further down may be
                    // glossing something else entirely.
                    $candidates[] = $this->make($v, 'idiomatic', 0.97);
                }
            }
        }

        // 2. Explicit labels. Значение is often descriptive prose rather than a
        //    translation, so it ranks below the head line but above bare quotes.
        foreach (['Перевод' => 0.95, 'Значение' => 0.88] as $label => $conf) {
            $value = $entry->label($label);
            if ($value === null || $value === '') {
                continue;
            }
            // Every clause, not only the first. A meaning is often given as several
            // ";"-separated readings and the usable one is not always leading:
            // "Разбираться в коробках с хаотично сваленными вещами; находить ценное
            // среди хлама" -- the first clause is too long to be an option, the
            // second is exactly right. Later clauses rank slightly lower.
            foreach ($this->clauses($value) as $i => $clause) {
                foreach ($this->splitVariants($clause) as $v) {
                    $candidates[] = $this->make($v, 'idiomatic', max(0.5, $conf - 0.03 * $i));
                }
            }
        }
        if (($lit = $entry->label('Дословно')) !== null && $lit !== '') {
            $candidates[] = $this->make($this->firstClause($lit), 'literal', 0.9);
        }

        // 2. Quoted spans in the explanation, classified by what introduces them.
        $explanation = $entry->explanation ?? '';
        if ($explanation !== '') {
            foreach ($this->quotedSpans($explanation) as [$text, $sense, $conf]) {
                foreach ($this->splitVariants($text) as $v) {
                    $candidates[] = $this->make($v, $sense, $conf);
                }
            }
        }

        // 3. Nothing quoted or labelled: take the clause after an explicit cue.
        if ($this->countUsable($candidates) === 0 && $explanation !== '') {
            if (preg_match(
                '/(?:переводится как|означает|значит)\s*[:\-–—]?\s*([^.;]{2,60})/ui',
                $explanation, $m
            )) {
                $candidates[] = $this->make(trim($m[1]), 'idiomatic', 0.6);
            }
        }

        $candidates = $this->rejectTransliterations($candidates, (string) ($entry->term ?? ''));

        return $this->dedupeAndRank($candidates);
    }

    /**
     * An "answer" that merely respells the Danish word in Cyrillic is explaining the
     * idiom, not translating it: "экспертом в родекассерах" for
     * "at være ekspert i rodekasser". Such text stays as a candidate -- it is real
     * content from the post -- but must never be offered as the correct answer.
     */
    private function rejectTransliterations(array $candidates, string $term): array
    {
        $stems = [];
        foreach (preg_split('/\s+/u', $term) ?: [] as $word) {
            $word = Text::trimPunctuation($word);
            if (mb_strlen($word, 'UTF-8') < 5) {
                continue;
            }
            $stem = $this->loosen(Text::translitToCyrillic($word));
            if (mb_strlen($stem, 'UTF-8') >= 6) {
                $stems[] = mb_substr($stem, 0, 6, 'UTF-8');
            }
        }
        if ($stems === []) {
            return $candidates;
        }

        foreach ($candidates as &$candidate) {
            if (!$candidate['quiz_usable']) {
                continue;
            }
            foreach (preg_split('/\s+/u', $candidate['text']) ?: [] as $token) {
                $token = $this->loosen(Text::trimPunctuation($token));
                foreach ($stems as $stem) {
                    if (mb_strlen($token, 'UTF-8') >= 6 && str_starts_with($token, $stem)) {
                        $candidate['quiz_usable'] = false;
                        $candidate['is_primary']  = false;
                        continue 3;
                    }
                }
            }
        }
        unset($candidate);

        return $candidates;
    }

    /** Folds Cyrillic letters that transliteration cannot reliably choose between. */
    private function loosen(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), ['э'=>'е','ё'=>'е','й'=>'и','ъ'=>'','ь'=>'']);
    }

    /** @return list<array{0:string,1:string,2:float}> */
    private function quotedSpans(string $text): array
    {
        if (!preg_match_all('/[«„"“]([^»„"“”]{1,90})[»“”"]/u', $text, $mm, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $masked = Text::maskParentheticals($text);
        $out = [];

        foreach ($mm[1] as $i => $capture) {
            $value = trim($capture[0]);
            if ($value === '') {
                continue;
            }

            // A quoted Danish word is the term being cited, not a translation.
            if (Text::latinRatio($value) >= 0.5) {
                continue;
            }

            $openByte = $mm[0][$i][1];
            $charPos  = Text::byteToChar($text, $openByte);
            $before   = mb_substr($text, max(0, $charPos - 50), min(50, $charPos), 'UTF-8');

            // Inside a parenthetical -> it explains an aside, not the headword.
            $insideParens = mb_substr($masked, $charPos, 1, 'UTF-8') === "\x01";

            [$sense, $conf] = match (true) {
                $insideParens                              => ['gloss', 0.5],
                (bool) preg_match(self::GLOSS_CUE, $before)     => ['gloss', 0.5],
                (bool) preg_match(self::LITERAL_CUE, $before)   => ['literal', 0.85],
                (bool) preg_match(self::IDIOMATIC_CUE, $before) => ['idiomatic', 0.95],
                default                                    => ['idiomatic', 0.7],
            };

            $out[] = [$value, $sense, $conf];
        }

        return $out;
    }

    /** Enumerations: «стереть ухмылку», «сбить спесь / заехать по физиономии» -> separate rows. */
    private function splitVariants(string $s): array
    {
        $parts = preg_split('~\s+/\s+~u', $s) ?: [$s];
        $out = [];
        foreach ($parts as $part) {
            $part = Text::trimPunctuation($part, false);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out === [] ? [] : $out;
    }

    /**
     * Splits a label value into its clauses, most promising first: the leading one,
     * then any others short enough to serve as an option.
     *
     * @return list<string>
     */
    private function clauses(string $value): array
    {
        $out = [$this->firstClause($value)];
        foreach (preg_split('/[;.]\s+/u', trim($value)) ?: [] as $part) {
            $part = Text::trimPunctuation(Text::collapseWhitespace($part), false);
            if ($part === '' || in_array($part, $out, true)) {
                continue;
            }
            if (Text::wordCount($part) <= self::MAX_QUIZ_WORDS
                && mb_strlen($part, 'UTF-8') <= self::MAX_QUIZ_CHARS) {
                $out[] = $part;
            }
        }
        return array_slice($out, 0, 4);
    }

    /** First sentence/clause only -- explanations run long after the meaning is given. */
    private function firstClause(string $s): string
    {
        $s = trim($s);

        // A head remainder often continues onto further lines with commentary
        // ("прогноз погоды\nЭто сложное слово..."). Only the first line is the meaning.
        $s = trim(preg_split('/\R/u', $s)[0] ?? $s);

        // Some entries give the meaning wholly inside parentheses, sometimes with the
        // author's letter-count hint appended: "(поздравления / пожелания — 13 букв)".
        if (preg_match('/^\((.+)\)$/su', $s, $m)) {
            $s = trim($m[1]);
        }
        $s = trim(preg_replace('/\s*[—–-]\s*\d+\s*букв\w*\s*$/u', '', $s) ?? $s);

        if (mb_strlen($s, 'UTF-8') <= self::MAX_QUIZ_CHARS) {
            return $s;
        }
        $cut = preg_split('/(?<=[.;])\s+|\s+\(/u', $s, 2);
        $first = trim($cut[0] ?? $s);
        if (mb_strlen($first, 'UTF-8') <= self::MAX_QUIZ_CHARS) {
            return $first;
        }
        // Still long: take the leading comma-clause, which in this corpus is the
        // headline meaning with elaborations trailing after it.
        $comma = preg_split('/,\s+/u', $first, 2);
        return trim($comma[0] ?? $first);
    }

    private function make(string $text, string $sense, float $confidence): array
    {
        // Final guard: guillemets can survive when a variant was not split, and they
        // would otherwise be shown to the user as part of the answer.
        $text  = Text::collapseWhitespace(Text::trimPunctuation($text, false));
        $words = Text::wordCount($text);
        $chars = mb_strlen($text, 'UTF-8');

        // Only the idiomatic reading may be a correct answer. A literal gloss
        // ("ударить себя вместе") is retained because it is an excellent distractor,
        // but offering it as the answer would teach the wrong meaning outright.
        $usable = $sense === 'idiomatic'
            && !preg_match(self::META_DESCRIPTION, $text)
            && !$this->isLexicographicPhrase($text, $words)
            && $words > 0 && $words <= self::MAX_QUIZ_WORDS
            && $chars <= self::MAX_QUIZ_CHARS
            && !str_contains($text, '…')
            && !str_contains($text, '...')
            && Text::latinRatio($text) < 0.5;

        return [
            'text'        => $text,
            'sense_type'  => $sense,
            'quiz_usable' => $usable,
            'is_primary'  => false,
            'confidence'  => $confidence,
        ];
    }

    /**
     * Catches meta-description that does not start with the giveaway noun:
     * "Яркое метафорическое выражение" leads with adjectives, so an anchored
     * pattern misses it. Any short phrase whose head noun is grammatical
     * terminology is describing the idiom, not translating it.
     */
    private function isLexicographicPhrase(string $text, int $words): bool
    {
        return $words <= 6 && (bool) preg_match(
            '/(выражени\w*|оборот\w*|словосочетани\w*|фразеологизм\w*|конструкци\w*'
            . '|идиом\w*|глагол\w*|существительн\w*|прилагательн\w*|наречи\w*'
            . '|частиц\w*|союз\w*|предлог\w*|междомети\w*|термин\w*'
            . '|поговорк\w*|пословиц\w*|описани\w*|обозначени\w*)/ui',
            $text
        );
    }

    private function countUsable(array $c): int
    {
        return count(array_filter($c, static fn(array $x): bool => $x['quiz_usable']));
    }

    private function dedupeAndRank(array $candidates): array
    {
        $seen = [];
        $out  = [];
        foreach ($candidates as $c) {
            if ($c['text'] === '') {
                continue;
            }
            $key = Normalizer::translation($c['text']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $c;
        }

        // Primary: the highest-confidence quiz-usable idiomatic reading.
        $bestIndex = null;
        foreach ($out as $i => $c) {
            if (!$c['quiz_usable'] || $c['sense_type'] !== 'idiomatic') {
                continue;
            }
            if ($bestIndex === null || $c['confidence'] > $out[$bestIndex]['confidence']) {
                $bestIndex = $i;
            }
        }
        if ($bestIndex !== null) {
            $out[$bestIndex]['is_primary'] = true;
        }

        return $out;
    }
}
