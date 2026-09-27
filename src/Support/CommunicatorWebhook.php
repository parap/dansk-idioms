<?php declare(strict_types=1);

namespace Dansk\Support;

use Dansk\Import\EntrySegmenter;
use Dansk\Import\Text;

/**
 * One update from Telegram: decide, and publish only if everything held.
 *
 * Separate from the HTTP handler so each refusal is checked without a request. All
 * four checks run before the first network call, because publishing to the group
 * cannot be undone -- the post is not recalled and every subscriber has seen it.
 *
 * The checks run cheapest and most foreign first: the secret turns away whoever
 * guessed the address, and only then does anything look at what was sent.
 */
final class CommunicatorWebhook
{
    /**
     * @param ?\Closure $ingest    Given the message and the id it was published under,
     *                             puts it in the corpus. Null leaves the corpus alone.
     * @param ?Drafts   $drafts   Where a message waits for a decision. Null means no
     *                             proposal can be made, so an unclear message is left
     *                             unpublished and said so.
     * @param ?\Closure $known     Given a piece, the term it names when that term is
     *                             already in the corpus, else null. Null checks nothing.
     */
    public function __construct(
        private BotApi $api,
        private array $settings,
        private ?\Closure $ingest = null,
        private ?Drafts $drafts = null,
        private EntrySegmenter $segmenter = new EntrySegmenter(),
        private ?\Closure $known = null,
    ) {
    }

    /**
     * @return string `published` | `refused` | `ignored` | `misconfigured`
     */
    public function handle(array $update, ?string $offeredSecret): string
    {
        if (!Communicator::accepts($this->settings['secret'] ?? null, $offeredSecret)) {
            return 'refused';
        }

        $press = $update['callback_query'] ?? null;
        if (is_array($press)) {
            return $this->press($press);
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            // Service updates -- a rights change, a member joining -- arrive here too
            // and are not posts.
            return 'ignored';
        }

        if (!Communicator::fromOwner($message, $this->owner())) {
            return 'ignored';
        }

        // Left as sent: trimming the front would shift every entity offset, and the
        // offsets are what say which words are bold.
        $raw = (string) ($message['text'] ?? '');
        if (trim($raw) === '') {
            // An empty post is litter that can no longer be removed.
            return 'ignored';
        }

        $group = $this->group();
        if ($group === null) {
            return 'misconfigured';
        }

        // Nothing goes to the group before the boundaries are settled. Publishing
        // first and skipping the corpus afterwards put a post nobody had decided
        // about in the one place nothing can be taken back from.
        $parts = $this->segmenter->entryParts($raw);
        if ($parts === null) {
            return $this->offer($raw, $message);
        }

        $done = $this->publishParts($group, $parts, $message['entities'] ?? [],
            Communicator::kindOf($message), $message['from'] ?? [], (int) ($message['date'] ?? 0));
        if ($done['published'] === 0) {
            return 'nothing_new';
        }
        if (!$done['stored']) {
            $this->tell('Опубликовала, но на сайт не взяла: сорвалась запись в базу.');

            return 'published_not_stored';
        }

        return 'published';
    }

    /**
     * Each new piece as its own post, its own bold, and its own entry in the corpus.
     *
     * A piece whose idiom the corpus already has goes nowhere, and the owner is told.
     * A post is out and cannot be recalled, so a failure to store never stops the
     * pieces after it; `stored` reports it.
     *
     * @param list<array{text:string, offset:int}> $parts
     * @return array{published:int, stored:bool}
     */
    private function publishParts(string $group, array $parts, array $entities, string $kind, array $from, int $date): array
    {
        $stored    = true;
        $published = 0;
        $skipped   = [];
        foreach ($parts as $part) {
            // Asked per piece, after the ones before it were stored: a batch naming one
            // idiom twice publishes it once.
            $term = $this->known === null ? null : ($this->known)($part['text']);
            if ($term !== null) {
                $skipped[] = $term;
                continue;
            }

            $published++;
            $postId = $this->api->sendMessage(
                $group,
                Communicator::tagged($part['text'], $kind),
                // Rebased to the piece: offsets count from the start of the whole text.
                Communicator::withBoldHead($part['text'], Communicator::sliceEntities(
                    $entities,
                    $part['offset'],
                    Text::utf16Length($part['text'])
                )),
            );

            if ($this->ingest === null) {
                continue;
            }
            try {
                ($this->ingest)(['text' => $part['text'], 'from' => $from, 'date' => $date], $postId);
            } catch (\Throwable $e) {
                error_log('communicator: published but not stored -- ' . $e->getMessage());
                $stored = false;
            }
        }

        if ($skipped !== []) {
            $this->tell("Уже есть на сайте, не публиковала:\n" . implode("\n", $skipped));
        }

        return ['published' => $published, 'stored' => $stored];
    }

