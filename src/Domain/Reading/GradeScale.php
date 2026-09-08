<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

use Dansk\Support\Db;
use RuntimeException;

/**
 * Turns a point total into a karakter.
 *
 * The table is data because the real exam recalibrates it every session -- its maximum
 * moved from 37 points in 2019 and 2021 to 39 in 2023 and 2024, and every band edge moved
 * with it. A revision is a new scale plus a flip of is_active, never an edit here.
 */
final class GradeScale
{
    /**
     * A paper is scored as a proportion of its own maximum before the table is consulted.
     * Papers vary in size while the corpus is thin, and a raw count would read a perfect
     * short paper as a failed one.
     */
    public function karakter(int $scored, int $max): string
    {
        $scale = Db::fetchOne(
            'SELECT id, max_points FROM reading_grade_scales WHERE is_active = 1'
        );
        if ($scale === null) {
            throw new RuntimeException('No grade scale is active.');
        }

        $points = $max > 0 ? (int) round($scored / $max * (int) $scale['max_points']) : 0;

        // Db::fetchValue hands back PDO's false when nothing matched, never null.
        $karakter = Db::fetchValue(
            'SELECT karakter FROM reading_grade_bands
             WHERE scale_id = ? AND ? BETWEEN min_points AND max_points',
            [(int) $scale['id'], $points]
        );
        if ($karakter === false) {
            throw new RuntimeException("The active scale has no band covering {$points} points.");
        }

        return (string) $karakter;
    }

    public function activeScaleId(): int
    {
        $id = Db::fetchValue('SELECT id FROM reading_grade_scales WHERE is_active = 1');
        if ($id === false) {
            throw new RuntimeException('No grade scale is active.');
        }

        return (int) $id;
    }
}
