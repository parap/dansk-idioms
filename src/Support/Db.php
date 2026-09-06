<?php declare(strict_types=1);

namespace Dansk\Support;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $c   = Config::get('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], $c['port'], $c['name'], $c['charset']
        );

        return self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements: LIMIT/OFFSET bind as integers correctly
            // and there is no client-side escaping to get wrong on UTF-8.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /** Connect without selecting a database -- used by the migration bootstrap. */
    public static function serverPdo(): PDO
    {
        $c = Config::get('db');
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']),
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: null;
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
