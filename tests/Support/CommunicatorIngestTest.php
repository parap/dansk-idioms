<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\CommunicatorIngest;
use PHPUnit\Framework\TestCase;

/**
 * The shape handed to the importer. This is where a mistake would land quietly in the
 * corpus rather than stopping anything, so it is checked field by field.
 */
final class CommunicatorIngestTest extends TestCase
{
    private const UPDATE = [
        'text' => 'at gå agurk — сойти с ума',
        'date' => 1_790_103_420,                 // 2026-09-22 18:57:00 UTC
        'from' => ['first_name' => 'Nataniel', 'id' => 158493465],
        'chat' => ['id' => 158493465],
    ];

    public function testItIsTheGroupPostThatIsRecorded(): void
    {
        // Not the private message the bot was written to. The corpus tracks what
        // stands in the group, and that id is what a reader would go back to.
        $m = CommunicatorIngest::messageFrom(self::UPDATE, 637_200);

        self::assertSame(637_200, $m['tg_message_id']);
    }

    public function testTheTextIsStoredWithoutTheTag(): void
    {
        // The tag is our addition, not the author's words. Stored, it would ride into
        // the last entry's explanation, since nothing separates it from what precedes.
        $m = CommunicatorIngest::messageFrom(self::UPDATE, 637_200);

        self::assertSame('at gå agurk — сойти с ума', $m['text']);
        self::assertStringNotContainsString('#text', $m['text']);
    }

    public function testTheTimeIsUtc(): void
    {
        // Telegram counts seconds since the epoch, which is UTC; the column says UTC.
        // Formatting in the server's own zone would shift every post by an hour or two.
        $m = CommunicatorIngest::messageFrom(self::UPDATE, 637_200);

        self::assertSame('2026-09-22 18:57:00', $m['posted_at']);
    }

    public function testTheAuthorSurvives(): void
    {
        $m = CommunicatorIngest::messageFrom(self::UPDATE, 637_200);

        self::assertSame('Nataniel', $m['from_name']);
    }

    public function testAMissingDateDoesNotBecomeNineteenSeventy(): void
    {
        // A message with no date would otherwise be filed at the epoch and sort before
        // the whole corpus, for good.
        $m = CommunicatorIngest::messageFrom(['text' => 'at spille'], 637_200);

        self::assertNotSame('1970-01-01 00:00:00', $m['posted_at']);
    }
}
