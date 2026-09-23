<?php declare(strict_types=1);

namespace Dansk\Support;

use Dansk\Import\Importer;
use Dansk\Import\SingleMessageReader;

/**
 * Puts a published post into the corpus, through the same import the export uses.
 *
 * Nothing here is new work: segmenting, parsing, confidence and publication are the
 * import's, and reusing them is the point -- a second copy of that loop would drift
 * away from the first and nothing would say when.
 *
 * It writes under the **same source** as the export. The group's message ids share one
 * numbering, so `uq_msg (source_id, tg_message_id)` makes a later re-import of an
 * export idempotent: the same post arrives again and updates its row instead of
 * doubling it.
 */
final class CommunicatorIngest
{
    public function __construct(
        private string $sourceTitle,
        private ?\Closure $runImport = null,
    ) {
    }

    /**
     * The message as the importer wants it.
     *
     * @param array<string,mixed> $update  Telegram's `message`
     * @param int $publishedId             The id the post got in the group
     * @return array{tg_message_id:int, from_name:?string, posted_at:string,
     *               posted_at_raw:string, text:string}
     */
    public static function messageFrom(array $update, int $publishedId): array
    {
        // Seconds since the epoch are UTC, and the column says UTC. Formatting in the
        // server's own zone would shift every post by an hour or two -- invisibly,
        // because a plausible time is still a time.
        $seconds = (int) ($update['date'] ?? 0);
        $posted  = (new \DateTimeImmutable('@' . ($seconds > 0 ? $seconds : time())))
            ->setTimezone(new \DateTimeZone('UTC'));

        return [
            // The post in the group, not the private message the bot was written to:
            // that id is what a reader would follow back.
            'tg_message_id' => $publishedId,
            'from_name'     => $update['from']['first_name'] ?? null,
            'posted_at'     => $posted->format('Y-m-d H:i:s'),
            'posted_at_raw' => $posted->format('Y-m-d H:i:s'),
            // Without the tag: it is our addition, not the author's words, and stored
            // it would ride into the last entry's explanation, since nothing separates
            // it from what precedes.
            'text'          => (string) ($update['text'] ?? ''),
        ];
    }

    public function __invoke(array $update, int $publishedId): void
    {
        $message = self::messageFrom($update, $publishedId);
        $run     = $this->runImport ?? static function (array $m, string $title): void {
            (new Importer(new SingleMessageReader($m)))
                ->import('telegram:' . $m['tg_message_id'], $title);
        };

        $run($message, $this->sourceTitle);
    }
}
