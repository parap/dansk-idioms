<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * What the publisher bot decides before anything reaches Telegram.
 *
 * Publishing cannot be undone -- a post is not recalled, and everyone subscribed has
 * already seen it -- so every refusal lives here, ahead of the first network call, and
 * is checked without HTTP.
 *
 * The bot writes the hashtag because nobody can add one afterwards: the right to edit
 * another user's message exists only in channels (`can_edit_messages`, "for channels
 * only"), and even the author cannot edit an old message -- `MESSAGE_EDIT_TIME_EXPIRED`.
 * Making the bot the author is what removes the problem instead of working around it.
 */
final class Communicator
{
    /** One tag per kind of post. The kind is visible in the attachment, not the words. */
    public const TAGS = ['text' => '#text', 'video' => '#video'];

    /**
     * Whether to admit a request claiming to be Telegram's webhook.
     *
     * Telegram sends `X-Telegram-Bot-Api-Secret-Token` when `setWebhook` was given a
     * `secret_token`. An unset secret refuses everyone: the webhook address is
     * guessable, and admitting callers until it is configured would hand publishing
     * rights to whoever guessed it.
     *
     * The comparison is constant-time. A plain `===` leaks the length of the shared
     * prefix to anyone timing the response.
     */
    public static function accepts(?string $configured, ?string $offered): bool
    {
        if ($configured === null || $configured === '') {
            return false;
        }

        return $offered !== null && hash_equals($configured, $offered);
    }

    /**
     * Whether the owner sent this.
     *
     * A bot can be found by name and written to; without this check a stranger's
     * message would go out to the group under the bot's name. An unset owner, like an
     * unset secret, refuses everyone.
     */
    public static function fromOwner(array $message, ?string $ownerChatId): bool
    {
        if ($ownerChatId === null || $ownerChatId === '') {
            return false;
        }

        return (string) ($message['chat']['id'] ?? '') === $ownerChatId;
    }

    /**
     * The kind of post: `video` when video was sent, `text` otherwise.
     *
     * Video arrives three ways and the `video` field is only one of them. A file sent
     * as a file lands in `document`, which is exactly how the `.mkv` fairy tales sit in
     * the group. Checking `video` alone would tag every one of them as text.
     */
    public static function kindOf(array $message): string
    {
        if (isset($message['video']) || isset($message['video_note'])) {
            return 'video';
        }
        $mime = (string) ($message['document']['mime_type'] ?? '');

        return str_starts_with($mime, 'video/') ? 'video' : 'text';
    }

    /**
     * The text with its hashtag on a line of its own.
     *
     * A tag already there is not doubled: webhook delivery is not guaranteed to happen
     * once, and reprocessing would otherwise produce "#text #text". The match is on a
     * whole word -- "#textil" is not a tag.
     */
    public static function tagged(string $text, string $kind): string
    {
        $tag = self::TAGS[$kind] ?? self::TAGS['text'];
        if (preg_match('/' . preg_quote($tag, '/') . '(?![\p{L}\p{N}_])/u', $text) === 1) {
            return $text;
        }

        return rtrim($text) . "\n\n" . $tag;
    }
}
