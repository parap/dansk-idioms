<?php declare(strict_types=1);

namespace Dansk\Import;

use Dansk\Support\Config;
use Dansk\Support\Db;
use PDO;

final class Importer
{
    private PDO $pdo;

    public function __construct(
        private HtmlExportReader $reader = new HtmlExportReader(),
        private EntrySegmenter $segmenter = new EntrySegmenter(),
        private EntryParser $parser = new EntryParser(),
        private TranslationExtractor $extractor = new TranslationExtractor(),
        private Classifier $classifier = new Classifier(),
    ) {
        $this->pdo = Db::pdo();
    }

    /** @return array<string,int> */
    public function import(string $file, string $sourceTitle, bool $dryRun = false): array
    {
        $parserVersion = (int) Config::get('import.parser_version', 1);
        $autoAccept    = (float) Config::get('import.auto_accept_threshold', 0.85);
        $reviewFloor   = (float) Config::get('import.review_threshold', 0.50);
        $lang          = 'ru';

        $stats = [
            'messages' => 0, 'entries' => 0, 'auto_accepted' => 0,
            'needs_review' => 0, 'rejected' => 0,
            'idioms_created' => 0, 'idioms_updated' => 0,
            'translations' => 0, 'no_primary' => 0,
        ];

        $sourceId = $dryRun ? 0 : $this->ensureSource($sourceTitle);
        $runId    = $dryRun ? 0 : $this->startRun($sourceId, $file, $parserVersion);

        foreach ($this->reader->read($file) as $message) {
            $stats['messages']++;
            $segmented = $this->segmenter->segment($message['text']);
            $entries   = $segmented['entries'];

            $messageId = $dryRun
                ? 0
                : $this->upsertMessage($sourceId, $message, $segmented['preamble'], count($entries));

            foreach ($entries as $index => $entryText) {
                $entry = $this->parser->parse($entryText);
                $stats['entries']++;

                $translations = $this->extractor->extract($entry);
                $hasPrimary   = (bool) array_filter($translations, static fn(array $t): bool => $t['is_primary']);
                if (!$hasPrimary) {
                    $stats['no_primary']++;
                }

                // An entry with nothing to ask is not publishable however confident
                // the split looked.
                $status = match (true) {
                    $entry->confidence >= $autoAccept && $hasPrimary => 'auto_accepted',
                    $entry->confidence >= $reviewFloor              => 'needs_review',
                    default                                         => 'rejected',
                };
                if ($entry->confidence >= $autoAccept && !$hasPrimary) {
                    $status = 'needs_review';
                }
                $stats[$status]++;

                if ($dryRun) {
                    continue;
                }

                $idiomId = null;
                if ($status === 'auto_accepted') {
                    [$idiomId, $created] = $this->upsertIdiom($entry, $messageId);
                    $stats[$created ? 'idioms_created' : 'idioms_updated']++;
                    $stats['translations'] += $this->writeTranslations($idiomId, $lang, $translations);
                    $this->writeExplanation($idiomId, $lang, $entry);
                    $this->publishIfAnswerable($idiomId, $lang);
                }

                $this->upsertRawEntry($messageId, $index, $entry, $status, $idiomId, $parserVersion);
            }
        }

        if (!$dryRun) {
            $this->finishRun($runId, $stats);
        }

        return $stats;
    }

    // ------------------------------------------------------------------ writes

    private function ensureSource(string $title): int
    {
        $existing = Db::fetchValue(
            "SELECT id FROM sources WHERE kind='telegram_group' AND title = ?",
            [$title]
        );
        if ($existing !== false && $existing !== null) {
            return (int) $existing;
        }
        Db::execute(
            "INSERT INTO sources (kind, title) VALUES ('telegram_group', ?)",
            [$title]
        );
        return (int) $this->pdo->lastInsertId();
    }

    private function startRun(int $sourceId, string $file, int $parserVersion): int
    {
        Db::execute(
            'INSERT INTO import_runs (source_id, file_name, file_hash, parser_version, started_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$sourceId, basename($file), hash_file('sha256', $file) ?: '', $parserVersion]
        );
        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, array $stats): void
    {
        Db::execute(
            'UPDATE import_runs SET finished_at = NOW(), messages_seen = ?, entries_seen = ?,
                    idioms_created = ?, idioms_updated = ?, needs_review = ?, stats = ?
             WHERE id = ?',
            [
                $stats['messages'], $stats['entries'], $stats['idioms_created'],
                $stats['idioms_updated'], $stats['needs_review'],
                json_encode($stats, JSON_UNESCAPED_UNICODE), $runId,
            ]
        );
    }

