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
/** A place to put a draft that is not a database. */
final class FakeDrafts implements \Dansk\Support\Drafts
{
    public array $kept = [];
    public array $claimable = [];

    public function keep(string $ownerChatId, string $text, array $entities, string $kind): string
    {
        $token = '01JJJJJJJJJJJJJJJJJJJJJJJJ';
        $this->kept[] = [$ownerChatId, $text, $kind];
        $this->claimable[$token] = [
            'text' => $text, 'entities' => $entities,
            'kind' => $kind, 'owner_chat_id' => $ownerChatId,
        ];

        return $token;
    }

    public function claim(string $token): ?array
    {
        $draft = $this->claimable[$token] ?? null;
        unset($this->claimable[$token]);

        return $draft;
    }
}

final class CommunicatorWebhookTest extends TestCase
{
    private array $sent = [];
    private array $stored = [];
    private ?\Dansk\Support\Drafts $drafts = null;
    private $ingest = null;

    private function webhook(array $overrides = []): CommunicatorWebhook
    {
        $this->sent   = [];
        $this->drafts ??= new FakeDrafts();
        $api = new BotApi('токен', function (string $method, array $params): array {
            $this->sent[] = [$method, $params];

            return ['ok' => true, 'result' => ['message_id' => 1]];
        });

        return new CommunicatorWebhook($api, array_replace([
            'secret' => 's3cret',
            'owner'  => '158493465',
            'group'  => '-5203885388',
        ], $overrides), $this->ingest, $this->drafts);
    }

