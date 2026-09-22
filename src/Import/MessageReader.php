<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Where messages come from.
 *
 * The importer reads a Telegram export today and a single webhook update tomorrow, and
 * everything after the reading -- segmenting, parsing, confidence, publication -- is the
 * same work either way. Naming the capability here is what lets a second source reuse
 * it, instead of a second copy of the loop drifting away from the first.
 *
 * @phpstan-type Message array{tg_message_id:int, from_name:?string, posted_at:?string,
 *                             posted_at_raw:string, text:string}
 */
interface MessageReader
{
    /**
     * @param string $source What to read: a file path, or whatever the implementation
     *                       names its own input.
     * @return iterable<Message>
     */
    public function read(string $source): iterable;
}
