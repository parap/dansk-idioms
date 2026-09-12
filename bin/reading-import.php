<?php declare(strict_types=1);

/**
 * Loads passage documents into the database.
 *
 *     php bin/reading-import.php content/reading/*.txt
 *     php bin/reading-import.php --publish content/reading/cykler.txt
 *     php bin/reading-import.php --replace content/reading/cykler.txt
 *
 * A document is parsed and validated before anything is written, and a file that fails
 * stops only itself: the rest of the batch still loads, and the exit status reports that
 * something was rejected.
 *
 * Passages arrive unpublished. Publishing is a separate act, here or in the admin
 * screen, so a half-finished text cannot reach a learner by accident.
 */

require_once __DIR__ . '/../bootstrap.php';

use Dansk\Domain\Reading\InvalidPassage;
use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Support\Db;

$args    = array_slice($argv, 1);
$publish = in_array('--publish', $args, true);
$replace = in_array('--replace', $args, true);
$files   = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));

if ($files === []) {
    fwrite(STDERR, "usage: php bin/reading-import.php [--publish] [--replace] <file.txt>...\n");
    exit(2);
}

$parser = new PassageDocument();
$repo   = new ReadingRepository();
$failed  = 0;
$skipped = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (!is_file($file)) {
        printf("  %-40s no such file\n", $name);
        $failed++;
        continue;
    }

    try {
        $doc = $parser->parse((string) file_get_contents($file));

        $existing = Db::fetchValue('SELECT id FROM reading_passages WHERE slug = ?', [$doc['slug']]);
        if ($existing !== false) {
            if (!$replace) {
                // Nothing to do is not a rejection. Conflating the two means a batch of
                // the whole directory can never succeed twice, which is exactly how this
                // is run from a deployment.
                printf("  %-40s slug '%s' already loaded -- pass --replace to overwrite\n", $name, $doc['slug']);
                $skipped++;
                continue;
            }

            // Replacing cascades to the items, and a session that has already served
            // them would lose the rows its answers point at.
            $served = (int) Db::fetchValue(
                'SELECT COUNT(*) FROM reading_session_items WHERE passage_id = ?',
                [(int) $existing]
            );
            if ($served > 0) {
                printf("  %-40s refused: %d answered item(s) reference this passage\n", $name, $served);
                $failed++;
                continue;
            }
            Db::execute('DELETE FROM reading_passages WHERE id = ?', [(int) $existing]);
        }

        $id = $repo->save($doc);
        if ($publish) {
            $repo->publish($id);
        }

        printf(
            "  %-40s ok  %s, %d question(s), %d words%s\n",
            $name, $doc['kind'], count($doc['items']),
            (int) Db::fetchValue('SELECT word_count FROM reading_passages WHERE id = ?', [$id]),
            $publish ? ', published' : ''
        );
    } catch (InvalidPassage $e) {
        printf("  %-40s rejected: %s\n", $name, $e->getMessage());
        $failed++;
    }
}

echo "\n";
if ($failed > 0) {
    echo "  {$failed} document(s) rejected\n";
} elseif ($skipped > 0) {
    echo "  every document loaded or already present ({$skipped} unchanged)\n";
} else {
    echo "  every document loaded\n";
}

exit($failed === 0 ? 0 : 1);
