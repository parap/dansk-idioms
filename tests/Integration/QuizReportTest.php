<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\QuizService;
use Dansk\Import\Importer;
use Dansk\Support\Db;

/** "This wrong answer was also correct": two reports stop a distractor being offered. */
final class QuizReportTest extends IntegrationTestCase
{
    private const ANON = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        (new Importer())->import($this->fixtureExport(), 'Fixture');
    }

    /** @return array{0:string,1:array<string,mixed>} the session and the option chosen */
    private function answeredWrong(QuizService $quiz): array
    {
        $session = $quiz->start(null, self::ANON, 'ru', 1);
        $row = Db::fetchOne(
            'SELECT q.options, q.correct_index FROM quiz_questions q
             JOIN quiz_sessions s ON s.id = q.session_id WHERE s.public_id = ? AND q.position = 1',
            [$session['public_id']]
        );
        $wrong = ((int) $row['correct_index'] + 1) % 4;
        $quiz->answer($session['public_id'], 1, $wrong, null);

        return [$session['public_id'], json_decode((string) $row['options'], true)[$wrong]];
    }

    public function testAReportOnAMissingQuestionIsRefused(): void
    {
        self::assertFalse((new QuizService())->report('01ARZ3NDEKTSV4RRFFQ69G5FAV', 1, 'also_correct'));
    }

    public function testTwoAlsoCorrectReportsActivateABlock(): void
    {
        $quiz = new QuizService();
        [$sid, $chosen] = $this->answeredWrong($quiz);

        self::assertTrue($quiz->report($sid, 1, 'also_correct'));
        self::assertSame(0, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM distractor_blocks WHERE blocked_tr_id = ? AND is_active = 1', [(int) $chosen['ref']]
        ), 'one report must not block on its own');

        $quiz->report($sid, 1, 'also_correct');
        self::assertSame(1, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM distractor_blocks WHERE blocked_tr_id = ? AND is_active = 1', [(int) $chosen['ref']]
        ));
    }

    public function testAReportForAnotherReasonBlocksNothing(): void
    {
        $quiz = new QuizService();
        [$sid] = $this->answeredWrong($quiz);

        $quiz->report($sid, 1, 'typo');
        $quiz->report($sid, 1, 'typo');

        self::assertSame(1, (int) Db::fetchValue(
            'SELECT reported FROM quiz_questions q JOIN quiz_sessions s ON s.id = q.session_id WHERE s.public_id = ?', [$sid]
        ));
        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM distractor_blocks'));
    }
}
