<?php declare(strict_types=1);
/**
 * Minimal forward-only migration runner.
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list applied / pending
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Dansk\Support\Db;

$dir = dirname(__DIR__) . '/db/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pdo = Db::pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB'
);

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) {
        $v = basename($f, '.sql');
        printf("%-10s %s\n", isset($applied[$v]) ? 'applied' : 'PENDING', $v);
    }
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($applied[$version])) {
        continue;
    }

    echo "Applying {$version} ... ";
    $sql = file_get_contents($file);

    // Strip full-line "--" comments, then split on statement-terminating
    // semicolons. Adequate for plain DDL; there are no stored programs here.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*[\r\n]/', $sql)),
        static fn(string $s): bool => $s !== ''
    );

    // No transaction here on purpose: MySQL implicitly commits on every DDL
    // statement, so a wrapping transaction cannot roll a failed migration back
    // and only causes commit() to fail afterwards, masking the real SQL error.
    // A failed migration must be inspected and repaired by hand.
    try {
        foreach ($statements as $i => $statement) {
            $pdo->exec($statement);
        }
    } catch (Throwable $e) {
        $preview = trim(substr($statements[$i] ?? '', 0, 200));
        echo "FAILED\n\n{$e->getMessage()}\n\nStatement #{$i}:\n{$preview}\n";
        exit(1);
    }

    $st = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
    $st->execute([$version]);

    echo "ok (" . count($statements) . " statements)\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to apply; schema is current.\n" : "Applied {$ran} migration(s).\n";
