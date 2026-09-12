<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Import\Normalizer;
use Dansk\Import\Text;
use Dansk\Support\Db;
use InvalidArgumentException;

/**
 * Loads hand-added idioms from a JSON file.
 *
 * The imported corpus can always be replayed from its Telegram export, but an idiom
 * somebody simply knows has no export to replay -- so these live in content/idioms/ and
 * the database is derived from them.
 *
 *     [
 *       {
 *         "term":    "at forholde sig til (noget)",
 *         "kind":    "phrase",
 *         "ru":      "отреагировать на что-либо",
 *         "also":    ["разобраться с чем-либо"],
 *         "explain": "the full meaning, shown to the learner after they answer"
 *       }
 *     ]
 *
 * `ru` has to be short enough to work as a quiz option; `explain` is where the rest of
 * the meaning goes.
 */
final class IdiomFile
{
    private const REQUIRED = ['term', 'ru'];

    /**
     * Declares relationships over the corpus rather than content of its own, so it is
     * read by loadSynonymGroups() and never by load().
     */
    public const GROUP_FILE = 'synonyms.json';

    /**
     * The entry files of a content directory.
     *
     * @return list<string>
     */
    public static function entryFiles(string $dir): array
    {
        return array_values(array_filter(
            glob(rtrim($dir, '/') . '/*.json') ?: [],
            static fn(string $f): bool => basename($f) !== self::GROUP_FILE
        ));
    }

    public static function groupFile(string $dir): string
    {
        return rtrim($dir, '/') . '/' . self::GROUP_FILE;
    }

    public function __construct(private ReviewRepository $idioms = new ReviewRepository()) {}

    /**
     * @return list<int> the idiom ids, in file order
     * @throws InvalidArgumentException naming the offending entry
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("No such idiom file: {$path}");
        }

        $entries = json_decode((string) file_get_contents($path), true);
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InvalidArgumentException(
                basename($path) . ' must contain a JSON list of idiom objects.'
            );
        }

        // Two passes: everything is stored first, then the cross-references are
        // resolved. A synonym is named by term, so a single pass would make the order of
        // the list load-bearing and fail blaming the entry that did nothing wrong.
        $ids = [];
        foreach ($entries as $i => $entry) {
            $ids[] = $this->loadOne($entry, basename($path), $i);
        }

        foreach ($entries as $entry) {
            $synonyms = $this->strings($entry['synonyms'] ?? []);
            if ($synonyms === []) {
                continue;
            }
            try {
                $this->idioms->declareSynonyms((string) $entry['term'], $synonyms);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(
                    basename($path) . ": '{$entry['term']}' — " . $e->getMessage(), 0, $e
                );
            }
        }

        return $ids;
    }

    /**
     * Declares synonym groups from a JSON list of term lists.
     *
     *     [["sgu", "fandeme"], ["at hoppe på", "at melde sig på banen"]]
     *
     * Kept apart from the idiom files because these describe relationships over the
     * imported corpus rather than content this repository owns. A group naming an idiom
     * that is not present is skipped rather than fatal: the Telegram export is not
     * committed, so a fresh database has none of those idioms and refusing to load would
     * make the file unusable exactly where it matters.
     *
     * @return int the number of groups actually declared
     */
    public function loadSynonymGroups(string $path): int
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("No such synonym file: {$path}");
        }

        $groups = json_decode((string) file_get_contents($path), true);
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new InvalidArgumentException(
                basename($path) . ' must contain a JSON list of term lists.'
            );
        }

        $declared = 0;
        foreach ($groups as $index => $group) {
            $terms = $this->strings($group);
            if (count($terms) < 2) {
                throw new InvalidArgumentException(
                    basename($path) . ": group {$index} needs at least two terms."
                );
            }

            $present = array_values(array_filter($terms, fn(string $t): bool => $this->known($t)));
            if (count($present) < 2) {
                continue;
            }

            // Every member joined to the first, and declareSynonym joins whichever group
            // either already belongs to -- so the whole set ends up in one group.
            $first = array_shift($present);
            $this->idioms->declareSynonyms($first, $present);
            $declared++;
        }

        return $declared;
    }

    private function known(string $term): bool
    {
        $id = Db::fetchValue(
            "SELECT id FROM idioms WHERE lang_code = 'da' AND term_norm = ?",
            [Normalizer::term(Text::collapseWhitespace($term))]
        );

        return $id !== false && $id !== null;
    }

    /**
     * @param  mixed $value
     * @return list<string>
     */
    private function strings($value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /** @param mixed $entry */
    private function loadOne($entry, string $file, int $index): int
    {
        if (!is_array($entry)) {
            throw new InvalidArgumentException("{$file} entry {$index} is not an object.");
        }

        // Named by its term rather than its index, because that is what an author is
        // looking at when they come to fix it.
        $name = isset($entry['term']) && is_string($entry['term'])
            ? "'{$entry['term']}'"
            : "entry {$index}";

        foreach (self::REQUIRED as $key) {
            if (!isset($entry[$key]) || !is_string($entry[$key]) || trim($entry[$key]) === '') {
                throw new InvalidArgumentException("{$file}: {$name} has no '{$key}'.");
            }
        }

        try {
            // Named, not positional. There are enough of these that dropping one
            // silently slides every later argument into the wrong parameter -- which is
            // exactly how "retire" once arrived as a list of synonyms.
            return $this->idioms->addByHand(
                term: $entry['term'],
                primary: $entry['ru'],
                extra: $this->strings($entry['also'] ?? []),
                kind: is_string($entry['kind'] ?? null) ? $entry['kind'] : 'phrase',
                note: is_string($entry['note'] ?? null) ? $entry['note'] : null,
                explanation: is_string($entry['explain'] ?? null) ? $entry['explain'] : null,
                shape: is_string($entry['shape'] ?? null) ? $entry['shape'] : null,
                literal: $this->strings($entry['literal'] ?? []),
                retire: $this->strings($entry['retire'] ?? []),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("{$file}: {$name} — " . $e->getMessage(), 0, $e);
        }
    }
}
