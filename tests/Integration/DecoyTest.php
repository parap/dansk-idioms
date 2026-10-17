<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\DistractorService;
use Dansk\Domain\IdiomFile;
use Dansk\Domain\QuizService;
use Dansk\Domain\ReviewRepository;
use Dansk\Support\Db;

/**
 * Decoys: wrong answers written for one idiom out of its own words.
 *
 * Distractors drawn from other idioms share no words with the prompt, so a learner who
 * knows "tredje" picks "третий возраст" without knowing the idiom. A decoy such as
 * "третий лишний" makes the familiar word stop giving the answer away.
 */
final class DecoyTest extends IntegrationTestCase
{
    private const ANON = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ReviewRepository $repo;
    private IdiomFile $loader;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = new ReviewRepository();
        $this->loader = new IdiomFile();
        $this->file   = tempnam(sys_get_temp_dir(), 'decoys');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @param array<string,list<string>> $decoys */
    private function load(array $decoys): int
    {
        file_put_contents($this->file, json_encode($decoys, JSON_UNESCAPED_UNICODE));
        return $this->loader->loadDecoys($this->file);
    }

    private const FILLERS = [
        'читать толстую книгу', 'мыть грязную посуду', 'чинить старый велосипед', 'печь яблочный пирог',
        'красить деревянный забор', 'стирать белые рубашки', 'копать глубокую яму', 'ловить мелкую рыбу',
        'писать длинное письмо', 'гладить мятую скатерть', 'строить песочный замок', 'сажать молодые деревья',
        'петь громкую песню', 'рисовать горный пейзаж', 'кормить голодных голубей', 'собирать лесные грибы',
        'вязать шерстяной шарф', 'варить густой суп', 'точить кухонные ножи', 'шить летнее платье',
    ];

    /** Distinct glosses: ones differing only by a number are near-duplicates of each other. */
    private function corpus(int $fillers = 8): int
    {
        foreach (array_slice(self::FILLERS, 0, $fillers) as $i => $gloss) {
            $this->repo->addByHand("at gøre ting nummer {$i}", $gloss, [], 'phrase', null, null, 'verbal');
        }
        return $this->repo->addByHand('at gå agurk', 'сойти с ума', ['потерять голову'], 'phrase', null, null, 'verbal');
    }

    /** @return array<string,mixed> */
    private function primary(int $idiomId): array
    {
        return Db::fetchOne(
            "SELECT t.id, t.idiom_id, t.text, t.shape, t.word_count, t.char_count, t.sense_type,
                    i.kind, i.register, i.shape AS term_shape
             FROM idiom_translations t JOIN idioms i ON i.id = t.idiom_id
             WHERE t.idiom_id = ? AND t.is_primary = 1", [$idiomId]
        );
    }

    /** @return list<string> the decoy texts offered over many questions */
    private function offered(int $idiomId, int $rounds = 30): array
    {
        $picker = new DistractorService();
        $texts  = [];
        for ($i = 0; $i < $rounds; $i++) {
            $options = $picker->pick($this->primary($idiomId), [], 'ru', 3);
            self::assertCount(3, $options, 'a question must still get three wrong answers');
            $decoys = array_values(array_filter($options, static fn(array $o): bool => isset($o['decoy_id'])));
            self::assertLessThanOrEqual(1, count($decoys), 'two decoys point at the answer they share words with');
            array_push($texts, ...array_column($decoys, 'text'));
        }
        return $texts;
    }

