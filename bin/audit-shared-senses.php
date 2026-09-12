<?php declare(strict_types=1);

/**
 * Lists pairs of idioms that can be served as each other's wrong answer.
 *
 *     php bin/audit-shared-senses.php
 *
 * Two published idioms sharing a sense is enough. The picker excludes options belonging
 * to the idiom being asked about, but not another idiom's copy of the same words -- so a
 * learner can be offered a translation that is genuinely correct and marked down for
 * choosing it. Reverse rounds are worse: the options are the Danish terms themselves, and
 * near-duplicate rejection compares text, so two terms that merely mean the same thing
 * pass straight through it.
 *
 * Declare the pair in content/idioms/synonyms.json. Both directions of the picker already
 * refuse a declared synonym. Exits 1 while anything is unresolved, so it can gate a
 * release.
 */

require_once __DIR__ . '/../bootstrap.php';

use Dansk\Domain\SharedSenseAudit;

$overlaps = (new SharedSenseAudit())->overlaps();

if ($overlaps === []) {
    echo "  no shared senses between undeclared idioms\n";
    exit(0);
}

printf("  %d pair(s) share a sense and are not declared synonyms:\n\n", count($overlaps));

foreach ($overlaps as $o) {
    // A shared primary is the worst case: it is the answer each question displays.
    $severity = $o['a_primary'] && $o['b_primary'] ? 'BOTH ANSWERS'
        : (($o['a_primary'] || $o['b_primary']) ? 'one answer  ' : 'both senses ');
    printf("  [%s]  %s\n", $severity, $o['text']);
    printf("      %s%s\n", $o['a_term'], $o['a_primary'] ? '  (its answer)' : '');
    printf("      %s%s\n\n", $o['b_term'], $o['b_primary'] ? '  (its answer)' : '');
}

echo "  Add the pairs that really mean the same to content/idioms/synonyms.json.\n";
echo "  Where they do not, remove the shared sense from whichever idiom it fits worse.\n";

exit(1);
