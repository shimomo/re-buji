<?php

declare(strict_types=1);

namespace ReBuji\Tests\Feed;

use JmaXml\Feed\Exception\FeedException;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedType;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Feed\DatabaseStateStore;
use ReBuji\Feed\FeedJob;
use ReBuji\Feed\TelegramRepository;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FakeJmaServer;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\JmaSamples;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class FeedJobTest extends TestCase
{
    private const string START = '2009-08-10T20:12:00Z';

    #[Test]
    public function primesOnTheFirstRun(): void
    {
        [$job, $db, $server, $clock, $state, $root] = $this->world();

        try {
            $entry = [['url' => FakeJmaServer::telegramUrl('20090810200900', 'VXSE51')]];
            $server->serve(self::high(), FakeJmaServer::feed(self::START, $entry));
            $server->serve(self::long(), FakeJmaServer::feed(self::START, $entry));

            $job->run($clock->now());

            $this->assertSame(0, $this->telegramCount($db));
            $this->assertSame(self::START, $state->get('feed.last_checked_at'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function storesNewTelegrams(): void
    {
        [$job, $db, $server, $clock, $state, $root] = $this->world();

        try {
            $this->prime($job, $server, $clock);
            $url = $this->newEntry($server, $clock, JmaSamples::xml('32-35_07_01_100915_VXSE51.xml'));

            $job->run($clock->now());

            $row = $db->fetchOne('SELECT url, event_id, title, status, info_type, reported_at, processed_at FROM telegrams');
            $this->assertNotNull($row);
            $this->assertSame([
                'url' => $url,
                'event_id' => '20090811050711',
                'title' => '震度速報',
                'status' => '通常',
                'info_type' => '発表',
                'reported_at' => '2009-08-10T20:09:00Z',
                'processed_at' => null,
            ], $row);
            $this->assertSame('2009-08-10T20:13:00Z', $state->get('feed.last_checked_at'));

            $clock->advance('+1 minute');
            $job->run($clock->now());

            $this->assertSame(1, $this->telegramCount($db));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function ignoresDrillTelegrams(): void
    {
        [$job, $db, $server, $clock, , $root] = $this->world();

        try {
            $this->prime($job, $server, $clock);
            $this->newEntry($server, $clock, JmaSamples::edited('32-35_07_01_100915_VXSE51.xml', ['<Status>通常</Status>' => '<Status>訓練</Status>']));

            $job->run($clock->now());

            $this->assertSame(0, $this->telegramCount($db));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function receivesTheTelegramAgainAfterAFailedSave(): void
    {
        [$job, $db, $server, $clock, , $root] = $this->world();

        try {
            $this->prime($job, $server, $clock);
            $url = $this->newEntry($server, $clock, JmaSamples::xml('32-35_07_01_100915_VXSE51.xml'));
            $db->execute("CREATE TRIGGER refuse_telegrams BEFORE INSERT ON telegrams BEGIN SELECT RAISE(ABORT, 'disk full'); END");

            try {
                $job->run($clock->now());
                $this->fail('The failed save should reach the cron runner');
            } catch (PDOException $exception) {
                $this->assertStringContainsString('disk full', $exception->getMessage());
            }

            $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM feed_seen WHERE url = ?', [$url]));

            $db->execute('DROP TRIGGER refuse_telegrams');
            $clock->advance('+1 minute');
            $job->run($clock->now());

            $this->assertSame(1, $this->telegramCount($db));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function remembersAGapItCouldNotFill(): void
    {
        [$job, , $server, $clock, $state, $root] = $this->world();

        try {
            $this->prime($job, $server, $clock);
            $clock->advance('+20 minutes');
            $server->serve(self::high(), FakeJmaServer::feed('2009-08-10T20:32:00Z', []));
            $server->serve(self::long(), 500);

            $job->run($clock->now());

            $this->assertSame(self::START, $state->get('feed.gap_since'));

            $clock->advance('+1 minute');
            $server->serve(self::long(), FakeJmaServer::feed('2009-08-10T20:32:00Z', []));
            $job->run($clock->now());

            $this->assertSame('', $state->get('feed.gap_since'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTelegramsThatCouldNotBeRead(): void
    {
        [$job, , $server, $clock, $state, $root] = $this->world();

        try {
            $this->prime($job, $server, $clock);
            $clock->advance('+1 minute');
            $url = FakeJmaServer::telegramUrl('20090810201300', 'VXSE51');
            $server->serve(self::high(), FakeJmaServer::feed('2009-08-10T20:13:00Z', [['url' => $url]]));

            $job->run($clock->now());

            $this->assertNull($state->get('feed.failed_telegrams'));

            $clock->advance('+11 minutes');
            $job->run($clock->now());

            $failed = json_decode((string) $state->get('feed.failed_telegrams'), true);
            $this->assertIsArray($failed);
            $this->assertCount(1, $failed);
            $this->assertIsArray($failed[0]);
            $this->assertSame('震度速報', $failed[0]['title']);
            $this->assertSame($url, $failed[0]['url']);
            $this->assertSame('2009-08-10T20:24:00Z', $failed[0]['at']);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function leavesTheCheckTimeWhenTheFeedFails(): void
    {
        [$job, , $server, $clock, $state, $root] = $this->world();

        try {
            $this->prime($job, $server, $clock);
            $clock->advance('+1 minute');
            $server->serve(self::high(), 500);

            try {
                $job->run($clock->now());
                $this->fail('A feed that cannot be read should reach the cron runner');
            } catch (FeedException) {
                // expected
            }

            $this->assertSame(self::START, $state->get('feed.last_checked_at'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{FeedJob, Database, FakeJmaServer, FrozenClock, SystemState, string}
     */
    private function world(): array
    {
        $root = TemporaryDirectory::create();
        $db = Database::sqliteFile($root . '/database.sqlite');
        $clock = new FrozenClock(new \DateTimeImmutable(self::START));
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $server = new FakeJmaServer();
        $store = new DatabaseStateStore($db);
        $state = new SystemState($db, $clock);
        $client = new FeedClient($server, new Psr17Factory(), $store, $clock, 'Re-Buji/test');
        $job = new FeedJob($client, $db, $store, new TelegramRepository($db), $state, new NullLogger());

        return [$job, $db, $server, $clock, $state, $root];
    }

    /**
     * The first run reads both feeds (empty here) and returns nothing.
     */
    private function prime(FeedJob $job, FakeJmaServer $server, FrozenClock $clock): void
    {
        $server->serve(self::high(), FakeJmaServer::feed(self::START, []));
        $server->serve(self::long(), FakeJmaServer::feed(self::START, []));
        $job->run($clock->now());
    }

    /**
     * A minute later the high-frequency feed lists one new telegram with the given XML.
     */
    private function newEntry(FakeJmaServer $server, FrozenClock $clock, string $xml): string
    {
        $clock->advance('+1 minute');
        $url = FakeJmaServer::telegramUrl('20090810201300', 'VXSE51');
        $server->serve(self::high(), FakeJmaServer::feed('2009-08-10T20:13:00Z', [['url' => $url]]));
        $server->serve($url, $xml);

        return $url;
    }

    private function telegramCount(Database $db): int
    {
        return (int) $db->fetchValue('SELECT COUNT(*) FROM telegrams');
    }

    private static function high(): string
    {
        return FeedType::EarthquakeVolcano->highFrequencyUrl();
    }

    private static function long(): string
    {
        return FeedType::EarthquakeVolcano->longTermUrl();
    }
}
