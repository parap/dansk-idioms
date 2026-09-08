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
    public const MAX_ANSWER_WORDS = 6;
    public const MAX_ANSWER_CHARS = 60;

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

        $this->assertAnswerFits($primary);

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

    /**
     * Publishes an idiom somebody simply knows.
     *
     * Everything else in the corpus arrives through the importer, so publishing one used
     * to require a raw_entries row to accept. The translation writer is shared with that
     * path, because the "one primary per idiom, expressed as 1 or NULL" rule is the most
     * load-bearing convention in the schema and must not exist twice.
     *
     * @param list<string> $extra       further senses, kept for the distractor pool and
     *                                  for reverse rounds, none of them primary
     * @param ?string      $explanation the full meaning, shown after an answer. The
     *                                  primary has to stay short enough to work as a
     *                                  quiz option, so without this the rest of what an
     *                                  author knows about the idiom has nowhere to go.
     * @param ?string      $shape       overrides the guess. The classifier reads a term
     *                                  by its surface form, which misses clauses used
     *                                  adverbially, and shape is what the distractor
     *                                  picker scores candidate options on.
     * @param list<string> $literal     literal readings. Kept, but never offered as an
     *                                  answer: a literal gloss is the best distractor
     *                                  there is -- register-matched, idiom-shaped, vivid
     *                                  and guaranteed wrong -- and a terrible answer.
     * @param list<string> $retire      senses to remove outright. Demoting a wrong
     *                                  translation is not enough: it stays quiz_usable
     *                                  and goes on being offered as a distractor
     *                                  elsewhere, where obvious metalanguage tells a
     *                                  reader which option to rule out.
     */
    public function addByHand(
        string $term,
        string $primary,
        array $extra = [],
        string $kind = 'phrase',
        ?string $note = null,
        ?string $explanation = null,
        ?string $shape = null,
        array $literal = [],
        array $retire = [],
    ): int {
        $term    = Text::collapseWhitespace($term);
        $primary = Text::collapseWhitespace($primary);
        if ($term === '' || $primary === '') {
            throw new \InvalidArgumentException('Both a term and a translation are required.');
        }

        $this->assertDanishScript($term);
        $this->assertAnswerFits($primary);
        $this->assertEnum('shape', $shape, ['verbal', 'nominal', 'adverbial', 'interjection', 'other']);
        $this->assertEnum('kind', $kind, [
            'idiom', 'phrase', 'collocation', 'word', 'particle', 'proverb', 'borrowed', 'other',
        ]);

        $norm     = Normalizer::term($term);
        $existing = Db::fetchValue("SELECT id FROM idioms WHERE lang_code='da' AND term_norm = ?", [$norm]);

        $shape ??= $this->classifier->termShape($term);

        if ($existing !== false && $existing !== null) {
            // content/idioms/ is the source of truth, so a corrected classification in
            // the file has to reach the row that already exists.
            $idiomId = (int) $existing;
            Db::execute(
                'UPDATE idioms SET term = ?, term_note = ?, kind = ?, shape = ? WHERE id = ?',
                [$term, $note, $kind, $shape, $idiomId]
            );
        } else {
            Db::execute(
                'INSERT INTO idioms (lang_code, term, term_norm, term_note, kind, register, shape,
                                     quality_score, rand_key)
                 VALUES (?,?,?,?,?,?,?,?,RAND())',
                ['da', $term, $norm, $note, $kind, 'neutral', $shape, 1.0]
            );
            $idiomId = (int) Db::pdo()->lastInsertId();
        }

        $this->writeTranslation($idiomId, $primary, 'idiomatic', true);
        foreach ($extra as $sense) {
            $sense = Text::collapseWhitespace($sense);
            if ($sense !== '' && $sense !== $primary) {
                $this->writeTranslation($idiomId, $sense, 'idiomatic', false);
            }
        }

        foreach ($literal as $reading) {
            $reading = Text::collapseWhitespace($reading);
            // The primary is the answer, so it cannot also be the literal reading.
            if ($reading === '' || $reading === $primary) {
                continue;
            }
            $this->writeTranslation($idiomId, $reading, 'literal', false);
        }

        foreach ($retire as $dropped) {
            $droppedNorm = Normalizer::translation(Text::collapseWhitespace($dropped));
            if ($droppedNorm === '') {
                continue;
            }
            // is_primary IS NULL is what keeps a file from retiring the very sense it
            // just promoted and leaving the idiom published with no answer at all.
            Db::execute(
                "DELETE FROM idiom_translations
                  WHERE idiom_id = ? AND lang_code = 'ru' AND text_norm = ? AND is_primary IS NULL",
                [$idiomId, $droppedNorm]
            );
        }

        $explanation = $explanation === null ? null : Text::collapseWhitespace($explanation);
        if ($explanation !== null && $explanation !== '') {
            // uq_expl is (idiom_id, lang_code, source), so an upsert would leave an
            // imported gloss in place beside this one. The quiz joins on idiom and
            // language alone, so two rows return the idiom twice and show whichever
            // gloss the engine reached first. One explanation per language, always.
            Db::execute(
                "DELETE FROM idiom_explanations WHERE idiom_id = ? AND lang_code = 'ru'",
                [$idiomId]
            );
            Db::execute(
                "INSERT INTO idiom_explanations (idiom_id, lang_code, body, source)
                 VALUES (?, 'ru', ?, 'manual')",
                [$idiomId, $explanation]
            );
        }

        Db::execute('UPDATE idioms SET is_published = 1 WHERE id = ?', [$idiomId]);

        return $idiomId;
    }

    /**
     * The column is an ENUM, so a value outside it is stored as the empty string with
     * only a warning. Catching it here names the field and the value instead.
     *
     * @param list<string> $allowed
     */
    private function assertEnum(string $field, ?string $value, array $allowed): void
    {
        if ($value === null || in_array($value, $allowed, true)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            "'%s' is not a valid %s. Use one of: %s.", $value, $field, implode(', ', $allowed)
        ));
    }

    /**
     * A Danish term written with Cyrillic letters looks correct and is a different word.
     * term_norm is accent- and case-sensitive, so it stores as its own idiom that no
     * search, import or quiz will ever match.
     */
    private function assertDanishScript(string $term): void
    {
        if (!preg_match_all('/\p{Cyrillic}/u', $term, $m)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'The Danish term contains Cyrillic letters (%s) — most likely a keyboard slip. '
            . 'Rewrite it in the Latin alphabet.',
            implode(' ', array_unique($m[0]))
        ));
    }

    private function assertAnswerFits(string $primary): void
    {
        // A gloss this long has no distractors of comparable length, which makes the
        // question answerable on sight. The full explanation is kept separately.
        $words = Text::wordCount($primary);
        $chars = mb_strlen($primary, 'UTF-8');
        if ($words > self::MAX_ANSWER_WORDS || $chars > self::MAX_ANSWER_CHARS) {
            throw new \InvalidArgumentException(sprintf(
                'The translation is too long to be a quiz option (%d words, %d characters; '
                . 'the limit is %d words and %d characters). Shorten it to the core meaning — '
                . 'the full explanation is kept separately.',
                $words, $chars, self::MAX_ANSWER_WORDS, self::MAX_ANSWER_CHARS
            ));
        }
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
