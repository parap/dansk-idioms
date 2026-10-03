<?php declare(strict_types=1);

namespace Dansk\Support;

use Dansk\Import\EntryParser;
use Dansk\Import\EntrySegmenter;
use Dansk\Import\Text;

/**
 * Whether a piece sent to the bot holds an idiom: a Danish headword and a Russian
 * meaning, read by the import's own segmenter and parser.
 *
 * Not the import's confidence: that measures how sure the split is, and an idiom with
 * its meaning on the next line scores low while being exactly what the group is for.
 */
final class IdiomScreen
{
    /** The headword, or null when the piece holds no idiom. */
    public function __invoke(string $piece): ?string
    {
        $entry = (new EntrySegmenter())->segment($piece)['entries'][0] ?? null;
        if ($entry === null) {
            return null;
        }

        $parsed = (new EntryParser())->parse($entry);
        $term   = trim((string) $parsed->term);
        if ($term === '' || Text::hasCyrillic($term) || preg_match('~https?://|www\.|t\.me/~i', $term) === 1) {
            return null;
        }

        return Text::hasCyrillic((string) $parsed->explanation) ? $term : null;
    }
}
