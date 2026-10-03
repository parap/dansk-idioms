<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\BotApi;
use Dansk\Support\CommunicatorWebhook;
use Dansk\Support\IdiomScreen;
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
    /** Terms already in the corpus, by the text that names them. */
    private array $known = [];

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
        ], $overrides), $this->ingest, $this->drafts, idiom: (new IdiomScreen())(...), known: function (string $piece): ?string {
            foreach ($this->known as $term) {
                if (str_starts_with(str_replace("\u{200B}", '', $piece), $term)) {
                    return $term;
                }
            }

            return null;
        });
    }

    /** Only what was posted to the group -- a note to the owner is a send too. */
    private function toTheGroup(): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn(array $call): bool => ($call[1]['chat_id'] ?? '') === '-5203885388'
        ));
    }

    /** What the owner was told, in his own chat. */
    private function toTheOwner(): array
    {
        return array_values(array_map(
            static fn(array $call): string => $call[1]['text'],
            array_filter(
                $this->sent,
                static fn(array $call): bool => $call[0] === 'sendMessage' && ($call[1]['chat_id'] ?? '') === '158493465'
            )
        ));
    }

    private static function update(string $text, int $chatId = 158493465): array
    {
        return ['message' => [
            'text' => $text,
            'chat' => ['id' => $chatId],
            'from' => ['id' => $chatId, 'first_name' => 'Alex'],
        ]];
    }

    // --- what gets published -------------------------------------------------

    public function testTheOwnersTextGoesToTheGroupTagged(): void
    {
        $outcome = $this->webhook()->handle(self::update('at spille — играть'), 's3cret');

        self::assertSame('published', $outcome);
        self::assertCount(1, $this->toTheGroup());
        self::assertSame('sendMessage', $this->sent[0][0]);
        self::assertSame('-5203885388', $this->sent[0][1]['chat_id']);
        self::assertSame("at spille — играть\n\n#text", $this->sent[0][1]['text']);
    }

    // --- what does not -------------------------------------------------------

    public function testAWrongSecretPublishesNothing(): void
    {
        $outcome = $this->webhook()->handle(self::update('at spille — играть'), 'не тот');

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
        $outcome = $this->webhook(['group' => null])->handle(self::update('at spille — играть'), 's3cret');

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

        $outcome = $this->webhook()->handle(self::update('at spille — играть'), 's3cret');

        self::assertSame('published', $outcome);
        self::assertSame([['at spille — играть', 1]], $this->stored);
    }

    // --- several idioms, one to a line ----------------------------------------

    private const ONE_PER_LINE =
        "at udstøde et gisp — издать возглас (ахнуть от изумления/испуга). Устойчивое глагольное сочетание.\n"
        . "at gøre (nogen) bange — напугать (кого-то), вселить страх. Устойчивое сочетание с прилагательным.\n"
        . "at regne med (noget) — рассчитывать на (что-то), ожидать (чего-то). Фразовый глагол..\n"
        . "der er god tid til (noget) — для этого еще полно/достаточно времени. Устойчивый разговорный оборот.";

    public function testIdiomsOneToALineArePublishedAsSeparatePosts(): void
    {
        // Each line opens with its headword and a dash, so the boundaries are the
        // line breaks themselves: there is nothing left to ask about.
        $this->ingest = function (array $message, int $publishedId): void {
            $this->stored[] = $message['text'];
        };

        $outcome = $this->webhook()->handle(self::update(self::ONE_PER_LINE), 's3cret');

        self::assertSame('published', $outcome);
        $posts = $this->toTheGroup();
        self::assertCount(4, $posts);
        self::assertSame(
            "at regne med (noget) — рассчитывать на (что-то), ожидать (чего-то). Фразовый глагол..\n\n#text",
            $posts[2][1]['text']
        );
        self::assertCount(4, $this->stored, 'each idiom is its own entry');
        self::assertStringStartsWith('der er god tid til (noget) —', $this->stored[3]);
    }

    public function testAForwardedQuoteIsPublishedIdiomByIdiom(): void
    {
        // Copied text carries U+200B before every line, which makes the boundaries
        // certain -- and was exactly why the whole quote used to go out as one post.
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };
        $quote = "\u{200B}" . str_replace("\n", "\n\n\u{200B}", self::ONE_PER_LINE);

        $outcome = $this->webhook()->handle(self::update($quote), 's3cret');

        self::assertSame('published', $outcome);
        $posts = $this->toTheGroup();
        self::assertCount(4, $posts);
        self::assertSame(
            "\u{200B}at gøre (nogen) bange — напугать (кого-то), вселить страх. Устойчивое сочетание с прилагательным.\n\n#text",
            $posts[1][1]['text']
        );
        self::assertSame(
            [['type' => 'bold', 'offset' => 1, 'length' => 21]],
            json_decode($posts[1][1]['entities'] ?? '[]', true),
            'the invisible separator is one unit ahead of the idiom'
        );
        self::assertCount(4, $this->stored);
    }

    public function testBlankLinesBetweenThemChangeNothing(): void
    {
        $this->webhook()->handle(self::update(str_replace("\n", "\n\n", self::ONE_PER_LINE)), 's3cret');

        self::assertCount(4, $this->toTheGroup());
    }

    public function testARussianLineStaysWithTheIdiomAboveIt(): void
    {
        $this->webhook()->handle(
            self::update("at regne med — рассчитывать\nПример: jeg regner med dig.\nder er god tid — времени полно"),
            's3cret'
        );

        $posts = $this->toTheGroup();
        self::assertCount(2, $posts);
        self::assertSame("at regne med — рассчитывать\nПример: jeg regner med dig.\n\n#text", $posts[0][1]['text']);
    }

    public function testEachIdiomIsBoldInItsOwnPost(): void
    {
        $this->webhook()->handle(self::update(self::ONE_PER_LINE), 's3cret');

        $bold = array_map(
            static fn(array $post): array => json_decode($post[1]['entities'] ?? '[]', true),
            $this->toTheGroup()
        );
        self::assertSame([['type' => 'bold', 'offset' => 0, 'length' => 18]], $bold[0]);
        self::assertSame([['type' => 'bold', 'offset' => 0, 'length' => 26]], $bold[3]);
    }

    public function testAnIdiomSentAloneIsBoldToo(): void
    {
        $this->webhook()->handle(self::update('at gå agurk — сойти с ума'), 's3cret');

        self::assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 11]],
            json_decode($this->toTheGroup()[0][1]['entities'] ?? '[]', true)
        );
    }

    public function testARussianNoteInBracketsIsLeftOutOfTheBold(): void
    {
        $this->webhook()->handle(self::update('at stanse op (в тексте: var standset op) — остановиться'), 's3cret');

        self::assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 12]],
            json_decode($this->toTheGroup()[0][1]['entities'] ?? '[]', true)
        );
    }

    public function testTheAuthorsOwnBoldIsLeftAsItIs(): void
    {
        $update = self::update('at gå agurk — сойти с ума');
        $update['message']['entities'] = [['type' => 'bold', 'offset' => 3, 'length' => 2]];

        $this->webhook()->handle($update, 's3cret');

        self::assertSame(
            [['type' => 'bold', 'offset' => 3, 'length' => 2]],
            json_decode($this->toTheGroup()[0][1]['entities'] ?? '[]', true)
        );
    }

    // --- only what is new ------------------------------------------------------

    public function testAnIdiomAlreadyInTheCorpusGoesNowhere(): void
    {
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };
        $this->known = ['at regne med (noget)'];

        $outcome = $this->webhook()->handle(self::update(self::ONE_PER_LINE), 's3cret');

        self::assertSame('published', $outcome);
        $posts = $this->toTheGroup();
        self::assertCount(3, $posts);
        self::assertStringStartsWith('der er god tid til', $posts[2][1]['text']);
        self::assertCount(3, $this->stored);
    }

    public function testTheOwnerIsToldWhatWasLeftOut(): void
    {
        // Silence would read as a post that got lost.
        $this->known = ['at regne med (noget)', 'at udstøde et gisp'];

        $this->webhook()->handle(self::update(self::ONE_PER_LINE), 's3cret');

        $notes = array_values(array_filter(
            $this->sent,
            static fn(array $call): bool => ($call[1]['chat_id'] ?? '') === '158493465'
        ));
        self::assertCount(1, $notes);
        self::assertStringContainsString('at udstøde et gisp', $notes[0][1]['text']);
        self::assertStringContainsString('at regne med (noget)', $notes[0][1]['text']);
    }

    public function testNothingNewPublishesNothing(): void
    {
        $this->known = ['at gå agurk'];

        $outcome = $this->webhook()->handle(self::update('at gå agurk — сойти с ума'), 's3cret');

        self::assertSame('nothing_new', $outcome);
        self::assertSame([], $this->toTheGroup());
    }

    public function testAPressedSplitLeavesOutWhatIsKnownToo(): void
    {
        $token = $this->offerTwo();
        $this->known = ['at gå agurk'];

        $this->webhook()->handle(self::press('split:' . $token), 's3cret');

        self::assertCount(1, $this->toTheGroup());
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
            self::update("at gå agurk\nсойти с ума\nat slænge sig\nразвалиться\nat tage fejl\nошибаться"),
            's3cret'
        );

        self::assertSame('offered', $outcome);
        self::assertSame([], $this->toTheGroup(), 'nothing reaches the group unasked');
        self::assertSame([], $this->stored);
    }

    public function testTheProposalShowsTheSplitAndBothWaysOut(): void
    {
        $this->webhook()->handle(
            self::update("at gå agurk\nсойти с ума\nat slænge sig\nразвалиться"),
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

        $outcome = $this->webhook()->handle(self::update('at spille — играть'), 's3cret');

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
            self::update("at gå agurk\nсойти с ума\nat slænge sig\nразвалиться"),
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

        $this->webhook()->handle(self::update('at spille — играть'), 's3cret');

        self::assertCount(1, $this->toTheGroup());
    }

    // --- what holds no idiom ---------------------------------------------------

    /** @return array<string,array{string}> */
    public static function noIdiom(): array
    {
        return [
            'a link'           => ['https://youtu.be/Qczpi7eDgzw?is=Hl25CScpCTwKUGFW'],
            'a link explained' => ['https://danskidioms.com — идиомы, поставил на сайт'],
            'a thumbs-up'      => ['👍'],
            'chatter'          => ['Вот как после этого верить ИИ Клоду 😂'],
            'links, one a line' => ["https://youtu.be/one\nhttps://youtu.be/two"],
        ];
    }

    /** @dataProvider noIdiom */
    public function testWhatHoldsNoIdiomReachesNeitherTheGroupNorTheSite(string $text): void
    {
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };

        $outcome = $this->webhook()->handle(self::update($text), 's3cret');

        self::assertSame('screened', $outcome);
        self::assertSame([], $this->toTheGroup());
        self::assertSame([], $this->stored);
        self::assertSame([], $this->drafts->kept, 'nothing is offered for splitting either');
    }

    public function testTheOwnerIsToldWhatWasScreenedOut(): void
    {
        $this->webhook()->handle(self::update('https://youtu.be/Qczpi7eDgzw'), 's3cret');

        $notes = $this->toTheOwner();
        self::assertCount(1, $notes);
        self::assertStringContainsString('Отсеяла', $notes[0]);
        self::assertStringContainsString('https://youtu.be/Qczpi7eDgzw', $notes[0]);
    }

    public function testOnlyTheIdiomsOfAMixedMessageArePublished(): void
    {
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['text'];
        };

        $outcome = $this->webhook()->handle(
            self::update("\u{200B}at gå agurk — сойти с ума\n\u{200B}https://youtu.be/Qczpi7eDgzw"),
            's3cret'
        );

        self::assertSame('published', $outcome);
        self::assertCount(1, $this->toTheGroup());
        self::assertSame(["\u{200B}at gå agurk — сойти с ума"], $this->stored);
        self::assertStringContainsString('https://youtu.be/Qczpi7eDgzw', $this->toTheOwner()[0]);
    }

    public function testAPressedSplitLeavesOutWhatHoldsNoIdiom(): void
    {
        $this->webhook()->handle(
            self::update("at gå agurk\nсойти с ума\nhttps://youtu.be/one\nat slænge sig\nразвалиться"),
            's3cret'
        );
        $this->sent = [];

        $this->webhook()->handle(self::press('split:01JJJJJJJJJJJJJJJJJJJJJJJJ'), 's3cret');

        self::assertCount(2, $this->toTheGroup());
    }

    // --- the report ------------------------------------------------------------

    public function testTheOwnerIsToldWhatWentToTheGroupAndWhatToTheSite(): void
    {
        // A silent success reads the same as a post that got lost on the way.
        $this->ingest = static fn(array $message): string =>
            str_starts_with($message['text'], 'at gøre') ? 'review' : 'published';

        $this->webhook()->handle(self::update(self::ONE_PER_LINE), 's3cret');

        $notes = $this->toTheOwner();
        self::assertCount(1, $notes, 'one report, not a message per idiom');
        [$group, $site] = explode('На сайт', $notes[0], 2) + [1 => ''];
        foreach (['at udstøde et gisp', 'at gøre bange', 'at regne med', 'der er god tid til'] as $term) {
            self::assertStringContainsString($term, $group);
        }
        [$published, $waiting] = explode('Ждут проверки', $site, 2) + [1 => ''];
        self::assertStringContainsString('at regne med', $published);
        self::assertStringNotContainsString('at gøre bange', $published);
        self::assertStringContainsString('at gøre bange', $waiting);
    }

    public function testThePressGetsTheSameReport(): void
    {
        $this->ingest = static fn(): string => 'published';
        $token = $this->offerTwo();

        $this->webhook()->handle(self::press('split:' . $token), 's3cret');

        $notes = $this->toTheOwner();
        self::assertCount(1, $notes);
        self::assertStringContainsString('at gå agurk', $notes[0]);
        self::assertStringContainsString('at slænge sig', $notes[0]);
        self::assertStringContainsString('На сайт', $notes[0]);
    }

    public function testAFailureToStoreIsNamedInTheReport(): void
    {
        $this->ingest = function (): void {
            throw new \RuntimeException('the database is away');
        };

        $this->webhook()->handle(self::update('at gå agurk — сойти с ума'), 's3cret');

        $notes = $this->toTheOwner();
        self::assertCount(1, $notes);
        self::assertStringContainsString('сорвалась запись', $notes[0]);
        self::assertStringContainsString('at gå agurk', $notes[0]);
    }

    // --- the press -------------------------------------------------------------

    private static function press(string $data, int $from = 158493465): array
    {
        return ['callback_query' => [
            'id'      => 'cb-1',
            'from'    => ['id' => $from, 'first_name' => 'Alex'],
            'data'    => $data,
            'message' => ['message_id' => 42, 'chat' => ['id' => 158493465]],
        ]];
    }

    private function offerTwo(): string
    {
        $this->webhook()->handle(
            self::update("at gå agurk\nсойти с ума\nat slænge sig\nразвалиться"),
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
        self::assertSame("at gå agurk\nсойти с ума\n\n#text", $posts[0][1]['text']);
        self::assertSame("at slænge sig\nразвалиться\n\n#text", $posts[1][1]['text']);
        self::assertCount(2, $this->stored, 'each piece is its own entry');
    }

    public function testAPressWithNoGroupConfiguredPublishesNothing(): void
    {
        // The draft is already claimed by the time the address is wanted, so a press
        // that gets this far has spent its one chance. Going on with an empty chat_id
        // would lose the message: Telegram refuses the send, and there is no draft left
        // to press again.
        $token = $this->offerTwo();

        $outcome = $this->webhook(['group' => null])->handle(self::press('split:' . $token), 's3cret');

        self::assertSame('misconfigured', $outcome);
        self::assertSame([], $this->toTheGroup());
    }

    public function testTheAuthorReachesTheCorpusWhicheverWayItWasPublished(): void
    {
        // Both doors record the same person: the bot acts on nobody else's messages and
        // answers nobody else's buttons, so whoever pressed is whoever wrote. A post
        // stored without its author reports nothing -- the entry reads perfectly and
        // only the byline is gone, which is why each door needs saying so out loud.
        $this->ingest = function (array $message): void {
            $this->stored[] = $message['from']['first_name'] ?? null;
        };

        $this->webhook()->handle(self::update('at spille — играть'), 's3cret');
        self::assertSame(['Alex'], $this->stored, 'straight through');

        $token = $this->offerTwo();
        $this->webhook()->handle(self::press('split:' . $token), 's3cret');

        self::assertSame(['Alex', 'Alex', 'Alex'], $this->stored, 'and each split piece');
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
