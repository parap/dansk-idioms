<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Import\Normalizer;
use Dansk\Import\Text;
use Dansk\Support\Db;

/**
 * Chooses the wrong answers.
 *
 * This is the part that decides whether the quiz teaches anything. With uniformly
 * random distractors the correct option is identifiable without knowing Danish --
 * by length, register, grammatical shape or sheer topical mismatch -- and the user
 * learns "pick the plausible-looking one" instead of the idiom.
 */
final class DistractorService
{
    private const POOL_SIZE      = 200;
    private const SHORTLIST      = 12;
    private const WORD_TOLERANCE = 3;
    private const JACCARD_LIMIT  = 0.34;
    private const LITERAL_SLOT_P = 40;   // percent of questions that get a literal gloss

    /** Russian function words carry no meaning for overlap comparison. */
    private const STOPWORDS = [
        'быть','что','как','кого','кому','чего','чему','это','этот','свой','своё','свои',
        'кто','то','либо','нибудь','и','или','в','во','на','с','со','к','по','за','от','до',
        'для','из','о','об','у','не','ни','же','бы','а','но','делать','сделать','очень',
    ];

    /**
     * Wrong Danish terms, for a round that prompts with the meaning and asks for the
     * idiom. Plausibility rests on different signals from the forward direction: the
     * options are Danish, so shape parity means the infinitive marker rather than a
     * Russian verb ending, and overlap is measured on Danish words.
     *
     * @param array<string,mixed> $correct        the correct idiom row
     * @param list<int>           $excludeIdioms  the other idioms in this round
     * @return list<array<string,mixed>>
     */
    public function pickTerms(array $correct, array $excludeIdioms, int $count = 3): array
    {
        $exclude = array_values(array_unique(array_merge($excludeIdioms, [(int) $correct['idiom_id']])));
        $holes   = implode(',', array_fill(0, count($exclude), '?'));

        $params = $exclude;
        $sql = "SELECT i.id AS idiom_id, i.term, i.term_norm, i.kind, i.register, i.shape
                FROM idioms i
                WHERE i.is_published = 1
                  AND i.id NOT IN ($holes)
                  AND EXISTS (SELECT 1 FROM idiom_translations t
                              WHERE t.idiom_id = i.id AND t.is_primary = 1 AND t.quiz_usable = 1)";

        // An idiom that means the same thing is a second correct answer, not a distractor.
        $sql .= ' AND i.id NOT IN (
                     SELECT s2.idiom_id FROM idiom_synonyms s1
                     JOIN idiom_synonyms s2 ON s2.group_id = s1.group_id
                     WHERE s1.idiom_id = ?)';
        $params[] = (int) $correct['idiom_id'];

        // Infinitive parity, the Danish counterpart of verb parity: an "at ..." phrase
        // among bare nouns is identifiable without knowing what either means. The term's
        // own shape, not the translation's -- they disagree often.
        $sql .= ($correct['term_shape'] ?? '') === 'verbal'
            ? " AND i.shape = 'verbal'"
            : " AND i.shape <> 'verbal'";

        $sql .= ' ORDER BY RAND() LIMIT ' . self::POOL_SIZE;

        $pool = $this->rejectSharedDanishWords(Db::fetchAll($sql, $params), (string) $correct['term']);
        if (count($pool) < $count) {
            return [];
        }

        $correctWords = Text::wordCount((string) $correct['term']);
        $correctChars = mb_strlen((string) $correct['term'], 'UTF-8');

        foreach ($pool as &$candidate) {
            $words = Text::wordCount((string) $candidate['term']);
            $chars = mb_strlen((string) $candidate['term'], 'UTF-8');
            $candidate['_score'] =
                  0.40 * (1.0 - min(1.0, abs($words - $correctWords) / 5))
                + 0.25 * (1.0 - min(1.0, abs($chars - $correctChars) / 30))
                + 0.20 * ($candidate['kind']     === $correct['kind']     ? 1 : 0)
                + 0.10 * ($candidate['register'] === $correct['register'] ? 1 : 0)
                + 0.05 * (mt_rand() / mt_getrandmax());
        }
        unset($candidate);

