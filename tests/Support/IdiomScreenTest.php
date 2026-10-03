<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\IdiomScreen;
use PHPUnit\Framework\TestCase;

/**
 * Whether a piece sent to the bot holds an idiom at all.
 *
 * The cases that fail are the ones the group actually received: links, a thumbs-up,
 * chatter in Russian. Each went out under the bot's name and could not be taken back.
 */
final class IdiomScreenTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function idioms(): array
    {
        return [
            'headword and dash'       => ['at gå agurk — сойти с ума', 'at gå agurk'],
            'meaning on the next line' => ["at gå agurk\nсойти с ума", 'at gå agurk'],
            'after the separator'     => ["\u{200B}at regne med (noget) — рассчитывать на (что-то)", 'at regne med'],
            'a translated sentence'   => ["Du er godt kørende, hvad?\n\nА ты в ударе, да?", 'Du er godt kørende, hvad'],
        ];
    }

    /** @dataProvider idioms */
    public function testAnIdiomIsNamedByItsHeadword(string $piece, string $term): void
    {
        self::assertSame($term, (new IdiomScreen())($piece));
    }

    /** @return array<string,array{string}> */
    public static function notIdioms(): array
    {
        return [
            'a link'                  => ['https://youtu.be/Qczpi7eDgzw?is=Hl25CScpCTwKUGFW'],
            'a link explained'        => ['https://danskidioms.com — идиомы, поставил на сайт'],
            'a thumbs-up'             => ['👍'],
            'chatter'                 => ['Вот как после этого верить ИИ Клоду 😂'],
            'Russian only'            => ['Андерсен, сказка с субтитрами'],
            'Danish with no meaning'  => ['Tak, fordi du gad komme forbi.'],
            'a headword alone'        => ['at spille'],
            'meaning not in Russian'  => ['Kunne tænke sig = i want'],
        ];
    }

    /** @dataProvider notIdioms */
    public function testWhatHoldsNoIdiomIsNamedNothing(string $piece): void
    {
        self::assertNull((new IdiomScreen())($piece));
    }
}
