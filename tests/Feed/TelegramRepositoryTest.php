<?php

declare(strict_types=1);

namespace ReBuji\Tests\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\JmaSamples;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class TelegramRepositoryTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function groupsUnprocessedTelegramsByEvent(string $driver): void
    {
        $telegrams = new TelegramRepository($this->migrated($driver));
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        JmaSamples::store($telegrams, $at, '32-35_07_01_100915_VXSE51.xml', '32-35_08_02_100915_VXSE51.xml', '32-35_07_05_100915_VXSE51.xml');

        $this->assertSame(['20090811050711', '20100705065610'], $telegrams->unprocessedEventIds());
        $this->assertCount(2, $telegrams->reportsOf('20090811050711'));

        $latest = $telegrams->latestOf('20090811050711');
        $this->assertNotNull($latest);
        $this->assertSame('震度速報', $latest['title']);
        $this->assertEquals(new DateTimeImmutable('2009-08-11T05:11:00+09:00'), $latest['reportedAt']);
        $this->assertNull($telegrams->latestOf('19990101000000'));

        $telegrams->markProcessed('20090811050711', $at);

        $this->assertSame(['20100705065610'], $telegrams->unprocessedEventIds());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function storesATelegramOnce(string $driver): void
    {
        $db = $this->migrated($driver);
        $telegrams = new TelegramRepository($db);
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');

        JmaSamples::store($telegrams, $at, '32-35_07_06_240613_VXSE53.xml');
        JmaSamples::store($telegrams, $at, '32-35_07_06_240613_VXSE53.xml');

        $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM telegrams'));
        $this->assertSame(JmaSamples::xml('32-35_07_06_240613_VXSE53.xml'), $db->fetchValue('SELECT xml FROM telegrams'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function prunesOldTelegramsOfQuakesWithoutContacts(string $driver): void
    {
        $db = $this->migrated($driver);
        $telegrams = new TelegramRepository($db);
        $now = new DateTimeImmutable('2009-08-20T00:00:00Z');
        $old = $now->modify('-9 days');
        JmaSamples::store($telegrams, $old, '32-35_07_01_100915_VXSE51.xml', '32-35_08_02_100915_VXSE51.xml', '32-35_06_01_240613_VXSE52.xml');
        $telegrams->markProcessed('20090811050711', $old);
        $telegrams->markProcessed('20100705065610', $old);
        $db->execute(
            "INSERT INTO earthquakes (kind, event_id, title, detected_at, threshold, delivery, late, created_at, updated_at) VALUES ('quake', '20090811050711', '最大震度6弱', ?, '5-', 'auto', 0, ?, ?)",
            ['2009-08-10T20:07:00Z', '2009-08-10T20:12:00Z', '2009-08-10T20:12:00Z'],
        );

        $this->assertSame(1, $telegrams->prune($now));
        $this->assertSame(
            [JmaSamples::url('32-35_06_01_240613_VXSE52.xml'), JmaSamples::url('32-35_07_01_100915_VXSE51.xml')],
            array_map(static fn(array $row): string => (string) $row['url'], $db->fetchAll('SELECT url FROM telegrams ORDER BY url')),
        );
    }

    #[Test]
    #[DataProvider('drivers')]
    public function keepsRecentTelegrams(string $driver): void
    {
        $telegrams = new TelegramRepository($this->migrated($driver));
        $now = new DateTimeImmutable('2009-08-20T00:00:00Z');
        JmaSamples::store($telegrams, $now->modify('-7 days'), '32-35_08_02_100915_VXSE51.xml');
        $telegrams->markProcessed('20100705065610', $now);

        $this->assertSame(0, $telegrams->prune($now));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }

    private function migrated(string $driver): Database
    {
        $db = TestDatabase::open($driver);
        (new Migrator($db, Migrations::all(), new FrozenClock()))->migrate();

        return $db;
    }
}