    public function testDecoysLoadForKnownTermsAndSkipUnknownOnes(): void
    {
        $id = $this->corpus();

        self::assertSame(2, $this->load([
            'at gå agurk'   => ['съесть огурец', 'пойти за огурцами'],
            'at ryste posen' => ['трясти мешок'],
        ]));
        self::assertSame(2, (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_decoys WHERE idiom_id = ?', [$id]));
        self::assertSame(2, (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_decoys'));
    }

    public function testReloadingChangesNothingAndDropsADecoyRemovedFromTheFile(): void
    {
        $this->corpus();
        $this->load(['at gå agurk' => ['съесть огурец', 'пойти за огурцами']]);
        $ids = Db::fetchAll('SELECT id FROM idiom_decoys ORDER BY id');

        $this->load(['at gå agurk' => ['съесть огурец', 'пойти за огурцами']]);
        self::assertSame($ids, Db::fetchAll('SELECT id FROM idiom_decoys ORDER BY id'));

        $this->load(['at gå agurk' => ['съесть огурец']]);
        self::assertSame(['съесть огурец'], array_column(Db::fetchAll('SELECT text FROM idiom_decoys'), 'text'));
    }

    public function testAQuestionOffersOneOfTheAskedIdiomsDecoys(): void
    {
        $id = $this->corpus();
        $this->load(['at gå agurk' => ['съесть огурец', 'пойти за огурцами']]);

        $offered = $this->offered($id);

        self::assertNotSame([], $offered, 'a decoy was never offered');
        self::assertSame([], array_diff($offered, ['съесть огурец', 'пойти за огурцами']));
    }

    public function testAnotherIdiomsDecoyIsNeverOffered(): void
    {
        $id = $this->corpus();
        $this->load(['at gøre ting nummer 0' => ['съесть огурец']]);

        self::assertSame([], $this->offered($id));
    }

    public function testADecoyThatIsOneOfTheIdiomsOwnSensesIsNeverOffered(): void
    {
        $id = $this->corpus();
        $this->load(['at gå agurk' => ['потерять голову']]);

        self::assertSame([], $this->offered($id));
    }

    public function testADecoyBreakingVerbParityIsNeverOffered(): void
    {
        $id = $this->corpus();
        $this->load(['at gå agurk' => ['солёный огурец']]);

        self::assertSame([], $this->offered($id));
    }

    public function testTwoAlsoCorrectReportsRetireADecoy(): void
    {
        $id = $this->corpus(fillers: 20);
        $this->load(['at gå agurk' => ['съесть огурец']]);
        // The round takes the other idioms out of the pool, so the corpus is twice its size.
        $quiz = new QuizService();
        $sid = $position = $decoy = null;
        for ($try = 0; $try < 20 && $decoy === null; $try++) {
            $sid = $quiz->start(null, self::ANON, 'ru', 10)['public_id'];
            $row = Db::fetchOne(
                'SELECT q.position, q.options FROM quiz_questions q JOIN quiz_sessions s ON s.id = q.session_id
                 WHERE s.public_id = ? AND q.idiom_id = ?', [$sid, $id]
            );
            foreach (json_decode((string) ($row['options'] ?? '[]'), true) as $o) {
                if (isset($o['decoy'])) {
                    [$position, $decoy] = [(int) $row['position'], $o];
                }
            }
        }
        self::assertNotNull($decoy, 'the decoy never reached a stored question');

        $quiz->answer($sid, $position, (int) $decoy['i'], null);
        $quiz->report($sid, $position, 'also_correct');
        self::assertSame(1, (int) Db::fetchValue('SELECT is_active FROM idiom_decoys WHERE id = ?', [(int) $decoy['decoy']]));

        $quiz->report($sid, $position, 'also_correct');
        self::assertSame(0, (int) Db::fetchValue('SELECT is_active FROM idiom_decoys WHERE id = ?', [(int) $decoy['decoy']]));
        self::assertSame([], $this->offered($id), 'a retired decoy was offered again');
    }

    public function testARetiredDecoyStaysRetiredAcrossAReload(): void
    {
        $id = $this->corpus();
        $this->load(['at gå agurk' => ['съесть огурец']]);
        Db::execute('UPDATE idiom_decoys SET is_active = 0, report_count = 2');

        $this->load(['at gå agurk' => ['съесть огурец']]);

        self::assertSame(0, (int) Db::fetchValue('SELECT is_active FROM idiom_decoys'));
        self::assertSame([], $this->offered($id));
    }

    public function testTheDecoyFileIsNotAnEntryFile(): void
    {
        $dir = sys_get_temp_dir() . '/decoy-entry-' . getmypid();
        @mkdir($dir);
        touch("{$dir}/" . IdiomFile::DECOY_FILE);
        touch("{$dir}/words.json");

        self::assertSame(["{$dir}/words.json"], IdiomFile::entryFiles($dir));

        array_map('unlink', glob("{$dir}/*.json") ?: []);
        rmdir($dir);
    }
}