    /**
     * A decision on a waiting message.
     *
     * The draft is claimed before anything is sent: publishing cannot be undone, so the
     * second press has to find nothing left to act on. Rejecting the split is not a
     * refusal -- it is a person saying "this is one thing", which is the answer the
     * guess could not supply, so the corpus may then take it as one entry.
     */
    private function press(array $press): string
    {
        $owner = $this->owner();
        if ($owner === null || (string) ($press['from']['id'] ?? '') !== $owner) {
            return 'ignored';
        }

        [$action, $token] = array_pad(explode(':', (string) ($press['data'] ?? ''), 2), 2, '');
        if (!in_array($action, ['split', 'whole'], true) || $token === '' || $this->drafts === null) {
            return 'ignored';
        }

        $draft = $this->drafts->claim($token);
        if ($draft === null) {
            $this->acknowledge($press, 'Уже сделано');

            return 'already_decided';
        }

        $group = $this->group();
        if ($group === null) {
            $this->acknowledge($press, 'Группа не настроена');

            return 'misconfigured';
        }

        $parts = $action === 'split'
            ? $this->segmenter->proposeSplitParts($draft['text'])
            : [['text' => $draft['text'], 'offset' => 0]];

        // The bot answers nobody else's buttons: whoever pressed is whoever wrote, and
        // the group post exists as of now, however long it waited.
        $done = $this->publishParts($group, $parts, $draft['entities'], $draft['kind'], $press['from'] ?? [], time());

        $this->acknowledge($press, match (true) {
            $done['published'] === 0 => 'Всё это уже есть',
            $done['published'] > 1   => 'Опубликовала ' . $done['published'],
            default                  => 'Опубликовала',
        });
        $this->unbutton($press);

        return 'published';
    }

    /** Failing to answer changes nothing about the posts, so it cannot be allowed to raise. */
    private function acknowledge(array $press, string $text): void
    {
        try {
            $this->api->answerCallback((string) ($press['id'] ?? ''), $text);
        } catch (\Throwable $e) {
            error_log('communicator: could not acknowledge -- ' . $e->getMessage());
        }
    }

    /** A live button under a decision already made says the decision was not made. */
    private function unbutton(array $press): void
    {
        try {
            $this->api->editReplyMarkup(
                (string) ($press['message']['chat']['id'] ?? ''),
                (int) ($press['message']['message_id'] ?? 0),
                ['inline_keyboard' => []],
            );
        } catch (\Throwable $e) {
            error_log('communicator: could not take the buttons away -- ' . $e->getMessage());
        }
    }

    /**
     * Show the split that would be made, and wait.
     *
     * Several headwords with no separator have no authoritative boundary. Acting on the
     * guess would fold them into one entry -- the first becoming the term, the rest its
     * explanation -- so the guess is shown instead, with both ways out one press away.
     */
    private function offer(string $raw, array $message): string
    {
        if ($this->drafts === null) {
            $this->tell(
                'Не опубликовала: в сообщении несколько идиом без невидимых'
                . ' разделителей, и где кончается одна и начинается другая — непонятно.'
                . ' Пришли по одной.'
            );

            return 'not_published';
        }

        $pieces = $this->segmenter->proposeSplit($raw);
        $token  = $this->drafts->keep(
            (string) $this->owner(),
            $raw,
            $message['entities'] ?? [],
            Communicator::kindOf($message),
        );

        $this->api->sendMessage(
            (string) $this->owner(),
            "Вижу несколько идиом, но границы между ними неточные — вот как я бы разбила:\n\n"
            . Communicator::proposal($pieces),
            [],
            Communicator::splitKeyboard($token, count($pieces)),
        );

        return 'offered';
    }

    /**
     * The group's address, or null when none is configured.
     *
     * An empty chat_id is refused by Telegram only after the handler has counted the
     * work as done, and a second copy of the check is a door that keeps publishing.
     */
    private function group(): ?string
    {
        $group = (string) ($this->settings['group'] ?? '');

        return $group === '' ? null : $group;
    }

    /** The owner's own chat, or null when none is configured. */
    private function owner(): ?string
    {
        $owner = (string) ($this->settings['owner'] ?? '');

        return $owner === '' ? null : $owner;
    }

    /**
     * A word back to the owner, in his own chat.
     *
     * A refusal nobody is told about is the failure the guard exists to prevent: the
     * post stands in the group looking finished while the corpus quietly skipped it.
     * Never to the group -- this is between the bot and whoever sent the idiom.
     *
     * Failing to report changes nothing about what happened to the post, so it cannot
     * be allowed to raise.
     */
    private function tell(string $text): void
    {
        $owner = $this->owner();
        if ($owner === null) {
            return;
        }

        try {
            $this->api->sendMessage($owner, $text);
        } catch (\Throwable $e) {
            error_log('communicator: could not report back -- ' . $e->getMessage());
        }
    }
}
