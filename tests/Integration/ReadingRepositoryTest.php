<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\InvalidPassage;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Support\Db;

/**
 * Authoring is the only way reading content enters the app, so the repository is the
 * gate: a passage that could not be rendered or graded must be refused here rather than
 * discovered by a learner halfway through a timed paper.
 */
final class ReadingRepositoryTest extends IntegrationTestCase
{
    private ReadingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ReadingRepository();
    }

    /** @return array<string,mixed> */
    private function cloze(array $overrides = []): array
    {
        return $overrides + [
            'slug'  => 'cykler-i-byen',
            'kind'  => 'cloze',
            'title' => 'Cykler i byen',
            'body'  => 'Hver morgen ruller tusindvis ind mod centrum. {{1}} har kommunen '
                     . 'bygget nye stier, og det {{2}} at flere tor cykle.',
            'items' => [
                ['position' => 1, 'options' => [
                    ['label' => 'A', 'text' => 'Derfor',    'correct' => true],
                    ['label' => 'B', 'text' => 'Alligevel'],
                    ['label' => 'C', 'text' => 'Dernaest'],
                    ['label' => 'D', 'text' => 'Til gengaeld'],
                ]],
                ['position' => 2, 'options' => [
                    ['label' => 'A', 'text' => 'betyder', 'correct' => true],
                    ['label' => 'B', 'text' => 'betyde'],
                    ['label' => 'C', 'text' => 'betydning'],
                    ['label' => 'D', 'text' => 'betydet'],
                ]],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function insertion(): array
    {
        return [
            'slug'  => 'fire-dages-uge',
            'kind'  => 'insert',
            'title' => 'En fire-dages arbejdsuge?',
            'body'  => 'Siden fagforeningernes opkomst {{1}} har man diskuteret arbejdstiden. {{2}}',
            'bank'  => [
                ['label' => 'A', 'text' => 'I Danmark er der hver dag 35.000 sygemeldinger.'],
                ['label' => 'B', 'text' => 'Det forventes, at nye medarbejdere moeder ind.'],
                ['label' => 'C', 'text' => 'Der er dog udfordringer ved tilpassede forhold.'],
            ],
            'items' => [
                ['position' => 1, 'correct_label' => 'A'],
                ['position' => 2, 'correct_label' => 'C'],
            ],
        ];
    }

    public function testSavingAPassageStoresItsItemsAndOptions(): void
    {
        $id = $this->repo->save($this->cloze());

        self::assertSame(2, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_items WHERE passage_id = ?', [$id]));
        self::assertSame(8, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_options WHERE passage_id = ?', [$id]));
    }

    public function testEachItemGetsExactlyOneCorrectOption(): void
    {
        $id = $this->repo->save($this->cloze());

        self::assertSame(0, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_items WHERE passage_id = ? AND correct_option_id IS NULL', [$id]
        ));
    }

    public function testPointsFollowTheTaskKind(): void
    {
        $cloze  = $this->repo->save($this->cloze());
        $insert = $this->repo->save($this->insertion());

        self::assertSame(1, (int) Db::fetchValue('SELECT DISTINCT points FROM reading_items WHERE passage_id = ?', [$cloze]));
        self::assertSame(2, (int) Db::fetchValue('SELECT DISTINCT points FROM reading_items WHERE passage_id = ?', [$insert]));
    }

    public function testTheWordCountIsComputedFromTheBody(): void
    {
        $id = $this->repo->save($this->cloze());

        self::assertGreaterThan(10, (int) Db::fetchValue('SELECT word_count FROM reading_passages WHERE id = ?', [$id]));
    }

    public function testAnInsertionBankKeepsThePartsThatFitNoGap(): void
    {
        $id = $this->repo->save($this->insertion());

        // B is a decoy: it is offered, and it answers nothing.
        self::assertSame(3, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_options WHERE passage_id = ?', [$id]));
        self::assertSame(1, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_options o WHERE o.passage_id = ?
               AND NOT EXISTS (SELECT 1 FROM reading_items i WHERE i.correct_option_id = o.id)', [$id]
        ));
    }

    public function testAnInsertionBankWithoutSparePartsIsRejected(): void
    {
        // Every part fitting somewhere turns the task into a permutation the learner can
        // finish by elimination alone, without reading for meaning.
        $doc = $this->insertion();
        array_pop($doc['bank']);
        $doc['items'] = [['position' => 1, 'correct_label' => 'A'], ['position' => 2, 'correct_label' => 'B']];

        $this->expectException(InvalidPassage::class);
        $this->repo->save($doc);
    }

    public function testAGapWithNoMatchingItemIsRejected(): void
    {
        $doc = $this->cloze(['body' => 'En tekst med {{1}} og {{2}} og {{3}}.']);

        $this->expectException(InvalidPassage::class);
        $this->repo->save($doc);
    }

    public function testAnItemWithNoMatchingGapIsRejected(): void
    {
        $doc = $this->cloze(['body' => 'En tekst med kun {{1}}.']);

        $this->expectException(InvalidPassage::class);
        $this->repo->save($doc);
    }

    public function testAMultipleChoicePassageMustNotCarryGapMarkers(): void
    {
        $this->expectException(InvalidPassage::class);
        $this->repo->save([
            'slug' => 'mc-med-huller', 'kind' => 'mc', 'title' => 'T',
            'body' => 'En tekst med et hul {{1}}.',
            'items' => [['position' => 1, 'prompt' => 'Hvorfor?', 'options' => [
                ['label' => 'A', 'text' => 'Fordi', 'correct' => true],
                ['label' => 'B', 'text' => 'Ikke'],
                ['label' => 'C', 'text' => 'Maaske'],
            ]]],
        ]);
    }

    public function testAnItemWithoutACorrectOptionIsRejected(): void
    {
        $doc = $this->cloze();
        unset($doc['items'][0]['options'][0]['correct']);

        $this->expectException(InvalidPassage::class);
        $this->repo->save($doc);
    }

    public function testARejectedPassageWritesNothingAtAll(): void
    {
        try {
            $this->repo->save($this->cloze(['body' => 'Kun {{1}} her.']));
        } catch (InvalidPassage) {
            // expected
        }

        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_passages'));
        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM reading_options'));
    }

    public function testAPassageIsNotPublishedUntilItIsPublished(): void
    {
        $this->repo->save($this->cloze());

        self::assertSame([], $this->repo->publishedByKind('cloze'));
    }

    public function testPublishingMakesAPassageAvailable(): void
    {
        $id = $this->repo->save($this->cloze());
        $this->repo->publish($id);

        $found = $this->repo->publishedByKind('cloze');

        self::assertCount(1, $found);
        self::assertSame('cykler-i-byen', $found[0]['slug']);
    }

    /** @return array<string,mixed> */
    private function video(array $overrides = []): array
    {
        return $overrides + [
            'slug'    => 'svinedrengen',
            'kind'    => 'video',
            'title'   => 'Svinedrengen',
            'youtube' => 'mvvRh8db3HY',
            'body'    => null,
            'items'   => [
                ['position' => 1, 'prompt' => 'Hvad sender prinsen?', 'options' => [
                    ['label' => 'A', 'text' => 'En rose', 'correct' => true],
                    ['label' => 'B', 'text' => 'En hest'],
                    ['label' => 'C', 'text' => 'En ring'],
                ]],
            ],
        ];
    }

    public function testAVideoTaskKeepsTheFilmItIsAbout(): void
    {
        $id = $this->repo->save($this->video());

        self::assertSame(
            'mvvRh8db3HY',
            Db::fetchValue('SELECT youtube_id FROM reading_passages WHERE id = ?', [$id])
        );
    }

    public function testAVideoTaskWithoutAFilmIsRejected(): void
    {
        $this->expectException(InvalidPassage::class);

        $this->repo->save($this->video(['youtube' => '']));
    }

    public function testPublishedVideosListEachFilmWithItsQuestionCount(): void
    {
        $this->repo->publish($this->repo->save($this->video()));
        $this->repo->save($this->video(['slug' => 'kladde', 'title' => 'Kladde']));

        self::assertSame(
            [['slug' => 'svinedrengen', 'title' => 'Svinedrengen', 'youtube' => 'mvvRh8db3HY', 'questions' => 1]],
            $this->repo->publishedVideos()
        );
    }

    /** @return array<string,mixed> */
    private function verbs(array $overrides = []): array
    {
        return $overrides + [
            'slug'  => 'verber-001-025',
            'kind'  => 'verbs',
            'title' => 'Verber 1–25',
            'body'  => null,
            'items' => [
                ['position' => 1, 'prompt' => 'gå (идти) — præteritum', 'options' => [
                    ['label' => 'A', 'text' => 'gik', 'correct' => true],
                    ['label' => 'B', 'text' => 'gået'],
                    ['label' => 'C', 'text' => 'går'],
                ]],
            ],
        ];
    }

    public function testPublishedVerbSetsListEachSetWithItsQuestionCount(): void
    {
        $this->repo->publish($this->repo->save($this->verbs()));
        $this->repo->save($this->verbs(['slug' => 'kladde', 'title' => 'Kladde']));
        $this->repo->publish($this->repo->save($this->video()));

        self::assertSame(
            [['slug' => 'verber-001-025', 'title' => 'Verber 1–25', 'questions' => 1]],
            $this->repo->publishedVerbSets()
        );
    }
}
