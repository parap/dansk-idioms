<?php declare(strict_types=1);
/**
 * One-off repair: answers longer than the quiz limit, which the review screen used to
 * accept before it validated length. Derives a short form from the leading clause where
 * one exists, keeps the full text as a non-usable variant, and reports anything that
 * still needs a human.
 *
 *   php bin/shorten-answers.php [--dry-run]
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use Dansk\Domain\ReviewRepository;
use Dansk\Import\Text;
use Dansk\Support\Db;

$dryRun = in_array('--dry-run', $argv, true);
$maxWords = ReviewRepository::MAX_ANSWER_WORDS;
$maxChars = ReviewRepository::MAX_ANSWER_CHARS;

$rows = Db::fetchAll(
    'SELECT t.id, t.idiom_id, t.text, i.term FROM idiom_translations t
     JOIN idioms i ON i.id = t.idiom_id
     WHERE t.quiz_usable = 1 AND (t.word_count > ? OR t.char_count > ?)',
    [$maxWords, $maxChars]
);

$fixed = 0; $manual = [];

foreach ($rows as $row) {
    $short = null;
    // Leading clause, then leading comma-clause: the same narrowing the importer uses.
    foreach (['/[.;(]/u', '/,\s+/u'] as $pattern) {
        $candidate = Text::trimPunctuation(Text::collapseWhitespace(
            (string) preg_split($pattern, $row['text'], 2)[0]
        ), false);
        if ($candidate === '') {
            continue;
        }
        $words = Text::wordCount($candidate);
        // Same guards the extractor applies: not grammar terminology, and not so
        // short it says nothing. "Прямое разговорное выражение" and "чувство" are
        // both worse than leaving the row for a human.
        $isMeta = (bool) preg_match(
            '/(выражени|оборот|словосочетани|фразеологизм|конструкци|идиом|глагол|'
            . 'существительн|прилагательн|наречи|частиц|союз|предлог|междомети|'
            . 'термин|поговорк|пословиц|описани|обозначени)/ui',
            $candidate
        );
        if (!$isMeta && $words >= 2 && $words <= $maxWords
            && mb_strlen($candidate, 'UTF-8') <= $maxChars) {
            $short = $candidate;
            break;
        }
    }

    if ($short === null) {
        $manual[] = $row;
        continue;
    }

    printf("  %-28s %s\n      -> %s\n", mb_substr($row['term'], 0, 26),
        mb_substr($row['text'], 0, 60) . '…', $short);
    $fixed++;

    if ($dryRun) {
        continue;
    }
    // Keep the full text, but only as context: it is not an option.
    Db::execute('UPDATE idiom_translations SET quiz_usable = 0, is_primary = NULL WHERE id = ?', [(int) $row['id']]);
    Db::execute(
        "INSERT INTO idiom_translations
            (idiom_id, lang_code, text, text_norm, sense_type, is_primary, quiz_usable,
             shape, word_count, char_count, source, confidence)
         VALUES (?, 'ru', ?, ?, 'idiomatic', 1, 1, ?, ?, ?, 'manual', 1.0)
         ON DUPLICATE KEY UPDATE is_primary = 1, quiz_usable = 1",
        [
            (int) $row['idiom_id'], $short, \Dansk\Import\Normalizer::translation($short),
            (new \Dansk\Import\Classifier())->translationShape($short),
            Text::wordCount($short), mb_strlen($short, 'UTF-8'),
        ]
    );
}

// An over-long answer that cannot be shortened automatically is still a bad option.
// Demote it, but only where a shorter usable alternative exists -- otherwise the idiom
// would be published with nothing to ask.
$demoted = 0;
foreach ($manual as $row) {
    $alternative = Db::fetchValue(
        "SELECT id FROM idiom_translations
         WHERE idiom_id = ? AND lang_code = 'ru' AND quiz_usable = 1 AND id <> ?
           AND word_count <= ? AND char_count <= ?
         ORDER BY word_count ASC LIMIT 1",
        [(int) $row['idiom_id'], (int) $row['id'], $maxWords, $maxChars]
    );
    if ($alternative === false || $alternative === null) {
        continue;
    }
    $demoted++;
    if (!$dryRun) {
        Db::execute('UPDATE idiom_translations SET quiz_usable = 0, is_primary = NULL WHERE id = ?', [(int) $row['id']]);
        Db::execute('UPDATE idiom_translations SET is_primary = 1 WHERE id = ?', [(int) $alternative]);
    }
}

printf("\n%s %d of %d over-long answers\n", $dryRun ? 'Would shorten' : 'Shortened', $fixed, count($rows));
if ($demoted > 0) {
    printf("%s %d more in favour of a shorter existing translation\n",
        $dryRun ? 'Would demote' : 'Demoted', $demoted);
}
if ($manual !== []) {
    printf("\n%d need shortening by hand at /admin:\n", count($manual));
    foreach ($manual as $row) {
        printf("  %s\n", $row['term']);
    }
}
