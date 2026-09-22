<?php declare(strict_types=1);

namespace Dansk\Support;

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
    public function __construct(private BotApi $api, private array $settings)
    {
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

        $this->api->sendMessage(
            $group,
            Communicator::tagged($raw, Communicator::kindOf($message)),
            Communicator::clampEntities($message['entities'] ?? [], rtrim($raw))
        );

        return 'published';
    }
}