    private function upsertMessage(int $sourceId, array $m, ?string $preamble, int $entryCount): int
    {
        $hash = hash('sha256', $m['text']);
        Db::execute(
            'INSERT INTO messages
                (source_id, tg_message_id, from_name, posted_at, posted_at_raw,
                 text_plain, preamble, content_hash, entry_count)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                text_plain = VALUES(text_plain), preamble = VALUES(preamble),
                content_hash = VALUES(content_hash), entry_count = VALUES(entry_count),
                id = LAST_INSERT_ID(id)',
            [
                $sourceId, $m['tg_message_id'], $m['from_name'],
                $m['posted_at'] ?? '1970-01-01 00:00:00', $m['posted_at_raw'],
                $m['text'], $preamble, $hash, $entryCount,
            ]
        );
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:bool} id and whether it was newly created */
    private function upsertIdiom(ParsedEntry $entry, int $messageId): array
    {
        $norm = Normalizer::term($entry->term ?? '');

        $existing = Db::fetchValue(
            "SELECT id FROM idioms WHERE lang_code='da' AND term_norm = ?",
            [$norm]
        );
        if ($existing !== false && $existing !== null) {
            Db::execute('UPDATE idioms SET seen_count = seen_count + 1 WHERE id = ?', [(int) $existing]);
            return [(int) $existing, false];
        }

        Db::execute(
            'INSERT INTO idioms
                (lang_code, term, term_norm, term_note, kind, register, shape,
                 first_message_id, quality_score, rand_key)
             VALUES (?,?,?,?,?,?,?,?,?,RAND())',
            [
                'da', $entry->term, $norm, $entry->termNote,
                $this->classifier->kind($entry),
                $this->classifier->register($entry),
                $this->classifier->termShape($entry->term ?? ''),
                $messageId ?: null,
                $entry->confidence,
            ]
        );
        $id = (int) $this->pdo->lastInsertId();

        // The inflected form as it appeared in the source text is the only attested
        // usage the corpus holds -- keep it as an unreviewed example seed.
        if ($entry->inflectedForm !== null) {
            Db::execute(
                "INSERT INTO examples (idiom_id, lang_code, sentence, source, is_reviewed)
                 VALUES (?, 'da', ?, 'telegram', 0)",
                [$id, $entry->inflectedForm]
            );
        }

        return [$id, true];
    }

    private function writeTranslations(int $idiomId, string $lang, array $translations): int
    {
        $written = 0;
        foreach ($translations as $t) {
            $norm = Normalizer::translation($t['text']);
            if ($norm === '') {
                continue;
            }
            Db::execute(
                'INSERT INTO idiom_translations
                    (idiom_id, lang_code, text, text_norm, sense_type, is_primary,
                     quiz_usable, shape, word_count, char_count, source, confidence)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    sense_type = VALUES(sense_type), quiz_usable = VALUES(quiz_usable),
                    confidence = GREATEST(confidence, VALUES(confidence))',
                [
                    $idiomId, $lang, $t['text'], $norm, $t['sense_type'],
                    // 1 or NULL, never 0: uq_primary relies on NULLs being ignored.
                    $t['is_primary'] ? 1 : null,
                    $t['quiz_usable'] ? 1 : 0,
                    $this->classifier->translationShape($t['text']),
                    Text::wordCount($t['text']),
                    mb_strlen($t['text'], 'UTF-8'),
                    'import', $t['confidence'],
                ]
            );
            $written++;
        }
        return $written;
    }

    private function writeExplanation(int $idiomId, string $lang, ParsedEntry $entry): void
    {
        $body = trim(($entry->explanation ?? '') . "\n" . implode("\n", array_map(
            static fn(string $k, string $v): string => "$k: $v",
            array_keys($entry->labels),
            array_values($entry->labels)
        )));
        if ($body === '') {
            return;
        }
        Db::execute(
            "INSERT INTO idiom_explanations (idiom_id, lang_code, body, source)
             VALUES (?,?,?, 'import')
             ON DUPLICATE KEY UPDATE body = VALUES(body)",
            [$idiomId, $lang, $body]
        );
    }

    /** Publishable only once something answerable exists. */
    private function publishIfAnswerable(int $idiomId, string $lang): void
    {
        Db::execute(
            'UPDATE idioms i SET i.is_published = 1
             WHERE i.id = ? AND EXISTS (
                 SELECT 1 FROM idiom_translations t
                 WHERE t.idiom_id = i.id AND t.lang_code = ?
                   AND t.quiz_usable = 1 AND t.is_primary = 1
             )',
            [$idiomId, $lang]
        );
    }

    private function upsertRawEntry(
        int $messageId, int $index, ParsedEntry $entry,
        string $status, ?int $idiomId, int $parserVersion
    ): void {
        Db::execute(
            'INSERT INTO raw_entries
                (message_id, entry_index, raw_text, term_guess, term_note_guess,
                 explanation_guess, separator_kind, strategy, confidence, status,
                 idiom_id, parser_version, signals, content_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                raw_text = VALUES(raw_text), term_guess = VALUES(term_guess),
                term_note_guess = VALUES(term_note_guess),
                explanation_guess = VALUES(explanation_guess),
                separator_kind = VALUES(separator_kind), strategy = VALUES(strategy),
                confidence = VALUES(confidence), idiom_id = VALUES(idiom_id),
                parser_version = VALUES(parser_version), signals = VALUES(signals),
                content_hash = VALUES(content_hash),
                -- a human edit outranks any re-parse
                status = IF(raw_entries.status = \'fixed\', \'fixed\', VALUES(status))',
            [
                $messageId, $index, $entry->rawText, $entry->term, $entry->termNote,
                $entry->explanation, $entry->separatorKind, $entry->strategy,
                $entry->confidence, $status, $idiomId, $parserVersion,
                json_encode($entry->signals, JSON_UNESCAPED_UNICODE),
                hash('sha256', $entry->rawText),
            ]
        );
    }
}
