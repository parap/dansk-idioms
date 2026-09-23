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
    public function __construct(
        private BotApi $api,
        private array $settings,
        private ?\Closure $ingest = null,
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
        if (!$this->segmenter->boundariesAreClear($raw)) {
            // Several headwords with no separator would fold into one entry, the first
            // becoming the term and the rest its explanation. The group is fine; the
            // corpus must not take this blindly.
            return 'published_not_stored';
        }

        try {
            ($this->ingest)($message, $published);
        } catch (\Throwable $e) {
            error_log('communicator: published but not stored -- ' . $e->getMessage());

            return 'published_not_stored';
        }

        return 'published';
    }
}
