<?php declare(strict_types=1);

namespace Dansk\Support;

use Dansk\Import\Text;

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

    /** How much of a piece the proposal shows before cutting it short. */
    public const PREVIEW = 70;

    /**
     * The split the bot would make, written out for a person to look at.
     *
     * What is being shown is the boundaries, not the text -- the text is already in
     * the sender's own chat. So each piece is cut short: the proposal has to fit one
     * message however long the explanations were.
     *
     * @param list<string> $pieces
     */
    public static function proposal(array $pieces): string
    {
        $lines = [];
        foreach ($pieces as $number => $piece) {
            $lines[] = ($number + 1) . '. ' . self::shortened($piece);
        }

        return implode("\n", $lines);
    }

    /** A piece on one line, cut short: enough to recognise it, never a whole explanation. */
    public static function shortened(string $piece): string
    {
        $flat = trim(preg_replace('/[\s\x{200B}]+/u', ' ', $piece) ?? $piece);

        return mb_substr($flat, 0, self::PREVIEW) . (mb_strlen($flat) > self::PREVIEW ? '…' : '');
    }

    /** Where a piece stands on the site, as the owner reads it. */
    private const SITE = [
        'review'   => 'Ждут проверки в админке, на сайте пока нет',
        'rejected' => 'Импорт не разобрал, на сайт не взяла',
        'failed'   => 'На сайт не записала, сорвалась запись в базу',
    ];

    /**
     * One message telling the owner where every piece went, idioms in bold.
     *
     * Each list names the idioms themselves: a count says something arrived, not which.
     * Sections with nothing in them are left out, and so is the site when nobody asked it.
     *
     * @param list<array{0:string, 1:?string}> $sent  headword and its state on the site
     * @param list<string> $known    terms the corpus already had
     * @param list<string> $refused  pieces that held no idiom
     * @return array{text:string, entities:list<array<string,mixed>>}
     */
    public static function report(array $sent, array $known, array $refused): array
    {
        $text     = $sent === [] ? '' : 'Приняла и передала.';
        $entities = [];
        $add      = static function (string $block) use (&$text): void {
            $text .= ($text === '' ? '' : "\n\n") . $block;
        };
        $section  = static function (string $title, array $items, bool $bold) use (&$text, &$entities, $add): void {
            if ($items === []) {
                return;
            }
            $add($title . ':');
            foreach ($items as $item) {
                $text .= "\n• ";
                if ($bold) {
                    $entities[] = ['type' => 'bold', 'offset' => self::utf16Length($text), 'length' => self::utf16Length($item)];
                }
                $text .= $item;
            }
        };
        $where = static fn(?string $state): array =>
            array_column(array_filter($sent, static fn(array $s): bool => $s[1] === $state), 0);

        $section('В канал (' . count($sent) . ')', array_column($sent, 0), true);
        $section('На сайт (' . count($where('published')) . ')', $where('published'), true);
        foreach (self::SITE as $state => $title) {
            $section($title, $where($state), true);
        }
        $section('Уже есть на сайте, не публиковала', $known, true);
        $section('Отсеяла, идиомы не нашла', $refused, false);

        if ($refused !== []) {
            $add('Идиома присылается так: at gå agurk — сойти с ума');
        }

        return ['text' => $text, 'entities' => $entities];
    }

    /**
     * The two ways out, both one press away.
     *
     * Splitting is a guess. Leaving "whole" equally available is what keeps the
     * question honest -- offered alone, the guess would be the only answer and the
     * asking would be decoration.
     *
     * `callback_data` is capped at 64 bytes and Telegram refuses the whole message over
     * it, so the token is a ULID and the prefixes are short.
     */
    public static function splitKeyboard(string $token, int $count): array
    {
        return [
            'inline_keyboard' => [
                [['text' => "Разбить на {$count}", 'callback_data' => 'split:' . $token]],
                [['text' => 'Одним постом', 'callback_data' => 'whole:' . $token]],
            ],
        ];
    }

    /**
     * Length in UTF-16 code units -- the unit Telegram counts entity offsets in.
     *
     * Not characters. Danish letters are one unit each, but an emoji is a surrogate
     * pair and counts as two. Measure in characters and every bold span after an emoji
     * points at the wrong word, with nothing to report it.
     */
    public static function utf16Length(string $text): int
    {
        return Text::utf16Length($text);
    }

    /**
     * The entities belonging to one piece, rebased to it.
     *
     * When a message is split into several posts, a bold span has to follow its own
     * piece: offsets are counted from the start of the whole text, and left as they
     * are Telegram would place them wherever those numbers happen to land in the
     * shorter post -- on whatever words sit there.
     *
     * A span crossing a boundary is cut at it rather than dropped: half a bold phrase
     * is wrong in a way a reader sees, while a missing one is wrong in a way nobody
     * does.
     *
     * @param array<int,array<string,mixed>> $entities
     * @return array<int,array<string,mixed>>
     */
    public static function sliceEntities(array $entities, int $start, int $length): array
    {
        $end  = $start + $length;
        $kept = [];
        foreach ($entities as $entity) {
            $from = (int) ($entity['offset'] ?? 0);
            $to   = $from + (int) ($entity['length'] ?? 0);
            $lo   = max($from, $start);
            $hi   = min($to, $end);
            if ($hi <= $lo) {
                continue;
            }
            $entity['offset'] = $lo - $start;
            $entity['length'] = $hi - $lo;
            $kept[] = $entity;
        }

        return $kept;
    }

    /**
     * The entities with the headword made bold, unless the author already bolded it.
     *
     * The headword is the Latin text before the first spaced dash, past any leading
     * U+200B -- which counts as a unit like any other character. A Russian note in
     * brackets just before the dash stays out of the bold.
     *
     * @param array<int,array<string,mixed>> $entities
     * @return array<int,array<string,mixed>>
     */
    public static function withBoldHead(string $text, array $entities): array
    {
        if (preg_match('/^([\s\x{200B}]*)(\p{Latin}[^\p{Cyrillic}\n]*?)\s+(?:\([^()\n]*\p{Cyrillic}[^()\n]*\)\s+)?[—–-]\s/u', $text, $m) !== 1) {
            return $entities;
        }
        $offset = self::utf16Length($m[1]);
        $end    = $offset + self::utf16Length($m[2]);
        foreach ($entities as $entity) {
            $from = (int) ($entity['offset'] ?? 0);
            if (($entity['type'] ?? '') === 'bold' && $from < $end && $from + (int) ($entity['length'] ?? 0) > $offset) {
                return $entities;
            }
        }

        return [['type' => 'bold', 'offset' => $offset, 'length' => $end - $offset], ...$entities];
    }

    /**
     * Entities cut to fit the text they describe.
     *
     * The tag is appended, so offsets that were valid stay valid -- but trailing
     * whitespace is stripped first, and an entity reaching into it would point past the
     * end. Telegram rejects the whole message for one bad entity, so the post would not
     * appear at all.
     *
     * @param array<int,array<string,mixed>> $entities
     * @return array<int,array<string,mixed>>
     */
    public static function clampEntities(array $entities, string $text): array
    {
        $limit = self::utf16Length($text);
        $kept  = [];
        foreach ($entities as $entity) {
            $offset = (int) ($entity['offset'] ?? 0);
            if ($offset >= $limit) {
                continue;
            }
            $entity['length'] = min((int) ($entity['length'] ?? 0), $limit - $offset);
            $kept[] = $entity;
        }

        return $kept;
    }

    /** The text without the tag `tagged()` appended -- ours, not the author's words. */
    public static function untagged(string $text): string
    {
        $tags = implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), self::TAGS));

        return preg_replace('/\s*(?:' . $tags . ')\s*$/u', '', $text) ?? $text;
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
