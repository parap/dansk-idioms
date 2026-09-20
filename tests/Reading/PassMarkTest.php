<?php declare(strict_types=1);

namespace Dansk\Tests\Reading;

use Dansk\Domain\Reading\PassMark;
use PHPUnit\Framework\TestCase;

/**
 * The citizenship exam's verdict. It is not a score on a scale: 36 of 45 passes, and 36
 * of 45 with only three of the five values questions right does not. The two conditions
 * are read as one rule here so that neither can be applied without the other.
 */
final class PassMarkTest extends TestCase
{
    private function decide(
        int $correct,
        int $vaerdierCorrect,
        int $asked = 45,
        int $paper = 45,
        ?int $pass = 36,
        ?int $vaerdierMin = 4,
    ): array {
        return PassMark::decide(
            correct: $correct,
            questions: $asked,
            paperQuestions: $paper,
            pass: $pass,
            vaerdierCorrect: $vaerdierCorrect,
            vaerdierQuestions: 5,
            vaerdierMin: $vaerdierMin,
        );
    }

    public function testThePassMarkItselfPasses(): void
    {
        self::assertSame(PassMark::PASSED, $this->decide(36, 4)['verdict']);
    }

    public function testOneBelowThePassMarkFails(): void
    {
        self::assertSame(PassMark::FAILED, $this->decide(35, 5)['verdict']);
    }

    /** Enough correct overall is not a pass without the values block. */
    public function testEnoughOverallStillFailsOnTheValuesBlock(): void
    {
        $tally = $this->decide(40, 3);

        self::assertSame(PassMark::FAILED, $tally['verdict']);
        self::assertSame(3, $tally['vaerdier_correct']);
        self::assertSame(4, $tally['vaerdier_min']);
    }

    public function testAPerfectPaperPasses(): void
    {
        self::assertSame(PassMark::PASSED, $this->decide(45, 5)['verdict']);
    }

    /** The sittings before the values block: 32 of 40, and no second condition. */
    public function testTheOlderPaperIsJudgedOnItsOwnMark(): void
    {
        $older = fn(int $correct): array => $this->decide(
            $correct, 0, asked: 40, paper: 40, pass: 32, vaerdierMin: null
        );

        self::assertSame(PassMark::PASSED, $older(32)['verdict']);
        self::assertSame(PassMark::FAILED, $older(31)['verdict']);
    }

    /** The June 2020 sheet states no pass mark, and one is not invented for it. */
    public function testAPaperWithNoStatedMarkGetsNoVerdict(): void
    {
        $tally = $this->decide(40, 5, pass: null, vaerdierMin: null);

        self::assertNull($tally['verdict']);
        self::assertSame('no_pass_mark', $tally['reason']);
    }

    /**
     * A round that left the current-affairs block out is not the paper the mark belongs
     * to: 36 of 40 is a different exam from 36 of 45, and reporting it as a pass would
     * tell the learner they passed something they did not sit.
     */
    public function testAShortenedRoundGetsNoVerdict(): void
    {
        $tally = $this->decide(38, 5, asked: 40);

        self::assertNull($tally['verdict']);
        self::assertSame('partial_round', $tally['reason']);
        self::assertSame(38, $tally['correct']);
        self::assertSame(40, $tally['questions']);
    }

    public function testAFullRoundCarriesNoReason(): void
    {
        self::assertNull($this->decide(36, 4)['reason']);
    }
}
