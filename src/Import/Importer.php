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
                if ($status !== 'rejected' && ($entry->term ?? '') !== '') {
                    // Entries awaiting review still get their idiom, unpublished,
                    // which keeps raw_entries.idiom_id populated. An idiom with no
                    // entry pointing at it is indistinguishable from a stale one, so
                    // leaving the link null the moment a stricter rule demotes an
                    // entry puts good idioms in front of anything that prunes.
                    [$idiomId, $created] = $this->upsertIdiom($entry, $messageId);
                    $stats[$created ? 'idioms_created' : 'idioms_updated']++;
                    $stats['translations'] += $this->writeTranslations($idiomId, $lang, $translations);
                    $this->writeExplanation($idiomId, $lang, $entry);

                    if ($status === 'auto_accepted') {
                        $this->publishIfAnswerable($idiomId, $lang);
                    }
                }

                $this->upsertRawEntry($messageId, $index, $entry, $status, $idiomId, $parserVersion);
            }
        }

        if (!$dryRun) {
            $this->ensurePrimaries($lang);
            $this->syncPublication($lang);
            $this->recomputeSeenCounts();
            // A published idiom with no primary translation is unanswerable and would
            // silently vanish from the quiz. Surfaced as a stat so a run that breaks
            // it cannot pass unnoticed.
            // An idiom whose term the parser has since changed keeps its old row
            // under the old term_norm, with nothing pointing at it any more.
            $stats['orphaned_idioms'] = (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idioms i WHERE NOT EXISTS
                     (SELECT 1 FROM raw_entries r WHERE r.idiom_id = i.id)'
            );
            $stats['published_without_answer'] = (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idioms i WHERE i.is_published = 1 AND NOT EXISTS (
                     SELECT 1 FROM idiom_translations t
                     WHERE t.idiom_id = i.id AND t.is_primary = 1 AND t.quiz_usable = 1)'
            );
            $this->finishRun($runId, $stats);
        }

        return $stats;
    }

    /**
     * Promotes a primary for any published idiom that has usable translations but no
     * primary among them -- otherwise the idiom is published yet unanswerable and
     * silently disappears from the quiz. Shortest usable reading wins, since a quiz
     * option has to sit beside three others of similar length.
     */
    private function ensurePrimaries(string $lang): void
    {
        $orphaned = Db::fetchAll(
            'SELECT i.id FROM idioms i
             WHERE i.is_published = 1
               AND NOT EXISTS (SELECT 1 FROM idiom_translations t
                               WHERE t.idiom_id = i.id AND t.lang_code = ? AND t.is_primary = 1)
               AND EXISTS     (SELECT 1 FROM idiom_translations t
                               WHERE t.idiom_id = i.id AND t.lang_code = ? AND t.quiz_usable = 1)',
            [$lang, $lang]
        );

        foreach ($orphaned as $row) {
            Db::execute(
                'UPDATE idiom_translations SET is_primary = 1
                 WHERE idiom_id = ? AND lang_code = ? AND quiz_usable = 1
                 ORDER BY word_count ASC, char_count ASC, confidence DESC LIMIT 1',
                [(int) $row['id'], $lang]
            );
        }
    }

    /** Derived, so repeated imports of the same export do not inflate it. */
    private function recomputeSeenCounts(): void
    {
        Db::execute(
            "UPDATE idioms i SET i.seen_count = GREATEST(1, (
                 SELECT COUNT(*) FROM raw_entries r
                 WHERE r.idiom_id = i.id AND r.status IN ('auto_accepted','fixed')
             ))"
        );
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
            // seen_count is recomputed from raw_entries at the end of the run rather
            // than incremented here, so re-importing the same export is idempotent.
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

        // uq_primary allows only one primary per (idiom, lang), so a re-parse that
        // moves the primary would collide with the previous one. Clear it first --
        // but never touch a row a human set, which is marked source='manual'.
        Db::execute(
            "UPDATE idiom_translations SET is_primary = NULL
             WHERE idiom_id = ? AND lang_code = ? AND is_primary = 1 AND source <> 'manual'",
            [$idiomId, $lang]
        );

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
                    sense_type  = VALUES(sense_type),
                    quiz_usable = VALUES(quiz_usable),
                    -- is_primary MUST be updated here. The statement above clears the
                    -- previous primary, so omitting it leaves the idiom with no answer
                    -- at all whenever the winning row already existed.
                    is_primary  = VALUES(is_primary),
                    confidence  = GREATEST(confidence, VALUES(confidence))',
                [
                    $idiomId, $lang, Text::clean($t['text']), $norm, $t['sense_type'],
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
        // ParsedEntry::$explanation is already label-qualified; appending the labels
        // again is what duplicated every explanation on screen.
        $body = trim((string) $entry->explanation);
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

    /**
     * Publication tracks answerability in BOTH directions. One-way publication
     * leaves an idiom published with nothing to ask when its answer later fails a
     * stricter rule: counted in the corpus, absent from every quiz.
     */
    private function publishIfAnswerable(int $idiomId, string $lang): void
    {
        Db::execute(
            'UPDATE idioms i SET i.is_published = IF(EXISTS (
                 SELECT 1 FROM idiom_translations t
                 WHERE t.idiom_id = i.id AND t.lang_code = ?
                   AND t.quiz_usable = 1 AND t.is_primary = 1
             ), 1, 0)
             WHERE i.id = ?',
            [$lang, $idiomId]
        );
    }

    /** Sweeps the same invariant across the corpus at the end of a run. */
    private function syncPublication(string $lang): void
    {
        Db::execute(
            "UPDATE idioms i SET i.is_published = 0
             WHERE i.is_published = 1 AND NOT EXISTS (
                 SELECT 1 FROM idiom_translations t
                 WHERE t.idiom_id = i.id AND t.lang_code = ?
                   AND t.quiz_usable = 1 AND t.is_primary = 1
             )",
            [$lang]
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
                -- A human edit outranks any re-parse. Everything the reviewer owns --
                -- the decision, the corrected term, and above all the link to the idiom
                -- they created -- is preserved; only parser bookkeeping is
                -- refreshed. Losing idiom_id here would orphan the idiom from its source
                -- and silently undercount seen_count.
                raw_text          = VALUES(raw_text),
                content_hash      = VALUES(content_hash),
                parser_version    = VALUES(parser_version),
                signals           = VALUES(signals),
                separator_kind    = IF(raw_entries.status = \'fixed\', raw_entries.separator_kind, VALUES(separator_kind)),
                strategy          = IF(raw_entries.status = \'fixed\', raw_entries.strategy, VALUES(strategy)),
                confidence        = IF(raw_entries.status = \'fixed\', raw_entries.confidence, VALUES(confidence)),
                term_guess        = IF(raw_entries.status = \'fixed\', raw_entries.term_guess, VALUES(term_guess)),
                term_note_guess   = IF(raw_entries.status = \'fixed\', raw_entries.term_note_guess, VALUES(term_note_guess)),
                explanation_guess = IF(raw_entries.status = \'fixed\', raw_entries.explanation_guess, VALUES(explanation_guess)),
                idiom_id          = IF(raw_entries.status = \'fixed\', raw_entries.idiom_id, VALUES(idiom_id)),
                status            = IF(raw_entries.status = \'fixed\', \'fixed\', VALUES(status))',
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