        usort($pool, static fn(array $a, array $b): int => $b['_score'] <=> $a['_score']);
        $shortlist = array_slice($pool, 0, max($count, self::SHORTLIST));
        shuffle($shortlist);

        return array_slice($shortlist, 0, $count);
    }

    /**
     * Two Danish idioms sharing a content word give the answer away by echo, and may
     * genuinely overlap in meaning. The infinitive marker and common prepositions carry
     * no information, so they are ignored.
     *
     * @param list<array<string,mixed>> $pool
     * @return list<array<string,mixed>>
     */
    private function rejectSharedDanishWords(array $pool, string $correctTerm): array
    {
        $stop = ['at','sig','en','et','den','det','de','i','på','til','af','med','for','om','og','er','som'];
        $words = static function (string $term) use ($stop): array {
            $out = [];
            foreach (preg_split('/\s+/u', mb_strtolower($term, 'UTF-8')) ?: [] as $word) {
                $word = Text::trimPunctuation($word, false);
                if ($word !== '' && mb_strlen($word, 'UTF-8') > 2 && !in_array($word, $stop, true)) {
                    $out[$word] = true;
                }
            }
            return $out;
        };

        $correctWords = $words($correctTerm);

        return array_values(array_filter($pool, static function (array $c) use ($words, $correctWords): bool {
            return array_intersect_key($correctWords, $words((string) $c['term'])) === [];
        }));
    }

    /**
     * @param array<string,mixed> $correct        the winning translation row
     * @param list<int>           $excludeIdioms  the other idioms in this round
     * @return list<array<string,mixed>>
     */
    public function pick(array $correct, array $excludeIdioms, string $lang = 'ru', int $count = 3): array
    {
        $pool = $this->candidatePool($correct, $excludeIdioms, $lang);
        $pool = $this->rejectNearDuplicates($pool, (string) $correct['text']);

        if (count($pool) < $count) {
            // Relax rather than serve a question with fewer than four options.
            $pool = $this->rejectNearDuplicates(
                $this->candidatePool($correct, $excludeIdioms, $lang, relaxed: true),
                (string) $correct['text']
            );
        }
        if (count($pool) < $count) {
            return [];
        }

        foreach ($pool as &$candidate) {
            $candidate['_score'] = $this->score($candidate, $correct);
        }
        unset($candidate);

        usort($pool, static fn(array $a, array $b): int => $b['_score'] <=> $a['_score']);
        $shortlist = array_slice($pool, 0, max($count, self::SHORTLIST));

        // A literal gloss of a *different* idiom is register-matched, idiom-shaped,
        // vivid and guaranteed wrong -- it punishes picking the most colourful option.
        $chosen = [];
        if (random_int(1, 100) <= self::LITERAL_SLOT_P) {
            foreach ($shortlist as $i => $c) {
                if ($c['sense_type'] === 'literal') {
                    $chosen[] = $c;
                    unset($shortlist[$i]);
                    break;
                }
            }
        }

        $shortlist = array_values($shortlist);
        shuffle($shortlist);
        foreach ($shortlist as $c) {
            if (count($chosen) >= $count) {
                break;
            }
            // Never two options from the same idiom.
            foreach ($chosen as $already) {
                if ($already['idiom_id'] === $c['idiom_id']) {
                    continue 2;
                }
            }
            $chosen[] = $c;
        }

        return count($chosen) >= $count ? array_slice($chosen, 0, $count) : [];
    }

    /** @return list<array<string,mixed>> */
    private function candidatePool(array $correct, array $excludeIdioms, string $lang, bool $relaxed = false): array
    {
        $exclude = array_values(array_unique(array_merge($excludeIdioms, [(int) $correct['idiom_id']])));
        $holes   = implode(',', array_fill(0, count($exclude), '?'));

        $params = [$lang];
        $sql = "SELECT t.id, t.idiom_id, t.text, t.text_norm, t.sense_type, t.shape,
                       t.word_count, t.char_count, i.register, i.kind
                FROM idiom_translations t
                JOIN idioms i ON i.id = t.idiom_id AND i.is_published = 1
                WHERE t.lang_code = ?
                  AND (t.quiz_usable = 1 OR t.sense_type = 'literal')
                  AND t.idiom_id NOT IN ($holes)";
        $params = array_merge($params, $exclude);

        if (!$relaxed) {
            // CAST to SIGNED: word_count is TINYINT UNSIGNED, and unsigned subtraction
            // wraps around instead of going negative (MySQL error 1690).
            $sql .= ' AND ABS(CAST(t.word_count AS SIGNED) - ?) <= ' . self::WORD_TOLERANCE;
            $params[] = (int) $correct['word_count'];
        }

        // Verb parity is a HARD filter and holds even in the relaxed pass. Offering
        // noun phrases against a verbal answer lets anyone discard them on sight
        // without knowing a word of Danish, so a question that cannot find matching
        // options is better skipped than served -- QuizService drops that idiom and
        // uses another. Only the length window is relaxed.
        $sql .= ($correct['shape'] ?? '') === 'verbal'
            ? " AND t.shape = 'verbal'"
            : " AND t.shape <> 'verbal'";

        // Declared synonyms of the correct idiom would be genuinely correct.
        $sql .= ' AND t.idiom_id NOT IN (
                     SELECT s2.idiom_id FROM idiom_synonyms s1
                     JOIN idiom_synonyms s2 ON s2.group_id = s1.group_id
                     WHERE s1.idiom_id = ?)';
        $params[] = (int) $correct['idiom_id'];

        // Blocks learned from "this was also correct" reports.
        $sql .= ' AND t.id NOT IN (
                     SELECT blocked_tr_id FROM distractor_blocks
                     WHERE correct_tr_id = ? AND is_active = 1)';
        $params[] = (int) $correct['id'];

        $sql .= ' ORDER BY RAND() LIMIT ' . self::POOL_SIZE;

        return Db::fetchAll($sql, $params);
    }

    /**
     * Drops candidates that might actually be right. This is the layer that prevents
     * mis-grading, as opposed to merely making the question easy.
     */
    private function rejectNearDuplicates(array $pool, string $correctText): array
    {
        $correctTokens = $this->tokens($correctText);
        $correctNorm   = Normalizer::translation($correctText);

        return array_values(array_filter($pool, function (array $c) use ($correctTokens, $correctNorm): bool {
            $norm = (string) $c['text_norm'];
            if ($norm === '' || $norm === $correctNorm) {
                return false;
            }
            if (str_contains($norm, $correctNorm) || str_contains($correctNorm, $norm)) {
                return false;
            }
            return $this->jaccard($correctTokens, $this->tokens((string) $c['text'])) < self::JACCARD_LIMIT;
        }));
    }

    private function score(array $c, array $correct): float
    {
        $lengthSim = 1.0 - min(1.0, abs((int) $c['word_count'] - (int) $correct['word_count']) / 5);
        $charSim   = 1.0 - min(1.0, abs((int) $c['char_count'] - (int) $correct['char_count']) / 40);

        // Shape carries the most weight: offering noun phrases against a verbal
        // idiom lets the user pick the odd one out without knowing any Danish.
        return 0.35 * ($c['shape']    === $correct['shape']    ? 1 : 0)
             + 0.25 * (($lengthSim + $charSim) / 2)
             + 0.20 * ($c['register'] === $correct['register'] ? 1 : 0)
             + 0.12 * ($c['kind']     === $correct['kind']     ? 1 : 0)
             + 0.08 * (mt_rand() / mt_getrandmax());
    }

    /** @return array<string,true> */
    private function tokens(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', Normalizer::translation($s)) ?: [] as $word) {
            $word = Text::trimPunctuation($word, false);
            if ($word !== '' && mb_strlen($word, 'UTF-8') > 2 && !in_array($word, self::STOPWORDS, true)) {
                $out[$word] = true;
            }
        }
        return $out;
    }

    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect_key($a, $b));
        $union        = count($a + $b);
        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
