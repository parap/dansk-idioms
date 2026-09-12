<?php declare(strict_types=1);

namespace Dansk\Tests\Reading;

use Dansk\Domain\Reading\InvalidPassage;
use Dansk\Domain\Reading\PassageDocument;
use PHPUnit\Framework\TestCase;

/**
 * The authoring format. A passage is only coherent as a whole -- the gap markers in the
 * text have to agree with the questions underneath it -- so it is written and validated
 * as one document rather than as rows in a form.
 */
final class PassageDocumentTest extends TestCase
{
    private const CLOZE = <<<'DOC'
        kind: cloze
        slug: cykler-i-byen
        title: Cykler i byen

        --- text ---
        Hver morgen ruller tusindvis af cyklister ind mod centrum. {{1}} har
        kommunen bygget nye stier, og det {{2}} at flere tør cykle.

        --- questions ---
        1.
        * Derfor
          Alligevel
          Dernæst
          Til gengæld

        2.
        * betyder
          betyde
          betydning
          betydet
        DOC;

    private const MC = <<<'DOC'
        kind: mc
        slug: brancheskift
        title: At skifte branche

        --- text ---
        Der kan være mange grunde til at skifte branche midt i livet.

        --- questions ---
        1. Hvorfor begyndte hun at overveje et skift?
        * Hun savnede tid til at fordybe sig
          Hun ville tjene mere
          Hun flyttede til en anden by
        DOC;

    private const INSERT = <<<'DOC'
        kind: insert
        slug: fire-dages-uge
        title: En fire-dages arbejdsuge?

        --- text ---
        Siden fagforeningernes opkomst {{1}} har man diskuteret arbejdstiden. {{2}}

        --- parts ---
        A I Danmark er der hver dag 35.000 sygemeldinger.
        B Det forventes at nye medarbejdere møder ind.
        C Der er dog udfordringer ved tilpassede forhold.

        --- questions ---
        1. A
        2. C
        DOC;

    private function parse(string $doc): array
    {
        return (new PassageDocument())->parse($doc);
    }

    // ---- the shape the repository expects -----------------------------------

    public function testACloseDocumentParsesIntoItemsAndOptions(): void
    {
        $doc = $this->parse(self::CLOZE);

        self::assertSame('cloze', $doc['kind']);
        self::assertSame('cykler-i-byen', $doc['slug']);
        self::assertSame('Cykler i byen', $doc['title']);
        self::assertCount(2, $doc['items']);
        self::assertCount(4, $doc['items'][0]['options']);
    }

    public function testTheTextKeepsItsGapMarkersAndLosesItsLineWrapping(): void
    {
        $doc = $this->parse(self::CLOZE);

        self::assertStringContainsString('{{1}}', $doc['body']);
        self::assertStringNotContainsString("\n", $doc['body']);
    }

    public function testTheStarredOptionIsTheCorrectOne(): void
    {
        $doc = $this->parse(self::CLOZE);
        $first = $doc['items'][0]['options'];

        self::assertSame('Derfor', $first[0]['text']);
        self::assertTrue($first[0]['correct']);
        self::assertArrayNotHasKey('correct', $first[1]);
    }

    public function testOptionsAreLetteredInTheOrderTheyAreWritten(): void
    {
        $doc = $this->parse(self::CLOZE);

        self::assertSame(['A', 'B', 'C', 'D'], array_column($doc['items'][0]['options'], 'label'));
    }

    public function testAMultipleChoiceItemCarriesItsQuestion(): void
    {
        $doc = $this->parse(self::MC);

        self::assertSame('Hvorfor begyndte hun at overveje et skift?', $doc['items'][0]['prompt']);
        self::assertCount(3, $doc['items'][0]['options']);
    }

    public function testAnInsertionDocumentBuildsABankAndNamesAPartPerGap(): void
    {
        $doc = $this->parse(self::INSERT);

        self::assertSame(['A', 'B', 'C'], array_column($doc['bank'], 'label'));
        self::assertSame('A', $doc['items'][0]['correct_label']);
        self::assertSame('C', $doc['items'][1]['correct_label']);
    }

