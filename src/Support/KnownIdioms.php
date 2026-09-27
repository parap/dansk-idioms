<?php declare(strict_types=1);

namespace Dansk\Support;

use Dansk\Import\EntryParser;
use Dansk\Import\EntrySegmenter;
use Dansk\Import\Normalizer;

/**
 * Whether a piece names an idiom the corpus already has, decided by the import's own
 * key: the parsed term, normalized, against `idioms.term_norm`. Any other key would
 * let the bot and the corpus disagree about what "already there" means.
 */
final class KnownIdioms
{
    /** The stored term when the idiom is already there, else null. */
    public function __invoke(string $piece): ?string
    {
        $entry = (new EntrySegmenter())->segment($piece)['entries'][0] ?? $piece;
        $norm  = Normalizer::term((new EntryParser())->parse($entry)->term ?? '');
        if ($norm === '') {
            return null;
        }

        $term = Db::fetchValue(
            "SELECT term FROM idioms WHERE lang_code = 'da' AND term_norm = ?",
            [$norm]
        );

        return $term === null || $term === false ? null : (string) $term;
    }
}
