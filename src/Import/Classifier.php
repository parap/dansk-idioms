<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Assigns kind / register / shape from the Russian explanation's own vocabulary.
 * Deliberately crude: there is no lemmatizer available and none is needed, because
 * these feed distractor matching rather than anything user-visible.
 */
final class Classifier
{
    public function kind(ParsedEntry $entry): string
    {
        $t = mb_strtolower(($entry->explanation ?? '') . ' ' . implode(' ', $entry->labels), 'UTF-8');
        $words = Text::wordCount($entry->term ?? '');

        return match (true) {
            (bool) preg_match('/пословиц|поговорк/u', $t)                        => 'proverb',
            (bool) preg_match('/частиц|междомети|усилительн/u', $t) && $words <= 2 => 'particle',
            (bool) preg_match('/заимствован\w* из англ|английск\w* идиом/u', $t)  => 'borrowed',
            (bool) preg_match('/идиом|фразеологизм|фразеологическ/u', $t)         => 'idiom',
            (bool) preg_match('/устойчив\w* (сочетани|выражени|оборот)|словосочетани|коллокаци/u', $t) => 'collocation',
            $words <= 1                                                          => 'word',
            default                                                              => 'phrase',
        };
    }

    public function register(ParsedEntry $entry): string
    {
        $t = mb_strtolower(($entry->explanation ?? '') . ' ' . implode(' ', $entry->labels), 'UTF-8');

        return match (true) {
            (bool) preg_match('/бранн|груб|вульгар|нецензур|матерн/u', $t) => 'vulgar',
            (bool) preg_match('/сленг|жаргон/u', $t)                      => 'slang',
            (bool) preg_match('/разг\.|разговорн|неформальн/u', $t)       => 'colloquial',
            (bool) preg_match('/книжн|литературн|высок\w* стил/u', $t)    => 'literary',
            default                                                       => 'neutral',
        };
    }

    /** Grammatical shape of a Danish term. */
    public function termShape(string $term): string
    {
        $t = mb_strtolower(trim($term), 'UTF-8');
        return match (true) {
            str_starts_with($t, 'at ')                        => 'verbal',
            (bool) preg_match('/[!?]$/u', $t)                 => 'interjection',
            (bool) preg_match('/^(i|på|til|af|med|for|om|ved|under|over|efter|uden)\s/u', $t) => 'adverbial',
            default                                           => 'nominal',
        };
    }

    /**
     * Does any token in the phrase inflect like a Russian verb?
     *
     * Checking only the first token for an infinitive ending missed every finite
     * and past form: "договорились", "на том и порешили" and "так и сделаем" were
     * all classed as noun phrases, and the distractor picker then matched them with
     * genuinely verbless options. Endings here are chosen for precision over recall
     * -- a missed verb costs a slightly weaker question, a false positive puts a
     * noun among verbs, which is the tell we are trying to remove.
     */
    public function hasVerb(string $text): bool
    {
        foreach (preg_split('/\s+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [] as $token) {
            $token = trim($token, ".,;:!?()«»\"'");
            if (mb_strlen($token, 'UTF-8') < 4) {
                continue;
            }
            $isVerb = preg_match(
                '/('
                . 'ться|тись|чься|ть|ти|чь'                       // infinitive
                . '|лся|лась|лось|лись'                            // reflexive past
                . '|[аяеиыуо]л|[аяеиыуо]ла|[аяеиыуо]ло|[аяеиыуо]ли'  // past
                . '|[аяеу]ет|[аяеу]ем|[аяеу]ешь|[аяеу]ете'         // present/future, 1st conj
                . '|[иеая]т|ит|ишь|им|ите'                          // present, 2nd conj
                . '|айте|ейте|ите'                                  // imperative
                . ')$/u',
                $token
            );
            if ($isVerb) {
                return true;
            }
        }
        return false;
    }

    /** Grammatical shape of a Russian translation -- used to match distractors. */
    public function translationShape(string $text): string
    {
        $first = mb_strtolower(preg_split('/\s+/u', trim($text))[0] ?? '', 'UTF-8');
        return match (true) {
            (bool) preg_match('/^(чёрт|черт|блин|ну|вот|ой|да|нет)$/u', $first) => 'interjection',
            $this->hasVerb($text)                                        => 'verbal',
            (bool) preg_match('/(о|е)$/u', $first) && mb_strlen($first) > 4 => 'adverbial',
            default                                                      => 'nominal',
        };
    }
}
