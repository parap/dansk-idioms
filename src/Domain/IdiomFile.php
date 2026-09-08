<?php declare(strict_types=1);

namespace Dansk\Domain;

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

        $ids = [];
        foreach ($entries as $i => $entry) {
            $ids[] = $this->loadOne($entry, basename($path), $i);
        }

        return $ids;
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
            return $this->idioms->addByHand(
                $entry['term'],
                $entry['ru'],
                array_values(array_filter((array) ($entry['also'] ?? []), 'is_string')),
                is_string($entry['kind'] ?? null) ? $entry['kind'] : 'phrase',
                is_string($entry['note'] ?? null) ? $entry['note'] : null,
                is_string($entry['explain'] ?? null) ? $entry['explain'] : null,
                is_string($entry['shape'] ?? null) ? $entry['shape'] : null,
                array_values(array_filter((array) ($entry['retire'] ?? []), 'is_string')),
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("{$file}: {$name} — " . $e->getMessage(), 0, $e);
        }
    }
}
