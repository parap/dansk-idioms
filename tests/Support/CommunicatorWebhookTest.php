<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\BotApi;
use Dansk\Support\CommunicatorWebhook;
use PHPUnit\Framework\TestCase;

/**
 * Handling one update from Telegram: what goes to the group and what does not.
 *
 * Publishing cannot be undone, so each refusal is checked by **nothing having been
 * sent**, not by the returned value: a handler that refused and published anyway
 * would return exactly the same refusal.
 */
final class CommunicatorWebhookTest extends TestCase
{
    private array $sent = [];
    private array $stored = [];
    private $ingest = null;

    private function webhook(array $overrides = []): CommunicatorWebhook
    {
        $this->sent = [];
        $api = new BotApi('токен', function (string $method, array $params): array {
            $this->sent[] = [$method, $params];

            return ['ok' => true, 'result' => ['message_id' => 1]];
        });

        return new CommunicatorWebhook($api, array_replace([
            'secret' => 's3cret',
            'owner'  => '158493465',
            'group'  => '-5203885388',
        ], $overrides), $this->ingest);
    }

    private static function update(string $text, int $chatId = 158493465): array
    {
        return ['message' => ['text' => $text, 'chat' => ['id' => $chatId]]];
    }

    // --- what gets published -------------------------------------------------

    public function testTheOwnersTextGoesToTheGroupTagged(): void
    {
        $outcome = $this->webhook()->handle(self::update('at spille'), 's3cret');

        self::assertSame('published', $outcome);
        self::assertCount(1, $this->sent);
        self::assertSame('sendMessage', $this->sent[0][0]);
        self::assertSame('-5203885388', $this->sent[0][1]['chat_id']);
        self::assertSame("at spille\n\n#text", $this->sent[0][1]['text']);
    }

    // --- what does not -------------------------------------------------------

    public function testAWrongSecretPublishesNothing(): void
    {
        $outcome = $this->webhook()->handle(self::update('at spille'), 'не тот');

        self::assertSame('refused', $outcome);
        self::assertSame([], $this->sent);
    }

    public function testAMessageFromAnyoneElsePublishesNothing(): void
    {
        $outcome = $this->webhook()->handle(self::update('чужое', 99), 's3cret');

        self::assertSame('ignored', $outcome);
        self::assertSame([], $this->sent);
    }

    public function testAnUnconfiguredGroupPublishesNothing(): void
    {
        // Otherwise the chat_id would go out empty and Telegram would refuse --
        // after the handler had already counted the work as done.
        $outcome = $this->webhook(['group' => null])->handle(self::update('at spille'), 's3cret');

        self::assertSame('misconfigured', $outcome);
        self::assertSame([], $this->sent);
    }

    public function testAnUpdateWithoutAMessagePublishesNothing(): void
    {
        // Telegram sends service updates too: a rights change, a member joining.
        $outcome = $this->webhook()->handle(['my_chat_member' => ['chat' => ['id' => -1]]], 's3cret');

        self::assertSame('ignored', $outcome);
        self::assertSame([], $this->sent);
    }

    public function testAnEmptyTextPublishesNothing(): void
    {
        // An empty post in the group is litter that can no longer be removed.
        $outcome = $this->webhook()->handle(self::update('   '), 's3cret');

        self::assertSame('ignored', $outcome);
        self::assertSame([], $this->sent);
    }

    // --- what reaches the corpus ----------------------------------------------

    public function testWhatWasPublishedIsAlsoStored(): void
    {
        $this->ingest = function (array $message, int $publishedId): void {
            $this->stored[] = [$message['text'], $publishedId];
        };

        $outcome = $this->webhook()->handle(self::update('at spille'), 's3cret');

        self::assertSame('published', $outcome);
        self::assertSame([['at spille', 1]], $this->stored);
    }

    public function testStoringIsSkippedWhenTheBoundariesAreUnclear(): void
    {
        // Three headwords pasted with newlines and no invisible markers: the segmenter
        // would fold them into one entry, taking the first as the term and the rest as
        // its explanation. The post is fine; only the corpus must not take it blindly.
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };

        $outcome = $this->webhook()->handle(
            self::update("at gå agurk — сойти с ума\nat slænge sig — развалиться\nat tage fejl — ошибаться"),
            's3cret'
        );

        self::assertSame('published_not_stored', $outcome);
        self::assertCount(1, $this->sent, 'the post still goes out');
        self::assertSame([], $this->stored);
    }

    public function testAFailureToStoreDoesNotLookLikeAFailureToPublish(): void
    {
        // The post is already in the group and cannot be recalled. Reporting this as a
        // failure would invite a second attempt, and a second post.
        $this->ingest = function (): void {
            throw new \RuntimeException('the database is away');
        };

        $outcome = $this->webhook()->handle(self::update('at spille'), 's3cret');

        self::assertSame('published_not_stored', $outcome);
        self::assertCount(1, $this->sent);
    }
}
