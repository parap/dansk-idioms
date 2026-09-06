<?php declare(strict_types=1);
/**
 * Import a Telegram Desktop HTML export.
 *
 *   php bin/import.php --file=storage/exports/messages.html [--dry-run] [--source="Dansk"]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Dansk\Import\Importer;

$opts   = getopt('', ['file:', 'source::', 'dry-run']);
$file   = $opts['file'] ?? null;
$source = $opts['source'] ?? 'Dansk idioms (Telegram)';
$dryRun = array_key_exists('dry-run', $opts);

if ($file === null) {
    fwrite(STDERR, "usage: php bin/import.php --file=<export.html> [--source=NAME] [--dry-run]\n");
    exit(2);
}
if (!is_file($file)) {
    fwrite(STDERR, "No such file: {$file}\n");
    exit(1);
}

$started = microtime(true);
echo ($dryRun ? "DRY RUN — nothing will be written\n" : "Importing\n"), "  {$file}\n\n";

$stats = (new Importer())->import($file, $source, $dryRun);

$labels = [
    'messages'        => 'messages read',
    'entries'         => 'entries parsed',
    'auto_accepted'   => '  auto-accepted',
    'needs_review'    => '  needs review',
    'rejected'        => '  rejected',
    'idioms_created'  => 'idioms created',
    'idioms_updated'  => 'idioms seen again',
    'translations'    => 'translations written',
    'no_primary'      => 'entries with no answer',
];
foreach ($labels as $key => $label) {
    printf("  %-24s %d\n", $label, $stats[$key] ?? 0);
}
printf("\nDone in %.1fs\n", microtime(true) - $started);
