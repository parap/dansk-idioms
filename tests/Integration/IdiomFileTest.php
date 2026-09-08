<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\IdiomFile;
use Dansk\Support\Db;
use InvalidArgumentException;

/**
 * Hand-added idioms live in content/idioms/ rather than only in the database, because a
 * rebuilt database would otherwise lose them: the imported corpus can be replayed from
 * its export, and these have no export to replay.
 *
 * The shipped files are loaded here as well as by the command line, so a typo in the
 * content is a failing test rather than a surprise at import time.
 */
final class IdiomFileTest extends IntegrationTestCase
{
    private IdiomFile $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new IdiomFile();
    }

    /** @return list<string> */
    private function shipped(): array
    {
        return glob(dirname(__DIR__, 2) . '/content/idioms/*.json') ?: [];
    }

    public function testEveryShippedFileLoads(): void
    {
        self::assertNotSame([], $this->shipped(), 'no idiom content is shipped at all');

        $loaded = 0;
        foreach ($this->shipped() as $file) {
            $loaded += count($this->loader->load($file));
        }

        self::assertSame($loaded, (int) Db::fetchValue('SELECT COUNT(*) FROM idioms'));
        $this->assertCorpusInvariants();
    }

    public function testEveryShippedIdiomIsPublishedWithAUsablePrimary(): void
    {
        foreach ($this->shipped() as $file) {
            $this->loader->load($file);
        }

        self::assertSame(0, (int) Db::fetchValue(
            "SELECT COUNT(*) FROM idioms i
             WHERE i.is_published = 0
                OR NOT EXISTS (SELECT 1 FROM idiom_translations t
                                WHERE t.idiom_id = i.id AND t.lang_code = 'ru'
                                  AND t.is_primary = 1 AND t.quiz_usable = 1)"
        ));
    }

    public function testTheExplanationSurvivesForEveryIdiomThatHasOne(): void
    {
        foreach ($this->shipped() as $file) {
            $this->loader->load($file);
        }

        self::assertGreaterThan(0, (int) Db::fetchValue(
            "SELECT COUNT(*) FROM idiom_explanations WHERE lang_code = 'ru' AND source = 'manual'"
        ));
    }

    public function testASynonymMayBeNamedBeforeTheEntryThatDefinesIt(): void
    {
        // Otherwise the order of a JSON list is load-bearing, and reordering it fails
        // with an error blaming the wrong entry.
        $path = sys_get_temp_dir() . '/order.json';
        file_put_contents($path, json_encode([
            ['term' => 'at smide benene op',  'ru' => 'вытянуть ноги',
             'synonyms' => ['at skuldrene synker']],
            ['term' => 'at skuldrene synker', 'ru' => 'сбросить напряжение'],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $ids = $this->loader->load($path);
            self::assertCount(2, $ids);
            self::assertSame(1, (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idiom_synonyms s1
                 JOIN idiom_synonyms s2 ON s2.group_id = s1.group_id AND s2.idiom_id <> s1.idiom_id
                 WHERE s1.idiom_id = ?', [$ids[0]]
            ));
        } finally {
            @unlink($path);
        }
    }

    public function testAFileThatIsNotAListOfObjectsIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/bad-idioms.json';
        file_put_contents($path, '{"term": "not a list"}');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->loader->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testAnEntryMissingItsTranslationIsRefusedByName(): void
    {
        $path = sys_get_temp_dir() . '/bad-entry.json';
        file_put_contents($path, '[{"term": "med det samme"}]');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('med det samme');
            $this->loader->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testLoadingTheSameFileTwiceDoesNotDuplicateAnything(): void
    {
        $file = $this->shipped()[0];
        $this->loader->load($file);
        $before = (int) Db::fetchValue('SELECT COUNT(*) FROM idioms');

        $this->loader->load($file);

        self::assertSame($before, (int) Db::fetchValue('SELECT COUNT(*) FROM idioms'));
        $this->assertCorpusInvariants();
    }
}
