<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\Communicator;
use PHPUnit\Framework\TestCase;

/**
 * What the publisher bot decides before anything reaches Telegram.
 *
 * Publishing cannot be undone: a post is not recalled from the group, and one sent by
 * the wrong person or to the wrong place has already been seen. So every refusal is
 * checked here rather than in the request handler, where it would need an HTTP call.
 */
final class CommunicatorTest extends TestCase
{
    // --- who may publish ----------------------------------------------------

    public function testAnUnsetSecretRefusesEveryone(): void
    {
        // An unset secret is a switched-off feature, not "admit everyone". The
        // webhook address is guessable, and the opposite would hand publishing
        // rights to whoever guessed it.
        self::assertFalse(Communicator::accepts(null, 'что угодно'));
        self::assertFalse(Communicator::accepts('', 'что угодно'));
        self::assertFalse(Communicator::accepts(null, null));
    }

    public function testOnlyTheMatchingSecretIsAccepted(): void
    {
        self::assertTrue(Communicator::accepts('s3cret', 's3cret'));
        self::assertFalse(Communicator::accepts('s3cret', 's3cre'));
        self::assertFalse(Communicator::accepts('s3cret', null));
    }

    public function testOnlyTheOwnerMayPublish(): void
    {
        // A bot can be found by name and written to. Without this check a
        // stranger's message would go out to the group under the bot's name.
        self::assertTrue(Communicator::fromOwner(self::message('привет'), '4242'));
        self::assertFalse(Communicator::fromOwner(self::message('привет', 99), '4242'));
        self::assertFalse(Communicator::fromOwner(self::message('привет'), null));
    }

    // --- what kind of post is it ---------------------------------------------

    public function testAPlainMessageIsText(): void
    {
        self::assertSame('text', Communicator::kindOf(self::message('at spille')));
    }

    public function testAMessageCarryingVideoIsVideo(): void
    {
        // The kind comes from the attachment, not the words: nothing to misread.
        self::assertSame('video', Communicator::kindOf(['video' => ['file_id' => 'x']]));
        self::assertSame('video', Communicator::kindOf(['video_note' => ['file_id' => 'x']]));
    }

    public function testAVideoFileSentAsADocumentIsStillVideo(): void
    {
        // The fairy tales sit in the group as .mkv files, and Telegram puts a file
        // in `document`, not `video`. Without this they would all get #text.
        self::assertSame('video', Communicator::kindOf(
            ['document' => ['file_name' => '01 Fyrtojet.mkv', 'mime_type' => 'video/x-matroska']]
        ));
    }

    public function testADocumentThatIsNotVideoIsText(): void
    {
        self::assertSame('text', Communicator::kindOf(
            ['document' => ['file_name' => 'ordliste.pdf', 'mime_type' => 'application/pdf']]
        ));
    }

    // --- the hashtag ---------------------------------------------------------

    public function testTheTagIsAppendedOnItsOwnLine(): void
    {
        self::assertSame("at spille\n\n#text", Communicator::tagged('at spille', 'text'));
    }

    public function testATagAlreadyThereIsNotDoubled(): void
    {
        // Otherwise a redelivered webhook puts "#text #text" in the post.
        self::assertSame("at spille\n\n#text", Communicator::tagged("at spille\n\n#text", 'text'));
    }

    public function testTheTagNeverLandsInsideAWord(): void
    {
        // "#textil" does not count as a tag already there.
        self::assertSame("#textil\n\n#text", Communicator::tagged('#textil', 'text'));
    }

    // --- formatting survives --------------------------------------------------

    public function testOffsetsAreCountedInUtf16Units(): void
    {
        // Telegram counts entity offsets in UTF-16 code units, not characters. Danish
        // letters are one unit each; an emoji is a surrogate pair and counts as two.
        // Get this wrong and every bold span after an emoji lands on the wrong word --
        // silently, because nothing errors.
        self::assertSame(5, Communicator::utf16Length('gå nu'));
        self::assertSame(2, Communicator::utf16Length('gå'));
    }

    public function testAnEmojiCountsAsTwoUnits(): void
    {
        self::assertSame(2, Communicator::utf16Length('🙂'));
        self::assertSame(4, Communicator::utf16Length('ab🙂'));
    }

    public function testEntitiesSurviveTheTagBeingAppended(): void
    {
        // The tag goes on the end, so offsets that were valid stay valid.
        $entities = [['type' => 'bold', 'offset' => 0, 'length' => 8]];

        self::assertSame($entities, Communicator::clampEntities($entities, 'at spille'));
    }

    public function testAnEntityRunningPastTheTextIsClamped(): void
    {
        // Trailing whitespace is stripped before the tag is appended. An entity that
        // reached into it would otherwise point past the end, and Telegram rejects the
        // whole message -- the post simply never appears.
        $entities = [['type' => 'bold', 'offset' => 0, 'length' => 20]];

        $clamped = Communicator::clampEntities($entities, 'at spille');

        self::assertSame(9, $clamped[0]['length']);
    }

    public function testAnEntityStartingPastTheTextIsDropped(): void
    {
        $entities = [['type' => 'bold', 'offset' => 50, 'length' => 3]];

        self::assertSame([], Communicator::clampEntities($entities, 'at spille'));
    }

    // --- the proposal shown before anything is published ----------------------

    public function testThePiecesAreNumberedFromOne(): void
    {
        $text = Communicator::proposal(['at gå agurk — сойти с ума', 'at slænge sig — развалиться']);

        self::assertStringContainsString('1. at gå agurk', $text);
        self::assertStringContainsString('2. at slænge sig', $text);
    }

    public function testALongPieceIsShortenedInTheProposal(): void
    {
        // The proposal must fit in one message however long the explanations were;
        // what it shows is the boundaries, not the whole text.
        $text = Communicator::proposal([str_repeat('очень длинное объяснение ', 40)]);

        self::assertLessThan(400, mb_strlen($text));
        self::assertStringContainsString('…', $text);
    }

    public function testBothChoicesAreOffered(): void
    {
        // Splitting is a guess. Publishing it whole must stay one press away, or the
        // guess becomes the only option and the question was rhetorical.
        $keyboard = Communicator::splitKeyboard('01JJJJJJJJJJJJJJJJJJJJJJJJ', 3);

        $labels = array_map(static fn(array $row): string => $row[0]['text'], $keyboard['inline_keyboard']);
        self::assertSame(['Разбить на 3', 'Одним постом'], $labels);
    }

    public function testEveryButtonFitsTelegramsLimit(): void
    {
        // callback_data is capped at 64 bytes and Telegram refuses the whole message
        // over it -- the proposal would simply never arrive.
        $keyboard = Communicator::splitKeyboard('01JJJJJJJJJJJJJJJJJJJJJJJJ', 3);

        foreach ($keyboard['inline_keyboard'] as $row) {
            self::assertLessThanOrEqual(64, strlen($row[0]['callback_data']), $row[0]['text']);
        }
    }

    public function testAPressNamesItsDraft(): void
    {
        $keyboard = Communicator::splitKeyboard('01JJJJJJJJJJJJJJJJJJJJJJJJ', 2);

        self::assertSame('split:01JJJJJJJJJJJJJJJJJJJJJJJJ', $keyboard['inline_keyboard'][0][0]['callback_data']);
        self::assertSame('whole:01JJJJJJJJJJJJJJJJJJJJJJJJ', $keyboard['inline_keyboard'][1][0]['callback_data']);
    }

    private static function message(string $text, int $chatId = 4242): array
    {
        return ['text' => $text, 'chat' => ['id' => $chatId]];
    }
}
