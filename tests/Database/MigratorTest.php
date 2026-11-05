<?php

declare(strict_types=1);

namespace ReBuji\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Tests\Support\FrozenClock;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }
}
