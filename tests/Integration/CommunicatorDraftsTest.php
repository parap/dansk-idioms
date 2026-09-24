<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Support\CommunicatorDrafts;
use Dansk\Support\Db;

/**
 * What the bot was sent, held between the proposal and the press.
 *
 * Two things must hold here or the feature lies. The text has to come back exactly as
 * it went in, entities and all, because a proposal rendered into a message has already
 * lost the bold spans a quarter of this corpus splits on. And a draft has to be
 * claimable once: publishing cannot be undone, so a doubled press must find nothing.
 */
final class CommunicatorDraftsTest extends IntegrationTestCase
{
    private const BOLD = [['type' => 'bold', 'offset' => 0, 'length' => 11]];

    protected function setUp(): void
    {
        Db::execute('DELETE FROM communicator_drafts');
    }

    public function testADraftComesBackExactlyAsItWentIn(): void
    {
        $drafts = new CommunicatorDrafts();
        $text   = "at gå agurk — сойти с ума\nat slænge sig — развалиться";

        $token = $drafts->keep('158493465', $text, self::BOLD, 'text');
        $taken = $drafts->claim($token);

        self::assertNotNull($taken);
        self::assertSame($text, $taken['text']);
        // By content, not by key order: a JSON column does not promise the order it
        // stores object keys in, and Telegram reads entities by name. Asserting the
        // order would be asserting something neither side undertakes to keep.
        self::assertEquals(self::BOLD, $taken['entities']);
        self::assertSame('text', $taken['kind']);
        self::assertSame('158493465', $taken['owner_chat_id']);
    }

    public function testAnInvisibleSeparatorSurvivesTheRoundTrip(): void
    {
        // U+200B is what the splitting rule reads. A store that strips or normalises it
        // would turn a message whose boundaries were knowable into one that is not.
        $drafts = new CommunicatorDrafts();
        $text   = "\u{200B}at gå agurk — сойти с ума";

        $taken = $drafts->claim($drafts->keep('1', $text, [], 'text'));

        self::assertSame($text, $taken['text']);
    }

    public function testADraftIsClaimedOnce(): void
    {
        // Publishing is not undoable. A second press must find nothing to act on.
        $drafts = new CommunicatorDrafts();
        $token  = $drafts->keep('1', 'at spille', [], 'text');

        self::assertNotNull($drafts->claim($token));
        self::assertNull($drafts->claim($token));
    }

    public function testAnUnknownTokenClaimsNothing(): void
    {
        self::assertNull((new CommunicatorDrafts())->claim('01JJJJJJJJJJJJJJJJJJJJJJJJ'));
    }

    public function testOnlyUnclaimedDraftsGoStale(): void
    {
        // A draft nobody pressed is litter; one that was acted on is a record.
        $drafts = new CommunicatorDrafts();
        $old    = $drafts->keep('1', 'at spille', [], 'text');
        $used   = $drafts->keep('1', 'at danse', [], 'text');
        $drafts->claim($used);
        Db::execute("UPDATE communicator_drafts SET created_at = created_at - INTERVAL 3 DAY");

        $swept = $drafts->expire(24);

        self::assertSame(1, $swept);
        self::assertNull($drafts->claim($old), 'the stale one is gone');
        self::assertSame(1, (int) Db::fetchValue(
            "SELECT COUNT(*) FROM communicator_drafts WHERE state = 'taken'"
        ));
    }
}
