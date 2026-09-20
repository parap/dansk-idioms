<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

/**
 * The indfødsretsprøve's verdict.
 *
 * The exam is not marked on a scale. It states a number of correct answers and, since
 * the values block was added, a second requirement inside it: 36 of 45 passes, and 36 of
 * 45 with three of the five values questions right does not. Both conditions live here so
 * that neither can be applied without the other, and both numbers come from the paper's
 * own answer sheet rather than from a constant.
 *
 * A round that is not the whole paper gets no verdict at all. Leaving the current-affairs
 * block out is worth doing -- a 2021 question about a 2021 minister trains nobody now --
 * but 36 of 40 is a different exam from 36 of 45, and calling it a pass would tell a
 * learner they passed something they never sat.
 */
final class PassMark
{
    public const PASSED = 'bestaaet';

    public const FAILED = 'ikke_bestaaet';

    /**
     * @return array{verdict:?string, reason:?string, correct:int, questions:int, pass:?int,
     *               vaerdier_correct:int, vaerdier_questions:int, vaerdier_min:?int}
     */
    public static function decide(
        int $correct,
        int $questions,
        int $paperQuestions,
        ?int $pass,
        int $vaerdierCorrect,
        int $vaerdierQuestions,
        ?int $vaerdierMin,
    ): array {
        $tally = [
            'verdict'            => null,
            'reason'             => null,
            'correct'            => $correct,
            'questions'          => $questions,
            'pass'               => $pass,
            'vaerdier_correct'   => $vaerdierCorrect,
            'vaerdier_questions' => $vaerdierQuestions,
            'vaerdier_min'       => $vaerdierMin,
        ];

        if ($pass === null) {
            return ['reason' => 'no_pass_mark'] + $tally;
        }
        if ($questions !== $paperQuestions) {
            return ['reason' => 'partial_round'] + $tally;
        }

        $passed = $correct >= $pass
            && ($vaerdierMin === null || $vaerdierCorrect >= $vaerdierMin);

        return ['verdict' => $passed ? self::PASSED : self::FAILED] + $tally;
    }
}
