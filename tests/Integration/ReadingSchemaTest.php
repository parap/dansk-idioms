<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Support\Db;
use PDOException;

/**
 * The reading schema's structural guarantees. Each one is enforced by the database
 * rather than by application code, so a future writer that forgets the rule is refused
 * at the point of insert instead of producing a paper that cannot be graded.
 */
final class ReadingSchemaTest extends IntegrationTestCase
{
    private function passage(string $slug, string $kind = 'cloze'): int
    {
        Db::execute(
            'INSERT INTO reading_passages (slug, kind, title, body, word_count, is_published)
             VALUES (?,?,?,?,?,1)',
            [$slug, $kind, 'T', 'Body with a gap {{1}}.', 5]
        );
        return (int) Db::pdo()->lastInsertId();
    }

    private function item(int $passageId, int $position, int $points = 1): int
    {
        Db::execute(
            'INSERT INTO reading_items (passage_id, position, points) VALUES (?,?,?)',
            [$passageId, $position, $points]
        );
        return (int) Db::pdo()->lastInsertId();
    }

    private function option(int $passageId, ?int $itemId, string $label): int
    {
        Db::execute(
            'INSERT INTO reading_options (passage_id, item_id, label, text, sort)
             VALUES (?,?,?,?,0)',
            [$passageId, $itemId, $label, 'option ' . $label]
        );
        return (int) Db::pdo()->lastInsertId();
    }

    public function testAPassageSlugCannotRepeat(): void
    {
        $this->passage('cykler-i-byen');

        $this->expectException(PDOException::class);
        $this->passage('cykler-i-byen');
    }

    public function testTwoSlugsDifferingOnlyByADanishLetterAreDistinct(): void
    {
        // Under the server default utf8mb4_0900_ai_ci these collide and the second
        // insert is silently refused -- on exactly the characters this project is about.
        $this->passage('laes-om-bolig');
        $this->passage('læs-om-bolig');

        self::assertSame(2, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_passages'));
    }

    public function testAnOptionCanBeTheCorrectAnswerToOnlyOneItem(): void
    {
        // This is what makes the insertion task's "each lettered part is usable once"
        // rule a property of the schema rather than of whoever wrote the paper.
        $p  = $this->passage('indsaet', 'insert');
        $i1 = $this->item($p, 1, 2);
        $i2 = $this->item($p, 2, 2);
        $o  = $this->option($p, null, 'A');

        Db::execute('UPDATE reading_items SET correct_option_id = ? WHERE id = ?', [$o, $i1]);

        $this->expectException(PDOException::class);
        Db::execute('UPDATE reading_items SET correct_option_id = ? WHERE id = ?', [$o, $i2]);
    }

    public function testABankOptionBelongsToThePassageRatherThanToAnyItem(): void
    {
        $p = $this->passage('indsaet-bank', 'insert');
        $this->item($p, 1, 2);
        $this->option($p, null, 'F');
        $this->option($p, null, 'G');

        // Two decoys: bank options that no item points at.
        self::assertSame(2, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_options o
             WHERE o.passage_id = ? AND o.item_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM reading_items i WHERE i.correct_option_id = o.id)',
            [$p]
        ));
    }

    public function testAnItemPositionIsUniqueWithinItsPassage(): void
    {
        $p = $this->passage('positioner');
        $this->item($p, 1);

        $this->expectException(PDOException::class);
        $this->item($p, 1);
    }

    public function testDeletingAPassageTakesItsItemsAndOptionsWithIt(): void
    {
        $p = $this->passage('slettes');
        $i = $this->item($p, 1);
        $this->option($p, $i, 'A');

        Db::execute('DELETE FROM reading_passages WHERE id = ?', [$p]);

        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_items'));
        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_options'));
    }

    public function testTheShippedGradeScaleSurvivesTheTestReset(): void
    {
        // Seed rows live in a migration, and the per-test wipe is built from SHOW TABLES.
        // Without an explicit exclusion the scale is gone before the first assertion.
        self::assertSame(1, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_grade_scales WHERE is_active = 1'
        ));
    }

    public function testEveryAttainablePointTotalMapsToExactlyOneKarakter(): void
    {
        $max = (int) Db::fetchValue('SELECT max_points FROM reading_grade_scales WHERE is_active = 1');
        self::assertGreaterThan(0, $max);

        for ($points = 0; $points <= $max; $points++) {
            $hits = (int) Db::fetchValue(
                'SELECT COUNT(*) FROM reading_grade_bands b
                 JOIN reading_grade_scales s ON s.id = b.scale_id AND s.is_active = 1
                 WHERE ? BETWEEN b.min_points AND b.max_points',
                [$points]
            );
            self::assertSame(1, $hits, "score {$points} maps to {$hits} bands, expected exactly 1");
        }
    }
}
