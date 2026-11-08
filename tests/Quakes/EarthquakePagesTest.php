<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class EarthquakePagesTest extends TestCase
{
    #[Test]
    public function listsQuakesNewestFirst(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $suruga = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T02:00:00Z');
            $records->contact($suruga, Notification::SENT);
            $records->contact($suruga, Notification::SENT);
            $records->contact($suruga, Notification::PENDING, '2026-11-05T02:00:00Z');
            $records->contact($suruga, Notification::FAILED);
            $cancelled = $records->quake('20080614084350', '岩手県内陸南部・最大震度6強', '2026-11-04T23:00:00Z');
            $records->earthquakes->markCancelled($cancelled, new DateTimeImmutable('2026-11-04T23:10:00Z'));
            $records->drill('2026-11-05T03:00:00Z');

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody();

            $drill = strpos($page, '訓練</a></td>');
            $quake = strpos($page, '駿河湾・最大震度6弱');
            $old = strpos($page, '岩手県内陸南部・最大震度6強');
            $this->assertNotFalse($drill);
            $this->assertNotFalse($quake);
            $this->assertNotFalse($old);
            $this->assertTrue($drill < $quake && $quake < $old, 'newest first');
            $this->assertStringContainsString('2026-11-05 11:00', $page);
            $this->assertStringContainsString('<td>4</td><td>2</td><td>1</td><td>1</td>', $page);
            $this->assertStringContainsString('取消', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function sendsALateQuakeWhenAsked(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $late = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-04T20:00:00Z', Earthquake::HELD);
            $contact = $records->contact($late, Notification::HELD, '2026-11-04T20:00:00Z');

            $list = (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody();
            $this->assertStringContainsString('遅れて届いた地震', $list);
            $this->assertStringContainsString('/earthquakes/' . $late . '/release', $list);
            $this->assertStringContainsString('/earthquakes/' . $late . '/dismiss', $list);

            $response = $app->handle(TestApp::request('POST', '/earthquakes/' . $late . '/release', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes', $response->getHeaderLine('Location'));
            $this->assertSame(Earthquake::RELEASED, $records->earthquakes->find($late)?->delivery);
            $released = $records->notifications->find($contact);
            $this->assertNotNull($released);
            $this->assertSame(Notification::PENDING, $released->state);
            $this->assertEquals(new DateTimeImmutable('2026-11-05T03:12:00Z'), $released->notBefore);
            $this->assertStringContainsString('遅れて届いた地震の連絡を送ります。cron が順に送ります。', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function dropsALateQuakeWhenAsked(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $late = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-04T20:00:00Z', Earthquake::HELD);
            $contact = $records->contact($late, Notification::HELD, '2026-11-04T20:00:00Z');

            $response = $app->handle(TestApp::request('POST', '/earthquakes/' . $late . '/dismiss', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(Earthquake::DISMISSED, $records->earthquakes->find($late)?->delivery);
            $this->assertSame(Notification::SKIPPED, $records->notifications->find($contact)?->state);
            $this->assertStringContainsString('遅れて届いた地震の連絡を送らないことにしました。', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesQuakesThatAreNotHeld(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $auto = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:00:00Z');

            $this->assertSame(404, $app->handle(TestApp::request('POST', '/earthquakes/' . $auto . '/release', TestApp::csrf($container)))->getStatusCode());
            $this->assertSame(404, $app->handle(TestApp::request('POST', '/earthquakes/999/dismiss', TestApp::csrf($container)))->getStatusCode());
            $this->assertSame(Earthquake::AUTO, $records->earthquakes->find($auto)?->delivery);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysWhenThereAreNoQuakes(): void
    {
        [$app, , $root] = self::loggedIn();

        try {
            $this->assertStringContainsString('まだ地震の連絡はありません。', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private static function loggedIn(): array
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }
}
