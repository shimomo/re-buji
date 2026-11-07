<?php

declare(strict_types=1);

namespace ReBuji\Tests\Feed;

use DateTimeImmutable;
use JmaXml\Feed\FeedState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Feed\DatabaseStateStore;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class DatabaseStateStoreTest extends TestCase
{
    private const string FEED = 'https://www.data.jma.go.jp/developer/xml/feed/eqvol.xml';

    #[Test]
    #[DataProvider('drivers')]
    public function savesTheFeedState(string $driver): void
    {
        $store = new DatabaseStateStore($this->migrated($driver));
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        $state = new FeedState('"abc"', null, $at, $at, $at->modify('-20 minutes'), ['https://example.com/a.xml'], 'fingerprint', ['https://example.com/a.xml' => $at]);

        $this->assertNull($store->load(self::FEED));

        $store->save(self::FEED, $state);
        $store->save(self::FEED, $state);

        $this->assertEquals($state, $store->load(self::FEED));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function remembersSeenTelegramsUntilTheyExpire(string $driver): void
    {
        $store = new DatabaseStateStore($this->migrated($driver));
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        $soon = 'https://www.data.jma.go.jp/developer/xml/data/20090810200900_0_VXSE51_220000.xml';
        $later = 'https://www.data.jma.go.jp/developer/xml/data/20090810201100_0_VXSE51_220000.xml';

        $this->assertFalse($store->isSeen($soon));

        $store->markSeen($soon, $at->modify('+1 day'));
        $store->markSeen($soon, $at->modify('+1 day'));
        $store->markSeen($later, $at->modify('+3 days'));

        $this->assertTrue($store->isSeen($soon));

        $store->purge($at->modify('+2 days'));

        $this->assertFalse($store->isSeen($soon));
        $this->assertTrue($store->isSeen($later));
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
