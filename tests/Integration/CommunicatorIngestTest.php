<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Support\CommunicatorIngest;

/**
 * Where a post the bot published stands on the site, read back from what the import
 * stored -- the owner's report says this, so it has to be the database's answer.
 */
final class CommunicatorIngestTest extends IntegrationTestCase
{
    private static function post(string $text): array
    {
        return ['text' => $text, 'date' => 1_790_103_420, 'from' => ['first_name' => 'Alex']];
    }

    public function testAnIdiomTheQuizCanAskIsPublished(): void
    {
        $state = (new CommunicatorIngest('Dansk idioms (Telegram)'))(
            self::post('at regne med (noget) — рассчитывать на (что-то), ожидать (чего-то). Фразовый глагол.'),
            640_500
        );

        self::assertSame('published', $state);
    }

    public function testAnIdiomTheImportIsUnsureOfWaitsForReview(): void
    {
        $state = (new CommunicatorIngest('Dansk idioms (Telegram)'))(
            self::post('Til knæene — по колено.'),
            640_501
        );

        self::assertSame('review', $state);
    }

    public function testAnIdiomTheImportCouldNotReadIsRejected(): void
    {
        // Meaning on the next line: an idiom, but the split scores too low to file.
        $state = (new CommunicatorIngest('Dansk idioms (Telegram)'))(
            self::post("at gå agurk\nсойти с ума"),
            640_502
        );

        self::assertSame('rejected', $state);
    }

    public function testTheAnswerIsAboutThisPostAlone(): void
    {
        $ingest = new CommunicatorIngest('Dansk idioms (Telegram)');
        $ingest(self::post('at regne med (noget) — рассчитывать на (что-то), ожидать (чего-то). Фразовый глагол.'), 640_503);

        self::assertSame('review', $ingest(self::post('Til knæene — по колено.'), 640_504));
    }
}
