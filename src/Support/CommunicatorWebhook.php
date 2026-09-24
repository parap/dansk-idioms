<?php declare(strict_types=1);

namespace Dansk\Support;

use Dansk\Import\EntrySegmenter;

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
     * @param ?\Closure $ingest Given the message and the id it was published under,
     *                          puts it in the corpus. Null leaves the corpus alone.
     */
    /**
     * @param ?\Closure $ingest    Given the message and the id it was published under,
     *                             puts it in the corpus. Null leaves the corpus alone.
     * @param ?\Closure $keepDraft Given owner, text, entities and kind, holds them and
     *                             returns a token. Null means no proposal can be made,
     *                             so an unclear message is left unpublished and said so.
     */
    public function __construct(
        private BotApi $api,
        private array $settings,
        private ?\Closure $ingest = null,
        private ?\Closure $keepDraft = null,
        private EntrySegmenter $segmenter = new EntrySegmenter(),
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

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            // Service updates -- a rights change, a member joining -- arrive here too
            // and are not posts.
            return 'ignored';
        }

        if (!Communicator::fromOwner($message, $this->settings['owner'] ?? null)) {
            return 'ignored';
        }

        // Left as sent: trimming the front would shift every entity offset, and the
        // offsets are what say which words are bold.
        $raw = (string) ($message['text'] ?? '');
        if (trim($raw) === '') {
            // An empty post is litter that can no longer be removed.
            return 'ignored';
        }

        $group = (string) ($this->settings['group'] ?? '');
        if ($group === '') {
            // With no group address the chat_id would go out empty and Telegram would
            // refuse -- after the handler had already counted the work as done.
            return 'misconfigured';
        }

        // Nothing goes to the group before the boundaries are settled. Publishing
        // first and skipping the corpus afterwards put a post nobody had decided
        // about in the one place nothing can be taken back from.
        if (!$this->segmenter->boundariesAreClear($raw)) {
            return $this->offer($raw, $message);
        }

        $published = $this->api->sendMessage(
            $group,
            Communicator::tagged($raw, Communicator::kindOf($message)),
            Communicator::clampEntities($message['entities'] ?? [], rtrim($raw))
        );

        if ($this->ingest === null) {
            return 'published';
        }

        // The post is out and cannot be recalled, so nothing below may report a
        // failure to publish: that would invite a second attempt and a second post.
        try {
            ($this->ingest)($message, $published);
        } catch (\Throwable $e) {
            error_log('communicator: published but not stored -- ' . $e->getMessage());
            $this->tell('Опубликовала, но на сайт не взяла: сорвалась запись в базу.');

            return 'published_not_stored';
        }

        return 'published';
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
        if ($this->keepDraft === null) {
            $this->tell(
                'Не опубликовала: в сообщении несколько идиом без невидимых'
                . ' разделителей, и где кончается одна и начинается другая — непонятно.'
                . ' Пришли по одной.'
            );

            return 'not_published';
        }

        $pieces = $this->segmenter->proposeSplit($raw);
        $token  = ($this->keepDraft)(
            (string) ($this->settings['owner'] ?? ''),
            $raw,
            $message['entities'] ?? [],
            Communicator::kindOf($message),
        );

        $this->api->sendMessage(
            (string) ($this->settings['owner'] ?? ''),
            "Вижу несколько идиом, но границы между ними неточные — вот как я бы разбила:\n\n"
            . Communicator::proposal($pieces),
            [],
            Communicator::splitKeyboard($token, count($pieces)),
        );

        return 'offered';
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
        $owner = (string) ($this->settings['owner'] ?? '');
        if ($owner === '') {
            return;
        }

        try {
            $this->api->sendMessage($owner, $text);
        } catch (\Throwable $e) {
            error_log('communicator: could not report back -- ' . $e->getMessage());
        }
    }
}
