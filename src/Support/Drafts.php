<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * Where a message waits between the proposal and the press.
 *
 * Named as a capability so the webhook can be exercised without a database: what has to
 * be proven about it -- that nothing reaches the group unasked, that a second press
 * finds nothing -- is decided in the handler, not in SQL.
 */
interface Drafts
{
    /** @param array<int,array<string,mixed>> $entities */
    public function keep(string $ownerChatId, string $text, array $entities, string $kind): string;

    /**
     * Take a draft, once. Null when there is nothing left to take.
     *
     * @return ?array{text:string, entities:array, kind:string, owner_chat_id:string}
     */
    public function claim(string $token): ?array;
}
