<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use Dansk\Support\Config;
use Dansk\Support\Db;
use InvalidArgumentException;

/**
 * Sends a letter or blog post for review and keeps what came back.
 *
 * The review runs on the site owner's own Claude subscription, which is for the owner's own
 * use, so only admins are offered it, each with a daily allowance that keeps the
 * subscription's limits for the owner's other work. A failed review is not counted.
 */
final class WritingCheckService
{
    /** Fewer words than this give the reviewer nothing to say. */
    private const FEWEST_WORDS = 10;

    /** Several times the test's minimum: past this the text is not an answer to the task. */
    private const MOST_WORDS = 600;

    private const MOST_CHARACTERS = 6000;

    /** The models the page offers, the one that spends least of the subscription first. */
    public const MODELS = ['haiku', 'sonnet', 'opus'];

    public function __construct(
        private ?WritingReviewer $reviewer,
        private int $perUserDaily,
        private WritingTasks $tasks = new WritingTasks(),
    ) {}

    /** Switched off unless the config names the directory the host worker reads. */
    public static function fromConfig(): self
    {
        $dir = Config::get('writing.queue_dir');

        return new self(
            $dir ? new SubscriptionWritingReviewer((string) $dir) : null,
            (int) Config::get('writing.per_user_daily'),
        );
    }

    /** @return array{available:bool,left_today:int} what the page offers this visitor */
    public function availability(?int $userId): array
    {
        if (!$this->offeredTo($userId)) {
            return ['available' => false, 'left_today' => 0];
        }

        return ['available' => true, 'left_today' => max(0, $this->perUserDaily - $this->usedToday($userId))];
    }

    /** @return array{feedback:array<string,mixed>,words:int,minimum:int,left_today:int} */
    public function check(?int $userId, string $taskId, string $text, string $lang, string $model): array
    {
        if (!$this->offeredTo($userId)) {
            throw new WritingCheckUnavailable('Checking is not offered here.');
        }
        if (!in_array($model, self::MODELS, true)) {
            throw new InvalidArgumentException("There is no model '{$model}' to check with.");
        }
        $task = $this->tasks->find($taskId);
        if ($task === null) {
            throw new InvalidArgumentException("There is no writing task '{$taskId}'.");
        }
        $words = WritingTasks::words($text);
        if ($words < self::FEWEST_WORDS) {
            throw new InvalidArgumentException('Write at least ' . self::FEWEST_WORDS . ' words before asking for a check.');
        }
        if ($words > self::MOST_WORDS || mb_strlen($text) > self::MOST_CHARACTERS) {
            throw new InvalidArgumentException('The text is far longer than the task asks for.');
        }

        $used = $this->usedToday($userId);
        if ($used >= $this->perUserDaily) {
            throw new WritingLimitReached('Today\'s checks are used up.');
        }

        $review = $this->reviewer->review($task, $text, $lang, $model);

        Db::execute(
            'INSERT INTO writing_checks (user_id, task_id, text, word_count, feedback, model, input_tokens, output_tokens)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $userId, $taskId, $text, $words, json_encode($review['feedback'], JSON_UNESCAPED_UNICODE),
                $review['model'], $review['input_tokens'], $review['output_tokens'],
            ]
        );

        return [
            'feedback'   => $review['feedback'],
            'words'      => $words,
            'minimum'    => $task['minimum'],
            'left_today' => $this->perUserDaily - $used - 1,
        ];
    }

    private function offeredTo(?int $userId): bool
    {
        return $this->reviewer !== null && $userId !== null
            && Db::fetchValue('SELECT role FROM users WHERE id = ?', [$userId]) === 'admin';
    }

    private function usedToday(int $userId): int
    {
        return (int) Db::fetchValue(
            'SELECT COUNT(*) FROM writing_checks WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY',
            [$userId]
        );
    }
}
