<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * One message, already in hand.
 *
 * A webhook update arrives parsed; there is nothing to open and nothing to decode. It
 * still goes through the same loop as an export, so segmenting, confidence, publication
 * and the post-run invariants stay one implementation rather than two that drift.
 */
final class SingleMessageReader implements MessageReader
{
    /**
     * @param array{tg_message_id:int, from_name:?string, posted_at:?string,
     *              posted_at_raw:string, text:string} $message
     */
    public function __construct(private array $message)
    {
    }

    public function read(string $source): iterable
    {
        yield $this->message;
    }
}
