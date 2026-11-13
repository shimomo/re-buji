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
     * MySQL 8.4's default, set here so that a local MariaDB is as strict as the CI job.
     */
    private const string MYSQL_SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

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

    /**
     * The database the quake, sending and reply tests run on: MySQL in the CI job that has it, SQLite elsewhere.
     */
    public static function quakeDriver(): string
    {
        return self::env('REBUJI_TEST_MYSQL_DSN') !== null ? 'mysql' : 'sqlite';
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
        $db->execute("SET SESSION sql_mode = '" . self::MYSQL_SQL_MODE . "'");

        return $db;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
