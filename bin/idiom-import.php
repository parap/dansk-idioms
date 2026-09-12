<?php declare(strict_types=1);

/**
 * Loads hand-added idioms into the corpus.
 *
 *     php bin/idiom-import.php content/idioms/*.json
 *
 * Re-running is safe: an idiom already present keeps its id, the newest translation takes
 * the primary slot, and the others are kept alongside it.
 */

require_once __DIR__ . '/../bootstrap.php';

use Dansk\Domain\IdiomFile;
use Dansk\Support\Db;

$files = array_slice($argv, 1);
if ($files === []) {
    $files = IdiomFile::entryFiles(__DIR__ . '/../content/idioms');
}
if ($files === []) {
    fwrite(STDERR, "usage: php bin/idiom-import.php <file.json>...\n");
    exit(2);
}

$loader = new IdiomFile();
$failed = 0;

foreach ($files as $file) {
    try {
        $ids = $loader->load($file);
        printf("  %-32s ok  %d idiom(s)\n", basename($file), count($ids));
    } catch (InvalidArgumentException $e) {
        printf("  %-32s rejected: %s\n", basename($file), $e->getMessage());
        $failed++;
    }
}

// Relationships come after content: a group can only be declared once both idioms exist.
$synonymFile = IdiomFile::groupFile(__DIR__ . '/../content/idioms');
if (is_file($synonymFile) && !in_array($synonymFile, $files, true)) {
    try {
        printf("  %-32s ok  %d group(s)\n", basename($synonymFile), $loader->loadSynonymGroups($synonymFile));
    } catch (InvalidArgumentException $e) {
        printf("  %-32s rejected: %s\n", basename($synonymFile), $e->getMessage());
        $failed++;
    }
}

printf(
    "\n  corpus: %d published idioms, %d quiz-usable translations\n",
    (int) Db::fetchValue('SELECT COUNT(*) FROM idioms WHERE is_published = 1'),
    (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_translations WHERE quiz_usable = 1')
);

exit($failed === 0 ? 0 : 1);
