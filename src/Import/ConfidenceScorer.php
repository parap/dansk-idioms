<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Scores how much to trust a parse. Every signal is recorded on the entry so the
 * thresholds can be retuned later from stored rows, without re-parsing the export.
 */
final class ConfidenceScorer
{
    private const BASE = [
        'bold'      => 0.95,
        'script'    => 0.90,
        'separator' => 0.70,
        'newline'   => 0.85,
        'fallback'  => 0.35,
    ];

    public function score(ParsedEntry $entry): void
    {
        $signals = [];
        $score = self::BASE[$entry->strategy ?? 'fallback'] ?? 0.35;
        $term = $entry->term ?? '';
        $expl = $entry->explanation ?? '';

        $add = static function (string $name, float $delta) use (&$signals, &$score): void {
            $signals[$name] = $delta;
            $score += $delta;
        };

        if ($term !== '' && preg_match('/[æøåÆØÅ]/u', $term))       $add('danish_letters', 0.05);
        if (preg_match('/^at\s+/ui', $term))                        $add('infinitive_at', 0.05);

        $words = Text::wordCount($term);
        if ($words >= 1 && $words <= 6)                             $add('term_1_6_words', 0.05);
        if (preg_match('/«[^»]+»/u', $expl))                        $add('has_quoted', 0.05);
        if (mb_strlen($expl, 'UTF-8') >= 30)                        $add('explanation_len', 0.03);
        if ($entry->labels !== [])                                  $add('has_label', 0.08);

        // Penalties: each of these means the split almost certainly went wrong.
        if ($term === '')                                           $add('empty_term', -0.60);
        if (Text::hasCyrillic($term))                               $add('cyrillic_in_term', -0.30);
        if ($words > 8 || mb_strlen($term, 'UTF-8') > 60)           $add('term_too_long', -0.35);
        if (preg_match('/\d|https?:|[@#]/u', $term))                $add('term_has_noise', -0.30);
        if ($expl !== '' && mb_strlen($expl, 'UTF-8') < 12)         $add('explanation_short', -0.25);
        if ($expl !== '' && !Text::hasCyrillic($expl))              $add('explanation_no_cyrillic', -0.30);
        if ($expl === '' && $entry->labels === [])                  $add('no_explanation', -0.40);

        $entry->signals    = $signals;
        $entry->confidence = max(0.0, min(1.0, round($score, 3)));
    }
}
