<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Mail\MessageRepository;
use ReBuji\Quakes\Notification;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\JmaSamples;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class QuakeDeletionTest extends TestCase
{
    #[Test]
    public function deletesAQuakeWithItsRecords(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:12:00Z');
            $other = $records->quake('20100705065610', '岩手県沖・最大震度4', '2026-11-05T03:00:00Z');
            $contact = $records->contact($quake, Notification::SENT);
            $records->contact($other, Notification::SENT);
            $at = new DateTimeImmutable('2026-11-05T03:12:00Z');
            self::get($container, MessageRepository::class)->record($contact, 'first', 'person1@example.com', 'm1@example.co.jp', true, '', $at);
            $replies = self::get($container, ReplyRepository::class);
            $replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '', 'r1@example.com', $at);
            $replies->record(null, 'reply', null, 'stranger@example.net', '無事です', '無事です', 'r2@example.net', $at);
            JmaSamples::store(self::get($container, TelegramRepository::class), $at, '32-35_07_01_100915_VXSE51.xml', '32-35_08_02_100915_VXSE51.xml');
            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();
            $this->assertStringContainsString('/earthquakes/' . $quake . '/delete', $page);
            $this->assertStringContainsString('この地震の連絡と返信の記録をすべて消します。元に戻せません。消しますか？', $page);

            $response = $app->handle(TestApp::request('POST', '/earthquakes/' . $quake . '/delete', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes', $response->getHeaderLine('Location'));
            $db = $records->db;
            $this->assertNull($records->earthquakes->find($quake));
            $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE earthquake_id = ?', [$quake]));
            $this->assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM messages WHERE message_id = 'm1@example.co.jp'"));
            $this->assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM replies WHERE message_id = 'r1@example.com'"));
            $this->assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM telegrams WHERE event_id = '20090811050711'"));
            $this->assertSame(1, (int) $db->fetchValue("SELECT COUNT(*) FROM telegrams WHERE event_id = '20100705065610'"));
            $this->assertNotNull($records->earthquakes->find($other));
            $this->assertSame(1, $replies->countUnmatched());
            $this->assertStringContainsString('地震の記録を消しました。', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAnUnknownQuake(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $this->assertSame(404, $app->handle(TestApp::request('POST', '/earthquakes/999/delete', TestApp::csrf($container)))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function get(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        assert($service instanceof $class);

        return $service;
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
