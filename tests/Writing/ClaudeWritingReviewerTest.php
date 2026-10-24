<?php declare(strict_types=1);

namespace Dansk\Tests\Writing;

use Anthropic\Client;
use Dansk\Domain\Writing\ClaudeWritingReviewer;
use Dansk\Domain\Writing\WritingTasks;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The request the review sends and how its answer is read, through the SDK itself with
 * the network replaced: a wrong parameter name fails here, not on the first paid call.
 */
final class ClaudeWritingReviewerTest extends TestCase
{
    private const FEEDBACK = [
        'summary' => 'Godt.', 'verdict' => 'likely_pass', 'task' => [], 'errors' => [], 'strengths' => ['Klart.'], 'corrected' => 'Hej Sune.',
    ];

    /** @var list<RequestInterface> */
    private array $sent = [];

    private function reviewer(string $stopReason = 'end_turn', ?string $text = null): ClaudeWritingReviewer
    {
        $body = [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text ?? json_encode(self::FEEDBACK)]],
            'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 700],
        ];
        $transport = new class($this->sent, json_encode($body)) implements ClientInterface {
            public function __construct(private array &$sent, private string $body) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;
                return new Response(200, ['Content-Type' => 'application/json'], $this->body);
            }
        };

        return new ClaudeWritingReviewer(
            new Client(apiKey: 'test-key', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]),
            'claude-opus-5-5'
        );
    }

    public function testSendsTheTaskAndTextToTheConfiguredModelForAStructuredAnswer(): void
    {
        $this->reviewer()->review((new WritingTasks())->find('e1'), 'Hej Sune, tak for din mail.', 'ru');

        self::assertCount(1, $this->sent);
        $request = $this->sent[0];
        $body    = json_decode((string) $request->getBody(), true);
        self::assertSame('claude-opus-5-5', $body['model']);
        self::assertSame('medium', $body['output_config']['effort']);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(['summary', 'verdict', 'task', 'errors', 'strengths', 'corrected'], $body['output_config']['format']['schema']['required']);
        self::assertSame('default', $body['fallbacks']);
        self::assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        self::assertStringContainsString('Hej Sune, tak for din mail.', $body['messages'][0]['content']);
        self::assertStringContainsString('Modultest DU3', is_string($body['system']) ? $body['system'] : json_encode($body['system']));
        self::assertArrayNotHasKey('thinking', $body);
    }

    public function testReadsTheReviewAndWhatItCost(): void
    {
        $review = $this->reviewer()->review((new WritingTasks())->find('b1'), 'Jeg er enig med Helle.', 'uk');

        self::assertSame(self::FEEDBACK, $review['feedback']);
        self::assertSame('claude-opus-5-5', $review['model']);
        self::assertSame([1500, 700], [$review['input_tokens'], $review['output_tokens']]);
    }

    /** Whatever text came with a refusal is not a review. */
    public function testADeclinedReviewIsAFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined');
        $this->reviewer('refusal')->review((new WritingTasks())->find('e1'), 'Hej.', 'ru');
    }

    public function testAReviewCutOffIsAFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cut off');
        $this->reviewer('max_tokens')->review((new WritingTasks())->find('e1'), 'Hej.', 'ru');
    }
}
