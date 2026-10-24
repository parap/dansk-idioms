<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

use Anthropic\Client;
use RuntimeException;

/**
 * The review by Claude: the prompt and schema from WritingPrompt, one request, the answer
 * parsed and checked before anything is stored or shown.
 */
final class ClaudeWritingReviewer implements WritingReviewer
{
    public function __construct(
        private Client $client,
        private string $model,
        private WritingPrompt $prompt = new WritingPrompt(),
    ) {}

    public function review(array $task, string $text, string $lang): array
    {
        // A declined request is retried on the model the server names, rather than left without a review.
        $message = $this->client->beta->messages->create(
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $this->prompt->user($task, $text, $lang)]],
            model: $this->model,
            outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $this->prompt->schema()]],
            system: $this->prompt->system(),
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The reviewer declined the text.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('The review was cut off.');
        }

        $json = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json .= $block->text;
            }
        }

        return [
            'feedback'      => $this->prompt->parse($json),
            'model'         => (string) $message->model,
            'input_tokens'  => (int) $message->usage->inputTokens,
            'output_tokens' => (int) $message->usage->outputTokens,
        ];
    }
}
