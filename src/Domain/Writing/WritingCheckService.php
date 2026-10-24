<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use Anthropic\Client;
use Dansk\Support\Config;
use Dansk\Support\Db;
use InvalidArgumentException;

/**
 * Sends a learner's letter or blog post for review and keeps what came back.
 *
 * Every review is paid for, so each learner has a daily allowance, and the site has a
 * ceiling of its own: an allowance per browser is no limit to someone who clears cookies.
 * A review that fails is not counted against either.
 */
final class WritingCheckService
{
    /** Fewer words than this give the reviewer nothing to say. */
    private const FEWEST_WORDS = 10;

    /** Several times the test's minimum: past this the text is not an answer to the task. */
    private const MOST_WORDS = 600;

    private const MOST_CHARACTERS = 6000;

    public function __construct(
        private ?WritingReviewer $reviewer,
        private int $perLearnerDaily,
        private int $siteDaily,
        private WritingTasks $tasks = new WritingTasks(),
    ) {}

    /** No API key configured means no reviewer, and the check reports itself unavailable. */
    public static function fromConfig(): self
    {
        $key = Config::get('writing.api_key');

        return new self(
            $key ? new ClaudeWritingReviewer(
                // Two tries of 80 seconds stay inside the 180 the controller allows the request.
                new Client(apiKey: $key, requestOptions: ['timeout' => 80.0, 'maxRetries' => 1]),
                (string) Config::get('writing.model')
            ) : null,
            (int) Config::get('writing.per_learner_daily'),
            (int) Config::get('writing.site_daily'),
        );
    }

    /** @return array{feedback:array<string,mixed>,words:int,minimum:int,left_today:int} */
    public function check(?int $userId, string $anonKey, string $taskId, string $text, string $lang): array
    {
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
        if ($this->reviewer === null) {
            throw new WritingCheckUnavailable('Checking is not switched on.');
        }

        $used = $this->usedToday($userId, $anonKey);
        if ($used >= $this->perLearnerDaily) {
            throw new WritingLimitReached('learner');
        }
        if ((int) Db::fetchValue('SELECT COUNT(*) FROM writing_checks WHERE created_at > NOW() - INTERVAL 1 DAY') >= $this->siteDaily) {
            throw new WritingLimitReached('site');
        }

        $review = $this->reviewer->review($task, $text, $lang);

        Db::execute(
            'INSERT INTO writing_checks (user_id, anon_key, task_id, text, word_count, feedback, model, input_tokens, output_tokens)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $userId, $userId === null ? $anonKey : null, $taskId, $text, $words,
                json_encode($review['feedback'], JSON_UNESCAPED_UNICODE), $review['model'],
                $review['input_tokens'], $review['output_tokens'],
            ]
        );

        return [
            'feedback'   => $review['feedback'],
            'words'      => $words,
            'minimum'    => $task['minimum'],
            'left_today' => $this->perLearnerDaily - $used - 1,
        ];
    }

    /** A signed-in learner is counted by account, so a new browser brings no new allowance. */
    private function usedToday(?int $userId, string $anonKey): int
    {
        return (int) ($userId === null
            ? Db::fetchValue('SELECT COUNT(*) FROM writing_checks WHERE anon_key = ? AND created_at > NOW() - INTERVAL 1 DAY', [$anonKey])
            : Db::fetchValue('SELECT COUNT(*) FROM writing_checks WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY', [$userId]));
    }
}
