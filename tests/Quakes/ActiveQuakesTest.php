<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class ActiveQuakesTest extends TestCase
{
    #[Test]
    public function picksTheQuakesStillBeingFollowed(): void
    {
        [, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            $records = new QuakeRecords($container);
            $since = new DateTimeImmutable('2026-11-02T03:12:00Z');

            $auto = $records->quake('e1', '自動', '2026-11-05T02:12:00Z');
            $records->contact($auto);
            $released = $records->quake('e2', '解放', '2026-11-04T00:00:00Z', Earthquake::RELEASED);
            $records->contact($released);
            $drill = $records->drill('2026-11-03T00:00:00Z');
            $records->contact($drill);
            $edge = $records->quake('e3', '境目', '2026-11-02T03:12:00Z');
            $records->contact($edge);

            $old = $records->quake('e4', '古い', '2026-11-02T03:11:00Z');
            $records->contact($old);
            $cancelled = $records->quake('e5', '取消', '2026-11-05T01:00:00Z');
            $records->contact($cancelled);
            $records->earthquakes->markCancelled($cancelled, new DateTimeImmutable('2026-11-05T01:30:00Z'));
            $held = $records->quake('e6', '保留', '2026-11-05T01:00:00Z', Earthquake::HELD);
            $records->contact($held, Notification::HELD);
            $dismissed = $records->quake('e7', '送らない', '2026-11-05T01:00:00Z');
            $records->contact($dismissed);
            $records->earthquakes->setDelivery($dismissed, Earthquake::DISMISSED);
            $records->quake('e8', '連絡なし', '2026-11-05T01:00:00Z');
            $skipped = $records->quake('e9', '対象外だけ', '2026-11-05T01:00:00Z');
            $records->contact($skipped, Notification::SKIPPED);

            $ids = array_map(static fn(Earthquake $quake): int => $quake->id, $records->earthquakes->active($since));

            $this->assertSame([$auto, $released, $drill, $edge], $ids);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
