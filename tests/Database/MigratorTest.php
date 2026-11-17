<?php

declare(strict_types=1);

namespace ReBuji\Tests\Database;

use Override;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migration;
use ReBuji\Database\MigrationInProgress;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestDatabase;
use ReBuji\Time;

/**
 * @author shimomo
 */
final class MigratorTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function appliesPendingMigrationsOnce(string $driver): void
    {
        $db = TestDatabase::open($driver);
        $migrator = new Migrator($db, Migrations::all(), new FrozenClock());

        $versions = array_map(static fn(Migration $m): string => $m->version(), Migrations::all());

        $this->assertSame($versions, array_map(static fn(Migration $m): string => $m->version(), $migrator->pending()));
        $this->assertSame($versions, $migrator->migrate());
        $this->assertSame([], $migrator->pending());
        $this->assertSame([], $migrator->migrate());

        $this->assertSame(1, $db->execute(
            'INSERT INTO admins (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)',
            ['管理者', 'admin@example.com', 'hash', '2026-11-05T03:12:00Z'],
        ));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function recordsWhenEachMigrationWasApplied(string $driver): void
    {
        $db = TestDatabase::open($driver);
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        $this->assertSame(Time::format($clock->now()), $db->fetchValue('SELECT applied_at FROM schema_migrations WHERE version = ?', ['0001']));
    }

    #[Test]
    public function refusesWhileAnotherUpdateIsRunning(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            $lock = $dir . '/migrate.lock';
            $held = fopen($lock, 'c');
            $this->assertNotFalse($held);
            flock($held, LOCK_EX);
            $migrator = new Migrator(TestDatabase::open('sqlite'), [self::migration(['CREATE TABLE test_only (id INTEGER)'])], new FrozenClock(), $lock);

            try {
                $migrator->migrate();
                $this->fail('The update ran while another one held the lock');
            } catch (MigrationInProgress) {
                // Refused, as it should be.
            }

            $this->assertSame(['9999'], array_map(static fn(Migration $m): string => $m->version(), $migrator->pending()));

            flock($held, LOCK_UN);
            fclose($held);

            $this->assertSame(['9999'], $migrator->migrate());
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function leavesNothingOfAMigrationThatFails(): void
    {
        $db = TestDatabase::open('sqlite');
        $migrator = new Migrator($db, [self::migration(['CREATE TABLE half_done (id INTEGER)', 'CREATE TABLE broken ('])], new FrozenClock());

        try {
            $migrator->migrate();
            $this->fail('A broken migration was applied');
        } catch (PDOException) {
            // The second statement fails, as it should.
        }

        $this->assertNull($db->fetchValue("SELECT name FROM sqlite_master WHERE name = 'half_done'"));
        $this->assertCount(1, $migrator->pending());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }

    /**
     * @param list<string> $statements
     */
    private static function migration(array $statements): Migration
    {
        return new class ($statements) implements Migration {
            /**
             * @param list<string> $statements
             */
            public function __construct(private readonly array $statements)
            {
                //
            }

            #[Override]
            public function version(): string
            {
                return '9999';
            }

            #[Override]
            public function description(): string
            {
                return 'テスト用の変更';
            }

            #[Override]
            public function statements(string $driver): array
            {
                return $this->statements;
            }
        };
    }
}
