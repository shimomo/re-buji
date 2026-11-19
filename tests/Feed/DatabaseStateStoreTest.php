<?php

declare(strict_types=1);

namespace ReBuji\Tests\Feed;

use DateTimeImmutable;
use JmaXml\Feed\FeedState;
use PDOException;
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
        $db = $this->migrated($driver);
        $store = new DatabaseStateStore($db);
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        $state = new FeedState('"abc"', null, $at, $at, $at->modify('-20 minutes'), ['https://example.com/a.xml'], 'fingerprint', ['https://example.com/a.xml' => $at]);

        $this->assertNull($store->load(self::FEED));

        $store->save(self::FEED, $state);
        $store->flush();
        $store->save(self::FEED, $state);
        $store->flush();

        $this->assertEquals($state, (new DatabaseStateStore($db))->load(self::FEED));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function remembersSeenTelegramsUntilTheyExpire(string $driver): void
    {
        $db = $this->migrated($driver);
        $store = new DatabaseStateStore($db);
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        $soon = 'https://www.data.jma.go.jp/developer/xml/data/20090810200900_0_VXSE51_220000.xml';
        $later = 'https://www.data.jma.go.jp/developer/xml/data/20090810201100_0_VXSE51_220000.xml';

        $this->assertFalse($store->isSeen($soon));

        $store->markSeen($soon, $at->modify('+1 day'));
        $store->flush();
        $store->markSeen($soon, $at->modify('+1 day'));
        $store->markSeen($later, $at->modify('+3 days'));
        $store->flush();

        $this->assertTrue((new DatabaseStateStore($db))->isSeen($soon));

        $store->purge($at->modify('+2 days'));

        $this->assertFalse((new DatabaseStateStore($db))->isSeen($soon));
        $this->assertTrue((new DatabaseStateStore($db))->isSeen($later));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function keepsWritesUntilFlushed(string $driver): void
    {
        $db = $this->migrated($driver);
        $store = new DatabaseStateStore($db);
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        $url = 'https://www.data.jma.go.jp/developer/xml/data/20090810200900_0_VXSE51_220000.xml';
        $state = new FeedState('"abc"', null, $at, $at, $at->modify('-20 minutes'), [$url], 'fingerprint', [$url => $at]);

        $store->markSeen($url, $at->modify('+1 day'));
        $store->save(self::FEED, $state);

        $this->assertTrue($store->isSeen($url));
        $this->assertEquals($state, $store->load(self::FEED));
        $this->assertFalse((new DatabaseStateStore($db))->isSeen($url));
        $this->assertNull((new DatabaseStateStore($db))->load(self::FEED));

        $store->discard();

        $this->assertFalse($store->isSeen($url));
        $this->assertNull($store->load(self::FEED));

        $store->markSeen($url, $at->modify('+1 day'));
        $store->flush();

        $this->assertTrue((new DatabaseStateStore($db))->isSeen($url));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function writesAllOrNothing(string $driver): void
    {
        $db = $this->migrated($driver);
        $store = new DatabaseStateStore($db);
        $at = new DateTimeImmutable('2009-08-10T20:12:00Z');
        $url = 'https://www.data.jma.go.jp/developer/xml/data/20090810200900_0_VXSE51_220000.xml';
        $store->markSeen($url, $at->modify('+1 day'));
        $store->save(self::FEED, new FeedState('"abc"', null, $at, $at, $at->modify('-20 minutes'), [$url], 'fingerprint', [$url => $at]));
        $db->execute('DROP TABLE feed_states');

        try {
            $store->flush();
            $this->fail('A flush that cannot write the feed state should fail');
        } catch (PDOException) {
            // expected
        }

        $this->assertFalse((new DatabaseStateStore($db))->isSeen($url));
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
