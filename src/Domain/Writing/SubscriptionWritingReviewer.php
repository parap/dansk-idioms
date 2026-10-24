<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use RuntimeException;

/**
 * The review by Claude Code on the server's own subscription. The app runs in a container
 * and Claude Code on the host, so the two meet in a shared directory: the job goes into
 * queue/, bin/writing-worker.py answers into done/, and this waits for the answer.
 */
final class SubscriptionWritingReviewer implements WritingReviewer
{
    private const LOOK_EVERY_SECONDS = 0.5;

    /** @var callable(): void */
    private $wait;

    public function __construct(
        private string $dir,
        private int $timeoutSeconds = 170,
        ?callable $wait = null,
        private ?ReviewPrompt $prompt = null,
    ) {
        $this->wait = $wait ?? static fn() => usleep((int) (self::LOOK_EVERY_SECONDS * 1_000_000));
    }

    public function review(array $task, string $text, string $lang, string $model): array
    {
        foreach (['queue', 'done'] as $sub) {
            if (!is_dir("{$this->dir}/{$sub}")) {
                mkdir("{$this->dir}/{$sub}", 0775, true);
            }
        }
        $id     = bin2hex(random_bytes(16));
        $job    = "{$this->dir}/queue/{$id}.json";
        $answer = "{$this->dir}/done/{$id}.json";

        $prompt = $this->prompt ?? ReviewPrompts::for($task);

        // Written aside and renamed in, so the worker never reads half a job.
        file_put_contents("{$job}.part", json_encode([
            'system' => $prompt->system(),
            'prompt' => $prompt->user($task, $text, $lang),
            'schema' => $prompt->schema(),
            'model'  => $model,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename("{$job}.part", $job);

        for ($look = 0; $look < $this->timeoutSeconds / self::LOOK_EVERY_SECONDS; $look++) {
            ($this->wait)();
            if (is_file($answer)) {
                return $this->read($answer, $prompt);
            }
        }

        @unlink($job);
        throw new RuntimeException("The review worker gave no answer in {$this->timeoutSeconds} seconds.");
    }

    /** @return array{feedback:array<string,mixed>,model:string,input_tokens:int,output_tokens:int} */
    private function read(string $file, ReviewPrompt $prompt): array
    {
        $answer = json_decode((string) file_get_contents($file), true);
        unlink($file);
        if (!is_array($answer) || empty($answer['ok'])) {
            throw new RuntimeException('The review failed: ' . ($answer['error'] ?? 'unreadable answer'));
        }

        return [
            'feedback'      => $prompt->parse(json_encode($answer['output'] ?? null)),
            'model'         => (string) ($answer['model'] ?? ''),
            'input_tokens'  => (int) ($answer['input_tokens'] ?? 0),
            'output_tokens' => (int) ($answer['output_tokens'] ?? 0),
        ];
    }
}
