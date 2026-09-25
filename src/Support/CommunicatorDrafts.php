<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * What the bot was sent, held between the proposal and the press.
 *
 * A message whose idiom boundaries are not knowable is not published at all: the bot
 * shows the split it would make and waits. Nothing else can hand the message back later
 * -- the Bot API has no history, and a proposal rendered into a message has already lost
 * the entities that say which words are bold, which is a quarter of what this corpus
 * splits on. So the text is kept verbatim and the rendered list is only a view of it.
 */
final class CommunicatorDrafts implements Drafts
{
    /** @param array<int,array<string,mixed>> $entities */
    public function keep(string $ownerChatId, string $text, array $entities, string $kind): string
    {
        $token = Ulid::generate();
        Db::execute(
            'INSERT INTO communicator_drafts (token, owner_chat_id, text_plain, entities, kind)
             VALUES (?, ?, ?, ?, ?)',
            [
                $token,
                $ownerChatId,
                $text,
                $entities === [] ? null : json_encode($entities, JSON_UNESCAPED_UNICODE),
                $kind,
            ]
        );

        return $token;
    }

    /**
     * Take a draft, once.
     *
     * The conditional UPDATE is the gate: the second press changes no rows and gets
     * nothing back. Publishing is not undoable, and a doubled press would mean doubled
     * posts in the group.
     *
     * @return ?array{text:string, entities:array, kind:string, owner_chat_id:string}
     */
    public function claim(string $token): ?array
    {
        $taken = Db::execute(
            "UPDATE communicator_drafts SET state = 'taken', decided_at = NOW()
             WHERE token = ? AND state = 'offered'",
            [$token]
        );
        if ($taken === 0) {
            return null;
        }

        $row = Db::fetchAll(
            'SELECT text_plain, entities, kind, owner_chat_id FROM communicator_drafts WHERE token = ?',
            [$token]
        )[0];

        return [
            'text'          => (string) $row['text_plain'],
            'entities'      => json_decode((string) ($row['entities'] ?? '[]'), true) ?: [],
            'kind'          => (string) $row['kind'],
            'owner_chat_id' => (string) $row['owner_chat_id'],
        ];
    }

    /**
     * Sweep drafts nobody acted on. Returns how many went.
     *
     * A draft that was pressed is a record of what happened and stays; one that was
     * never pressed is litter holding a copy of a message.
     */
    public function expire(int $olderThanHours): int
    {
        // The interval is interpolated, not bound: MySQL will not take a placeholder
        // where it expects an interval literal. The value is an int by signature, so
        // there is nothing a caller could smuggle through it.
        return Db::execute(
            "DELETE FROM communicator_drafts
             WHERE state = 'offered' AND created_at < NOW() - INTERVAL " . (int) $olderThanHours . " HOUR"
        );
    }
}
