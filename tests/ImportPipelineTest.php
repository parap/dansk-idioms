<?php declare(strict_types=1);

namespace Dansk\Tests;

use Dansk\Import\{EntryParser, EntrySegmenter, Normalizer, Text, TranslationExtractor};
use PHPUnit\Framework\TestCase;

/**
 * The importer's regression contract. Every case here is taken verbatim from the real
 * export; each one encodes a failure mode that was actually observed.
 */
final class ImportPipelineTest extends TestCase
{
    private const ZW = Text::ZWSP;
    private const B0 = Text::BOLD_OPEN;
    private const B1 = Text::BOLD_CLOSE;

    private function parse(string $entry): \Dansk\Import\ParsedEntry
    {
        return (new EntryParser())->parse($entry);
    }

    // ---- segmentation ------------------------------------------------------

    public function testZwspContinuationLinesDoNotBecomeSeparateEntries(): void
    {
        // The bug this guards: U+200B prefixes continuation lines too, so a naive
        // split produced 619 chunks where only 354 were entries.
        $msg = self::ZW . 'at smide benene op — забросить ноги.' . "\n"
             . self::ZW . 'Значение: Разговорное выражение, описывающее позу.' . "\n"
             . self::ZW . 'at slænge sig — развалиться, растянуться.' . "\n"
             . self::ZW . 'Значение: Возвратный глагол.';

        $result = (new EntrySegmenter())->segment($msg);

        self::assertCount(2, $result['entries'], 'continuations must merge into their parent');
        self::assertStringContainsString('Значение', $result['entries'][0]);
    }

    public function testRussianProseWithBoldHeadwordIsAnEntryNotPreamble(): void
    {
        $msg = 'Глагольная конструкция ' . self::B0 . 'at mærke efter' . self::B1 . ' — это важное выражение.';
        $result = (new EntrySegmenter())->segment($msg);

        self::assertCount(1, $result['entries']);
        self::assertSame('at mærke efter', $this->parse($result['entries'][0])->term);
    }

    public function testUnboldedHeadwordOnItsOwnLineIsFound(): void
    {
        // "få at vide" — Cyrillic-initial, headword neither bold nor first.
        $msg = "Конструкция\nfå at vide\n(буквально: «получить знать») — стандартный оборот, означающий «узнать».";
        $result = (new EntrySegmenter())->segment($msg);

        self::assertCount(1, $result['entries']);
        self::assertSame('få at vide', $this->parse($result['entries'][0])->term);
    }

    // ---- term / separator --------------------------------------------------

    /** @dataProvider separatorCases */
    public function testTermSplitting(string $entry, string $expectedTerm): void
    {
        self::assertSame($expectedTerm, $this->parse($entry)->term);
    }

    public static function separatorCases(): array
    {
        return [
            'interior colon after Cyrillic is not a separator' => [
                'knyttede næve — устойчивое сочетание: «сжатый кулак».',
                'knyttede næve',
            ],
            'em dash inside a parenthetical is not a separator' => [
                'fandeme — бранное слово (происходит от Fanden — «дьявол, чёрт»). Переводится как «чёрт возьми».',
                'fandeme',
            ],
            'colon separator' => [
                'at slå sig sammen: Словосочетание означает «объединяться».',
                'at slå sig sammen',
            ],
            'Cyrillic parenthetical inside the term does not defeat the split' => [
                "Jeg ved sgu ikk' (сокращение от ikke) — разговорный оборот, означающий «да фиг знает».",
                "Jeg ved sgu ikk'",
            ],
        ];
    }

    public function testInflectedFormIsCaptured(): void
    {
        $entry = $this->parse("At være i sit es (в тексте: er i sit es)\nЗначение: Быть в своей стихии.");

        self::assertSame('At være i sit es', $entry->term);
        self::assertSame('er i sit es', $entry->inflectedForm);
        self::assertSame('Быть в своей стихии.', $entry->label('Значение'));
    }

    public function testEntryWithNoInlineTranslationStillGetsOneFromZnachenie(): void
    {
        $entry = $this->parse("At prale af (noget)\nЗначение: Хвастаться чем-либо.");
        $translations = (new TranslationExtractor())->extract($entry);

        $primary = array_values(array_filter($translations, fn($t) => $t['is_primary']));
        self::assertCount(1, $primary, 'an entry whose head carries no translation must still resolve one');
        self::assertSame('Хвастаться чем-либо', $primary[0]['text']);
    }

    // ---- translation extraction -------------------------------------------

    public function testGlossOfADifferentWordIsNeverQuizUsable(): void
    {
        // THE trap: «дьявол, чёрт» glosses *Fanden*, not *fandeme*.
        $entry = $this->parse(
            'fandeme — бранная частица (происходит от Fanden — «дьявол, чёрт»). '
            . 'Переводится как «чёрт возьми», «чертовски».'
        );
        $translations = (new TranslationExtractor())->extract($entry);

        $byText = [];
        foreach ($translations as $t) {
            $byText[$t['text']] = $t;
        }

        self::assertArrayHasKey('дьявол, чёрт', $byText);
        self::assertSame('gloss', $byText['дьявол, чёрт']['sense_type']);
        self::assertFalse($byText['дьявол, чёрт']['quiz_usable'], 'must never be offered as an answer');
        self::assertTrue($byText['чёрт возьми']['is_primary']);
    }

