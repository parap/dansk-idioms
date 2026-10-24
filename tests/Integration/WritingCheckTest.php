<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Writing\WritingCheckService;
use Dansk\Domain\Writing\WritingCheckUnavailable;
use Dansk\Domain\Writing\WritingLimitReached;
use Dansk\Domain\Writing\WritingReviewer;
use Dansk\Support\Db;
use InvalidArgumentException;
use RuntimeException;

/**
 * A letter or blog post sent for review: offered only to the site's admins, checked against
 * the task it answers, counted against a daily allowance, and kept with what came back.
 */
final class WritingCheckTest extends IntegrationTestCase
{
    private const FEEDBACK = [
        'summary' => 'Godt.', 'verdict' => 'borderline', 'task' => [], 'errors' => [], 'strengths' => [], 'corrected' => 'Hej.',
    ];

    private const TEXT = 'Hej Sune. Tak for din mail. Vi har det godt på landet, og børnene er glade for skolen.';

    /** Records what it was asked and answers with a fixed review. */
    private function reviewer(?RuntimeException $fails = null): WritingReviewer
    {
        return new class(self::FEEDBACK, $fails) implements WritingReviewer {
            public array $asked = [];
            public function __construct(private array $feedback, private ?RuntimeException $fails) {}
            public function review(array $task, string $text, string $lang): array
            {
                $this->asked[] = [$task['id'], $text, $lang];
                if ($this->fails) {
                    throw $this->fails;
                }
                return ['feedback' => $this->feedback, 'model' => 'fake-model', 'input_tokens' => 1200, 'output_tokens' => 900];
            }
        };
    }

    private function service(?WritingReviewer $reviewer, int $perUser = 3): WritingCheckService
    {
        return new WritingCheckService($reviewer, perUserDaily: $perUser);
    }

    private function user(string $role = 'admin', string $email = 'alex@example.test'): int
    {
        Db::execute('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)', [$email, 'x', $role]);
        return (int) Db::pdo()->lastInsertId();
    }

    public function testReturnsTheReviewWithTheServersOwnWordCount(): void
    {
        $reviewer = $this->reviewer();

        $result = $this->service($reviewer)->check($this->user(), 'e1', self::TEXT, 'uk');

        self::assertSame(self::FEEDBACK, $result['feedback']);
        self::assertSame(18, $result['words']);
        self::assertSame(125, $result['minimum']);
        self::assertSame(2, $result['left_today']);
        self::assertSame([['e1', self::TEXT, 'uk']], $reviewer->asked);
    }

    public function testKeepsTheCheckWithWhatItCost(): void
    {
        $user = $this->user();
        $this->service($this->reviewer())->check($user, 'b2', self::TEXT, 'ru');

        $row = Db::fetchOne('SELECT user_id, task_id, text, word_count, model, input_tokens, output_tokens, feedback FROM writing_checks');
        self::assertSame($user, (int) $row['user_id']);
        self::assertSame('b2', $row['task_id']);
        self::assertSame(self::TEXT, $row['text']);
        self::assertSame(18, (int) $row['word_count']);
        self::assertSame('fake-model', $row['model']);
        self::assertSame([1200, 900], [(int) $row['input_tokens'], (int) $row['output_tokens']]);
        self::assertEquals(self::FEEDBACK, json_decode($row['feedback'], true));
    }

    /** The review runs on the owner's own subscription, so nobody else is offered it. */
    public function testAGuestIsNotOfferedTheCheck(): void
    {
        $reviewer = $this->reviewer();

        try {
            $this->service($reviewer)->check(null, 'e1', self::TEXT, 'ru');
            self::fail('a guest was reviewed');
        } catch (WritingCheckUnavailable) {
        }
        self::assertSame([], $reviewer->asked);
        self::assertSame(['available' => false, 'left_today' => 0], $this->service($reviewer)->availability(null));
    }

    public function testASignedInLearnerWhoIsNoAdminIsNotOfferedTheCheck(): void
    {
        $learner  = $this->user('user', 'learner@example.test');
        $reviewer = $this->reviewer();

        try {
            $this->service($reviewer)->check($learner, 'e1', self::TEXT, 'ru');
            self::fail('a learner who is no admin was reviewed');
        } catch (WritingCheckUnavailable) {
        }
        self::assertSame([], $reviewer->asked);
        self::assertFalse($this->service($reviewer)->availability($learner)['available']);
    }

    public function testAnAdminIsOfferedTheCheckWithTheChecksLeftToday(): void
    {
        $admin   = $this->user();
        $service = $this->service($this->reviewer(), perUser: 3);
        $service->check($admin, 'e1', self::TEXT, 'ru');

        self::assertSame(['available' => true, 'left_today' => 2], $service->availability($admin));
    }

    /** With no reviewer configured nothing can be checked, whoever asks. */
    public function testWithoutAReviewerTheCheckIsUnavailable(): void
    {
        $admin = $this->user();

        self::assertFalse($this->service(null)->availability($admin)['available']);
        $this->expectException(WritingCheckUnavailable::class);
        $this->service(null)->check($admin, 'e1', self::TEXT, 'ru');
    }

    public function testRefusesATaskThePageDoesNotOffer(): void
    {
        $reviewer = $this->reviewer();

        try {
            $this->service($reviewer)->check($this->user(), 'e99', self::TEXT, 'ru');
            self::fail('an unknown task was reviewed');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $reviewer->asked);
    }

    /** Too little to review is turned away before anything is spent on it. */
    public function testRefusesATextOfAFewWords(): void
    {
        $reviewer = $this->reviewer();

        try {
            $this->service($reviewer)->check($this->user(), 'e1', 'Hej Sune, tak.', 'ru');
            self::fail('three words were reviewed');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $reviewer->asked);
    }

    public function testRefusesATextFarLongerThanTheTaskAsksFor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service($this->reviewer())->check($this->user(), 'e1', str_repeat('ord ', 700), 'ru');
    }

    public function testStopsAtTheDailyAllowance(): void
    {
        $admin    = $this->user();
        $reviewer = $this->reviewer();
        $service  = $this->service($reviewer, perUser: 2);
        $service->check($admin, 'e1', self::TEXT, 'ru');
        $service->check($admin, 'e1', self::TEXT, 'ru');

        try {
            $service->check($admin, 'e1', self::TEXT, 'ru');
            self::fail('a third check went through');
        } catch (WritingLimitReached) {
        }
        self::assertCount(2, $reviewer->asked);
        self::assertSame(1, $service->check($this->user('admin', 'other@example.test'), 'e1', self::TEXT, 'ru')['left_today'], 'another admin was stopped too');
    }

    public function testYesterdaysChecksDoNotCountToday(): void
    {
        $admin   = $this->user();
        $service = $this->service($this->reviewer(), perUser: 1);
        $service->check($admin, 'e1', self::TEXT, 'ru');
        Db::execute('UPDATE writing_checks SET created_at = NOW() - INTERVAL 25 HOUR');

        self::assertSame(0, $service->check($admin, 'e1', self::TEXT, 'ru')['left_today']);
    }

    public function testAFailedReviewIsNotCounted(): void
    {
        $service = $this->service($this->reviewer(new RuntimeException('timed out')), perUser: 1);

        try {
            $service->check($this->user(), 'e1', self::TEXT, 'ru');
            self::fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('timed out', $e->getMessage());
        }
        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM writing_checks'));
    }
}
