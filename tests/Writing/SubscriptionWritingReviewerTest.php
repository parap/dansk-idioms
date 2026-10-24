<?php declare(strict_types=1);

namespace Dansk\Tests\Writing;

use Dansk\Domain\Writing\SubscriptionWritingReviewer;
use Dansk\Domain\Writing\WritingTasks;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * The review is handed to a worker on the host through a shared directory: a job file goes
 * in, an answer file comes back. Here the wait between looks plays the worker.
 */
final class SubscriptionWritingReviewerTest extends TestCase
{
    private const FEEDBACK = [
        'summary' => 'Godt.', 'verdict' => 'likely_pass', 'task' => [], 'errors' => [], 'strengths' => ['Klart.'], 'corrected' => 'Hej Sune.',
    ];

    private string $dir;

    /** @var list<array<string,mixed>> */
    private array $jobs = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/writing-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** A worker that answers every job in the queue with $answer, after $after looks. */
    private function worker(array $answer, int $after = 1): callable
    {
        $looks = 0;
        return function () use ($answer, $after, &$looks): void {
            if (++$looks < $after) {
                return;
            }
            foreach (glob("{$this->dir}/queue/*.json") as $file) {
                $this->jobs[] = json_decode((string) file_get_contents($file), true);
                file_put_contents("{$this->dir}/done/" . basename($file), json_encode($answer));
                unlink($file);
            }
        };
    }

    private function answered(): array
    {
        return ['ok' => true, 'output' => self::FEEDBACK, 'model' => 'claude-opus-5-5', 'input_tokens' => 2400, 'output_tokens' => 800];
    }

    public function testHandsTheWorkerTheSystemPromptTheRequestAndTheSchema(): void
    {
        (new SubscriptionWritingReviewer($this->dir, 10, $this->worker($this->answered())))
            ->review((new WritingTasks())->find('e1'), 'Hej Sune, tak for din mail.', 'ru', 'sonnet');

        self::assertCount(1, $this->jobs);
        self::assertSame('sonnet', $this->jobs[0]['model']);
        self::assertStringContainsString('Modultest DU3', $this->jobs[0]['system']);
        self::assertStringContainsString('Hej Sune, tak for din mail.', $this->jobs[0]['prompt']);
        self::assertSame(['summary', 'verdict', 'task', 'errors', 'strengths', 'corrected'], $this->jobs[0]['schema']['required']);
    }

    /** Each kind of task has its own prompt and schema: a connector task is not reviewed as a letter. */
    public function testAConnectorTaskIsAskedWithTheConnectorPrompt(): void
    {
        $answer = ['output' => ['summary' => 'Godt.', 'sentences' => []]] + $this->answered();

        $review = (new SubscriptionWritingReviewer($this->dir, 10, $this->worker($answer)))
            ->review((new WritingTasks())->find('fo-tid'), 'Først spiste vi, derefter gik vi.', 'ru', 'haiku');

        self::assertSame(['summary', 'sentences'], $this->jobs[0]['schema']['required']);
        self::assertSame([], $review['feedback']['sentences']);
    }

    public function testReturnsTheReviewAndWhatItCostAndTidiesUp(): void
    {
        $review = (new SubscriptionWritingReviewer($this->dir, 10, $this->worker($this->answered(), after: 3)))
            ->review((new WritingTasks())->find('b1'), 'Jeg er enig med Helle.', 'uk', 'haiku');

        self::assertSame(self::FEEDBACK, $review['feedback']);
        self::assertSame('claude-opus-5-5', $review['model']);
        self::assertSame([2400, 800], [$review['input_tokens'], $review['output_tokens']]);
        self::assertSame([], glob("{$this->dir}/done/*"), 'the answer file was left behind');
    }

    public function testAFailureTheWorkerReportsIsAFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('usage limit');

        (new SubscriptionWritingReviewer($this->dir, 10, $this->worker(['ok' => false, 'error' => 'usage limit reached'])))
            ->review((new WritingTasks())->find('e1'), 'Hej.', 'ru', 'haiku');
    }

    public function testAnAnswerThatIsNoReviewIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new SubscriptionWritingReviewer($this->dir, 10, $this->worker(['output' => ['summary' => 'Godt.']] + $this->answered())))
            ->review((new WritingTasks())->find('e1'), 'Hej.', 'ru', 'haiku');
    }

    /** A job nobody answered in time is taken back, so the worker does not spend a review on it later. */
    public function testGivesUpAfterItsTimeAndTakesTheJobBack(): void
    {
        $reviewer = new SubscriptionWritingReviewer($this->dir, 2, static function (): void {});

        try {
            $reviewer->review((new WritingTasks())->find('e1'), 'Hej.', 'ru', 'haiku');
            self::fail('a review came back from nobody');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('no answer', $e->getMessage());
        }
        self::assertSame([], glob("{$this->dir}/queue/*"), 'the abandoned job is still queued');
    }

    /** The worker reads queue/*.json, so a job is one file of that name and nothing beside it. */
    public function testQueuesOneJobUnderTheNameTheWorkerReads(): void
    {
        $seen = [];
        $reviewer = new SubscriptionWritingReviewer($this->dir, 1, function () use (&$seen): void {
            $seen = array_map('basename', glob("{$this->dir}/queue/*"));
        });

        try {
            $reviewer->review((new WritingTasks())->find('e1'), 'Hej.', 'ru', 'haiku');
        } catch (RuntimeException) {
        }
        self::assertCount(1, $seen);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.json$/', $seen[0]);
    }
}
