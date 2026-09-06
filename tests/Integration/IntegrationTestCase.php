<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Support\Config;
use Dansk\Support\Db;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that exercise real SQL.
 *
 * Every expensive bug this project has had lived in code that talks to the database --
 * a primary flag lost on upsert, a human correction severed by a re-parse, idioms
 * deleted by a wrong definition of "orphaned". None of it was reachable from a pure
 * unit test, so these run against a scratch schema built from the real migrations.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const TEST_DB = 'dansk_test';

    public static function setUpBeforeClass(): void
    {
        Config::override(['db' => ['name' => self::TEST_DB]]);
        Db::reset();

        // Built from db/migrations, not a hand-maintained copy: a schema that has
        // drifted from production would make these tests worse than useless.
        $server = Db::serverPdo();
        $server->exec('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        $server->exec(
            'CREATE DATABASE ' . self::TEST_DB
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
        );

        Db::reset();
        $pdo = Db::pdo();
        foreach (glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') ?: [] as $file) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
            foreach (preg_split('/;\s*[\r\n]/', (string) $sql) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        Db::serverPdo()->exec('DROP DATABASE IF EXISTS ' . self::TEST_DB);
        Db::reset();
        self::$tables = null;
    }

    /** @var list<string>|null */
    private static ?array $tables = null;

    protected function setUp(): void
    {
        $pdo = Db::pdo();

        // DELETE, not TRUNCATE. TRUNCATE is DDL: InnoDB drops and recreates the
        // tablespace, which cost ~0.5s across these tables and, at 20 tables per test,
        // was over half the suite's total runtime. DELETE on an already-empty table is
        // effectively free. Nothing here asserts on specific auto-increment values.
        self::$tables ??= array_values(array_diff(
            $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
            ['languages', 'schema_migrations']
        ));

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$tables as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function fixtureExport(): string
    {
        return dirname(__DIR__) . '/fixtures/export-sample.html';
    }

    /** Guards the invariants that broke silently in production. */
    protected function assertCorpusInvariants(): void
    {
        self::assertSame(
            0,
            (int) Db::fetchValue(
                'SELECT COUNT(*) FROM idioms i WHERE i.is_published = 1 AND NOT EXISTS (
                     SELECT 1 FROM idiom_translations t
                     WHERE t.idiom_id = i.id AND t.is_primary = 1 AND t.quiz_usable = 1)'
            ),
            'every published idiom must have a usable primary translation'
        );

        self::assertSame(
            0,
            (int) Db::fetchValue(
                "SELECT COUNT(*) FROM idiom_translations t1
                 JOIN idiom_translations t2 ON t2.idiom_id = t1.idiom_id
                  AND t2.lang_code = t1.lang_code AND t2.id <> t1.id
                 WHERE t1.is_primary = 1 AND t2.is_primary = 1"
            ),
            'at most one primary translation per idiom and language'
        );

        self::assertSame(
            0,
            (int) Db::fetchValue('SELECT COUNT(*) FROM idiom_translations WHERE is_primary = 0'),
            'is_primary must be 1 or NULL, never 0 -- the unique index depends on it'
        );
    }
}