    /** Only what was posted to the group -- a note to the owner is a send too. */
    private function toTheGroup(): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn(array $call): bool => ($call[1]['chat_id'] ?? '') === '-5203885388'
        ));
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

    public function testUnclearBoundariesPublishNothingYet(): void
    {
        // The earlier behaviour published and skipped the corpus. That put a post in
        // the group that no decision had been made about, and the group is the one
        // place nothing can be taken back from. Now it waits.
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };

        $outcome = $this->webhook()->handle(
            self::update("at gå agurk — сойти с ума\nat slænge sig — развалиться\nat tage fejl — ошибаться"),
            's3cret'
        );

        self::assertSame('offered', $outcome);
        self::assertSame([], $this->toTheGroup(), 'nothing reaches the group unasked');
        self::assertSame([], $this->stored);
    }

    public function testTheProposalShowsTheSplitAndBothWaysOut(): void
    {
        $this->webhook()->handle(
            self::update("at gå agurk — сойти с ума\nat slænge sig — развалиться"),
            's3cret'
        );

        [$method, $params] = $this->sent[0];
        self::assertSame('sendMessage', $method);
        self::assertSame('158493465', $params['chat_id'], 'asked in his own chat, not the group');
        self::assertStringContainsString('1. at gå agurk', $params['text']);
        self::assertStringContainsString('2. at slænge sig', $params['text']);
        self::assertStringContainsString('Разбить на 2', $params['reply_markup']);
        self::assertStringContainsString('Одним постом', $params['reply_markup']);
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
        self::assertCount(1, $this->toTheGroup());
    }

    public function testWithNowhereToKeepADraftItPublishesNothingAndSaysSo(): void
    {
        // Without a place to hold the message there is no proposal to make, and a
        // guess cannot be acted on. Silence here would leave him waiting for a post
        // that is never coming.
        $hook = new CommunicatorWebhook(
            new BotApi('token', function (string $method, array $params): array {
                $this->sent[] = [$method, $params];

                return ['ok' => true, 'result' => ['message_id' => 1]];
            }),
            ['secret' => 's3cret', 'owner' => '158493465', 'group' => '-5203885388'],
        );

        $outcome = $hook->handle(
            self::update("at gå agurk — сойти с ума\nat slænge sig — развалиться"),
            's3cret'
        );

        self::assertSame('not_published', $outcome);
        self::assertSame([], $this->toTheGroup());
        self::assertSame('158493465', $this->sent[0][1]['chat_id']);
    }

    public function testTheNoteNeverReachesTheGroup(): void
    {
        // Telling the owner is between the two of them; the group gets the idiom only.
        $this->ingest = function (): void {
            throw new \RuntimeException('the database is away');
        };

        $this->webhook()->handle(self::update('at spille'), 's3cret');

        self::assertCount(1, $this->toTheGroup());
    }

    // --- the press -------------------------------------------------------------

    private static function press(string $data, int $from = 158493465): array
    {
        return ['callback_query' => [
            'id'      => 'cb-1',
            'from'    => ['id' => $from],
            'data'    => $data,
            'message' => ['message_id' => 42, 'chat' => ['id' => 158493465]],
        ]];
    }

    private function offerTwo(): string
    {
        $this->webhook()->handle(
            self::update("at gå agurk — сойти с ума\nat slænge sig — развалиться"),
            's3cret'
        );
        $this->sent = [];

        return '01JJJJJJJJJJJJJJJJJJJJJJJJ';
    }

    public function testSplittingPostsEachPieceOnItsOwn(): void
    {
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };
        $token = $this->offerTwo();

        $outcome = $this->webhook()->handle(self::press('split:' . $token), 's3cret');

        self::assertSame('published', $outcome);
        $posts = $this->toTheGroup();
        self::assertCount(2, $posts);
        self::assertSame("at gå agurk — сойти с ума\n\n#text", $posts[0][1]['text']);
        self::assertSame("at slænge sig — развалиться\n\n#text", $posts[1][1]['text']);
        self::assertCount(2, $this->stored, 'each piece is its own entry');
    }

    public function testPublishingWholeKeepsItOnePost(): void
    {
        // Rejecting the split is a person saying "this is one thing" -- which is the
        // answer the guess could not supply, so the corpus may take it as one entry.
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };
        $token = $this->offerTwo();

        $outcome = $this->webhook()->handle(self::press('whole:' . $token), 's3cret');

        self::assertSame('published', $outcome);
        self::assertCount(1, $this->toTheGroup());
        self::assertCount(1, $this->stored);
    }

    public function testASecondPressPublishesNothingMore(): void
    {
        // The draft is claimed once. Publishing cannot be undone, so the second press
        // must find nothing -- and be told so rather than left spinning.
        $token = $this->offerTwo();
        // One instance for both presses: the factory clears the record of what was
        // sent, and a second call would wipe the very evidence under test.
        $hook = $this->webhook();
        $hook->handle(self::press('split:' . $token), 's3cret');
        $before = count($this->toTheGroup());

        $outcome = $hook->handle(self::press('split:' . $token), 's3cret');

        self::assertSame('already_decided', $outcome);
        self::assertCount($before, $this->toTheGroup());
    }

    public function testAPressFromAnyoneElseDoesNothing(): void
    {
        $token = $this->offerTwo();

        $outcome = $this->webhook()->handle(self::press('split:' . $token, 99), 's3cret');

        self::assertSame('ignored', $outcome);
        self::assertSame([], $this->toTheGroup());
    }

    public function testAPressIsAlwaysAcknowledged(): void
    {
        // Unanswered, Telegram spins for half a minute and invites a second press.
        $token = $this->offerTwo();

        $this->webhook()->handle(self::press('split:' . $token), 's3cret');

        $answers = array_filter($this->sent, static fn(array $c): bool => $c[0] === 'answerCallbackQuery');
        self::assertCount(1, $answers);
    }

    public function testTheButtonsAreTakenAwayAfterwards(): void
    {
        $token = $this->offerTwo();

        $this->webhook()->handle(self::press('whole:' . $token), 's3cret');

        $edits = array_filter($this->sent, static fn(array $c): bool => $c[0] === 'editMessageReplyMarkup');
        self::assertCount(1, $edits, 'a live button says the decision was not made');
    }
}
