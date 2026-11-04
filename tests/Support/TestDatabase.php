<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use ReBuji\Database\Database;
use RuntimeException;

/**
 * Databases for tests: SQLite in memory always, and MySQL when REBUJI_TEST_MYSQL_DSN is set (CI).
 *
 * The data provider yields driver names; each test opens its own fresh database with open(), because providers run
 * before any test and could not hand every test an empty MySQL database.
 *
 * @author shimomo
 */
final class TestDatabase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function each(): iterable
    {
        yield 'sqlite' => ['sqlite'];

        if (self::env('REBUJI_TEST_MYSQL_DSN') !== null) {
            yield 'mysql' => ['mysql'];
        }
    }

    public static function open(string $driver): Database
    {
        if ($driver === 'sqlite') {
            return Database::sqliteInMemory();
        }

        $dsn = self::env('REBUJI_TEST_MYSQL_DSN') ?? throw new RuntimeException('REBUJI_TEST_MYSQL_DSN is not set');
        $db = Database::mysql($dsn, self::env('REBUJI_TEST_MYSQL_USER') ?? 'root', self::env('REBUJI_TEST_MYSQL_PASSWORD') ?? '');
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($db->fetchAll('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
            $db->execute('DROP TABLE `' . (string) $row['name'] . '`');
        }

        $db->execute('SET FOREIGN_KEY_CHECKS = 1');

        return $db;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
