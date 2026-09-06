<?php declare(strict_types=1);
/**
 * Recompute derived classification (shape) over existing translations, without
 * re-importing. Use after changing Classifier heuristics.
 *
 *   php bin/reclassify.php [--dry-run]
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Dansk\Import\Classifier;
use Dansk\Support\Db;

$dryRun = in_array('--dry-run', $argv, true);
$classifier = new Classifier();

$rows = Db::fetchAll('SELECT id, text, shape FROM idiom_translations');
$changed = 0;
$moves = [];

foreach ($rows as $row) {
    $shape = $classifier->translationShape((string) $row['text']);
    if ($shape === $row['shape']) {
        continue;
    }
    $moves[($row['shape'] ?? 'null') . ' -> ' . $shape] ??= 0;
    $moves[($row['shape'] ?? 'null') . ' -> ' . $shape]++;
    $changed++;
    if (!$dryRun) {
        Db::execute('UPDATE idiom_translations SET shape = ? WHERE id = ?', [$shape, (int) $row['id']]);
    }
}

printf("%s %d of %d translations\n", $dryRun ? 'Would reclassify' : 'Reclassified', $changed, count($rows));
arsort($moves);
foreach ($moves as $move => $n) {
    printf("  %-26s %d\n", $move, $n);
}
