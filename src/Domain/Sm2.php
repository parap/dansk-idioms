<?php declare(strict_types=1);

namespace Dansk\Domain;

/**
 * SM-2 review scheduling, lightly simplified: an answer is right or wrong, with no
 * per-answer quality grade.
 *
 * Pure arithmetic, so a schedule is reproducible from its inputs and testable without a
 * database. Callers own their own storage -- the progress tables differ per module and a
 * shared writer would have to interpolate a table name into SQL.
 */
final class Sm2
{
    public const DEFAULT_EASE = 2.50;

    /** Below this the multiplier collapses future intervals toward nothing. */
    public const MIN_EASE = 1.30;

    /** Above this a single lucky streak pushes the next review out of sight. */
    public const MAX_EASE = 3.00;

    /**
     * @return array{ease: float, interval_days: int, repetitions: int, lapses: int}
     */
    public static function next(
        float $ease,
        int $intervalDays,
        int $repetitions,
        int $lapses,
        bool $isCorrect,
    ): array {
        if (!$isCorrect) {
            return [
                'ease'          => max(self::MIN_EASE, $ease - 0.2),
                'interval_days' => 1,
                'repetitions'   => 0,
                'lapses'        => $lapses + 1,
            ];
        }

        $repetitions++;

        // The multiplication uses the ease held before this answer; the reward is applied
        // to the ease that governs the answer after it.
        $interval = match ($repetitions) {
            1       => 1,
            2       => 6,
            default => (int) round($intervalDays * $ease),
        };

        return [
            'ease'          => min(self::MAX_EASE, $ease + 0.1),
            'interval_days' => $interval,
            'repetitions'   => $repetitions,
            'lapses'        => $lapses,
        ];
    }
}
