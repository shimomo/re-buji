<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;
use ReBuji\Time;

/**
 * @author shimomo
 */
final class SystemStateTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function setsAndOverwritesValues(string $driver): void
    {
        $db = TestDatabase::open($driver);
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $state = new SystemState($db, $clock);

        $this->assertNull($state->get('x'));
        $state->set('x', 'a');
        $this->assertSame('a', $state->get('x'));
        $state->set('x', 'b');
        $this->assertSame('b', $state->get('x'));
        $state->set('x', 'b');
        $this->assertSame('b', $state->get('x'));
        $this->assertSame(Time::format($clock->now()), $db->fetchValue('SELECT updated_at FROM system_state WHERE name = ?', ['x']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }
}
