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
use ReBuji\Quakes\QuakeSnapshot;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class QuakeBoardPageTest extends TestCase
{
    #[Test]
    public function showsTheQuakeInTheOrderOfTheDesign(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $safe = $records->contact($quake, Notification::SENT);
            $check = $records->contact($quake, Notification::SENT);
            $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($safe, Notification::SAFE, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringContainsString('11月5日 12:12 ごろ　宮城県沖（M6.8）最大震度6弱', $page);
            $this->assertStringContainsString('震源・震度に関する情報（12:17 発表）をもとに判定　｜　対象：震度5弱以上の市町村に登録した人', $page);
            $this->assertStringContainsString('通常（訓練ではない）', $page);
            $positions = array_map(static fn(string $text): int|false => strpos($page, $text), ['対象 3 人', '返信なし 1 人', '要確認（本文あり） 1 人', '無事（空の返信） 1 人', '<h2>返信なし', '<h2>要確認', '<h2>無事']);
            $this->assertNotContains(false, $positions);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions);
            $this->assertStringContainsString('/contacts/' . $check . '/check', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function marksAReplyChecked(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $check = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));

            $response = $app->handle(TestApp::request('POST', '/contacts/' . $check . '/check', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes/' . $quake, $response->getHeaderLine('Location'));
            $checked = $records->notifications->find($check);
            $this->assertNotNull($checked);
            $this->assertEquals(new DateTimeImmutable('2026-11-05T03:12:00Z'), $checked->checkedAt);
            $this->assertSame('安否 太郎', $checked->checkedBy);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesToCheckWhatNeedsNoChecking(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $contact = $records->contact(self::miyagi($records), Notification::SENT);

            $this->assertSame(404, $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/check', TestApp::csrf($container)))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysAQuakeWasCancelled(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $records->earthquakes->markCancelled($quake, new DateTimeImmutable('2026-11-05T03:15:00Z'));

            $this->assertStringContainsString('この地震は気象庁が取り消しました。', (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody());
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/earthquakes/999'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function linksTheListToEachQuake(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $quake = self::miyagi(new QuakeRecords($container));

            $this->assertStringContainsString('href="/earthquakes/' . $quake . '"', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private static function miyagi(QuakeRecords $records): int
    {
        return $records->earthquakes->createQuake(
            '20261105121200',
            new QuakeSnapshot('宮城県沖・最大震度6弱', '宮城県沖', '6.8', '6-', null, '震源・震度に関する情報', new DateTimeImmutable('2026-11-05T03:17:00Z')),
            '5-',
            Earthquake::AUTO,
            false,
            new DateTimeImmutable('2026-11-05T03:12:00Z'),
        );
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
