<?php

declare(strict_types=1);

namespace ReBuji\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\Config;
use ReBuji\Database\Database;
use ReBuji\Paths;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestDatabase;
use RuntimeException;

/**
 * @author shimomo
 */
final class DatabaseTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function runsQueriesWithParameters(string $driver): void
    {
        $db = TestDatabase::open($driver);
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body VARCHAR(255))');

        $this->assertSame(1, $db->execute('INSERT INTO notes (id, body) VALUES (?, ?)', [1, '無事です']));
        $this->assertSame(['id' => 1, 'body' => '無事です'], array_map(static fn(mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $db->fetchOne('SELECT id, body FROM notes WHERE id = ?', [1]) ?? []));
        $this->assertNull($db->fetchOne('SELECT id FROM notes WHERE id = ?', [2]));
        $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM notes'));
        $this->assertCount(1, $db->fetchAll('SELECT * FROM notes'));
        $this->assertSame($driver, $db->driver());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function rollsBackAFailedTransaction(string $driver): void
    {
        $db = TestDatabase::open($driver);
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body VARCHAR(255))');

        try {
            $db->transaction(static function (Database $db): void {
                $db->execute('INSERT INTO notes (id, body) VALUES (1, ?)', ['x']);

                throw new RuntimeException('boom');
            });
            $this->fail('The exception was swallowed');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM notes'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function joinsAnOpenTransaction(string $driver): void
    {
        // A CSV import saves every row in one transaction, and each save opens its own.
        $db = TestDatabase::open($driver);
        $db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body VARCHAR(255))');

        try {
            $db->transaction(static function (Database $db): void {
                $db->execute('INSERT INTO notes (id, body) VALUES (1, ?)', ['x']);
                $db->transaction(static fn(Database $db): int => $db->execute('INSERT INTO notes (id, body) VALUES (2, ?)', ['y']));

                throw new RuntimeException('boom');
            });
            $this->fail('The exception was swallowed');
        } catch (RuntimeException) {
            $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM notes'));
        }

        $db->transaction(static function (Database $db): void {
            $db->execute('INSERT INTO notes (id, body) VALUES (1, ?)', ['x']);
            $db->transaction(static fn(Database $db): int => $db->execute('INSERT INTO notes (id, body) VALUES (2, ?)', ['y']));
        });

        $this->assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM notes'));
    }

    #[Test]
    public function sqliteFileIsOpenedWithoutWal(): void
    {
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/storage');
            $db = Database::fromConfig(new Config(['db.driver' => 'sqlite', 'db.sqlite.path' => 'database.sqlite']), new Paths($root));

            $this->assertSame('delete', $db->fetchValue('PRAGMA journal_mode'));
            $this->assertSame(1, (int) $db->fetchValue('PRAGMA foreign_keys'));
            $this->assertSame(5000, (int) $db->fetchValue('PRAGMA busy_timeout'));
            $this->assertFileExists($root . '/storage/database.sqlite');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }
}
