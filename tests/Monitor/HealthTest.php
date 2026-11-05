<?php

declare(strict_types=1);

namespace ReBuji\Tests\Monitor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Monitor\Health;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Time;

/**
 * @author shimomo
 */
final class HealthTest extends TestCase
{
    #[Test]
    public function isStaleWithoutARunOrAfterFifteenMinutes(): void
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $state = new SystemState($db, $clock);
        $health = new Health($state, $clock);

        $this->assertNull($health->lastRunAt());
        $this->assertTrue($health->isStale());

        foreach (['-14 minutes' => false, '-15 minutes' => true, '-16 minutes' => true] as $ago => $stale) {
            $state->set('cron.last_finished_at', Time::format($clock->now()->modify($ago)));
            $this->assertSame($stale, $health->isStale(), $ago);
        }
    }

    #[Test]
    public function reportsTheLastErrorOnlyWhenThereIsOne(): void
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $state = new SystemState($db, $clock);
        $health = new Health($state, $clock);

        $this->assertNull($health->lastError());
        $state->set('cron.last_error', 'feed: down');
        $this->assertSame('feed: down', $health->lastError());
        $state->set('cron.last_error', '');
        $this->assertNull($health->lastError());
    }
}
