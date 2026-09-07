<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Import\Importer;
use Dansk\Support\Db;

final class ImporterTest extends IntegrationTestCase
{
    private function import(): array
    {
        return (new Importer())->import($this->fixtureExport(), 'Fixture');
    }

    public function testImportBuildsAnAnswerableCorpus(): void
    {
        $stats = $this->import();

        self::assertSame(16, $stats['messages']);
        self::assertGreaterThan(10, $stats['idioms_created']);
        self::assertSame(0, $stats['published_without_answer']);
        $this->assertCorpusInvariants();

        self::assertGreaterThan(
            0,
            (int) Db::fetchValue('SELECT COUNT(*) FROM idioms WHERE is_published = 1')
        );
    }

    /**
     * The single highest-value assertion here. An upsert that omits any column it
     * should carry, or increments a derived counter, shows up as a corpus that differs
     * from itself after a second import of the same file.
     */
    public function testReimportingAnUnchangedExportChangesNothing(): void
    {
        $this->import();
        $before = $this->snapshot();

        $second = $this->import();

        self::assertSame(0, $second['idioms_created'], 'nothing new may be created');
        self::assertSame($before, $this->snapshot(), 'the corpus must be byte-identical');
        $this->assertCorpusInvariants();
    }

    public function testSeenCountDoesNotInflateOnRepeatedImports(): void
    {
        $this->import();
        $this->import();
        $this->import();

        self::assertSame(
            0,
            (int) Db::fetchValue('SELECT COUNT(*) FROM idioms WHERE seen_count > 1'),
            'seen_count is derived, so importing the same export three times cannot raise it'
        );
    }

    /** A human correction outranks every later re-parse -- decision, term and link. */
    public function testAHumanCorrectionSurvivesReimport(): void
    {
        $this->import();

        $entry = Db::fetchOne("SELECT id, idiom_id FROM raw_entries WHERE status = 'auto_accepted' LIMIT 1");
        Db::execute(
            "UPDATE raw_entries SET status = 'fixed', term_guess = 'HUMAN EDITED' WHERE id = ?",
            [(int) $entry['id']]
        );

        $this->import();

        $after = Db::fetchOne('SELECT status, term_guess, idiom_id FROM raw_entries WHERE id = ?', [(int) $entry['id']]);
        self::assertSame('fixed', $after['status']);
        self::assertSame('HUMAN EDITED', $after['term_guess']);
        self::assertSame(
            (int) $entry['idiom_id'],
            (int) $after['idiom_id'],
            'the link to the idiom must survive: nulling it once made good idioms look orphaned'
        );
    }

    public function testEntriesAwaitingReviewKeepTheirIdiomUnpublished(): void
    {
        $this->import();

        self::assertSame(
            0,
            (int) Db::fetchValue(
                "SELECT COUNT(*) FROM raw_entries
                 WHERE status = 'needs_review' AND idiom_id IS NULL AND term_guess <> ''"
            ),
            'a reviewable entry must stay linked to its idiom, or a prune will delete it'
        );
    }

    public function testNoIdiomIsLeftOrphaned(): void
    {
        $this->import();

        self::assertSame(
            0,
            (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idioms i
                 WHERE NOT EXISTS (SELECT 1 FROM raw_entries r WHERE r.idiom_id = i.id)'
            )
        );
    }

    /**
     * A published idiom holding usable translations but no primary is unanswerable: it
     * counts towards the corpus and appears in no quiz. The import repairs that state
     * by promoting the shortest usable reading.
     *
     * The idiom here has no raw entry of its own, so the import never rewrites its
     * translations — which is what isolates the repair from the ordinary upsert path.
     */
    public function testAPublishedIdiomLeftWithoutAPrimaryIsRepaired(): void
    {
        $this->import();

        Db::execute(
            "INSERT INTO idioms (lang_code, term, term_norm, kind, is_published, rand_key)
             VALUES ('da', 'at stå for skud', 'stå for skud', 'idiom', 1, RAND())"
        );
        $idiomId = (int) Db::pdo()->lastInsertId();
        Db::execute(
            "INSERT INTO idiom_translations
                (idiom_id, lang_code, text, text_norm, sense_type, is_primary, quiz_usable,
                 shape, word_count, char_count, source, confidence)
             VALUES (?, 'ru', 'принимать удар на себя', 'принимать удар на себя',
                     'idiomatic', NULL, 1, 'verbal', 4, 22, 'manual', 1.0)",
            [$idiomId]
        );

        self::assertSame(
            0,
            (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1',
                [$idiomId]
            ),
            'precondition: published, one usable translation, no primary'
        );

        $this->import();

        self::assertSame(
            1,
            (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idiom_translations
                 WHERE idiom_id = ? AND is_primary = 1 AND quiz_usable = 1',
                [$idiomId]
            ),
            'the usable translation must be promoted to primary'
        );
        $this->assertCorpusInvariants();
    }