    public function testGlossOfAComponentWordIsNotTheAnswer(): void
    {
        // Reported from a real round: "Слово grus означает «гравий, щебень, труха»"
        // defines a component of the idiom, not the idiom. It was being served as
        // the correct answer, outranking the author's own translation on the head line.
        $entry = $this->parse(
            "at få verden til at styrte i grus — заставить мир рухнуть в прах / разрушить чей-то мир до основания.\n"
            . "Значение: Яркое метафорическое выражение. Слово grus означает «гравий, щебень, труха», "
            . "а весь оборот описывает эмоциональный крах."
        );
        $translations = (new TranslationExtractor())->extract($entry);

        $byText = [];
        foreach ($translations as $t) {
            $byText[$t['text']] = $t;
        }

        self::assertSame('gloss', $byText['гравий, щебень, труха']['sense_type']);
        self::assertFalse($byText['гравий, щебень, труха']['quiz_usable']);

        $primary = array_values(array_filter($translations, fn($t) => $t['is_primary']));
        self::assertSame('заставить мир рухнуть в прах', $primary[0]['text'],
            'the head-line translation must outrank a quoted gloss buried in the explanation');
    }

    public function testMetaDescriptionIsRejectedEvenWhenItLeadsWithAdjectives(): void
    {
        $entry = $this->parse("at prøve — Яркое метафорическое выражение, описывающее нечто.");
        foreach ((new TranslationExtractor())->extract($entry) as $t) {
            if (str_contains($t['text'], 'метафорическое')) {
                self::assertFalse($t['quiz_usable']);
                return;
            }
        }
        self::fail('expected the adjective-led meta-description to be present but rejected');
    }

    public function testLexicographicMetaDescriptionIsNotAnAnswer(): void
    {
        $entry = $this->parse(
            "at give et sug i maven\nЗначение: Устойчивое идиоматическое выражение, описывающее ощущение."
        );
        foreach ((new TranslationExtractor())->extract($entry) as $t) {
            if (str_starts_with($t['text'], 'Устойчивое')) {
                self::assertFalse($t['quiz_usable'], 'grammar terminology is not a translation');
                return;
            }
        }
        self::fail('expected the meta-description candidate to be present but rejected');
    }

    public function testLiteralGlossIsKeptButNotOfferedAsTheAnswer(): void
    {
        $entry = $this->parse(
            'at slå pjalterne sammen — Дословно «ударить себя вместе». Означает «объединиться».'
        );
        $translations = (new TranslationExtractor())->extract($entry);

        $literal = array_values(array_filter($translations, fn($t) => $t['sense_type'] === 'literal'));
        self::assertNotEmpty($literal, 'literal glosses are retained -- they make ideal distractors');
        self::assertFalse($literal[0]['quiz_usable']);
    }

    // ---- normalization -----------------------------------------------------

    public function testDanishLettersAreNeverAsciiFolded(): void
    {
        self::assertNotSame(Normalizer::term('hår'), Normalizer::term('har'));
        self::assertNotSame(Normalizer::term('fører'), Normalizer::term('forer'));
    }

    public function testInfinitiveMarkerAndParentheticalsAreStrippedFromTheKey(): void
    {
        self::assertSame(
            Normalizer::term('at slå sig sammen'),
            Normalizer::term('Slå sig sammen (om noget)')
        );
    }

    /** @dataProvider cyrillicEndings */
    public function testTrimmingNeverProducesInvalidUtf8(string $input): void
    {
        // trim($s, "…«»") is byte-based and '…' contributes 0x80, the trailing byte of
        // many Cyrillic letters -- it used to cut 'р' in half and MySQL rejected the row.
        foreach ([Normalizer::translation($input), Normalizer::term($input),
                  Text::trimPunctuation($input)] as $out) {
            self::assertTrue(mb_check_encoding($out, 'UTF-8'), "mangled: " . bin2hex($out));
        }
    }

    public static function cyrillicEndings(): array
    {
        return [
            'ends in р (D1 80)'   => ['«собрать»'],
            'ends in с (D1 81)'   => ['…вопрос…'],
            'ends in ь (D1 8C)'   => ['«потерять уверенность»'],
            'quoted and dotted'   => ['„сдаться…“'],
            'guillemets both ends' => ['«чёрт возьми»'],
        ];
    }

    public function testDecomposedFormsCollideWithComposedOnes(): void
    {
        // macOS-sourced text arrives NFD; without NFC these silently duplicate.
        self::assertSame(Normalizer::term('hår'), Normalizer::term("ha\u{030A}r"));
    }
}
