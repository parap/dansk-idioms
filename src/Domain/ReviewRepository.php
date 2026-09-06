<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Import\{Classifier, EntryParser, Normalizer, Text, TranslationExtractor};
use Dansk\Support\Db;
use PDO;

/**
 * Backs the admin review queue: fetch what the parser was unsure about, and apply
 * the human's decision.
 *
 * A decision always writes status='fixed', which the importer treats as immune to
 * re-parsing -- an evening of corrections must survive the next heuristic tweak.
 */
final class ReviewRepository
{
    public function __construct(
        private EntryParser $parser = new EntryParser(),
        private TranslationExtractor $extractor = new TranslationExtractor(),
        private Classifier $classifier = new Classifier(),
    ) {}

    public function counts(): array
    {
        $rows = Db::fetchAll('SELECT status, COUNT(*) AS n FROM raw_entries GROUP BY status');
        $out = ['needs_review' => 0, 'auto_accepted' => 0, 'rejected' => 0, 'fixed' => 0, 'pending' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function queue(int $limit = 50): array
    {
        $rows = Db::fetchAll(
            "SELECT r.id, r.raw_text, r.term_guess, r.term_note_guess, r.explanation_guess,
                    r.confidence, r.strategy, r.signals, m.tg_message_id, m.posted_at
             FROM raw_entries r
             JOIN messages m ON m.id = r.message_id
             WHERE r.status = 'needs_review'
             ORDER BY r.confidence ASC, r.id ASC
             LIMIT " . max(1, min(200, $limit))
        );

        foreach ($rows as &$row) {
            $parsed = $this->parser->parse($row['raw_text']);
            $row['confidence']    = (float) $row['confidence'];
            $row['inflected']     = $parsed->inflectedForm;
            $row['labels']        = $parsed->labels;
            $row['head_remainder']= $parsed->headRemainder;
            $row['signals']       = json_decode((string) $row['signals'], true) ?: [];
            // Everything the extractor found, so the reviewer can pick rather than type.
            $row['candidates']    = array_map(
                static fn(array $c): array => [
                    'text' => $c['text'], 'sense' => $c['sense_type'], 'usable' => $c['quiz_usable'],
                ],
                $this->extractor->extract($parsed)
            );
        }
        return $rows;
    }

    /**
     * Accept an entry, with optional human corrections.
     *
     * @param list<string> $extraTranslations
     */
    public function accept(int $entryId, string $term, string $primary, array $extraTranslations = []): array
    {
        $entry = Db::fetchOne('SELECT * FROM raw_entries WHERE id = ?', [$entryId]);
        if ($entry === null) {
            throw new \RuntimeException("No such entry: {$entryId}");
        }

        $term    = Text::collapseWhitespace($term);
        $primary = Text::collapseWhitespace($primary);
        if ($term === '' || $primary === '') {
            throw new \InvalidArgumentException('Both a term and a translation are required.');
        }

        $parsed  = $this->parser->parse($entry['raw_text']);
        $pdo     = Db::pdo();
        $idiomId = $this->upsertIdiom($term, $parsed, (int) $entry['message_id']);

        $this->writeTranslation($idiomId, $primary, 'idiomatic', true);
        foreach ($extraTranslations as $extra) {
            $extra = Text::collapseWhitespace($extra);
            if ($extra !== '' && $extra !== $primary) {
                $this->writeTranslation($idiomId, $extra, 'idiomatic', false);
            }
        }

        // Keep the literal glosses the parser found: they are the distractor pool.
        foreach ($this->extractor->extract($parsed) as $c) {
            if ($c['sense_type'] === 'literal') {
                $this->writeTranslation($idiomId, $c['text'], 'literal', false);
            }
        }

        Db::execute('UPDATE idioms SET is_published = 1 WHERE id = ?', [$idiomId]);
        Db::execute(
            "UPDATE raw_entries SET status = 'fixed', idiom_id = ?, term_guess = ? WHERE id = ?",
            [$idiomId, $term, $entryId]
        );

        return ['idiom_id' => $idiomId, 'term' => $term, 'translation' => $primary];
    }

    public function reject(int $entryId): void
    {
        // 'fixed' too: a human decided, so a re-parse must not resurrect it.
        Db::execute(
            "UPDATE raw_entries SET status = 'fixed', idiom_id = NULL WHERE id = ?",
            [$entryId]
        );
        Db::execute(
            "UPDATE raw_entries SET status = 'rejected' WHERE id = ? AND idiom_id IS NULL",
            [$entryId]
        );
    }

    private function upsertIdiom(string $term, $parsed, int $messageId): int
    {
        $norm = Normalizer::term($term);
        $existing = Db::fetchValue("SELECT id FROM idioms WHERE lang_code='da' AND term_norm = ?", [$norm]);
        if ($existing !== false && $existing !== null) {
            return (int) $existing;
        }

        Db::execute(
            'INSERT INTO idioms (lang_code, term, term_norm, term_note, kind, register, shape,
                                 first_message_id, quality_score, rand_key)
             VALUES (?,?,?,?,?,?,?,?,?,RAND())',
            [
                'da', $term, $norm, $parsed->termNote,
                $this->classifier->kind($parsed),
                $this->classifier->register($parsed),
                $this->classifier->termShape($term),
                $messageId ?: null,
                1.0,
            ]
        );
        $id = (int) Db::pdo()->lastInsertId();

        if ($parsed->inflectedForm !== null) {
            Db::execute(
                "INSERT INTO examples (idiom_id, lang_code, sentence, source, is_reviewed)
                 VALUES (?, 'da', ?, 'telegram', 0)",
                [$id, $parsed->inflectedForm]
            );
        }
        return $id;
    }

    private function writeTranslation(int $idiomId, string $text, string $sense, bool $primary): void
    {
        $norm = Normalizer::translation($text);
        if ($norm === '') {
            return;
        }
        // Only one primary may exist per (idiom, lang); clear the old one first.
        if ($primary) {
            Db::execute(
                "UPDATE idiom_translations SET is_primary = NULL
                 WHERE idiom_id = ? AND lang_code = 'ru' AND is_primary = 1",
                [$idiomId]
            );
        }
        Db::execute(
            "INSERT INTO idiom_translations
                (idiom_id, lang_code, text, text_norm, sense_type, is_primary, quiz_usable,
                 shape, word_count, char_count, source, confidence)
             VALUES (?, 'ru', ?, ?, ?, ?, ?, ?, ?, ?, 'manual', 1.0)
             ON DUPLICATE KEY UPDATE
                text = VALUES(text), sense_type = VALUES(sense_type),
                is_primary = VALUES(is_primary), quiz_usable = VALUES(quiz_usable),
                source = 'manual', confidence = 1.0",
            [
                $idiomId, $text, $norm, $sense,
                $primary ? 1 : null,
                $sense === 'idiomatic' ? 1 : 0,
                $this->classifier->translationShape($text),
                Text::wordCount($text),
                mb_strlen($text, 'UTF-8'),
            ]
        );
    }
}