    /**
     * A human's chosen primary outranks the parser's. Marking an imported row primary
     * while a manual one stands violates uq_primary and aborts the whole run, leaving
     * the corpus half-written.
     */
    public function testAManualPrimaryDoesNotCollideWithTheImporter(): void
    {
        $this->import();

        $idiomId = (int) Db::fetchValue(
            'SELECT idiom_id FROM idiom_translations WHERE is_primary = 1 AND quiz_usable = 1 LIMIT 1'
        );
        Db::execute('UPDATE idiom_translations SET is_primary = NULL WHERE idiom_id = ?', [$idiomId]);
        Db::execute(
            "INSERT INTO idiom_translations
                (idiom_id, lang_code, text, text_norm, sense_type, is_primary, quiz_usable,
                 shape, word_count, char_count, source, confidence)
             VALUES (?, 'ru', 'выбранный человеком ответ', 'выбранный человеком ответ',
                     'idiomatic', 1, 1, 'nominal', 3, 25, 'manual', 1.0)",
            [$idiomId]
        );

        $stats = $this->import();

        self::assertSame(16, $stats['messages'], 'the run must reach the end, not abort partway');

        $primaries = Db::fetchAll(
            'SELECT text, source FROM idiom_translations WHERE idiom_id = ? AND is_primary = 1',
            [$idiomId]
        );
        self::assertCount(1, $primaries);
        self::assertSame('manual', $primaries[0]['source'], 'the human choice must survive');
        self::assertSame('выбранный человеком ответ', $primaries[0]['text']);
        $this->assertCorpusInvariants();
    }

    /** A corrected parse must remove the reading it no longer produces, not merely add. */
    public function testStaleImporterRowsAreRemoved(): void
    {
        $this->import();

        $idiomId = (int) Db::fetchValue('SELECT id FROM idioms WHERE is_published = 1 LIMIT 1');
        Db::execute(
            "INSERT INTO idiom_translations
                (idiom_id, lang_code, text, text_norm, sense_type, is_primary, quiz_usable,
                 shape, word_count, char_count, source, confidence)
             VALUES (?, 'ru', 'ельно / с намеком', 'ельно / с намеком', 'idiomatic', NULL, 1,
                     'nominal', 3, 17, 'import', 0.6)",
            [$idiomId]
        );

        $this->import();

        self::assertSame(
            0,
            (int) Db::fetchValue(
                "SELECT COUNT(*) FROM idiom_translations WHERE idiom_id = ? AND text = 'ельно / с намеком'",
                [$idiomId]
            ),
            'a reading the current parse does not produce must not survive the import'
        );
    }

    public function testLiteralGlossesAreStoredButNeverTheAnswer(): void
    {
        $this->import();

        self::assertGreaterThan(
            0,
            (int) Db::fetchValue("SELECT COUNT(*) FROM idiom_translations WHERE sense_type = 'literal'"),
            'literal readings are kept -- they make the best distractors'
        );
        self::assertSame(
            0,
            (int) Db::fetchValue(
                "SELECT COUNT(*) FROM idiom_translations
                 WHERE sense_type <> 'idiomatic' AND (quiz_usable = 1 OR is_primary = 1)"
            ),
            'only an idiomatic reading may be offered as the correct answer'
        );
    }

    public function testNonIdiomChatterIsNotPublished(): void
    {
        $this->import();

        self::assertSame(
            0,
            (int) Db::fetchValue(
                "SELECT COUNT(*) FROM idioms WHERE is_published = 1 AND term LIKE '%gad komme forbi%'"
            ),
            'a plain Danish sentence is not an idiom'
        );
    }

    public function testEveryAnswerIsShortEnoughToBeAnOption(): void
    {
        $this->import();

        self::assertSame(
            0,
            (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_translations WHERE quiz_usable = 1 AND word_count > 6'),
            'an over-long option has no distractors of comparable length'
        );
    }

    /** Everything that must stay stable across a re-import. */
    private function snapshot(): array
    {
        return [
            'idioms' => Db::fetchAll(
                'SELECT term, term_norm, kind, register, is_published, seen_count FROM idioms ORDER BY term_norm'
            ),
            'translations' => Db::fetchAll(
                'SELECT idiom_id, text, sense_type, is_primary, quiz_usable FROM idiom_translations
                 ORDER BY idiom_id, text'
            ),
            'entries' => Db::fetchAll('SELECT entry_index, status, term_guess FROM raw_entries ORDER BY id'),
        ];
    }
}
