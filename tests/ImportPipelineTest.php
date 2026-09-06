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

    public function testInternalMarkersNeverReachStoredText(): void
    {
        // \x02/\x03 carry <strong> through parsing and U+200B drives segmentation.
        // Both were reaching the database and rendering as stray glyphs on screen.
        $entry = $this->parse(
            "at prøve" . self::B1 . " — попытка\n"
            . self::ZW . "Значение: Разговорный оборот" . self::B0 . " со смыслом."
        );

        foreach ([$entry->explanation, $entry->label('Значение'), $entry->headRemainder] as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            self::assertSame($text, Text::clean($text), 'markers survived: ' . bin2hex($text));
            self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{200B}]/u', $text);
        }
    }

    public function testExplanationIsNotDuplicated(): void
    {
        $entry = $this->parse("at prøve — попытка\nЗначение: Смысл выражения.");

        self::assertSame(
            1,
            substr_count((string) $entry->explanation, 'Смысл выражения.'),
            'the label value must appear once, not both bare and label-qualified'
        );
        self::assertStringContainsString('Значение: Смысл выражения.', (string) $entry->explanation);
    }

    public function testTermGivingTwoFormsPicksTheInfinitive(): void
    {
        // "Rodekasser / at være ekspert i rodekasser" is two forms of one entry.
        // Only one can be the headword; the infinitive is the idiom proper.
        $entry = $this->parse("Rodekasser / at være ekspert i rodekasser\nЗначение: Находить ценное среди хлама.");

        self::assertSame('at være ekspert i rodekasser', $entry->term);
        self::assertStringContainsString('Rodekasser', (string) $entry->termNote);
    }

    public function testTransliteratedDanishIsNeverTheAnswer(): void
    {
        // "экспертом в родекассерах" respells the Danish word in Cyrillic. It
        // explains the idiom; it does not translate it.
        $entry = $this->parse(
            "at være ekspert i rodekasser\n"
            . "Значение: Находить ценное среди хлама.\n"
            . "Объяснение: Быть «экспертом в родекассерах» означает искать сокровища."
        );
        foreach ((new TranslationExtractor())->extract($entry) as $t) {
            if (str_contains($t['text'], 'родекассер')) {
                self::assertFalse($t['quiz_usable'], 'a respelt Danish word is not a translation');
                return;
            }
        }
        self::fail('expected the transliterated candidate to be present but rejected');
    }

    public function testAnswerIsFoundInALaterClauseWhenTheFirstIsTooLong(): void
    {
        $entry = $this->parse(
            "at være ekspert i rodekasser\n"
            . "Значение: Разбираться в коробках с хаотично сваленными вещами; находить ценное среди хлама."
        );
        $primary = array_values(array_filter(
            (new TranslationExtractor())->extract($entry),
            fn($t) => $t['is_primary']
        ));

        self::assertNotEmpty($primary);
        self::assertSame('находить ценное среди хлама', $primary[0]['text']);
    }

    public function testLiterallyTranslatesAsIsNotTheMeaning(): void
    {
        // "Буквально переводится как «быть в своем тузе»" ends in an idiomatic cue
        // but is a literal gloss. It was outranking the real meaning in Значение.
        $entry = $this->parse(
            "At være i sit es\n"
            . "Значение: Быть в своей стихии, чувствовать себя как рыба в воде, быть на высоте.\n"
            . "Объяснение: Слово es означает «туз». Буквально переводится как «быть в своем тузе»."
        );
        $byText = [];
        foreach ((new TranslationExtractor())->extract($entry) as $t) {
            $byText[$t['text']] = $t;
        }

        self::assertSame('literal', $byText['быть в своем тузе']['sense_type']);
        self::assertFalse($byText['быть в своем тузе']['quiz_usable']);
        self::assertSame('gloss', $byText['туз']['sense_type']);

        $primary = array_values(array_filter($byText, fn($t) => $t['is_primary']));
        self::assertNotEmpty($primary, 'the meaning from Значение must win the primary slot');
        self::assertStringContainsString('стихии', $primary[0]['text']);
    }

    public function testSlashSeparatedVerbsSharingAnObjectAreNotSplit(): void
    {
        // "Дать / подать / опубликовать объявление в прессе" is three verbs sharing
        // one object. Splitting it produced the answer "Дать", which translates nothing.
        $entry = $this->parse("At indrykke en annonce\nЗначение: Дать / подать / опубликовать объявление в прессе.");
        foreach ((new TranslationExtractor())->extract($entry) as $t) {
            self::assertNotSame('Дать', $t['text'], 'a bare verb without its object is not a translation');
        }
    }

    public function testMeaningInAnUnlabelledContinuationLineIsFound(): void
    {
        // "Буквально оно означает «...», а по смыслу:" then the meaning as a bare
        // list. The literal was being served as the answer: the compound cue needs
        // to tolerate a word between "буквально" and "означает", and the meaning
        // lives in a continuation line that was not being read at all.
        $entry = $this->parse(
            self::B0 . 'at mærke efter' . self::B1 . " — это важное выражение.\n"
            . "Буквально оно означает «ощупывать вслед за ощущением», а по смыслу:\n"
            . "прислушиваться к своим чувствам и потребностям;"
        );
        $translations = (new TranslationExtractor())->extract($entry);

        $byText = [];
        foreach ($translations as $t) {
            $byText[$t['text']] = $t;
        }
        self::assertSame('literal', $byText['ощупывать вслед за ощущением']['sense_type']);
        self::assertFalse($byText['ощупывать вслед за ощущением']['quiz_usable']);

        $primary = array_values(array_filter($translations, fn($t) => $t['is_primary']));
        self::assertSame('прислушиваться к своим чувствам и потребностям', $primary[0]['text']);
    }

    public function testHeadLineAnswerSurvivesAParentheticalContainingQuotes(): void
    {
        // "стокроновая купюра (… слово lap означает «лоскут», «заплатка»)" — the head
        // was skipped wholesale for containing «, and a fallback regex then emitted
        // the unbalanced fragment 'лоскут», «заплатка»)' as the answer.
        $entry = $this->parse(
            'en hundredelap — стокроновая купюра (наименование банкноты; '
            . 'слово lap буквально означает «лоскут», «заплатка»).'
        );
        $primary = array_values(array_filter(
            (new TranslationExtractor())->extract($entry),
            fn($t) => $t['is_primary']
        ));

        self::assertSame('стокроновая купюра', $primary[0]['text']);
    }

    // ---- classification ----------------------------------------------------

    /** @dataProvider verbPhrases */
    public function testFiniteAndPastVerbFormsAreRecognised(string $text, bool $expected): void
    {
        // Reported from a real round: "договорились" was classed as a noun phrase
        // because only infinitive endings were checked, so the picker offered
        // "сочная красотка" and "затею" alongside it.
        self::assertSame($expected, (new \Dansk\Import\Classifier())->hasVerb($text));
    }

    public static function verbPhrases(): array
    {
        return [
            'reflexive past'   => ['договорились', true],
            'past plural'      => ['на том и порешили', true],
            'future 1pl'       => ['так и сделаем', true],
            'infinitive'       => ['не упустить шанс', true],
            'reflexive inf'    => ['юркнуть назад', true],
            'noun phrase'      => ['сочная красотка', false],
            'bare noun'        => ['затею', false],
            'prepositional'    => ['по рукам', false],
            'noun enumeration' => ['гравий, щебень, труха', false],
        ];
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