    public function testAWrappedPartRejoinsIntoOneLine(): void
    {
        // A part runs 25-35 words. Demanding it on one physical line makes the document
        // unreadable in the editor it is written in, and the text section already
        // rejoins, so a part that wraps has to mean the same thing.
        $doc = $this->parse(<<<'DOC'
            kind: insert
            slug: x
            title: X

            --- text ---
            En sætning mangler her. {{1}} Resten af teksten fortsætter bagefter.

            --- parts ---
            A I Danmark er der hver dag 35.000 sygemeldinger, og det tal
              har ligget stabilt i flere år.
            B Det forventes at nye medarbejdere møder ind.

            --- questions ---
            1. A
            DOC);

        self::assertSame(
            'I Danmark er der hver dag 35.000 sygemeldinger, og det tal har ligget stabilt i flere år.',
            $doc['bank'][0]['text']
        );
        self::assertSame(['A', 'B'], array_column($doc['bank'], 'label'));
    }

    // ---- what it refuses ----------------------------------------------------

    public function testAPartListedTwiceIsRejected(): void
    {
        // A repeated letter is a typo, and an unlabelled line is a continuation -- so
        // without this check the second B is glued onto the part above it and the
        // document parses as if nothing were wrong. The bank here keeps a spare part
        // either way, so nothing but this check can produce the refusal.
        $this->expectException(InvalidPassage::class);
        $this->expectExceptionMessage("Part 'B' is listed twice");
        $this->parse(<<<'DOC'
            kind: insert
            slug: x
            title: X

            --- text ---
            En sætning mangler her. {{1}} Resten fortsætter bagefter.

            --- parts ---
            A Den første tekstdel, som hører til i hullet ovenfor.
            B En tekstdel, der ikke passer nogen steder.
            C Endnu en tekstdel, der heller ikke passer.
            B En tekstdel mere med et bogstav, der allerede er brugt.

            --- questions ---
            1. A
            DOC);
    }

    public function testADocumentWithoutATextSectionIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse("kind: cloze\nslug: x\ntitle: X\n\n--- questions ---\n1.\n* a\n  b\n  c\n");
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace('kind: cloze', 'kind: essay', self::CLOZE));
    }

    public function testAGapWithNoQuestionIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace('tør cykle.', 'tør cykle {{3}}.', self::CLOZE));
    }

    public function testAQuestionWithNoGapIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace('{{2}}', 'betyder', self::CLOZE));
    }

    public function testAMultipleChoiceTextMustNotCarryGapMarkers(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace('midt i livet.', 'midt i livet {{1}}.', self::MC));
    }

    public function testAnItemWithNoStarredOptionIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace('* Derfor', '  Derfor', self::CLOZE));
    }

    public function testAnItemWithTwoStarredOptionsIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace('  Alligevel', '* Alligevel', self::CLOZE));
    }

    public function testAGapNamingAPartTheBankDoesNotOfferIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace("2. C", "2. G", self::INSERT));
    }

    public function testTheSameLetterFillingTwoGapsIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->parse(str_replace("2. C", "2. A", self::INSERT));
    }

    public function testABankWithNoSparePartsIsRejected(): void
    {
        // With every part fitting somewhere the task becomes a permutation the learner
        // can finish by elimination, without reading the text for meaning.
        $doc = str_replace("C Der er dog udfordringer ved tilpassede forhold.\n", '', self::INSERT);
        $doc = str_replace("2. C", "2. B", $doc);

        $this->expectException(InvalidPassage::class);
        $this->parse($doc);
    }

    public function testAnErrorNamesTheLineItWasFoundOn(): void
    {
        try {
            $this->parse(str_replace('* Derfor', '  Derfor', self::CLOZE));
            self::fail('expected the document to be rejected');
        } catch (InvalidPassage $e) {
            self::assertMatchesRegularExpression('/line \d+/', $e->getMessage());
        }
    }
}
