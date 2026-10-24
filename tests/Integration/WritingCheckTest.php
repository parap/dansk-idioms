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
 * A learner's letter or blog post sent for review: checked against the task it answers,
 * counted against a daily allowance, and kept with what the reviewer said.
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

    private function service(?WritingReviewer $reviewer, int $perLearner = 3, int $site = 100): WritingCheckService
    {
        return new WritingCheckService($reviewer, perLearnerDaily: $perLearner, siteDaily: $site);
    }

    public function testReturnsTheReviewWithTheServersOwnWordCount(): void
    {
        $reviewer = $this->reviewer();

        $result = $this->service($reviewer)->check(null, 'anon-1', 'e1', self::TEXT, 'uk');

        self::assertSame(self::FEEDBACK, $result['feedback']);
        self::assertSame(18, $result['words']);
        self::assertSame(125, $result['minimum']);
        self::assertSame(2, $result['left_today']);
        self::assertSame([['e1', self::TEXT, 'uk']], $reviewer->asked);
    }

    public function testKeepsTheCheckWithWhatItCost(): void
    {
        $this->service($this->reviewer())->check(null, 'anon-1', 'b2', self::TEXT, 'ru');

        $row = Db::fetchOne('SELECT anon_key, user_id, task_id, text, word_count, model, input_tokens, output_tokens, feedback FROM writing_checks');
        self::assertSame('anon-1', $row['anon_key']);
        self::assertNull($row['user_id']);
        self::assertSame('b2', $row['task_id']);
        self::assertSame(self::TEXT, $row['text']);
        self::assertSame(18, (int) $row['word_count']);
        self::assertSame('fake-model', $row['model']);
        self::assertSame([1200, 900], [(int) $row['input_tokens'], (int) $row['output_tokens']]);
        self::assertEquals(self::FEEDBACK, json_decode($row['feedback'], true));
    }

    public function testRefusesATaskThePageDoesNotOffer(): void
    {
        $reviewer = $this->reviewer();

        try {
            $this->service($reviewer)->check(null, 'anon-1', 'e99', self::TEXT, 'ru');
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
            $this->service($reviewer)->check(null, 'anon-1', 'e1', 'Hej Sune, tak.', 'ru');
            self::fail('three words were reviewed');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $reviewer->asked);
    }

    public function testRefusesATextFarLongerThanTheTaskAsksFor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service($this->reviewer())->check(null, 'anon-1', 'e1', str_repeat('ord ', 700), 'ru');
    }

    public function testStopsALearnerAtTheDailyAllowance(): void
    {
        $reviewer = $this->reviewer();
        $service  = $this->service($reviewer, perLearner: 2);
        $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru');
        $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru');

        try {
            $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru');
            self::fail('a third check went through');
        } catch (WritingLimitReached) {
        }
        self::assertCount(2, $reviewer->asked);
        self::assertSame(1, $service->check(null, 'anon-2', 'e1', self::TEXT, 'ru')['left_today'], 'another learner was stopped too');
    }

    /** A signed-in learner is counted by account, so a fresh browser is no new allowance. */
    public function testCountsASignedInLearnerByAccount(): void
    {
        $user = $this->user();
        $service = $this->service($this->reviewer(), perLearner: 1);
        $service->check($user, 'anon-1', 'e1', self::TEXT, 'ru');

        $this->expectException(WritingLimitReached::class);
        $service->check($user, 'anon-2', 'e1', self::TEXT, 'ru');
    }

    public function testYesterdaysChecksDoNotCountToday(): void
    {
        $service = $this->service($this->reviewer(), perLearner: 1);
        $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru');
        Db::execute('UPDATE writing_checks SET created_at = NOW() - INTERVAL 25 HOUR');

        self::assertSame(0, $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru')['left_today']);
    }

    /** The whole site has a ceiling too: an allowance per browser is no limit to someone clearing cookies. */
    public function testStopsEveryoneAtTheSitesDailyCeiling(): void
    {
        $service = $this->service($this->reviewer(), perLearner: 5, site: 2);
        $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru');
        $service->check(null, 'anon-2', 'e1', self::TEXT, 'ru');

        $this->expectException(WritingLimitReached::class);
        $service->check(null, 'anon-3', 'e1', self::TEXT, 'ru');
    }

    public function testAFailedReviewIsNotCounted(): void
    {
        $service = $this->service($this->reviewer(new RuntimeException('overloaded')), perLearner: 1);

        try {
            $service->check(null, 'anon-1', 'e1', self::TEXT, 'ru');
            self::fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('overloaded', $e->getMessage());
        }
        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM writing_checks'));
    }

    /** With no key configured there is no reviewer, and the page is told so rather than left waiting. */
    public function testWithoutAReviewerTheCheckIsUnavailable(): void
    {
        $this->expectException(WritingCheckUnavailable::class);
        $this->service(null)->check(null, 'anon-1', 'e1', self::TEXT, 'ru');
    }

    private function user(): int
    {
        Db::execute("INSERT INTO users (email, password_hash) VALUES ('skriv@example.test', 'x')");
        return (int) Db::pdo()->lastInsertId();
    }
}
